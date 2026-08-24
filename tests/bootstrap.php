<?php
/**
 * PHPUnit bootstrap for the SenroFlux plugin suite.
 *
 * Defines the minimal WordPress shims the code under test calls — only when a
 * real WP load order hasn't already provided them. The hook functions are a
 * WORKING mini-registry (not no-ops) so tests can exercise filter contracts
 * like `senroflux_default_budget` for real.
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

// Test-only stand-ins for WordPress globals (a real WP load order wins).
if ( ! class_exists( 'wpdb', false ) ) {
	require_once __DIR__ . '/stubs/wpdb.php';
}
if ( ! class_exists( 'WP_Error', false ) ) {
	require_once __DIR__ . '/stubs/wp-error.php';
}
require_once __DIR__ . '/stubs/abilities.php';

$GLOBALS['senroflux_test_abilities'] = array();


if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// --- Working mini hook registry -------------------------------------------

$GLOBALS['senroflux_test_filters'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Register a filter callback (priority + arg count accepted, dispatch in priority order).
	 *
	 * @param string   $hook         Filter name.
	 * @param callable $callback     Callback.
	 * @param int      $priority     Priority.
	 * @param int      $accepted_args Argument count.
	 * @return bool
	 */
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['senroflux_test_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Dispatch through registered callbacks, WP-style value threading.
	 *
	 * @param string $hook    Filter name.
	 * @param mixed  $value   Value.
	 * @param mixed  ...$args Extra args.
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['senroflux_test_filters'][ $hook ] ?? array() as $callbacks ) {
			foreach ( $callbacks as list( $callback, $accepted_args ) ) {
				$value = $callback( $value, ...array_slice( $args, 0, $accepted_args - 1 ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * Clear one or all filters between tests.
	 *
	 * @param string|null $hook Filter name or null for all.
	 * @return void
	 */
	function remove_all_filters( ?string $hook = null ): void {
		if ( null === $hook ) {
			$GLOBALS['senroflux_test_filters'] = array();
			return;
		}
		unset( $GLOBALS['senroflux_test_filters'][ $hook ] );
	}
}

// --- Minimal function shims (recording where useful) -----------------------

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

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Identity shim.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	$GLOBALS['senroflux_test_current_user_id'] = 0;

	/** Test control knob: set $GLOBALS['senroflux_test_current_user_id'] per-test. */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['senroflux_test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/** Minimal shim. */
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	$GLOBALS['senroflux_test_transients'] = array();

	/** Recording shim mirroring the options-table backing. */
	function get_transient( string $key ): mixed {
		return $GLOBALS['senroflux_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/** Recording shim. */
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['senroflux_test_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/** Recording shim. */
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['senroflux_test_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON shim.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	$GLOBALS['senroflux_test_dbdelta_queries'] = array();

	/**
	 * Recording shim: captures statements instead of touching a database.
	 *
	 * @param string|list<string> $queries SQL statements.
	 * @return array<string,string>
	 */
	function dbDelta( $queries = '', bool $execute = true ): array {
		foreach ( (array) $queries as $query ) {
			$GLOBALS['senroflux_test_dbdelta_queries'][] = (string) $query;
		}

		return array();
	}
}
