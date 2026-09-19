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

	/** A lookup that fails the test if it is ever invoked. */
	private function neverCalledLookup(): callable {
		return function ( string|int $object_id ): array {
			$this->fail( 'the lookup must not be called for id ' . (string) $object_id );
		};
	}
}
