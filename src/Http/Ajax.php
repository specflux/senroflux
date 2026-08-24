<?php
/**
 * admin-ajax surface (S9): browser-driven ticks from the logged-in session.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Http;

use Specflux\SenroFlux\Plugin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Four actions mirroring the PHP API: start, tick, cancel, get. Nonce
 * `senroflux_run`, capability `read`, plus per-run ownership enforced in the
 * Runner itself (the tick protocol re-checks it).
 */
final class Ajax {

	private const NONCE = 'senroflux_run';

	/** Register on init. */
	public function register(): void {
		add_action( 'wp_ajax_senroflux_start', array( $this, 'handleStart' ) );
		add_action( 'wp_ajax_senroflux_tick', array( $this, 'handleTick' ) );
		add_action( 'wp_ajax_senroflux_cancel', array( $this, 'handleCancel' ) );
		add_action( 'wp_ajax_senroflux_get', array( $this, 'handleGet' ) );
	}

	/** POST consumer, goal, allow[], budget?. */
	public function handleStart(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		$allow_raw  = isset( $_POST['allow'] ) ? wp_unslash( $_POST['allow'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON decoded then value-sanitized below.
		$budget_raw = isset( $_POST['budget'] ) ? wp_unslash( $_POST['budget'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- same.

		$allow  = array_values(
			array_filter(
				(array) json_decode( is_string( $allow_raw ) ? $allow_raw : '[]', true ),
				static fn ( $entry ): bool => is_string( $entry ) && '' !== $entry
			)
		);
		$allow  = array_map( 'sanitize_text_field', $allow );
		$budget = is_array( json_decode( is_string( $budget_raw ) ? $budget_raw : '{}', true ) )
			? (array) json_decode( $budget_raw, true )
			: array();

		$result = senroflux()->start(
			sanitize_text_field( wp_unslash( $_POST['consumer'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) ),
			array_values( array_filter( is_array( $allow ) ? $allow : array(), 'is_string' ) ),
			is_array( $budget ) ? $budget : array()
		);

		$this->respond( $result );
	}

	/** POST run_id, step_count, approval_action?. */
	public function handleTick(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		$result = senroflux()->tick(
			absint( $_POST['run_id'] ?? 0 ),
			absint( $_POST['step_count'] ?? 0 ),
			isset( $_POST['approval_action'] ) ? sanitize_key( wp_unslash( $_POST['approval_action'] ) ) : null
		);

		$this->respond( $result );
	}

	/** POST run_id. */
	public function handleCancel(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		$this->respond( senroflux()->cancel( absint( $_POST['run_id'] ?? 0 ) ) );
	}

	/** POST run_id. */
	public function handleGet(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		$this->respond( senroflux()->get( absint( $_POST['run_id'] ?? 0 ) ) );
	}

	/**
	 * Normalize a Runner result into success/error JSON.
	 *
	 * @param mixed $result RunState or WP_Error.
	 */
	private function respond( mixed $result ): void {
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		wp_send_json_success( $result );
	}
}
