<?php
/**
 * Test-only stand-ins for the block-theme / global-styles WordPress surface
 * `Packs\Site\Style` calls (0.3 quality feature 5). TARGET REPO PATH:
 * tests/stubs/style.php
 *
 * `WP_Theme_JSON_Resolver::get_style_variations()` and
 * `get_user_global_styles_post_id()` are real core statics (WP 6.2+); this
 * file stubs them behind test-controlled globals so a test can set up "the
 * active theme ships N variations" and "here is the current global styles
 * post" without a real WordPress. The user global styles post lives in the
 * SAME `senroflux_test_posts` store `blocks.php` provides (it is just a post
 * whose `post_type` is `wp_global_styles`), so `get_post()`/`wp_update_post()`
 * keep working unmodified.
 *
 * Everything is `class_exists`/`function_exists`-guarded so a real WordPress
 * load order wins.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

$GLOBALS['senroflux_test_is_block_theme']        = true;
$GLOBALS['senroflux_test_style_variations']      = array();
$GLOBALS['senroflux_test_global_styles_post_id'] = 0;

if ( ! function_exists( 'wp_is_block_theme' ) ) {
	function wp_is_block_theme(): bool {
		return (bool) $GLOBALS['senroflux_test_is_block_theme'];
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$slug = strtolower( trim( $title ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );

		return trim( $slug, '-' );
	}
}

if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
	/**
	 * Test double for the two core statics `Style` calls.
	 */
	class WP_Theme_JSON_Resolver {

		/**
		 * @return list<array<string,mixed>>
		 */
		public static function get_style_variations(): array {
			return $GLOBALS['senroflux_test_style_variations'];
		}

		public static function get_user_global_styles_post_id(): int {
			if ( 0 !== $GLOBALS['senroflux_test_global_styles_post_id'] ) {
				return $GLOBALS['senroflux_test_global_styles_post_id'];
			}

			// Mirrors core: create the post the first time it is asked for.
			$id = wp_insert_post(
				array(
					'post_type'    => 'wp_global_styles',
					'post_status'  => 'publish',
					'post_title'   => 'Custom Styles',
					'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"settings":{},"styles":{}}',
				)
			);

			$GLOBALS['senroflux_test_global_styles_post_id'] = $id;

			return $id;
		}
	}
}
