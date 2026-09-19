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
 *
 * `images` (0.3 S5) and `refunds` (0.3 S19) are a different KIND of ceiling
 * from the other five: each is spent by one pack ability (the posts pack's
 * image generation; the commerce pack's refund, stage 12/13), not derived
 * from the tick loop itself, and exhaustion is a clean ability-level refusal
 * (`budget_exhausted`) rather than a run failure. This class stays
 * domain-agnostic even so — it knows only the KEY NAMES `images`/`refunds`,
 * never that one means pictures and the other means money back; the ability
 * asking "how much of this key is left" and "spend one" is a harness seam
 * ({@see \Specflux\SenroFlux\Packs\Content\Media}, and {@see self::spentCount()}
 * for the shared counting rule both use), not this class's job.
 */
final class Budget {

	public const MAX_STEPS      = 'max_steps';
	public const MAX_TOOL_CALLS = 'max_tool_calls';
	public const MAX_TOKENS     = 'max_tokens';
	public const MAX_QUESTIONS  = 'max_questions';
	public const MAX_PLANS      = 'max_plans';
	public const IMAGES         = 'images';
	public const REFUNDS        = 'refunds';

	/**
	 * The shipped defaults (S4 as amended by 0.2 S4). Exhausting questions or
	 * plans is NOT a failure — the tool is withdrawn / the run cancels per
	 * S6/S7 — but the ceilings still bound how many park round-trips a run
	 * may cause.
	 *
	 * @param array<string,int> $pack_overrides Pack-declared overrides (S7),
	 *                                          applied over the shipped table
	 *                                          BEFORE the site-wide filter.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int}
	 */
	public static function defaults( array $pack_overrides = array() ): array {
		// 0.3 S7: a pack's own default table (e.g. the site pack's flat-and-high
		// budget) is applied over the shipped table BEFORE the site-wide filter
		// runs, so the filter (and, below, a caller's per-run override) sees the
		// pack's numbers as ITS baseline, not the generic shipped one.
		$base = self::mergeOver( self::shipped(), $pack_overrides );

		/**
		 * Filters the default budget for new runs.
		 *
		 * @param array{max_steps:int,max_tool_calls:int,max_tokens:int,max_questions:int,max_plans:int,images:int,refunds:int} $defaults
		 */
		$filtered = apply_filters( 'senroflux_default_budget', $base );

		if ( array() === $pack_overrides ) {
			// Pre-0.3 behaviour, byte for byte: no pack in play, the filter may
			// move a key either direction over the shipped table.
			return self::mergeOver( $base, $filtered );
		}

		// S7: "the site filter and consumers may still only lower it" — once a
		// pack has declared its own (flat and high) defaults, the filter may
		// only pull a key DOWN from that pack baseline, never raise it back
		// toward or past the pack's own ceiling.
		return self::mergeOverCapped( $base, $filtered );
	}

	/**
	 * The hard-coded table the `senroflux_default_budget` filter starts from.
	 * The one place these numbers appear.
	 *
	 * Sized from live pages-pack runs (0.2 stage 10). A consumer may only LOWER
	 * a budget (S13 `clamp()`), so a ceiling below what the shipped pack's own
	 * use case costs is not a "safe default" — it is a stock install that always
	 * dies `budget_exceeded`.
	 *
	 * Three live "design and publish a page" runs, measured end to end:
	 *   run 43 — clean path: 31 steps, 11 tool results, 48.8k tokens.
	 *   run 44 — model retried invalid block markup: died at 40 steps mid-repair.
	 *   run 45 — published AND verified, then died at 40 steps with nothing left
	 *            to write the report with. 90k tokens.
	 * A real model spends steps on refused writes (`invalid_markup`,
	 * `page_shape`, `not_in_plan`), and those retries are the normal case, not
	 * the pathological one. 40 was still under the observed worst case, so the
	 * step ceiling is 60: above every run seen so far with room for a retry
	 * loop, and still a hard bound on a runaway. `max_tokens` is a backstop for
	 * a long-context loop rather than the binding limit — 90k over 40 steps
	 * scales to roughly 150k over 60, so 250000 leaves it non-binding while
	 * still capping the damage.
	 *
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int}
	 */
	private static function shipped(): array {
		return array(
			self::MAX_STEPS      => 60,
			self::MAX_TOOL_CALLS => 30,
			self::MAX_TOKENS     => 250000,
			self::MAX_QUESTIONS  => 5,
			self::MAX_PLANS      => 3,
			// S5: shipped default 6, lower-only like every other key. Sized
			// so a real "write and illustrate a post" run is not forced to
			// budget_exhausted on its first image.
			self::IMAGES         => 6,
			// S19: shipped default 1, lower-only like `images` (may also be
			// exactly zero — a store owner who wants NO refund from a run
			// sets this to 0 without disabling the commerce pack outright).
			// Spent once per successful call of the ability that declares it
			// (the commerce pack's refund ability, stage 12/13) — never a
			// word this class itself knows.
			self::REFUNDS        => 1,
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
	 * @param mixed             $raw            Raw budget-ish input.
	 * @param array<string,int> $pack_overrides Pack-declared overrides (S7).
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int}
	 */
	public static function sanitize( mixed $raw, array $pack_overrides = array() ): array {
		$base = self::defaults( $pack_overrides );
		if ( array() === $pack_overrides ) {
			return self::mergeOver( $base, $raw );
		}

		// S7: same lower-only rule as {@see defaults()}, applied to a caller's
		// per-run override once a pack default is in play.
		return self::mergeOverCapped( $base, $raw );
	}

	/**
	 * Overlay the known keys of `$raw` onto `$base` as positive integers —
	 * EXCEPT `images` (S7), which may also be exactly zero. Every other key
	 * genuinely cannot be zero (a zero step/tool-call/token/question/plan
	 * ceiling would fail the run immediately); `images` is a clean-refusal
	 * SPEND counter, not a run-ending ceiling, and the site pack's own
	 * default (S7) is `images: 0` — "no image generation in this run" — which
	 * a positive-only rule could never represent.
	 *
	 * @param array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int} $base Starting table.
	 * @param mixed                                                                                                       $raw  Raw budget-ish input.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int}
	 */
	private static function mergeOver( array $base, mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return $base;
		}

		foreach ( array_keys( $base ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_int( $raw[ $key ] ) && self::isAcceptableCap( $key, $raw[ $key ] ) ) {
				$base[ $key ] = $raw[ $key ];
				continue;
			}
			// Numeric strings arrive from JSON; accept them under the same rule.
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && preg_match( '/^\d+$/', $raw[ $key ] ) ) {
				$int = (int) $raw[ $key ];
				if ( self::isAcceptableCap( $key, $int ) ) {
					$base[ $key ] = $int;
				}
			}
		}

		return $base;
	}

	/**
	 * Whether `$value` is an acceptable cap for `$key`: positive for every
	 * key, or zero-or-positive for `images`/`refunds` (see {@see mergeOver()}
	 * — both are clean-refusal SPEND counters, not run-ending ceilings, and
	 * zero legitimately means "none of this in this run").
	 */
	private static function isAcceptableCap( string $key, int $value ): bool {
		if ( self::IMAGES === $key || self::REFUNDS === $key ) {
			return $value >= 0;
		}

		return $value > 0;
	}

	/**
	 * Like {@see mergeOver()}, but every resulting key is also capped at
	 * `$base`'s own value (S7 lower-only rule): `$raw` may only pull a key
	 * DOWN from `$base`, never past it.
	 *
	 * @param array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int} $base Ceiling table.
	 * @param mixed                                                                                                       $raw  Raw budget-ish input.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int}
	 */
	private static function mergeOverCapped( array $base, mixed $raw ): array {
		$merged = self::mergeOver( $base, $raw );
		foreach ( $base as $key => $ceiling_value ) {
			$merged[ $key ] = min( $merged[ $key ], $ceiling_value );
		}

		return $merged;
	}

	/**
	 * Sanitize a caller's request against a ceiling: each cap may only come
	 * down. Untrusted callers (the HTTP surface) go through here so a request
	 * cannot raise its own limits.
	 *
	 * @param mixed                                                                 $requested Raw budget-ish input.
	 * @param array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int} $ceiling   Upper bound per key.
	 * @return array{max_steps: int, max_tool_calls: int, max_tokens: int, max_questions: int, max_plans: int, images: int, refunds: int}
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

	/**
	 * The number of PRIOR successful calls to one ability (by its BASE name,
	 * namespace stripped) in a run — the shared counting rule behind every
	 * SPEND-style budget key (`images`, S5; `refunds`, S19). This class stays
	 * domain-agnostic even so: it is handed the ability's base name and reads
	 * only the run's own step history — never told what the key MEANS. The
	 * pack ability that asks "how much of this key is left" and "spend one"
	 * owns that knowledge ({@see \Specflux\SenroFlux\Packs\Content\Media} for
	 * `images`; the commerce pack's refund ability, stage 12/13, is the
	 * second caller `refunds` was added for).
	 *
	 * @param RunStore $store             The run store.
	 * @param int      $run_id            The run to read.
	 * @param string   $ability_base_name The ability's final path segment
	 *                                    (namespace stripped), e.g. 'generate-image'.
	 */
	public static function spentCount( RunStore $store, int $run_id, string $ability_base_name ): int {
		$spent = 0;
		foreach ( $store->getSteps( $run_id ) as $step ) {
			if ( 'ok' !== $step->status || null === $step->toolName ) {
				continue;
			}
			if ( self::baseName( $step->toolName ) === $ability_base_name ) {
				++$spent;
			}
		}

		return $spent;
	}

	/** The ability name's final segment (namespace stripped). */
	private static function baseName( string $ability ): string {
		$pos = strrpos( $ability, '/' );

		return false === $pos ? $ability : substr( $ability, $pos + 1 );
	}
}
