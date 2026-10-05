<?php
/**
 * Report::build() unit tests: the S12 change-row shape, and the defect fix
 * (live run 56) that a READ-ONLY marker (no last_write_seq) must never open
 * a report row.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Report;
use Specflux\SenroFlux\Run\Tracker;

final class ReportTest extends TestCase {

	public function test_a_read_only_marker_opens_no_change_row(): void {
		$objects = Tracker::recordRead( array(), 'site-navigation', 'marker-a' );

		$report = Report::build( 'Done.', $objects, self::neverCalledLookup() );

		$this->assertSame( array(), $report['changes'] );
	}

	public function test_a_written_object_opens_a_resolved_change_row(): void {
		$objects = Tracker::recordWrite( array(), 'site-navigation', 3 );

		$report = Report::build(
			'Done.',
			$objects,
			static fn ( string|int $object_id ): array => array( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixture: the resolved shape is fixed, regardless of which id it was called with.
				'object_type' => 'navigation',
				'title'       => 'Site navigation',
				'status'      => '',
				'edit_url'    => 'https://example.test/wp-admin/site-editor.php',
				'preview_url' => null,
			)
		);

		$this->assertCount( 1, $report['changes'] );
		$row = $report['changes'][0];
		$this->assertSame( 'navigation', $row['object_type'] );
		$this->assertSame( 'Site navigation', $row['title'] );
		$this->assertFalse( $row['verified'] );
	}

	public function test_a_corrupt_entry_still_opens_an_unknown_unverified_row(): void {
		$objects = array( 'x' => 'not-an-array' );

		$report = Report::build( 'Done.', $objects, self::neverCalledLookup() );

		$this->assertCount( 1, $report['changes'] );
		$this->assertSame( 'unknown', $report['changes'][0]['object_type'] );
		$this->assertFalse( $report['changes'][0]['verified'] );
	}

	public function test_build_carries_gate_mode_through(): void {
		$report = Report::build( '', array(), self::neverCalledLookup(), GateMode::BuiltIn );

		$this->assertSame( 'built_in', $report['gate_mode'] );
	}

	// ------------------------------------------------------------------
	// Stage 22b: grants, adopted objects and "left for you to remove".
	// ------------------------------------------------------------------

	public function test_grants_ride_on_the_report_with_their_expiry(): void {
		$grants = array(
			array(
				'verb'       => 'senroflux/publish-post',
				'status'     => 'revoked',
				'remaining'  => 1,
				'expires_at' => '2026-10-03T10:00:00Z',
			),
		);

		$report = Report::build( '', array(), self::neverCalledLookup(), GateMode::AgentSafety, array(), null, $grants );

		$this->assertSame( $grants, $report['grants'] );
	}

	public function test_a_report_with_no_grants_or_object_lists_carries_none_of_those_keys(): void {
		$report = Report::build( '', array(), self::neverCalledLookup() );

		$this->assertArrayNotHasKey( 'grants', $report );
		$this->assertArrayNotHasKey( 'adopted', $report );
		$this->assertArrayNotHasKey( 'left_for_you', $report );
	}

	public function test_adopted_and_left_for_you_rows_are_resolved_through_the_lookup(): void {
		$lookup = static fn ( string|int $object_id ): array => array(
			'object_type' => 'page',
			'title'       => 'Resolved ' . $object_id,
			'status'      => 'publish',
			'edit_url'    => 'https://example.test/wp-admin/post.php?post=' . $object_id . '&action=edit',
			'preview_url' => null,
		);

		$report = Report::build(
			'',
			array(),
			$lookup,
			GateMode::BuiltIn,
			array(),
			null,
			array(),
			array(
				array(
					'id'    => '7',
					'title' => 'Model title',
				),
			),
			array(
				array(
					'id'    => '2',
					'title' => '',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'object_id' => '7',
					'title'     => 'Resolved 7',
					'status'    => 'publish',
					'edit_url'  => 'https://example.test/wp-admin/post.php?post=7&action=edit',
				),
			),
			$report['adopted']
		);
		$this->assertSame( 'Resolved 2', $report['left_for_you'][0]['title'] );
	}

	public function test_an_object_the_lookup_cannot_resolve_falls_back_to_the_plans_title_and_no_link(): void {
		$lookup = static fn ( string|int $object_id ): array => array( 'object_type' => 'unknown' ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fixture.

		$report = Report::build(
			'',
			array(),
			$lookup,
			GateMode::BuiltIn,
			array(),
			null,
			array(),
			array(),
			array(
				array(
					'id'    => '99',
					'title' => 'Sample Page',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'object_id' => '99',
					'title'     => 'Sample Page',
					'status'    => '',
					'edit_url'  => null,
				),
			),
			$report['left_for_you']
		);
	}

	/** A lookup that fails the test if it is ever invoked. */
	private function neverCalledLookup(): callable {
		return function ( string|int $object_id ): array {
			$this->fail( 'the lookup must not be called for id ' . (string) $object_id );
		};
	}
}
