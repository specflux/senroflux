<?php
/**
 * REST surface (S9): the same four operations as admin-ajax, for non-admin-ajax
 * consumers authenticated over REST (e.g. application passwords).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Http;

use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Plugin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * `@api` (S23): the public `@api` CONSUMER surface — the contract a
 * registered {@see ConsumerPolicy} consumer (e.g. application passwords)
 * integrates against. `Ajax` is the Runs screen's own PRIVATE transport
 * (`@internal`) and is not required to be a superset of this; the two are
 * independent implementations of the same underlying `Plugin`/`Runner`
 * operations, not one wrapping the other.
 *
 * Every route requires a logged-in user (`is_user_logged_in()` +
 * `current_user_can()` in its `permission_callback`) and returns a
 * `\WP_REST_Response` built by {@see respond()}: on success, the raw
 * RunState array `senroflux()`'s corresponding `Plugin` method returns
 * (status 200); on a `WP_Error`, `{code: string, message: string}` at the
 * error's own `status` data key (default 400).
 *
 * Routes under `senroflux/v1`:
 *
 * - `POST /runs` — {@see routeStart()}. Params: `consumer` (string,
 *   required — must be registered via `senroflux_http_consumers`, itself
 *   NOT `@api`, see its own docblock), `goal` (string, required), `pack`
 *   (string, optional — a registered {@see \Specflux\SenroFlux\Packs\Pack}
 *   name), `budget` (object, optional — may only LOWER a consumer's
 *   registered ceiling), `follow_up_of` (int, optional — a prior run id),
 *   `model_provider`/`model_id` (string, optional — both omitted means
 *   automatic selection). Response: the new run's RunState
 *   (`{run, steps, ui}`, see {@see \Specflux\SenroFlux\Plugin::get()} for
 *   the `run`/step shape).
 * - `POST /runs/{run_id}/tick` — {@see routeTick()}. Params: `run_id`
 *   (int, from the URL), `step_count` (int, required — the caller's
 *   last-known `run.step_count`, else `senroflux_conflict`), `resume`
 *   (object, optional — a park resolution shaped for the run's current
 *   park kind; the removed 0.1 `approval_action` field is refused
 *   `senroflux_bad_request` rather than silently ignored). Response:
 *   RunState.
 * - `POST /runs/{run_id}/cancel` — {@see routeCancel()}. No params beyond
 *   `run_id`. Response: RunState.
 * - `GET /runs` — {@see routeList()} (0.3 S10). Params: `limit` (int,
 *   optional, default 50, clamped 1..100 — rows CONSIDERED before
 *   viewer-scoping, so a response may be shorter). Response:
 *   `{runs: list<array<string,mixed>>}`, one lightweight summary per row
 *   (see {@see \Specflux\SenroFlux\Plugin::listRecent()}).
 * - `GET /runs/{run_id}` — {@see routeGet()}. Response: RunState.
 * - `POST /runs/{run_id}/suggestions/{seq}` — {@see routeSuggestionDecision()}
 *   (0.3 S20; requires `manage_options`, re-checked in the handler). Params:
 *   `run_id`/`seq` (int, from the URL), `action` (string, required),
 *   `text` (string, optional — a rewrite; omitted keeps the suggestion's
 *   original text). Response: RunState.
 */
final class Rest {

	private const NAMESPACE_V1 = 'senroflux/v1';

	/** Register on rest_api_init. */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/runs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'routeStart' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'read' ),
				'args'                => array(
					'consumer'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'goal'           => array(
						'type'     => 'string',
						'required' => true,
					),
					// Runs-pack fix (mirrors Ajax::handleStart()): a REST
					// consumer had no way to bind a run to a capability pack.
					'pack'           => array(
						'type'     => 'string',
						'required' => false,
					),
					'budget'         => array(
						'type'     => 'object',
						'required' => false,
					),
					// 0.3 S20: follow-up runs.
					'follow_up_of'   => array(
						'type'     => 'integer',
						'required' => false,
					),
					// Optional per-run model pin; both omitted (or a
					// follow-up whose source is automatic) means automatic
					// selection.
					'model_provider' => array(
						'type'     => 'string',
						'required' => false,
					),
					'model_id'       => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/runs/(?P<run_id>\d+)/tick',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'routeTick' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'read' ),
				'args'                => array(
					'run_id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'step_count' => array(
						'type'     => 'integer',
						'required' => true,
					),
					// S5 (breaking): the 0.1 approval_action param is gone;
					// the park resolution is a resume object.
					'resume'     => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/runs/(?P<run_id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'routeCancel' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'read' ),
			)
		);

		// 0.3 S10: the React screen's left-hand list. `read` here matches the
		// other read routes; the ACTUAL scoping lives in
		// {@see \Specflux\SenroFlux\Plugin::listRecent()}, which filters to
		// the runs this viewer may see or drive — a logged-in Subscriber gets
		// their own runs and nothing else, never an empty-capability leak of
		// every goal on the site.
		register_rest_route(
			self::NAMESPACE_V1,
			'/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'routeList' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'read' ),
				'args'                => array(
					'limit' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/runs/(?P<run_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'routeGet' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'read' ),
			)
		);

		// 0.3 S20: a human click only — manage_options in the permission
		// callback, RE-CHECKED in the handler (fail closed, B0 rule 2), and
		// the REST framework's own cookie-nonce check for a logged-in
		// browser session (a REST nonce, as S20 asks).
		register_rest_route(
			self::NAMESPACE_V1,
			'/runs/(?P<run_id>\d+)/suggestions/(?P<seq>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'routeSuggestionDecision' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'manage_options' ),
				'args'                => array(
					'run_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'seq'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'action' => array(
						'type'     => 'string',
						'required' => true,
					),
					'text'   => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * POST /runs.
	 *
	 * Runs-pack fix: mirrors {@see Ajax::handleStart()} exactly — the Runs
	 * screen's own consumer ({@see RunsScreen::CONSUMER}) has no allow-list
	 * without a pack, so a pack-less start from it is refused outright rather
	 * than started to deadlock; every other consumer may still start
	 * pack-less. An unknown pack name overrides no budget here and is left
	 * for `start()`'s own `pack_unknown` refusal.
	 */
	public function routeStart( \WP_REST_Request $request ): \WP_REST_Response {
		$consumer = (string) $request->get_param( 'consumer' );
		$pack     = (string) ( $request->get_param( 'pack' ) ?? '' );

		if ( RunsScreen::CONSUMER === $consumer && '' === $pack ) {
			return $this->respond(
				new \WP_Error(
					'senroflux_bad_request',
					__( 'Choose what to work on before starting a run.', 'senroflux' ),
					array( 'status' => 400 )
				)
			);
		}

		$pack_obj              = '' !== $pack ? PackRegistry::fromFilters()->get( $pack ) : null;
		$pack_budget_overrides = null !== $pack_obj ? $pack_obj->defaultBudget() : array();

		$policy = ConsumerPolicy::resolve( $consumer, $request->get_param( 'budget' ), $pack_budget_overrides );
		if ( is_wp_error( $policy ) ) {
			return $this->respond( $policy );
		}

		$follow_up_of = $request->get_param( 'follow_up_of' );

		// Optional per-run model pin; a blank string reads as null,
		// matching the admin-ajax handler.
		$model_provider = $request->get_param( 'model_provider' );
		$model_id       = $request->get_param( 'model_id' );

		return $this->respond(
			senroflux()->start(
				$consumer,
				(string) $request->get_param( 'goal' ),
				$policy['allow'],
				$policy['budget'],
				'' !== $pack ? $pack : null,
				null,
				null !== $follow_up_of ? (int) $follow_up_of : null,
				is_string( $model_provider ) && '' !== $model_provider ? $model_provider : null,
				is_string( $model_id ) && '' !== $model_id ? $model_id : null
			)
		);
	}

	/** POST /runs/{id}/tick. */
	public function routeTick( \WP_REST_Request $request ): \WP_REST_Response {
		// S5 (breaking): refuse the removed 0.1 field loudly so a stale
		// consumer fails instead of silently losing its approval.
		if ( null !== $request->get_param( 'approval_action' ) ) {
			return $this->respond(
				new \WP_Error(
					'senroflux_bad_request',
					__( 'The approval_action field was removed; send a resume object instead (S5).', 'senroflux' ),
					array( 'status' => 400 )
				)
			);
		}

		/** @var array<string,mixed>|null $resume */
		$resume = $request->get_param( 'resume' );

		return $this->respond(
			senroflux()->tick(
				(int) $request->get_param( 'run_id' ),
				(int) $request->get_param( 'step_count' ),
				$resume
			)
		);
	}

	/** POST /runs/{id}/cancel. */
	public function routeCancel( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond( senroflux()->cancel( (int) $request->get_param( 'run_id' ) ) );
	}

	/** GET /runs/{id}. */
	public function routeGet( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond( senroflux()->get( (int) $request->get_param( 'run_id' ) ) );
	}

	/**
	 * GET /runs (0.3 S10).
	 *
	 * `limit` is the number of rows CONSIDERED before scoping, so a response
	 * may be shorter than the limit asked for. It is clamped to 1..100: an
	 * unbounded limit would let any logged-in user walk the whole table one
	 * request at a time, even though each row is already scoped.
	 */
	public function routeList( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = $request->get_param( 'limit' );
		$limit = null === $limit ? 50 : (int) $limit;
		$limit = max( 1, min( 100, $limit ) );

		return $this->respond( array( 'runs' => senroflux()->listRecent( $limit ) ) );
	}

	/**
	 * POST /runs/{id}/suggestions/{n} (0.3 S20).
	 *
	 * The permission_callback already required manage_options; RE-CHECKED
	 * here regardless (fail closed, B0 rule 2) — a route whose permission
	 * callback is ever bypassed or misconfigured must still refuse.
	 */
	public function routeSuggestionDecision( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return $this->respond(
				new \WP_Error( 'senroflux_forbidden', __( 'Insufficient permissions.', 'senroflux' ), array( 'status' => 403 ) )
			);
		}

		$text = $request->get_param( 'text' );

		return $this->respond(
			senroflux()->resolveSuggestion(
				(int) $request->get_param( 'run_id' ),
				(int) $request->get_param( 'seq' ),
				(string) $request->get_param( 'action' ),
				is_string( $text ) ? $text : null
			)
		);
	}

	/**
	 * Normalize Runner result into a REST response.
	 *
	 * @param mixed $result RunState or WP_Error.
	 */
	private function respond( mixed $result ): \WP_REST_Response {
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 400 );

			return new \WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		return new \WP_REST_Response( $result, 200 );
	}
}
