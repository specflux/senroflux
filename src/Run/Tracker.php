<?php
/**
 * The written-object set behind the S12 re-read nudge.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers over the `objects_json` map (0.2 S12, extended by 0.3 S8):
 *
 *   { "<object_id>": { last_write_seq: int, verified_seq: int|null, modified_marker?: string } }
 *
 * The harness stays domain-agnostic: an object id is an opaque string|int
 * produced by a tool result and later re-read by another call's args, and a
 * "modified marker" (0.3 S8) is an opaque string a pack's read/write
 * abilities agree on the meaning of (a post's `post_modified_gmt`, a hash of
 * a classic menu's items) — this class never mentions posts/pages/blocks nor
 * computes a marker itself. The post-shape resolution for the REPORT lives
 * in {@see Report} behind its injectable `$post_lookup` adapter; the S8
 * compare lives in each pack's write ability.
 *
 * All methods are immutable-ish: they RETURN a new map and never mutate
 * their input. Fail closed: a missing or corrupt entry is reported as
 * UNVERIFIED (S12) or STALE (S8) rather than trusted, and a corrupt
 * `$objects` container is treated as an empty set by the callers (it never
 * blocks a write).
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
		$key = (string) $object_id;

		// S8: a write REPLACES the write/verify bookkeeping but must never
		// erase a marker already recorded for this id (by a read, or by the
		// write ability itself right after this same write) — recordWrite()
		// and recordRead() are called in either order depending on the
		// ability (create-post records the read AFTER its insert; a read
		// records it BEFORE any write), so preserving whatever is already
		// there is the only order-independent rule.
		$existing = $objects[ $key ] ?? null;
		$marker   = is_array( $existing ) && array_key_exists( 'modified_marker', $existing )
			? $existing['modified_marker']
			: null;

		$objects[ $key ] = array(
			'last_write_seq' => $seq,
			'verified_seq'   => null, // A new write re-opens verification.
		);

		if ( null !== $marker ) {
			$objects[ $key ]['modified_marker'] = $marker;
		}

		return $objects;
	}

	/**
	 * Record (or update) `$object_id`'s modified marker (0.3 S8) — called at
	 * READ time by a pack's read ability, and again by a WRITE ability right
	 * after a successful write (so a run may keep editing its own writes
	 * without re-reading). Unlike {@see recordVerification()}, this creates
	 * the entry when it does not yet exist — a marker is evidence of a READ,
	 * independent of whether the object has ever been written.
	 *
	 * @param array<string,mixed> $objects   The objects_json map.
	 * @param string|int          $object_id Read/written object id.
	 * @param string              $marker    Opaque marker (e.g. `post_modified_gmt`).
	 * @return array<string,mixed> A NEW map with the marker recorded.
	 */
	public static function recordRead( array $objects, string|int $object_id, string $marker ): array {
		$key   = (string) $object_id;
		$entry = $objects[ $key ] ?? null;
		if ( ! is_array( $entry ) ) {
			$entry = array(
				'last_write_seq' => null,
				'verified_seq'   => null,
			);
		}

		$entry['modified_marker'] = $marker;
		$objects[ $key ]          = $entry;

		return $objects;
	}

	/**
	 * Whether a write to `$object_id` must be refused as a stale write (0.3
	 * S8): true when the run never recorded a marker for this id, or when the
	 * recorded marker no longer matches the object's CURRENT marker. Fail
	 * closed: a corrupt entry, or one with no recorded marker at all, is
	 * treated exactly like "never read".
	 *
	 * @param array<string,mixed> $objects        The objects_json map.
	 * @param string|int          $object_id      Object id being written.
	 * @param string              $current_marker The object's CURRENT marker, read fresh.
	 */
	public static function staleWrite( array $objects, string|int $object_id, string $current_marker ): bool {
		$key = (string) $object_id;
		if ( ! array_key_exists( $key, $objects ) ) {
			return true; // Never read.
		}

		$entry = $objects[ $key ];
		if ( ! is_array( $entry ) || ! array_key_exists( 'modified_marker', $entry ) ) {
			return true; // Fail closed: corrupt entry, or read never recorded a marker.
		}

		return (string) $entry['modified_marker'] !== $current_marker;
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
