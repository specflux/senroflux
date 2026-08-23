<?php
/**
 * Budget ceilings for one run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

/**
 * Per S4, a run carries hard ceilings so a runaway loop dies before it eats
 * the provider's context window or the site's patience. Exceeding ANY of them
 * fails the run with error code budget_exceeded.
 *
 * Defaults are filterable site-wide via `senroflux_default_budget`; the
 * consumer may pass per-run overrides at start(), which are merged over the
 * (filtered) defaults and clamped to positive integers — a zero/negative cap
 * would make every run fail immediately.
 */
final class Budget {

	public const MAX_STEPS      = 'max_steps';
	public const MAX_TOOL_CALLS = 'max_tool_calls';
	public const MAX_TOKENS     = 'max_tokens';

	/**
	 * The shipped defaults (S4).
	 *
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int}
	 */
	public static function defaults(): array {
		$defaults = array(
			self::MAX_STEPS      => 20,
			self::MAX_TOOL_CALLS => 12,
			self::MAX_TOKENS     => 60000,
		);

		/**
		 * Filters the default budget for new runs.
		 *
		 * @param array{max_steps:int,max_tool_calls:int,max_tokens:int} $defaults
		 */
		$filtered = apply_filters( 'senroflux_default_budget', $defaults );

		return self::sanitize( $filtered );
	}

	/**
	 * Merge caller overrides over the defaults, keeping only the three known
	 * keys as positive integers. Anything else is dropped: an unknown key must
	 * not silently masquerade as policy.
	 *
	 * @param mixed $raw Raw budget-ish input.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int}
	 */
	public static function sanitize( mixed $raw ): array {
		$defaults = array(
			self::MAX_STEPS      => 20,
			self::MAX_TOOL_CALLS => 12,
			self::MAX_TOKENS     => 60000,
		);

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		foreach ( array_keys( $defaults ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_int( $raw[ $key ] ) && $raw[ $key ] > 0 ) {
				$defaults[ $key ] = $raw[ $key ];
				continue;
			}
			// Numeric strings arrive from JSON; accept them under the same rule.
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && preg_match( '/^\d+$/', $raw[ $key ] ) ) {
				$defaults[ $key ] = (int) $raw[ $key ];
			}
		}

		return $defaults;
	}
}
