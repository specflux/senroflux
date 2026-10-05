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

if ( ! class_exists( 'WP_Theme', false ) ) {
	/**
	 * Test-only stand-in: `$GLOBALS['senroflux_test_declared_patterns']` is the
	 * list of `{slug: ...}` entries `WP_Theme::get_block_patterns()` returns
	 * (empty unless a test sets it).
	 */
	class WP_Theme {

		/** @return list<array<string,mixed>> */
		public function get_block_patterns(): array {
			return $GLOBALS['senroflux_test_declared_patterns'] ?? array();
		}

		/** @return false */
		public function parent(): bool {
			return false;
		}
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme(): WP_Theme {
		return new WP_Theme();
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

if ( ! function_exists( 'esc_attr_x' ) ) {
	function esc_attr_x( string $text, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );

		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	// Spectra One's pattern files build their image URLs with it.
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'https://example.test/wp-content/themes/twentytwentyfive';
	}
}

if ( ! function_exists( 'wp_get_global_settings' ) ) {
	/**
	 * Test-only stand-in for the global-settings palette/gradients lookup
	 * (D3a, S4). `$GLOBALS['senroflux_test_global_settings']` holds the same
	 * shape the real function returns for `array( 'color', 'palette' )` /
	 * `array( 'color', 'gradients' )`: `array( 'color' => array( 'palette'
	 * => list<array{slug:string,...}>, 'gradients' => list<array{slug:
	 * string,...}> ) )`. Defaults to Twenty Twenty-Five's real merged
	 * palette (`base`, `contrast`, `accent-1`..`accent-6`) and no gradients,
	 * so a fixture that never opts in stays exactly as eligible as before
	 * D3a.
	 *
	 * @param list<string> $path Setting path, e.g. `array( 'color', 'palette' )`.
	 * @return mixed A list, an origin-keyed map of lists, or a flag.
	 */
	function wp_get_global_settings( array $path = array() ): mixed {
		$settings = $GLOBALS['senroflux_test_global_settings'] ?? array(
			'color' => array(
				'palette'   => array(
					array( 'slug' => 'base' ),
					array( 'slug' => 'contrast' ),
					array( 'slug' => 'accent-1' ),
					array( 'slug' => 'accent-2' ),
					array( 'slug' => 'accent-3' ),
					array( 'slug' => 'accent-4' ),
					array( 'slug' => 'accent-5' ),
					array( 'slug' => 'accent-6' ),
				),
				'gradients' => array(),
			),
		);

		$value = $settings;
		foreach ( $path as $segment ) {
			$value = is_array( $value ) && array_key_exists( $segment, $value ) ? $value[ $segment ] : array();
		}

		return $value;
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
