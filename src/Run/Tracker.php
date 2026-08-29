<?php
/**
 * The written-object set behind the S12 re-read nudge (draft).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers over the `objects_json` map (0.2 S12):
 *
 *   { "<object_id>": { last_write_seq: int, verified_seq: int|null } }
 *
 * The harness stays domain-agnostic: an object id is an opaque string|int
 * produced by a tool result and later re-read by another call's args. This
 * class never mentions posts/pages/blocks — the post-shape resolution lives
 * in {@see Report} behind its injectable `$post_lookup` adapter.
 *
 * All three methods are immutable-ish: they RETURN a new map and never mutate
 * their input. Fail closed: a missing or corrupt entry is reported as
 * UNVERIFIED rather than trusted, and a corrupt `$objects` container is
 * treated as an empty set by the callers (it never blocks a write).
 */
final class Tracker {

	/**
	 * Record a write of `$object_id` at step `$seq`.
	 *
	 * A write RE-OPENS verification: `verified_seq` is always reset to null,
	 * even if the object was previously verified. The returned map carries
	 * `last_write_seq = $seq`.
	 *
	 * @param array<string,mixed> $objects   The objects_json map (mutable copy made here).
	 * @param string|int          $object_id Written object id (normalised to a string key).
	 * @param int                 $seq       Step seq at which the write result was recorded.
	 * @return array<string,mixed> A NEW map with the object's write recorded.
	 */
	public static function recordWrite( array $objects, string|int $object_id, int $seq ): array {
		$objects[ (string) $object_id ] = array(
			'last_write_seq' => $seq,
			'verified_seq'   => null, // A new write re-opens verification.
		);

		return $objects;
	}

	/**
	 * Record a verification (a read) of `$object_id` at step `$seq`.
	 *
	 * A verification is a read AFTER a write: it only upgrades an object that
	 * is ALREADY tracked. Reads of unwritten objects are ignored (they are not
	 * evidence of a write). No seq-ordering guard is applied here — the
	 * {@see self::unverified()} check is the sole source of truth, so a stale
	 * verification (seq before last_write) is still reported unverified.
	 *
	 * @param array<string,mixed> $objects   The objects_json map.
	 * @param string|int          $object_id Read object id (normalised to a string key).
	 * @param int                 $seq       Step seq at which the read result was recorded.
	 * @return array<string,mixed> The map, upgraded when the object exists.
	 */
	public static function recordVerification( array $objects, string|int $object_id, int $seq ): array {
		$key = (string) $object_id;

		if ( ! array_key_exists( $key, $objects ) ) {
			// Unknown object: reads of unwritten objects are not tracked.
			return $objects;
		}

		$entry = $objects[ $key ];
		if ( ! is_array( $entry ) ) {
			// Corrupt entry: leave it as-is rather than guess (fail closed).
			return $objects;
		}

		$entry['verified_seq'] = $seq;
		$objects[ $key ]       = $entry;

		return $objects;
	}

	/**
	 * Object ids that still need a re-read before the run may finish.
	 *
	 * An object is UNVERIFIED when it has no verification, or its verification
	 * predates its most recent write. A corrupt entry is also unverified.
	 *
	 * @param array<string,mixed> $objects The objects_json map.
	 * @return list<string> Object ids, in map insertion order.
	 */
	public static function unverified( array $objects ): array {
		$ids = array();

		foreach ( $objects as $object_id => $entry ) {
			if ( ! is_array( $entry ) ) {
				// Fail closed: a corrupt entry is never trusted as verified.
				$ids[] = (string) $object_id;
				continue;
			}

			$last_write = $entry['last_write_seq'] ?? null;
			$verified   = $entry['verified_seq'] ?? null;

			if ( null === $last_write || null === $verified || $verified < $last_write ) {
				$ids[] = (string) $object_id;
			}
		}

		return $ids;
	}
}
