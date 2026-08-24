<?php
/**
 * REST surface (S9): the same four operations as admin-ajax, for non-admin-ajax
 * consumers authenticated over REST (e.g. application passwords).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Http;

use Specflux\SenroFlux\Plugin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Routes under senroflux/v1:
 *   POST /runs                {consumer, goal, allow[], budget?}
 *   POST /runs/{id}/tick      {step_count, approval_action?}
 *   POST /runs/{id}/cancel
 *   GET  /runs/{id}
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
					'consumer' => array(
						'type'     => 'string',
						'required' => true,
					),
					'goal'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'allow'    => array(
						'type'     => 'array',
						'required' => false,
					),
					'budget'   => array(
						'type'     => 'object',
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
					'run_id'          => array(
						'type'     => 'integer',
						'required' => true,
					),
					'step_count'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'approval_action' => array(
						'type' => 'string',
						'enum' => array( 'approve', 'reject' ),
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

		register_rest_route(
			self::NAMESPACE_V1,
			'/runs/(?P<run_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'routeGet' ),
				'permission_callback' => static fn (): bool => is_user_logged_in() && current_user_can( 'read' ),
			)
		);
	}

	/** POST /runs. */
	public function routeStart( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond(
			senroflux()->start(
				(string) $request->get_param( 'consumer' ),
				(string) $request->get_param( 'goal' ),
				array_values( array_filter( (array) ( $request->get_param( 'allow' ) ?? array() ), 'is_string' ) ),
				is_array( $request->get_param( 'budget' ) ) ? (array) $request->get_param( 'budget' ) : array()
			)
		);
	}

	/** POST /runs/{id}/tick. */
	public function routeTick( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond(
			senroflux()->tick(
				(int) $request->get_param( 'run_id' ),
				(int) $request->get_param( 'step_count' ),
				$request->get_param( 'approval_action' )
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
