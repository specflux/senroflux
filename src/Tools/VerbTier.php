<?php
/**
 * Resolve an Agent Safety verb to its tier (0.2 S7).
 *
 * The plan fence (S7) needs a verb → tier source: a plan step is annotated
 * with the highest Agent Safety tier among its verbs, and each ability call is
 * tiered the same way so "Tier-1+" is a deterministic predicate. The tier is
 * ALWAYS from Agent Safety's classifier — never from the model.
 *
 * Stage 4 has no pack, so its verb map comes from the site-wide
 * `senroflux_verb_map` filter: verb => int (0 = read/free, 1 = write/fenced,
 * 2 = irreversible/grants). Stage 6 replaces this seam with the pack's real
 * map: the RUNNER resolves each call through {@see self::tierFor()} passing
 * the pack's map, and the plan annotation threads the same map through
 * PlanTools, so the two can never drift.
 *
 * Fail closed: a verb that is not in the map is treated as tier 2
 * (irreversible) — an unmapped verb in a plan or a call is the most
 * restrictive reading, never a free pass.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Static tier resolver for verbs.
 */
final class VerbTier {

	/** Tier 0 — read / free: allowed before any plan. */
	public const TIER_0 = 0;

	/** Tier 1 — write / fenced: needs an accepted plan. */
	public const TIER_1 = 1;

	/** Tier 2 — irreversible / grants: needs an accepted plan (and parks). */
	public const TIER_2 = 2;

	/**
	 * The site-wide filter name backing the stage-4 verb map.
	 */
	public const VERB_MAP_FILTER = 'senroflux_verb_map';

	/**
	 * Resolve one verb's tier.
	 *
	 * When `$verb_map` is null the map is read from the `senroflux_verb_map`
	 * filter (value: verb => int, optionally threaded with the run id so a
	 * stage-6 pack can return a per-run map). Unknown verbs default to tier 2
	 * (fail closed).
	 *
	 * @param string     $verb      The verb (stage 4: an ability name).
	 * @param array<string,int>|null $verb_map Optional verb => int map; null = filter.
	 * @param int|null   $run_id    Optional run id, threaded to the filter.
	 * @return int 0|1|2
	 */
	public static function tierFor( string $verb, ?array $verb_map = null, ?int $run_id = null ): int {
		if ( null === $verb_map ) {
			$verb_map = self::mapForRun( $run_id );
		}

		if ( isset( $verb_map[ $verb ] ) ) {
			$tier = $verb_map[ $verb ];
			if ( is_int( $tier ) ) {
				return self::clamp( $tier );
			}
			if ( is_numeric( $tier ) ) {
				return self::clamp( (int) $tier );
			}
		}

		// Fail closed: an unmapped verb is treated as irreversible.
		return self::TIER_2;
	}

	/**
	 * The verb map for a run, from the `senroflux_verb_map` filter. Value is a
	 * verb => int (0/1/2) map; non-array returns collapse to the empty map.
	 *
	 * @param int|null $run_id Optional run id (stage 6 pack seam).
	 * @return array<string,int>
	 */
	public static function mapForRun( ?int $run_id = null ): array {
		/**
		 * Filters the verb => tier map for one run.
		 *
		 * @param array<string,int> $verb_map Default empty map.
		 * @param int|null          $run_id   The run id, when known (stage 6).
		 */
		$verb_map = apply_filters( 'senroflux_verb_map', array(), $run_id );

		return is_array( $verb_map ) ? $verb_map : array();
	}

	/**
	 * Clamp an integer into the 0..2 tier domain.
	 *
	 * @param int $tier Raw tier.
	 * @return int
	 */
	private static function clamp( int $tier ): int {
		return max( self::TIER_0, min( self::TIER_2, $tier ) );
	}
}
