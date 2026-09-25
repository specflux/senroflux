<?php
/**
 * Report::build() unit tests for 0.3 S6's `withheld_roles` field — added next
 * to `gate_mode`, existing report shape otherwise unchanged.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Report;

final class ReportWithheldRolesTest extends TestCase {

	public function test_withheld_roles_defaults_to_empty(): void {
		$report = Report::build( 'Done.', array() );

		$this->assertSame( array(), $report['withheld_roles'] );
	}

	public function test_withheld_roles_carries_the_given_list(): void {
		$report = Report::build( 'Done.', array(), null, GateMode::AgentSafety, array( 'generate' ) );

		$this->assertSame( array( 'generate' ), $report['withheld_roles'] );
	}

	public function test_non_string_entries_are_dropped(): void {
		$report = Report::build( 'Done.', array(), null, GateMode::AgentSafety, array( 'generate', 5, null ) );

		$this->assertSame( array( 'generate' ), $report['withheld_roles'] );
	}

	public function test_the_existing_report_shape_is_unchanged(): void {
		$report = Report::build( 'Done.', array(), null, GateMode::AgentSafety, array( 'generate' ) );

		$this->assertArrayHasKey( 'summary', $report );
		$this->assertArrayHasKey( 'changes', $report );
		$this->assertArrayHasKey( 'gate_mode', $report );
	}
}
