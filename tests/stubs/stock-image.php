<?php
/**
 * Test-only shims for `OpenverseStockImageGateway`'s outbound HTTP surface.
 *
 * TARGET REPO PATH: tests/stubs/stock-image.php
 *
 * `wp_remote_get()` never makes a real request: it records the URL called
 * and returns whatever the test queued in
 * `$GLOBALS['senroflux_test_remote_get_responses']` (a queue, so a single
 * test can script search-then-detail-then-download in one gateway call).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Recording shim: records the URL and pops the next queued response.
	 *
	 * @param string               $url  The request URL.
	 * @param array<string,mixed>  $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	function wp_remote_get( string $url, array $args = array() ) {
		$GLOBALS['senroflux_test_remote_get_calls'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		$queue = &$GLOBALS['senroflux_test_remote_get_responses'];
		if ( ! is_array( $queue ) || array() === $queue ) {
			return new WP_Error( 'http_request_failed', 'No response queued.' );
		}

		return array_shift( $queue );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param mixed $response A response shim array.
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param mixed $response A response shim array.
	 */
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
