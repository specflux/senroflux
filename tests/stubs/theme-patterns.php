<?php
/**
 * Test-only stand-in for `WP_Block_Patterns_Registry` and the active theme's
 * directory functions (stage 15, 0.3 S21). TARGET REPO PATH:
 * tests/stubs/theme-patterns.php
 *
 * A test sets `$GLOBALS['senroflux_test_theme_patterns']` (a list of registry
 * entries, same shape `WP_Theme::get_block_patterns()` produces: `name`,
 * `title`, `description`, `content`, `filePath`, `categories`, `postTypes`,
 * `blockTypes`, `templateTypes`, `inserter`, `source`) and
 * `$GLOBALS['senroflux_test_stylesheet_dir']` (defaults to
 * `$GLOBALS['senroflux_test_template_dir']`, defaults to `/theme`) before
 * calling {@see \Specflux\SenroFlux\Packs\Pages\ThemePatterns::eligible()}.
 *
 * Everything is `function_exists`/`class_exists`-guarded so a real WordPress
 * load order wins.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		return $GLOBALS['senroflux_test_stylesheet_dir'] ?? ( $GLOBALS['senroflux_test_template_dir'] ?? '/theme' );
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return $GLOBALS['senroflux_test_template_dir'] ?? ( $GLOBALS['senroflux_test_stylesheet_dir'] ?? '/theme' );
	}
}

// --- i18n/escaping shims the REAL Twenty Twenty-Five fixture files call ---
// (0.2/0.3's main bootstrap already declares `esc_html`, `esc_attr`,
// `esc_url`, `__`; these are the handful the chosen fixtures additionally
// call while rendering.)

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		unset( $domain );
		echo htmlspecialchars( $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only shim, already escaping.
	}
}

if ( ! function_exists( 'esc_html_x' ) ) {
	function esc_html_x( string $text, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );

		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );

		return $text;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( mixed $text ): string {
		return (string) $text;
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		unset( $domain );
		echo htmlspecialchars( $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test-only shim, already escaping.
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'https://example.test/wp-content/themes/twentytwentyfive';
	}
}

if ( ! class_exists( 'WP_Block_Patterns_Registry', false ) ) {
	/**
	 * Minimal stand-in over `$GLOBALS['senroflux_test_theme_patterns']`.
	 */
	class WP_Block_Patterns_Registry {

		private static ?self $instance = null;

		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * @return list<array<string,mixed>>
		 */
		public function get_all_registered(): array {
			return $GLOBALS['senroflux_test_theme_patterns'] ?? array();
		}
	}
}
