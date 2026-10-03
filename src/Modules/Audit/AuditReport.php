<?php
/**
 * Read queries for the Site Audit and On-Page SEO screens.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Modules\Audit;

use BlueLens\Analytics\Aggregation\SiteTime;
use BlueLens\Analytics\Core\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Shapes audit data for the dashboard.
 */
final class AuditReport {

	/**
	 * Constructor.
	 *
	 * @param SiteAudit $audit Audit runner.
	 */
	public function __construct( private SiteAudit $audit ) {}

	/**
	 * Overview: running progress, latest completed run with issues and ideas, history.
	 *
	 * @return array<string, mixed>
	 */
	public function overview(): array {
		$running = $this->audit->running();
		$latest  = $this->latest_complete();

		$out = [
			'running' => $running ? $this->progress( $running ) : null,
			'latest'  => null,
			'history' => $this->history(),
		];
		if ( ! $latest ) {
			return $out;
		}

		$summary = (array) $latest['summary'];
		$issues  = [];
		foreach ( (array) ( $summary['issues'] ?? [] ) as $code => $pages ) {
			$def = AuditCatalog::get( (string) $code );
			if ( ! $def ) {
				continue;
			}
			$issues[] = [
				'code'     => (string) $code,
				'severity' => $def['severity'],
				'category' => $def['category'],
				'scope'    => $def['scope'],
				'title'    => $def['title'],
				'fix'      => $def['fix'],
				'pages'    => (int) $pages,
			];
		}
		$rank = [
			AuditCatalog::ERROR   => 0,
			AuditCatalog::WARNING => 1,
			AuditCatalog::NOTICE  => 2,
		];
		usort( $issues, static fn( array $a, array $b ): int => [ $rank[ $a['severity'] ] ?? 3, -$a['pages'] ] <=> [ $rank[ $b['severity'] ] ?? 3, -$b['pages'] ] );

		$ideas = (array) ( $summary['ideas'] ?? [] );

		$out['latest'] = [
			'id'          => $latest['id'],
			'finished_at' => (string) $latest['finished_at'],
			'started_at'  => (string) $latest['started_at'],
			'health'      => (int) $latest['health'],
			'errors'      => $latest['errors'],
			'warnings'    => $latest['warnings'],
			'notices'     => $latest['notices'],
			'pages'       => $latest['pages_done'],
			'crawl'       => (array) ( $summary['crawl'] ?? [] ),
			'links'       => count( (array) ( $summary['links'] ?? [] ) ),
			'issues'      => $issues,
			'ideas'       => [
				'total'      => array_sum( array_map( 'intval', $ideas ) ),
				'categories' => array_map( 'intval', $ideas ),
				'pages'      => $this->pages_with_ideas( $latest['id'] ),
			],
			'top_pages'   => $this->top_pages( $latest['id'] ),
		];

		return $out;
	}

	/**
	 * Pages affected by one issue.
	 *
	 * @param int    $run_id Run ID (0 = latest).
	 * @param string $code   Issue code.
	 * @return array<string, mixed>
	 */
	public function issue( int $run_id, string $code ): array {
		$run_id = $run_id ?: (int) ( $this->latest_complete()['id'] ?? 0 );
		$rows   = [];
		foreach ( $this->audit->pages( $run_id ) as $p ) {
			if ( array_key_exists( $code, $p['issues'] ) ) {
				$rows[] = $this->page_row( $p ) + [ 'detail' => $p['issues'][ $code ] ];
			}
		}

		return [
			'code'  => $code,
			'issue' => AuditCatalog::get( $code ),
			'rows'  => $rows,
		];
	}

	/**
	 * Crawled pages, optionally filtered.
	 *
	 * @param int    $run_id Run ID (0 = latest).
	 * @param string $filter all, healthy, warnings, errors, redirects, broken.
	 * @return array<string, mixed>
	 */
	public function pages( int $run_id, string $filter ): array {
		$run_id = $run_id ?: (int) ( $this->latest_complete()['id'] ?? 0 );
		$views  = $this->pageviews();
		$rows   = [];
		foreach ( $this->audit->pages( $run_id ) as $p ) {
			if ( 'all' !== $filter && self::bucket( $p ) !== $filter ) {
				continue;
			}
			$rows[] = $this->page_row( $p ) + [ 'pageviews' => $views[ SiteAudit::path_key( $p['path'] ) ] ?? 0 ];
		}

		return [ 'rows' => $rows ];
	}

	/**
	 * Everything known about one crawled page.
	 *
	 * @param int $page_id Page row ID.
	 * @return array<string, mixed>|null
	 */
	public function page( int $page_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::name( Tables::AUDIT_PAGES ), $page_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$p      = SiteAudit::decode_page( $row );
		$issues = [];
		foreach ( $p['issues'] as $code => $detail ) {
			$def = AuditCatalog::get( (string) $code );
			if ( $def ) {
				$issues[] = $def + [
					'code'   => (string) $code,
					'detail' => $detail,
				];
			}
		}
		$rank = [
			AuditCatalog::ERROR   => 0,
			AuditCatalog::WARNING => 1,
			AuditCatalog::NOTICE  => 2,
		];
		usort( $issues, static fn( array $a, array $b ): int => ( $rank[ $a['severity'] ] ?? 3 ) <=> ( $rank[ $b['severity'] ] ?? 3 ) );
		$facts = $p['facts'];
		unset( $facts['links'] );

		return $this->page_row( $p ) + [
			'facts'          => $facts,
			'internal_links' => count( (array) ( $p['facts']['links'] ?? [] ) ),
			'issues'         => $issues,
			'edit_url'       => $p['post_id'] ? (string) get_edit_post_link( $p['post_id'], 'raw' ) : '',
			'pageviews'      => $this->pageviews()[ SiteAudit::path_key( $p['path'] ) ] ?? 0,
		];
	}

	/**
	 * Progress of a running audit.
	 *
	 * @param array<string, mixed> $run Run.
	 * @return array<string, mixed>
	 */
	public function progress( array $run ): array {
		$queue = count( (array) ( $run['summary']['link_queue'] ?? [] ) );
		$done  = count( (array) ( $run['summary']['links'] ?? [] ) );

		return [
			'id'          => (int) $run['id'],
			'status'      => (string) $run['status'],
			'phase'       => (string) $run['phase'],
			'pages_total' => (int) $run['pages_total'],
			'pages_done'  => (int) $run['pages_done'],
			'links_total' => $queue + $done,
			'links_done'  => $done,
			'started_at'  => (string) $run['started_at'],
		];
	}

	/**
	 * Latest completed run.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest_complete(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1', Tables::name( Tables::AUDIT_RUNS ), 'complete' ), ARRAY_A );

		return is_array( $row ) ? SiteAudit::decode_run( $row ) : null;
	}

	/**
	 * Health over the last completed runs, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function history(): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, finished_at, health, errors, warnings, notices, pages_done FROM %i WHERE status = %s ORDER BY id DESC LIMIT 10', Tables::name( Tables::AUDIT_RUNS ), 'complete' ), ARRAY_A );

		return array_reverse(
			array_map(
				static fn( array $r ): array => [
					'id'          => (int) $r['id'],
					'finished_at' => (string) $r['finished_at'],
					'health'      => (int) $r['health'],
					'errors'      => (int) $r['errors'],
					'warnings'    => (int) $r['warnings'],
					'notices'     => (int) $r['notices'],
					'pages'       => (int) $r['pages_done'],
				],
				$rows
			)
		);
	}

	/**
	 * Number of pages with at least one idea.
	 *
	 * @param int $run_id Run ID.
	 */
	private function pages_with_ideas( int $run_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND checked = 1 AND (errors + warnings + notices) > 0', Tables::name( Tables::AUDIT_PAGES ), $run_id ) );
	}

	/**
	 * Pages to optimise first: most visited pages that have ideas, else those with the most ideas.
	 *
	 * @param int $run_id Run ID.
	 * @return list<array<string, mixed>>
	 */
	private function top_pages( int $run_id ): array {
		$views = $this->pageviews();
		$rows  = [];
		foreach ( $this->audit->pages( $run_id ) as $p ) {
			$ideas = $p['errors'] + $p['warnings'] + $p['notices'];
			if ( $ideas > 0 && $p['status_code'] >= 200 && $p['status_code'] < 300 ) {
				$rows[] = $this->page_row( $p ) + [ 'pageviews' => $views[ SiteAudit::path_key( $p['path'] ) ] ?? 0 ];
			}
		}
		usort( $rows, static fn( array $a, array $b ): int => [ $b['pageviews'], $b['errors'], $b['ideas'] ] <=> [ $a['pageviews'], $a['errors'], $a['ideas'] ] );

		return array_slice( $rows, 0, 10 );
	}

	/**
	 * Page views per path over the last 28 days.
	 *
	 * @return array<string, int>
	 */
	private function pageviews(): array {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT path, SUM(pageviews) AS pv FROM %i WHERE day >= %s GROUP BY path', Tables::name( Tables::DAILY_CONTENT ), SiteTime::add_days( SiteTime::today(), -27 ) ), ARRAY_A );
		$cache = [];
		foreach ( $rows as $r ) {
			$key           = SiteAudit::path_key( (string) $r['path'] );
			$cache[ $key ] = ( $cache[ $key ] ?? 0 ) + (int) $r['pv'];
		}

		return $cache;
	}

	/**
	 * Common page fields.
	 *
	 * @param array<string, mixed> $p Page.
	 * @return array<string, mixed>
	 */
	private function page_row( array $p ): array {
		return [
			'id'          => $p['id'],
			'url'         => (string) $p['url'],
			'path'        => (string) $p['path'],
			'title'       => (string) $p['title'],
			'source'      => (string) $p['source'],
			'status_code' => $p['status_code'],
			'response_ms' => $p['response_ms'],
			'words'       => $p['word_count'],
			'inlinks'     => $p['inlinks'],
			'errors'      => $p['errors'],
			'warnings'    => $p['warnings'],
			'notices'     => $p['notices'],
			'ideas'       => $p['errors'] + $p['warnings'] + $p['notices'],
			'bucket'      => self::bucket( $p ),
		];
	}

	/**
	 * Crawl bucket of a page (matches SiteAudit::summarize()).
	 *
	 * @param array<string, mixed> $p Page.
	 */
	public static function bucket( array $p ): string {
		if ( 0 === $p['status_code'] || $p['status_code'] >= 400 ) {
			return 'broken';
		}
		if ( $p['status_code'] >= 300 ) {
			return 'redirects';
		}
		if ( $p['errors'] > 0 ) {
			return 'errors';
		}

		return $p['warnings'] > 0 ? 'warnings' : 'healthy';
	}
}
