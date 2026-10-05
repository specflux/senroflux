<?php
/**
 * A tiny extra WP shim the S22 adversarial suite needs that the shared
 * bootstrap does not otherwise define: `Rest::routeSuggestionDecision()`
 * (0.3 S20) is the one REST callback that re-checks `is_user_logged_in()`
 * INSIDE its own body (every other route leaves that check to
 * `permission_callback`, which this bare-PHPUnit harness never dispatches
 * through) — see tests/bootstrap.php and tests/stubs/http.php for the
 * rest of the HTTP surface's shims.
 *
 * Global namespace (no `namespace` line) and function_exists-guarded, same
 * discipline as every shim in tests/stubs/, so a real WP load order wins.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/** Test knob: $GLOBALS['senroflux_test_logged_in'], defaults to true (an ordinary logged-in session). */
	function is_user_logged_in(): bool {
		return (bool) ( $GLOBALS['senroflux_test_logged_in'] ?? true );
	}
}
