<?php
/**
 * Test-only shims for the HTTP surfaces (admin-ajax + REST).
 *
 * TARGET REPO PATH: tests/stubs/http.php
 *
 * Loaded from tests/bootstrap.php after the other stubs, so the shims are
 * tests/bootstrap.php, so the shared bootstrap stays owned by the harness
 * tests. Every definition is guarded, so a real WordPress load order (or a
 * later move into bootstrap.php) always wins.
 *
 * `wp_send_json_*` in real WordPress WRITES and then `die()`s. Dying is the
 * behaviour under test — a handler that "refuses" must not fall through to the
 * tick — so the shims throw {@see SenroFluxJsonResponse}, which carries the
 * payload and the HTTP status for assertions.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'SenroFluxJsonResponse', false ) ) {
	/**
	 * The JSON a handler sent, thrown in place of WordPress's `die()`.
	 */
	class SenroFluxJsonResponse extends RuntimeException {

		/**
		 * @param bool                 $success Whether wp_send_json_success was used.
		 * @param mixed                $data    The payload.
		 * @param int                  $status  HTTP status.
		 */
		public function __construct( public readonly bool $success, public readonly mixed $data, public readonly int $status ) {
			parent::__construct( 'senroflux-json' );
		}

		/** The error code in the payload, or '' when there is none. */
		public function code(): string {
			return is_array( $this->data ) ? (string) ( $this->data['code'] ?? '' ) : '';
		}
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	/**
	 * Throwing shim mirroring WordPress's write-then-die.
	 *
	 * @param mixed $data        Payload.
	 * @param int   $status_code HTTP status.
	 */
	function wp_send_json_error( mixed $data = null, int $status_code = 200 ): void {
		throw new SenroFluxJsonResponse( false, $data, $status_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test double: nothing is printed, the payload is asserted on.
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	/**
	 * Throwing shim mirroring WordPress's write-then-die.
	 *
	 * @param mixed $data        Payload.
	 * @param int   $status_code HTTP status.
	 */
	function wp_send_json_success( mixed $data = null, int $status_code = 200 ): void {
		throw new SenroFluxJsonResponse( true, $data, $status_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test double: nothing is printed, the payload is asserted on.
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	/**
	 * No-op shim: nonce verification is WordPress's job, not this suite's.
	 *
	 * @param string $action    Nonce action.
	 * @param mixed  $query_arg Field name.
	 * @param bool   $stop      Whether WP would die.
	 */
	function check_ajax_referer( string $action = '-1', mixed $query_arg = false, bool $stop = true ): bool {
		unset( $action, $query_arg, $stop );

		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	/**
	 * Minimal request double: just the param bag `Rest` reads.
	 */
	class WP_REST_Request {

		/** @param array<string,mixed> $params Request params. */
		public function __construct( private array $params = array() ) {
		}

		/** One param, or null. */
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/** Set one param. */
		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	/**
	 * Minimal response double: payload + status.
	 */
	class WP_REST_Response {

		/**
		 * @param mixed $data   Payload.
		 * @param int   $status HTTP status.
		 */
		public function __construct( private mixed $data = null, private int $status = 200 ) {
		}

		/** The payload. */
		public function get_data(): mixed {
			return $this->data;
		}

		/** The HTTP status. */
		public function get_status(): int {
			return $this->status;
		}
	}
}
