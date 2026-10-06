<?php
/**
 * admin-ajax surface (S9): browser-driven ticks from the logged-in session.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Http;

use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Admin\ScreenCapability;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Plugin;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * `@internal` (S23): the Runs screen's own PRIVATE transport, never the
 * declared consumer contract — {@see Rest} is the `@api` surface a
 * third-party consumer integrates against. This class exists only because
 * the bundled React Runs screen (`assets/src/runs/`) talks admin-ajax, not
 * REST; its four actions are not guaranteed to stay a superset (or subset)
 * of `Rest`'s routes and may change shape without a SENROFLUX_API_VERSION
 * bump.
 *
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

	/**
	 * POST consumer, goal, pack?, budget?. Allow-list comes from ConsumerPolicy.
	 *
	 * Runs-pack fix: the Runs screen's own consumer ({@see RunsScreen::CONSUMER})
	 * has NO allow-list of its own without a pack — {@see RunsScreen::registerAdminConsumer()}
	 * unions the registered packs' allow-lists, but `start()` only narrows to
	 * ONE pack's verb map when a pack is actually given. A pack-less start
	 * from that consumer would carry an empty verb map, so every read/plan
	 * call is refused fail-closed and the run can never progress — refused
	 * outright (400) rather than started to deadlock. Other consumers may
	 * still start pack-less (a direct-allow run), unchanged.
	 *
	 * `@internal` (S23) — see the class docblock.
	 */
	public function handleStart(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'senroflux' ) ),
				403
			);
		}

		$consumer = sanitize_text_field( wp_unslash( $_POST['consumer'] ?? '' ) );
		$pack     = sanitize_text_field( wp_unslash( $_POST['pack'] ?? '' ) );

		// 0.3 S20 (stage 22b): a follow-up run started from the Runs screen.
		// start() forces the pack to the source run's own (fail closed — the
		// posted pack is never trusted once a source is named), so a
		// follow-up needs no pack field of its own.
		$follow_up_of = absint( $_POST['follow_up_of'] ?? 0 );
		if ( $follow_up_of > 0 ) {
			// The source run's pack also decides the budget ceiling below. A
			// source the viewer may not see (or that does not exist) leaves
			// the posted pack in place; start() refuses it either way.
			$source = senroflux()->get( $follow_up_of );
			if ( is_array( $source ) && is_string( $source['run']['pack'] ?? null ) ) {
				$pack = $source['run']['pack'];
			}
		}

		if ( RunsScreen::CONSUMER === $consumer && '' === $pack && 0 === $follow_up_of ) {
			wp_send_json_error(
				array(
					'code'    => 'senroflux_bad_request',
					'message' => __( 'Choose what to work on before starting a run.', 'senroflux' ),
				),
				400
			);
		}

		// S7: the chosen pack's own default-budget overrides become the
		// ceiling ConsumerPolicy clamps against, same as `RunsScreen::handleNewRun()`
		// — otherwise a pack asking for a flat, high budget (the site pack)
		// would be clamped straight back down to the generic consumer ceiling.
		// An unknown pack name overrides nothing here; `start()` still refuses
		// it with its own `pack_unknown` below.
		$pack_obj              = '' !== $pack ? PackRegistry::fromFilters()->get( $pack ) : null;
		$pack_budget_overrides = null !== $pack_obj ? $pack_obj->defaultBudget() : array();

		// The budget arrives as a JSON body; a malformed payload degrades to
		// the consumer's ceiling. `allow` is never read from the request.
		$budget_raw = isset( $_POST['budget'] ) ? wp_unslash( $_POST['budget'] ) : '{}'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a JSON body; sanitized field by field by sanitizeBudget() right after decoding.
		$policy     = ConsumerPolicy::resolve(
			$consumer,
			self::sanitizeBudget( json_decode( is_string( $budget_raw ) ? $budget_raw : '{}', true ) ),
			$pack_budget_overrides
		);
		if ( is_wp_error( $policy ) ) {
			$this->respond( $policy );

			return;
		}

		// Optional per-run model pin. An absent/blank field reads as
		// null; start() itself refuses a half-specified pair or an
		// unavailable one.
		$model_provider = sanitize_text_field( wp_unslash( $_POST['model_provider'] ?? '' ) );
		$model_id       = sanitize_text_field( wp_unslash( $_POST['model_id'] ?? '' ) );

		$result = senroflux()->start(
			$consumer,
			sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) ),
			$policy['allow'],
			$policy['budget'],
			'' !== $pack ? $pack : null,
			null,
			$follow_up_of > 0 ? $follow_up_of : null,
			'' !== $model_provider ? $model_provider : null,
			'' !== $model_id ? $model_id : null
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
	 *
	 * S13 delegation: the Runs screen POLLS this endpoint, and a screen
	 * capability holder may drive a run they do not own. So the tick runs
	 * under the SAME scoped `senroflux_can_tick` allowance the screen's own
	 * park handlers use ({@see ScreenCapability::tickAsScreen()}) whenever the
	 * caller holds that capability. Without it, polling a DELEGATED run 403'd
	 * while submitting the form on the same page succeeded. A caller who does
	 * not hold the capability gets the plain owner-only tick, unchanged.
	 *
	 * `@internal` (S23) — see the class docblock.
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
			$raw    = wp_unslash( $_POST['resume'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a JSON body; every decoded string is sanitized by sanitizeResume() before the shape check.
			$resume = self::sanitizeResume( is_string( $raw ) ? json_decode( $raw, true ) : $raw );
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

		$run_id     = absint( $_POST['run_id'] ?? 0 );
		$step_count = absint( $_POST['step_count'] ?? 0 );

		$this->respond( $this->tick( $run_id, $step_count, $resume ) );
	}

	/**
	 * Tick, with the Runs-screen delegation allowance when the caller holds
	 * the screen capability.
	 *
	 * Fail closed: the allowance is only reachable through
	 * {@see ScreenCapability::tickAsScreen()}, which re-checks the capability
	 * itself and scopes the allowance to this one run id.
	 *
	 * @param array<string,mixed>|null $resume Park resolution.
	 * @return array<string,mixed>|WP_Error RunState or an error.
	 */
	private function tick( int $run_id, int $step_count, ?array $resume ): array|WP_Error {
		if ( ScreenCapability::held() ) {
			return ScreenCapability::tickAsScreen(
				$run_id,
				static fn (): array|WP_Error => senroflux()->tick( $run_id, $step_count, $resume )
			);
		}

		return senroflux()->tick( $run_id, $step_count, $resume );
	}

	/** POST run_id. `@internal` (S23) — see the class docblock. */
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

	/** POST run_id. `@internal` (S23) — see the class docblock. */
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
	 * Sanitize a decoded budget body: only non-negative integer caps (or
	 * digit strings) under sanitized keys survive. Anything else is dropped,
	 * which Budget::clamp() reads as "use the ceiling".
	 *
	 * @param mixed $decoded The json_decode()d budget.
	 * @return array<string,int>
	 */
	public static function sanitizeBudget( mixed $decoded ): array {
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$budget = array();
		foreach ( $decoded as $key => $value ) {
			if ( ( is_int( $value ) && $value >= 0 ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
				$budget[ sanitize_key( (string) $key ) ] = absint( $value );
			}
		}

		return $budget;
	}

	/**
	 * Sanitize a decoded park resolution: every string (answer text and
	 * choice, plan action and note, approval action) goes through
	 * sanitize_textarea_field(); booleans and integers keep their type so
	 * Resume::check() can still enforce exact shapes such as `skip: true`.
	 * Keys are left as sent: Resume::check() matches them against exact
	 * allow-lists and refuses anything else.
	 *
	 * @param mixed $decoded The json_decode()d resume payload.
	 * @return mixed Same shape, with sanitized strings.
	 */
	public static function sanitizeResume( mixed $decoded ): mixed {
		if ( is_array( $decoded ) ) {
			return array_map( array( self::class, 'sanitizeResume' ), $decoded );
		}

		if ( is_string( $decoded ) ) {
			return sanitize_textarea_field( $decoded );
		}

		return is_bool( $decoded ) || is_int( $decoded ) ? $decoded : null;
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
