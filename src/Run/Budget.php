<?php
/**
 * Budget ceilings for one run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

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
	public const MAX_QUESTIONS  = 'max_questions';
	public const MAX_PLANS      = 'max_plans';

	/**
	 * The shipped defaults (S4 as amended by 0.2 S4). Exhausting questions or
	 * plans is NOT a failure — the tool is withdrawn / the run cancels per
	 * S6/S7 — but the ceilings still bound how many park round-trips a run
	 * may cause.
	 *
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int}
	 */
	public static function defaults(): array {
		/**
		 * Filters the default budget for new runs.
		 *
		 * @param array{max_steps:int,max_tool_calls:int,max_tokens:int,max_questions:int,max_plans:int} $defaults
		 */
		$filtered = apply_filters( 'senroflux_default_budget', self::shipped() );

		// Merge over the SHIPPED table, not over defaults(): the filter is what
		// defines the defaults, so re-entering this method here would recurse.
		return self::mergeOver( self::shipped(), $filtered );
	}

	/**
	 * The hard-coded table the `senroflux_default_budget` filter starts from.
	 * The one place these numbers appear.
	 *
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int}
	 */
	private static function shipped(): array {
		return array(
			self::MAX_STEPS      => 20,
			self::MAX_TOOL_CALLS => 12,
			self::MAX_TOKENS     => 60000,
			self::MAX_QUESTIONS  => 5,
			self::MAX_PLANS      => 3,
		);
	}

	/**
	 * Merge caller overrides over the FILTERED defaults, keeping only the known
	 * keys as positive integers. Anything else is dropped: an unknown key must
	 * not silently masquerade as policy.
	 *
	 * A key the caller does not supply falls back to {@see self::defaults()} —
	 * the site's filtered table, never the shipped constants, so a host that
	 * lowers `max_tokens` site-wide is honoured by every run that does not
	 * override it.
	 *
	 * @param mixed $raw Raw budget-ish input.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int}
	 */
	public static function sanitize( mixed $raw ): array {
		return self::mergeOver( self::defaults(), $raw );
	}

	/**
	 * Overlay the known keys of `$raw` onto `$base` as positive integers.
	 *
	 * @param array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int} $base Starting table.
	 * @param mixed                                                                                          $raw  Raw budget-ish input.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int}
	 */
	private static function mergeOver( array $base, mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return $base;
		}

		foreach ( array_keys( $base ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_int( $raw[ $key ] ) && $raw[ $key ] > 0 ) {
				$base[ $key ] = $raw[ $key ];
				continue;
			}
			// Numeric strings arrive from JSON; accept them under the same rule.
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && preg_match( '/^\d+$/', $raw[ $key ] ) ) {
				$base[ $key ] = (int) $raw[ $key ];
			}
		}

		return $base;
	}

	/**
	 * Sanitize a caller's request against a ceiling: each cap may only come
	 * down. Untrusted callers (the HTTP surface) go through here so a request
	 * cannot raise its own limits.
	 *
	 * @param mixed                                                    $requested Raw budget-ish input.
	 * @param array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int} $ceiling   Upper bound per key.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int}
	 */
	public static function clamp( mixed $requested, array $ceiling ): array {
		$ceiling = self::sanitize( $ceiling );
		if ( ! is_array( $requested ) ) {
			return $ceiling;
		}

		$wanted = self::sanitize( array_merge( $ceiling, $requested ) );
		foreach ( array_keys( $ceiling ) as $key ) {
			$ceiling[ $key ] = min( $ceiling[ $key ], $wanted[ $key ] );
		}

		return $ceiling;
	}
}
