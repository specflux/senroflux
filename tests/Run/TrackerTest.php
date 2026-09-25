<?php
/**
 * Tracker unit tests: the 0.2 S12 write/verify bookkeeping, plus the 0.3 S8
 * modified-marker extension (recordRead/staleWrite, and recordWrite's
 * marker-preserving merge).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Tracker;

final class TrackerTest extends TestCase {

	// --- S12 (pre-existing behaviour, guarded against regression) -----------

	public function test_record_write_opens_an_entry_unverified(): void {
		$objects = Tracker::recordWrite( array(), 42, 3 );

		$this->assertSame(
			array(
				'42' => array(
					'last_write_seq' => 3,
					'verified_seq'   => null,
				),
			),
			$objects
		);
	}

	public function test_unverified_lists_objects_with_no_or_stale_verification(): void {
		$objects = array(
			'1' => array(
				'last_write_seq' => 3,
				'verified_seq'   => null,
			),
			'2' => array(
				'last_write_seq' => 3,
				'verified_seq'   => 3,
			),
			'3' => array(
				'last_write_seq' => 5,
				'verified_seq'   => 2,
			),
		);

		$this->assertSame( array( '1', '3' ), Tracker::unverified( $objects ) );
	}

	/**
	 * Defect fix (live run 56): a READ marker alone (last_write_seq null,
	 * e.g. Navigation/FrontPage's read-time recordRead()) must never be
	 * reported unverified — nothing was ever written, so there is nothing to
	 * re-read before finishing.
	 */
	public function test_a_read_only_marker_is_never_unverified(): void {
		$objects = Tracker::recordRead( array(), 'site-navigation', 'marker-a' );

		$this->assertSame( array(), Tracker::unverified( $objects ) );
	}

	/**
	 * A corrupt entry stays fail-closed unverified regardless of the
	 * read/write distinction above — there is no last_write_seq to trust
	 * either way.
	 */
	public function test_a_corrupt_entry_is_still_unverified(): void {
		$objects = array( 'x' => 'not-an-array' );

		$this->assertSame( array( 'x' ), Tracker::unverified( $objects ) );
	}

	// --- S8: recordRead / staleWrite -----------------------------------------

	public function test_a_never_read_object_is_a_stale_write(): void {
		$this->assertTrue( Tracker::staleWrite( array(), 100, 'marker-a' ) );
	}

	public function test_a_read_then_a_matching_write_is_not_stale(): void {
		$objects = Tracker::recordRead( array(), 100, 'marker-a' );

		$this->assertFalse( Tracker::staleWrite( $objects, 100, 'marker-a' ) );
	}

	public function test_a_read_then_a_changed_marker_is_stale(): void {
		$objects = Tracker::recordRead( array(), 100, 'marker-a' );

		$this->assertTrue( Tracker::staleWrite( $objects, 100, 'marker-b' ) );
	}

	public function test_a_corrupt_entry_is_stale(): void {
		$objects = array( '100' => 'not-an-array' );

		$this->assertTrue( Tracker::staleWrite( $objects, 100, 'marker-a' ) );
	}

	public function test_an_entry_with_no_recorded_marker_is_stale(): void {
		// e.g. an entry that only ever went through recordWrite() pre-0.3.
		$objects = Tracker::recordWrite( array(), 100, 1 );

		$this->assertTrue( Tracker::staleWrite( $objects, 100, 'marker-a' ) );
	}

	public function test_record_read_creates_an_entry_when_none_exists(): void {
		$objects = Tracker::recordRead( array(), 100, 'marker-a' );

		$this->assertSame(
			array(
				'100' => array(
					'last_write_seq'  => null,
					'verified_seq'    => null,
					'modified_marker' => 'marker-a',
				),
			),
			$objects
		);
	}

	public function test_record_read_updates_the_marker_on_an_existing_entry(): void {
		$objects = Tracker::recordWrite( array(), 100, 3 );
		$objects = Tracker::recordRead( $objects, 100, 'marker-after-write' );

		$this->assertSame( 3, $objects['100']['last_write_seq'] );
		$this->assertSame( 'marker-after-write', $objects['100']['modified_marker'] );
	}

	/**
	 * The order-independence rule stated on {@see Tracker::recordWrite()}: a
	 * write must never erase a marker already recorded for the same id — this
	 * is what lets a write-then-record-new-marker sequence (the pack's own
	 * post-write re-record) survive the harness's OWN recordWrite() call for
	 * the same tool result.
	 */
	public function test_record_write_preserves_an_existing_marker(): void {
		$objects = Tracker::recordRead( array(), 100, 'marker-a' );
		$objects = Tracker::recordWrite( $objects, 100, 5 );

		$this->assertSame(
			array(
				'last_write_seq'  => 5,
				'verified_seq'    => null,
				'modified_marker' => 'marker-a',
			),
			$objects['100']
		);
	}

	public function test_record_write_with_no_prior_marker_carries_none(): void {
		$objects = Tracker::recordWrite( array(), 100, 1 );

		$this->assertArrayNotHasKey( 'modified_marker', $objects['100'] );
	}
}
