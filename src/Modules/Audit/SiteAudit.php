<?php
/**
 * Site audit: crawls the site's own pages and scores its technical and on-page health.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Audit;

use BlueLens\Analytics\Aggregation\SiteTime;
use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Jobs;
use BlueLens\Analytics\Core\Settings;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an audit in small steps so it never hits PHP time limits:
 *
 * 1. start(): lists URLs from WordPress itself (home page, published content of every public post
 *    type, busiest category/tag archives) and stores them as pending rows.
 * 2. step(): fetches pending pages anonymously over HTTP and analyses each one (PageAnalyzer),
 *    then checks internal links that point outside the crawled set.
 * 3. finalize(): adds cross-page issues (duplicates, orphans, broken links, pages without
 *    visits), runs site-wide checks and computes the health score.
 *
 * Steps run in the background (Action Scheduler or WP-Cron) and also while the Site Audit
 * screen is open, so audits finish on sites where background jobs are slow.
 */
final class SiteAudit implements Hookable {

	public const STEP_HOOK   = 'blue_lens_audit_step';
	public const WEEKLY_HOOK = 'blue_lens_audit_weekly';
	public const UA          = 'BlueLensAudit';

	private const LOCK_OPTION     = 'blue_lens_audit_lock';
	private const KEEP_RUNS       = 10;
	private const MAX_LINK_CHECKS = 300;
	private const MAX_TERMS       = 30;
	private const STALE_SECONDS   = 6 * HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings.
	 * @param PageAnalyzer $analyzer Page analyser.
	 */
	public function __construct(
		private Settings $settings,
		private PageAnalyzer $analyzer
	) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( self::STEP_HOOK, [ $this, 'background_step' ] );
		add_action( self::WEEKLY_HOOK, [ $this, 'weekly' ] );
		add_filter(
			'blue_lens_recurring_jobs',
			function ( array $jobs ): array {
				if ( $this->settings->get( 'audit_weekly' ) ) {
					$jobs[ self::WEEKLY_HOOK ] = WEEK_IN_SECONDS;
				}
				return $jobs;
			}
		);
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Starts an audit, or returns the one already running.
	 *
	 * @param int $user_id User who started it (0 for scheduled runs).
	 * @return array<string, mixed> Run row.
	 */
	public function start( int $user_id = 0 ): array {
		global $wpdb;

		$running = $this->running();
		if ( $running ) {
			return $running;
		}

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Tables::name( Tables::AUDIT_RUNS ),
			[
				'status'     => 'running',
				'phase'      => 'pages',
				'started_at' => $now,
				'started_by' => $user_id,
			],
			[ '%s', '%s', '%s', '%d' ]
		);
		$run_id = (int) $wpdb->insert_id;

		$urls = $this->collect_urls( (int) $this->settings->get( 'audit_max_pages' ) );
		foreach ( array_chunk( $urls, 100 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $u ) {
				$values[] = $wpdb->prepare( '(%d, %s, %s, %d, %s)', $run_id, $u['url'], $u['path'], $u['post_id'], $u['source'] );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- each value tuple is prepared above.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (run_id, url, path, post_id, source) VALUES ', Tables::name( Tables::AUDIT_PAGES ) ) . implode( ',', $values ) );
		}

		$wpdb->update( Tables::name( Tables::AUDIT_RUNS ), [ 'pages_total' => count( $urls ) ], [ 'id' => $run_id ], [ '%d' ], [ '%d' ] );

		$this->prune();
		Jobs::enqueue( self::STEP_HOOK, [ $run_id ] );

		/**
		 * Fires when a site audit starts.
		 *
		 * @param int $run_id Run ID.
		 */
		do_action( 'blue_lens_audit_started', $run_id );

		return (array) $this->run( $run_id );
	}

	/**
	 * Background job: one step, then schedules the next.
	 *
	 * @param int|string $run_id Run ID.
	 */
	public function background_step( $run_id = 0 ): void {
		$run = $this->step( (int) $run_id, 20.0 );
		if ( $run && 'running' === $run['status'] ) {
			Jobs::enqueue( self::STEP_HOOK, [ (int) $run_id ] );
		}
	}

	/**
	 * Weekly scheduled audit.
	 */
	public function weekly(): void {
		if ( $this->settings->get( 'audit_weekly' ) ) {
			$this->start( 0 );
		}
	}

	/**
	 * Advances a run for up to $budget seconds.
	 *
	 * @param int   $run_id Run ID.
	 * @param float $budget Seconds to spend.
	 * @return array<string, mixed>|null Run row after the step.
	 */
	public function step( int $run_id, float $budget = 6.0 ): ?array {
		$run = $this->run( $run_id );
		if ( ! $run || 'running' !== $run['status'] ) {
			return $run;
		}
		if ( ! $this->lock() ) {
			return $run;
		}

		$deadline = microtime( true ) + $budget;
		try {
			if ( 'pages' === $run['phase'] ) {
				$this->crawl_pages( $run_id, $deadline );
			}
			$run = (array) $this->run( $run_id );
			if ( 'links' === $run['phase'] && microtime( true ) < $deadline ) {
				$this->check_links( $run_id, $deadline );
			}
			$run = (array) $this->run( $run_id );
			if ( 'finalize' === $run['phase'] ) {
				$this->finalize( $run_id );
			}
		} finally {
			$this->unlock();
		}

		return $this->run( $run_id );
	}

	/**
	 * Cancels a running audit.
	 *
	 * @param int $run_id Run ID.
	 */
	public function cancel( int $run_id ): void {
		global $wpdb;

		$wpdb->update(
			Tables::name( Tables::AUDIT_RUNS ),
			[
				'status'      => 'cancelled',
				'finished_at' => current_time( 'mysql', true ),
			],
			[
				'id'     => $run_id,
				'status' => 'running',
			],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);
	}

	/**
	 * A run row with decoded JSON columns.
	 *
	 * @param int $run_id Run ID.
	 * @return array<string, mixed>|null
	 */
	public function run( int $run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::name( Tables::AUDIT_RUNS ), $run_id ), ARRAY_A );

		return is_array( $row ) ? self::decode_run( $row ) : null;
	}

	/**
	 * The running audit, if any. Runs that stopped advancing hours ago are marked failed.
	 *
	 * @return array<string, mixed>|null
	 */
	public function running(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1', Tables::name( Tables::AUDIT_RUNS ), 'running' ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( strtotime( $row['started_at'] . ' UTC' ) < time() - self::STALE_SECONDS ) {
			$wpdb->update( Tables::name( Tables::AUDIT_RUNS ), [ 'status' => 'failed' ], [ 'id' => (int) $row['id'] ], [ '%s' ], [ '%d' ] );
			return null;
		}

		return self::decode_run( $row );
	}

	/**
	 * Decodes JSON columns and casts numbers.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public static function decode_run( array $row ): array {
		foreach ( [ 'site_checks', 'summary' ] as $col ) {
			$decoded     = json_decode( (string) ( $row[ $col ] ?? '' ), true );
			$row[ $col ] = is_array( $decoded ) ? $decoded : [];
		}
		foreach ( [ 'id', 'started_by', 'pages_total', 'pages_done', 'errors', 'warnings', 'notices' ] as $col ) {
			$row[ $col ] = (int) ( $row[ $col ] ?? 0 );
		}
		$row['health'] = null === $row['health'] ? null : (int) $row['health'];

		return $row;
	}

	/* ------------------------------------------------------------------ */
	/* Crawling                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * URLs to audit, from WordPress itself.
	 *
	 * @param int $limit Maximum URLs.
	 * @return list<array{url: string, path: string, post_id: int, source: string}>
	 */
	public function collect_urls( int $limit ): array {
		$limit = max( 1, $limit );
		$out   = [];
		$seen  = [];
		$add   = function ( string $url, int $post_id, string $source ) use ( &$out, &$seen, $limit ): void {
			if ( count( $out ) >= $limit || '' === $url ) {
				return;
			}
			$key = $this->analyzer->normalize_url( $url, $url );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$out[]        = [
				'url'     => $url,
				'path'    => self::path_of( $url ),
				'post_id' => $post_id,
				'source'  => $source,
			];
		};

		$add( home_url( '/' ), (int) get_option( 'page_on_front' ), 'home' );

		$types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
		$ids   = get_posts(
			[
				'post_type'        => $types,
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
		foreach ( $ids as $id ) {
			$link = get_permalink( (int) $id );
			if ( is_string( $link ) ) {
				$add( $link, (int) $id, 'content' );
			}
		}

		$taxonomies = array_values( array_diff( get_taxonomies( [ 'public' => true ] ), [ 'post_format' ] ) );
		if ( $taxonomies ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomies,
					'hide_empty' => true,
					'number'     => self::MAX_TERMS,
					'orderby'    => 'count',
					'order'      => 'DESC',
				]
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( is_string( $link ) ) {
						$add( $link, 0, 'archive' );
					}
				}
			}
		}

		/**
		 * Filters the URLs a site audit crawls.
		 *
		 * @param list<array{url: string, path: string, post_id: int, source: string}> $out   URLs.
		 * @param int                                                                  $limit Maximum.
		 */
		$out = (array) apply_filters( 'blue_lens_audit_urls', $out, $limit );

		return array_slice( array_values( $out ), 0, $limit );
	}

	/**
	 * Fetches and analyses pending pages until the deadline.
	 *
	 * @param int   $run_id   Run ID.
	 * @param float $deadline microtime() deadline.
	 */
	private function crawl_pages( int $run_id, float $deadline ): void {
		global $wpdb;

		$table = Tables::name( Tables::AUDIT_PAGES );
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, url, source FROM %i WHERE run_id = %d AND checked = 0 ORDER BY id LIMIT 3', $table, $run_id ), ARRAY_A );
			foreach ( $rows as $row ) {
				$response = $this->fetch( (string) $row['url'] );
				$result   = $this->analyzer->analyze( (string) $row['url'], $response['status'], $response['ms'], $response['body'], (string) $row['source'] );
				$counts   = AuditCatalog::count( $result['issues'] );

				$wpdb->update(
					$table,
					[
						'checked'     => 1,
						'status_code' => $response['status'],
						'redirect_to' => substr( $response['location'], 0, 1024 ),
						'response_ms' => $response['ms'],
						'html_bytes'  => strlen( $response['body'] ),
						'title'       => mb_substr( (string) ( $result['facts']['title'] ?? '' ), 0, 255 ),
						'word_count'  => (int) ( $result['facts']['words'] ?? 0 ),
						'errors'      => $counts['error'],
						'warnings'    => $counts['warning'],
						'notices'     => $counts['notice'],
						'issues'      => wp_json_encode( (object) $result['issues'] ),
						'facts'       => wp_json_encode( $result['facts'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					],
					[ 'id' => (int) $row['id'] ],
					[ '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ],
					[ '%d' ]
				);
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET pages_done = pages_done + 1 WHERE id = %d', Tables::name( Tables::AUDIT_RUNS ), $run_id ) );
			}
		} while ( $rows && microtime( true ) < $deadline );

		if ( ! $rows || 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND checked = 0', $table, $run_id ) ) ) {
			$this->queue_links( $run_id );
		}
	}

	/**
	 * Lists internal link targets that were not crawled, for status checks.
	 *
	 * @param int $run_id Run ID.
	 */
	private function queue_links( int $run_id ): void {
		$crawled = [];
		$targets = [];
		foreach ( $this->pages( $run_id ) as $page ) {
			$crawled[ $this->analyzer->normalize_url( $page['url'], $page['url'] ) ] = true;
			foreach ( (array) ( $page['facts']['links'] ?? [] ) as $link ) {
				$targets[ (string) $link ] = true;
			}
		}
		$queue = array_slice( array_keys( array_diff_key( $targets, $crawled ) ), 0, self::MAX_LINK_CHECKS );

		$this->update_run(
			$run_id,
			[ 'phase' => 'links' ],
			[
				'link_queue' => $queue,
				'links'      => [],
			]
		);
	}

	/**
	 * Checks queued internal links until the deadline.
	 *
	 * @param int   $run_id   Run ID.
	 * @param float $deadline microtime() deadline.
	 */
	private function check_links( int $run_id, float $deadline ): void {
		$run     = (array) $this->run( $run_id );
		$queue   = (array) ( $run['summary']['link_queue'] ?? [] );
		$checked = (array) ( $run['summary']['links'] ?? [] );

		while ( $queue && microtime( true ) < $deadline ) {
			$url             = (string) array_shift( $queue );
			$checked[ $url ] = $this->fetch( $url, 'HEAD' )['status'];
		}

		$this->update_run(
			$run_id,
			$queue ? [] : [ 'phase' => 'finalize' ],
			[
				'link_queue' => array_values( $queue ),
				'links'      => $checked,
			]
		);
	}

	/**
	 * Requests a URL anonymously without following redirects.
	 *
	 * @param string $url    URL.
	 * @param string $method GET or HEAD.
	 * @return array{status: int, ms: int, body: string, location: string}
	 */
	public function fetch( string $url, string $method = 'GET' ): array {
		$args = [
			'method'              => $method,
			'timeout'             => 15,
			'redirection'         => 0,
			'user-agent'          => self::UA . '/' . BLA_VERSION . ' (+' . home_url( '/' ) . ')',
			'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'limit_response_size' => 3 * MB_IN_BYTES,
			'headers'             => [ 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ],
			'cookies'             => [],
		];

		$start    = microtime( true );
		$response = wp_remote_request( $url, $args );
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return [
				'status'   => 0,
				'ms'       => $ms,
				'body'     => '',
				'location' => '',
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		// Some servers reject HEAD; confirm with GET before calling a link broken.
		if ( 'HEAD' === $method && in_array( $status, [ 403, 405, 501 ], true ) ) {
			return $this->fetch( $url, 'GET' );
		}

		return [
			'status'   => $status,
			'ms'       => $ms,
			'body'     => 'HEAD' === $method ? '' : (string) wp_remote_retrieve_body( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Finalising                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Adds cross-page issues, site checks and scores, and completes the run.
	 *
	 * @param int $run_id Run ID.
	 */
	private function finalize( int $run_id ): void {
		global $wpdb;

		$run   = (array) $this->run( $run_id );
		$pages = $this->pages( $run_id );
		$links = (array) ( $run['summary']['links'] ?? [] );

		// Status by normalised URL, for crawled pages and checked links.
		$status = [];
		foreach ( $pages as $p ) {
			$status[ $this->analyzer->normalize_url( $p['url'], $p['url'] ) ] = $p['status_code'];
		}
		foreach ( $links as $url => $code ) {
			$status[ (string) $url ] = (int) $code;
		}

		// Incoming links, duplicates.
		$inlinks = [];
		$titles  = [];
		$descs   = [];
		foreach ( $pages as $p ) {
			foreach ( (array) ( $p['facts']['links'] ?? [] ) as $link ) {
				$inlinks[ (string) $link ][ $p['id'] ] = true;
			}
			if ( $p['status_code'] >= 200 && $p['status_code'] < 300 && empty( $p['facts']['noindex'] ) ) {
				$t = mb_strtolower( trim( (string) ( $p['facts']['title'] ?? '' ) ) );
				$d = mb_strtolower( trim( (string) ( $p['facts']['description'] ?? '' ) ) );
				if ( '' !== $t ) {
					$titles[ $t ][] = $p['id'];
				}
				if ( '' !== $d ) {
					$descs[ $d ][] = $p['id'];
				}
			}
		}

		$visited = $this->visited_paths();

		foreach ( $pages as $p ) {
			$issues = $p['issues'];
			$key    = $this->analyzer->normalize_url( $p['url'], $p['url'] );
			$in     = isset( $inlinks[ $key ] ) ? count( $inlinks[ $key ] ) : 0;
			$ok     = $p['status_code'] >= 200 && $p['status_code'] < 300;

			if ( $ok ) {
				$broken = [];
				foreach ( (array) ( $p['facts']['links'] ?? [] ) as $link ) {
					$code = $status[ (string) $link ] ?? null;
					if ( null !== $code && ( 0 === $code || $code >= 400 ) ) {
						$broken[] = (string) $link;
					}
				}
				if ( $broken ) {
					$issues['broken_internal_links'] = array_slice( $broken, 0, 20 );
				}

				$t = mb_strtolower( trim( (string) ( $p['facts']['title'] ?? '' ) ) );
				if ( '' !== $t && isset( $titles[ $t ] ) && count( $titles[ $t ] ) > 1 && empty( $p['facts']['noindex'] ) ) {
					$issues['title_duplicate'] = count( $titles[ $t ] );
				}
				$d = mb_strtolower( trim( (string) ( $p['facts']['description'] ?? '' ) ) );
				if ( '' !== $d && isset( $descs[ $d ] ) && count( $descs[ $d ] ) > 1 && empty( $p['facts']['noindex'] ) ) {
					$issues['meta_description_duplicate'] = count( $descs[ $d ] );
				}

				if ( 0 === $in && 'home' !== $p['source'] && empty( $p['facts']['noindex'] ) ) {
					$issues['orphan_page'] = 1;
				}
				if ( null !== $visited && ! isset( $visited[ self::path_key( $p['path'] ) ] ) && empty( $p['facts']['noindex'] ) ) {
					$issues['no_recent_visits'] = 1;
				}
			}

			$counts = AuditCatalog::count( $issues );
			$wpdb->update(
				Tables::name( Tables::AUDIT_PAGES ),
				[
					'inlinks'  => $in,
					'errors'   => $counts['error'],
					'warnings' => $counts['warning'],
					'notices'  => $counts['notice'],
					'issues'   => wp_json_encode( (object) $issues ),
				],
				[ 'id' => $p['id'] ],
				[ '%d', '%d', '%d', '%d', '%s' ],
				[ '%d' ]
			);
		}

		$site_checks = $this->site_checks();
		$summary     = $this->summarize( $this->pages( $run_id ), $site_checks );

		$wpdb->update(
			Tables::name( Tables::AUDIT_RUNS ),
			[
				'status'      => 'complete',
				'phase'       => 'done',
				'finished_at' => current_time( 'mysql', true ),
				'health'      => $summary['health'],
				'errors'      => $summary['totals']['error'],
				'warnings'    => $summary['totals']['warning'],
				'notices'     => $summary['totals']['notice'],
				'site_checks' => wp_json_encode( (object) $site_checks ),
				'summary'     => wp_json_encode( $summary['summary'], JSON_UNESCAPED_SLASHES ),
			],
			[ 'id' => $run_id ],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		/**
		 * Fires when a site audit completes.
		 *
		 * @param int $run_id Run ID.
		 * @param int $health Health score 0–100.
		 */
		do_action( 'blue_lens_audit_completed', $run_id, $summary['health'] );
	}

	/**
	 * Scores and aggregates for a finished run.
	 *
	 * Health = 100 × (1 − (pages with errors + 0.3 × pages with only warnings) ÷ pages),
	 * then minus 10 per site-wide error and 3 per site-wide warning, kept between 0 and 100.
	 *
	 * @param list<array<string, mixed>> $pages       Pages.
	 * @param array<string, mixed>       $site_checks Site issues.
	 * @return array{health: int, totals: array{error: int, warning: int, notice: int}, summary: array<string, mixed>}
	 */
	public function summarize( array $pages, array $site_checks ): array {
		$totals = AuditCatalog::count( $site_checks );
		$crawl  = [
			'healthy'   => 0,
			'warnings'  => 0,
			'errors'    => 0,
			'redirects' => 0,
			'broken'    => 0,
		];
		$issues = [];
		$ideas  = array_fill_keys( AuditCatalog::CATEGORIES, 0 );
		foreach ( array_keys( $site_checks ) as $code ) {
			$issues[ $code ] = 1;
			$cat             = AuditCatalog::get( (string) $code )['category'] ?? '';
			if ( isset( $ideas[ $cat ] ) ) {
				++$ideas[ $cat ];
			}
		}

		$with_errors   = 0;
		$with_warnings = 0;
		foreach ( $pages as $p ) {
			$totals['error']   += $p['errors'];
			$totals['warning'] += $p['warnings'];
			$totals['notice']  += $p['notices'];

			if ( 0 === $p['status_code'] || $p['status_code'] >= 400 ) {
				++$crawl['broken'];
			} elseif ( $p['status_code'] >= 300 ) {
				++$crawl['redirects'];
			} elseif ( $p['errors'] > 0 ) {
				++$crawl['errors'];
			} elseif ( $p['warnings'] > 0 ) {
				++$crawl['warnings'];
			} else {
				++$crawl['healthy'];
			}

			if ( $p['errors'] > 0 ) {
				++$with_errors;
			} elseif ( $p['warnings'] > 0 ) {
				++$with_warnings;
			}

			foreach ( array_keys( $p['issues'] ) as $code ) {
				$issues[ $code ] = ( $issues[ $code ] ?? 0 ) + 1;
				$cat             = AuditCatalog::get( (string) $code )['category'] ?? '';
				if ( isset( $ideas[ $cat ] ) ) {
					++$ideas[ $cat ];
				}
			}
		}

		$n      = count( $pages );
		$health = $n > 0 ? 100 * ( 1 - ( $with_errors + 0.3 * $with_warnings ) / $n ) : 100;
		$site   = AuditCatalog::count( $site_checks );
		$health = (int) round( max( 0, min( 100, $health - 10 * $site['error'] - 3 * $site['warning'] ) ) );
		arsort( $issues );

		return [
			'health'  => $health,
			'totals'  => $totals,
			'summary' => [
				'crawl'  => $crawl,
				'issues' => $issues,
				'ideas'  => $ideas,
			],
		];
	}

	/**
	 * Site-wide checks.
	 *
	 * @return array<string, mixed> Issue code => detail.
	 */
	public function site_checks(): array {
		$issues = [];

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$issues['search_engines_blocked'] = 1;
		}
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$issues['no_https'] = 1;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$issues['plain_permalinks'] = 1;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) {
			$issues['debug_display'] = 1;
		}

		$robots = $this->fetch( home_url( '/robots.txt' ) );
		if ( 200 !== $robots['status'] ) {
			$issues['robots_missing'] = $robots['status'];
		} elseif ( $this->robots_blocks_all( $robots['body'] ) ) {
			$issues['robots_blocks_all'] = 1;
		}

		$sitemap = false;
		foreach ( [ '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml' ] as $path ) {
			$res = $this->fetch( home_url( $path ) );
			if ( $res['status'] >= 300 && $res['status'] < 400 && '' !== $res['location'] ) {
				$res = $this->fetch( $res['location'] );
			}
			if ( 200 === $res['status'] && str_contains( $res['body'], '<' ) ) {
				$sitemap = true;
				break;
			}
		}
		if ( ! $sitemap ) {
			$issues['sitemap_missing'] = 1;
		}

		$missing = $this->fetch( home_url( '/blue-lens-audit-' . strtolower( wp_generate_password( 10, false ) ) . '/' ) );
		if ( 200 === $missing['status'] ) {
			$issues['soft_404'] = 1;
		}

		/**
		 * Filters the site-wide audit results.
		 *
		 * @param array<string, mixed> $issues Issue code => detail.
		 */
		return (array) apply_filters( 'blue_lens_audit_site_checks', $issues );
	}

	/**
	 * Whether robots.txt disallows everything for all user agents.
	 *
	 * @param string $body robots.txt.
	 */
	private function robots_blocks_all( string $body ): bool {
		$applies = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: [] as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', $line ) );
			if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
				$applies = '*' === trim( $m[1] );
			} elseif ( $applies && preg_match( '/^disallow:\s*\/\s*$/i', $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Paths viewed in the last 28 days, or null when Blue Lens has under 28 days of data.
	 *
	 * @return array<string, true>|null
	 */
	private function visited_paths(): ?array {
		global $wpdb;

		$since = SiteTime::add_days( SiteTime::today(), -27 );
		$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i', Tables::name( Tables::DAILY_TRAFFIC ) ) );
		if ( ! is_string( $first ) || $first > $since ) {
			return null;
		}

		$paths = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT path FROM %i WHERE day >= %s AND pageviews > 0', Tables::name( Tables::DAILY_CONTENT ), $since ) );
		$out   = [];
		foreach ( $paths as $path ) {
			$out[ self::path_key( (string) $path ) ] = true;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * All page rows of a run, decoded.
	 *
	 * @param int $run_id Run ID.
	 * @return list<array<string, mixed>>
	 */
	public function pages( int $run_id ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND checked = 1 ORDER BY id', Tables::name( Tables::AUDIT_PAGES ), $run_id ), ARRAY_A );

		return array_map( [ self::class, 'decode_page' ], $rows );
	}

	/**
	 * Decodes a page row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public static function decode_page( array $row ): array {
		foreach ( [ 'issues', 'facts' ] as $col ) {
			$decoded     = json_decode( (string) ( $row[ $col ] ?? '' ), true );
			$row[ $col ] = is_array( $decoded ) ? $decoded : [];
		}
		foreach ( [ 'id', 'run_id', 'post_id', 'checked', 'status_code', 'response_ms', 'html_bytes', 'word_count', 'inlinks', 'errors', 'warnings', 'notices' ] as $col ) {
			$row[ $col ] = (int) ( $row[ $col ] ?? 0 );
		}

		return $row;
	}

	/**
	 * Path (and query) of a URL, as stored and matched against analytics paths.
	 *
	 * @param string $url URL.
	 */
	public static function path_of( string $url ): string {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Comparison key for paths: no trailing slash, lower case.
	 *
	 * @param string $path Path.
	 */
	public static function path_key( string $path ): string {
		$key = strtolower( untrailingslashit( $path ) );

		return '' === $key ? '/' : $key;
	}

	/**
	 * Updates run columns and merges keys into its summary.
	 *
	 * @param int                  $run_id  Run ID.
	 * @param array<string, mixed> $columns Columns.
	 * @param array<string, mixed> $summary Summary keys.
	 */
	private function update_run( int $run_id, array $columns, array $summary ): void {
		global $wpdb;

		$run = (array) $this->run( $run_id );
		if ( $summary ) {
			$columns['summary'] = wp_json_encode( array_merge( (array) ( $run['summary'] ?? [] ), $summary ), JSON_UNESCAPED_SLASHES );
		}
		if ( $columns ) {
			$wpdb->update( Tables::name( Tables::AUDIT_RUNS ), $columns, [ 'id' => $run_id ] );
		}
	}

	/**
	 * Deletes all but the most recent runs.
	 */
	private function prune(): void {
		global $wpdb;

		$keep = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT %d', Tables::name( Tables::AUDIT_RUNS ), self::KEEP_RUNS ) );
		if ( count( $keep ) < self::KEEP_RUNS ) {
			return;
		}
		$oldest = min( array_map( 'intval', $keep ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_id < %d', Tables::name( Tables::AUDIT_PAGES ), $oldest ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', Tables::name( Tables::AUDIT_RUNS ), $oldest ) );
	}

	/**
	 * Takes the step lock (one step at a time per site).
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time() + 60, '', false ) ) {
			return true;
		}
		$expires = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $expires < time() ) {
			update_option( self::LOCK_OPTION, time() + 60, false );
			return true;
		}

		return false;
	}

	/**
	 * Releases the step lock.
	 */
	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
