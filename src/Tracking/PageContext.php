<?php
/**
 * WordPress content context for the current page.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the compact context embedded in each page so tracking works from full-page caches.
 *
 * Keys: k page kind, p post ID, t post type, a author ID, pd/md published/modified (UTC Y-m-d),
 * tx taxonomy => term IDs, q archive query, tpl template, b page builder, l language,
 * li logged in, r user role.
 */
final class PageContext {

	/**
	 * Template file chosen by WordPress for this request.
	 *
	 * @var string
	 */
	private string $template = '';

	/**
	 * template_include filter callback: records the template, returns it unchanged.
	 *
	 * @param string $template Template path.
	 */
	public function capture_template( string $template ): string {
		$this->template = basename( $template, '.php' );

		return $template;
	}

	/**
	 * Builds the context.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$ctx = [ 'k' => $this->kind() ];

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$ctx += $this->post_context( $post );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$ctx['q'] = [
					'tax'  => $term->taxonomy,
					'term' => $term->term_id,
				];
			}
		} elseif ( is_search() ) {
			global $wp_query;
			// Read by the tracker to send a site_search event; the server re-validates it as an attribute
			// (PII dropped) and never stores it in the page context.
			$ctx['sq'] = mb_substr( sanitize_text_field( get_search_query( false ) ), 0, 100 );
			$ctx['sr'] = $wp_query instanceof \WP_Query ? (int) $wp_query->found_posts : 0;
		} elseif ( is_author() ) {
			$ctx['q'] = [ 'author' => (int) get_queried_object_id() ];
		} elseif ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			if ( is_string( $type ) && '' !== $type ) {
				$ctx['t'] = $type;
			}
		}

		$template = $this->template;
		// Block themes render every page through template-canvas.php; the real template is the block template ID.
		if ( 'template-canvas' === $template && ! empty( $GLOBALS['_wp_current_template_id'] ) ) {
			$parts    = explode( '//', (string) $GLOBALS['_wp_current_template_id'] );
			$template = (string) end( $parts );
		}
		if ( '' !== $template ) {
			$ctx['tpl'] = substr( sanitize_key( $template ), 0, 64 );
		}

		$language = $this->language();
		if ( '' !== $language ) {
			$ctx['l'] = $language;
		}

		if ( is_user_logged_in() ) {
			$ctx['li'] = 1;
			$user      = wp_get_current_user();
			if ( $user->roles ) {
				$ctx['r'] = (string) reset( $user->roles );
			}
		}

		/**
		 * Filters the page context embedded for the tracker.
		 *
		 * @param array<string, mixed> $ctx Context.
		 */
		return (array) apply_filters( 'blue_lens_page_context', $ctx );
	}

	/**
	 * Page kind.
	 */
	private function kind(): string {
		return match ( true ) {
			is_404()               => '404',
			is_search()            => 'search',
			is_front_page()        => 'front',
			is_home()              => 'blog',
			is_singular()          => 'singular',
			is_post_type_archive() => 'pt_archive',
			is_category(), is_tag(), is_tax() => 'term',
			is_author()            => 'author',
			is_date()              => 'date',
			default                => 'other',
		};
	}

	/**
	 * Context for a single post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function post_context( \WP_Post $post ): array {
		$ctx = [
			'p'  => $post->ID,
			't'  => $post->post_type,
			'a'  => (int) $post->post_author,
			'pd' => substr( $post->post_date_gmt, 0, 10 ),
			'md' => substr( $post->post_modified_gmt, 0, 10 ),
			'b'  => $this->builder( $post ),
		];

		$taxonomies = [];
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) && $terms ) {
				$taxonomies[ $taxonomy->name ] = array_slice( array_map( static fn( \WP_Term $t ): int => $t->term_id, $terms ), 0, 20 );
			}
			if ( count( $taxonomies ) >= 10 ) {
				break;
			}
		}
		if ( $taxonomies ) {
			$ctx['tx'] = $taxonomies;
		}

		if ( '0000-00-00' === $ctx['pd'] ) {
			unset( $ctx['pd'], $ctx['md'] );
		}

		return $ctx;
	}

	/**
	 * Page builder used for a post.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function builder( \WP_Post $post ): string {
		$id = $post->ID;

		$builder = match ( true ) {
			'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) => 'elementor',
			'on' === get_post_meta( $id, '_et_pb_use_builder', true )        => 'divi',
			metadata_exists( 'post', $id, '_bricks_page_content_2' )         => 'bricks',
			(bool) get_post_meta( $id, '_fl_builder_enabled', true )         => 'beaver',
			'true' === get_post_meta( $id, '_wpb_vc_js_status', true )       => 'wpbakery',
			metadata_exists( 'post', $id, 'ct_builder_shortcodes' )          => 'oxygen',
			has_blocks( $post )                                              => 'gutenberg',
			default                                                          => 'classic',
		};

		/**
		 * Filters the detected page builder.
		 *
		 * @param string   $builder Builder slug.
		 * @param \WP_Post $post    Post.
		 */
		return (string) apply_filters( 'blue_lens_page_builder', $builder, $post );
	}

	/**
	 * Current language code (WPML, Polylang, else site locale).
	 */
	private function language(): string {
		$wpml = apply_filters( 'wpml_current_language', null );
		if ( is_string( $wpml ) && '' !== $wpml && 'all' !== $wpml ) {
			return $wpml;
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language();
			if ( is_string( $pll ) && '' !== $pll ) {
				return $pll;
			}
		}

		return strtolower( str_replace( '_', '-', determine_locale() ) );
	}
}
