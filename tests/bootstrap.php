<?php
/**
 * PHPUnit bootstrap for the SenroFlux plugin suite: minimal WP function shims
 * (only when a real WP load order hasn't provided them) so the dependency-gate
 * and, later, run-store tests run without WordPress.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

$senroflux_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_readable( $senroflux_autoload ) ) {
	fwrite( STDERR, "Missing vendor/autoload.php — run `composer install` first.\n" );
	exit( 1 );
}
require_once $senroflux_autoload;

// WordPress defines ABSPATH before any plugin file loads; every src file
// guards itself with `defined( 'ABSPATH' ) || exit`. Under bare PHPUnit that
// guard would silently EXIT the whole test run, so provide the constant.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/senroflux-tests/' );
}

if ( ! function_exists( 'add_action' ) ) {
	$GLOBALS['senroflux_test_actions'] = array();

	/**
	 * Recording shim: $GLOBALS['senroflux_test_actions'][$hook][] = callback.
	 *
	 * @param mixed ...$args Hook args.
	 * @return bool
	 */
	function add_action( ...$args ): bool {
		$GLOBALS['senroflux_test_actions'][ (string) $args[0] ][] = $args[1] ?? null;

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * No-op shim.
	 *
	 * @param mixed ...$args Hook args.
	 * @return bool
	 */
	function add_filter( ...$args ): bool {
		return true;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param string $text  Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return (string) $text;
	}
}
