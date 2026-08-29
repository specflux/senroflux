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
 * Runner itself (the tick protocol re-checks it). Start is additionally
 * gated by {@see ConsumerPolicy}: the server owns the allow-list.
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

	/** POST consumer, goal, budget?. Allow-list comes from ConsumerPolicy. */
	public function handleStart(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		$consumer = sanitize_text_field( wp_unslash( $_POST['consumer'] ?? '' ) );

		// The budget arrives as a JSON body; a malformed payload degrades to
		// the consumer's ceiling. `allow` is never read from the request.
		$budget_raw = isset( $_POST['budget'] ) ? wp_unslash( $_POST['budget'] ) : '{}';
		$policy     = ConsumerPolicy::resolve(
			$consumer,
			json_decode( is_string( $budget_raw ) ? $budget_raw : '{}', true )
		);
		if ( is_wp_error( $policy ) ) {
			$this->respond( $policy );

			return;
		}

		$result = senroflux()->start(
			$consumer,
			sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) ),
			$policy['allow'],
			$policy['budget']
		);

		$this->respond( $result );
	}

	/**
	 * POST run_id, step_count, resume?.
	 *
	 * S5 (breaking): the 0.1 `approval_action` field is GONE — a request
	 * carrying it is refused outright (400) so a stale consumer fails loudly
	 * instead of silently losing its approval. The park resolution arrives as
	 * a JSON string in the `resume` field and is decoded here; the Runner
	 * validates its shape against the park kind.
	 */
	public function handleTick(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		if ( isset( $_POST['approval_action'] ) ) {
			wp_send_json_error(
				array(
					'code'    => 'senroflux_bad_request',
					'message' => __( 'The approval_action field was removed; send a resume object instead (S5).', 'senroflux' ),
				),
				400
			);
		}

		$resume = null;
		if ( isset( $_POST['resume'] ) ) {
			$raw    = wp_unslash( $_POST['resume'] );
			$resume = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( ! is_array( $resume ) ) {
				wp_send_json_error(
					array(
						'code'    => 'resume_mismatch',
						'message' => __( 'The resume field must be a JSON object matching the run\'s park kind.', 'senroflux' ),
					),
					400
				);
			}
		}

		$this->respond(
			senroflux()->tick(
				absint( $_POST['run_id'] ?? 0 ),
				absint( $_POST['step_count'] ?? 0 ),
				$resume
			)
		);
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
