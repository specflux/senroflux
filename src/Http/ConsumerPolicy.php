<?php
/**
 * Server-side policy for HTTP-started runs.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Http;

use Specflux\SenroFlux\Run\Budget;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The browser never chooses a run's tool surface. A consumer that wants to
 * start runs over admin-ajax/REST is registered by PHP via the
 * `senroflux_http_consumers` filter, which owns the allow-list and the budget
 * ceiling; the request may only lower the budget. Unregistered consumers are
 * refused, so the HTTP surface is closed until a plugin opens it.
 */
final class ConsumerPolicy {

	public const FILTER = 'senroflux_http_consumers';

	/**
	 * Resolve the allow-list and budget for an HTTP start.
	 *
	 * @param string             $consumer              Consumer id from the request.
	 * @param mixed              $requested_budget      Budget-ish input from the request.
	 * @param array<string,int>  $pack_budget_overrides S7: the chosen pack's own
	 *                                                   {@see \Specflux\SenroFlux\Packs\Pack::defaultBudget()},
	 *                                                   when the start names one. Empty for a packless
	 *                                                   start, which keeps this seam pack-agnostic.
	 * @return array{allow:list<string>,budget:array{max_steps:int,max_tool_calls:int,max_tokens:int}}|WP_Error
	 */
	public static function resolve( string $consumer, mixed $requested_budget, array $pack_budget_overrides = array() ): array|WP_Error {
		/**
		 * Filters the consumers allowed to start runs over HTTP.
		 *
		 * @param array<string,array{allow?:list<string>,budget?:array<string,int>}> $consumers Keyed by consumer id.
		 */
		$consumers = apply_filters( 'senroflux_http_consumers', array() );
		$policy    = is_array( $consumers ) ? ( $consumers[ $consumer ] ?? null ) : null;

		$allow = array();
		if ( is_array( $policy ) && isset( $policy['allow'] ) && is_array( $policy['allow'] ) ) {
			foreach ( $policy['allow'] as $entry ) {
				if ( is_string( $entry ) && '' !== $entry ) {
					$allow[] = $entry;
				}
			}
		}

		if ( array() === $allow ) {
			return new WP_Error(
				'senroflux_unknown_consumer',
				__( 'This consumer is not registered to start runs over HTTP.', 'senroflux' ),
				array( 'status' => 403 )
			);
		}

		// S7: the pack's own (flat-and-high) defaults become the BASELINE the
		// ceiling is built from, but the registered consumer's own policy
		// budget still caps every key it sets — Budget::sanitize()'s
		// mergeOverCapped keeps a key the consumer didn't set at the
		// pack-adjusted default while never letting a key the consumer DID
		// set rise back toward or past the pack's own baseline. Applying this
		// unconditionally (rather than only when the pack has no overrides)
		// avoids silently loosening a third-party consumer's registered
		// budget on every pack-driven start.
		$ceiling = Budget::sanitize(
			is_array( $policy ) ? ( $policy['budget'] ?? null ) : null,
			$pack_budget_overrides
		);

		return array(
			'allow'  => $allow,
			'budget' => Budget::clamp( $requested_budget, $ceiling ),
		);
	}
}
