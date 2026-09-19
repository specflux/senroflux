<?php
/**
 * RunsScreen list-row affordances (0.3 S9, stage 8): park-kind naming, the
 * "Needs you" filter, and stalled-run detection.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Run\RunStatus;

final class RunsScreenListTest extends TestCase {

	public function test_park_kind_label_names_each_park_kind(): void {
		$this->assertSame( 'waiting for your answer', RunsScreen::parkKindLabel( RunStatus::AwaitingUser->value ) );
		$this->assertSame( 'waiting for approval', RunsScreen::parkKindLabel( RunStatus::AwaitingApproval->value ) );
		$this->assertSame( 'waiting on the plan', RunsScreen::parkKindLabel( RunStatus::AwaitingPlan->value ) );
	}

	public function test_park_kind_label_is_null_for_a_non_parked_status(): void {
		$this->assertNull( RunsScreen::parkKindLabel( RunStatus::Running->value ) );
		$this->assertNull( RunsScreen::parkKindLabel( RunStatus::Completed->value ) );
	}

	public function test_list_row_label_prefers_the_park_kind_over_the_ordinary_status_label(): void {
		$run = array(
			'status'  => RunStatus::AwaitingPlan->value,
			'stalled' => false,
		);

		$this->assertSame( 'waiting on the plan', RunsScreen::listRowLabel( $run ) );
	}

	public function test_list_row_label_names_a_stalled_running_run(): void {
		$run = array(
			'status'  => RunStatus::Running->value,
			'stalled' => true,
		);

		$this->assertSame( 'paused while closed — open to continue', RunsScreen::listRowLabel( $run ) );
	}

	public function test_list_row_label_is_the_ordinary_status_label_for_a_live_running_run(): void {
		$run = array(
			'status'  => RunStatus::Running->value,
			'stalled' => false,
		);

		$this->assertSame( RunsScreen::statusLabel( RunStatus::Running->value ), RunsScreen::listRowLabel( $run ) );
	}

	public function test_needs_you_includes_only_parked_runs_the_viewer_may_tick(): void {
		$parked_and_tickable = array(
			'status'          => RunStatus::AwaitingApproval->value,
			'viewer_may_tick' => true,
		);
		$parked_not_tickable = array(
			'status'          => RunStatus::AwaitingApproval->value,
			'viewer_may_tick' => false,
		);
		$running_tickable    = array(
			'status'          => RunStatus::Running->value,
			'viewer_may_tick' => true,
		);
		$stalled_running     = array(
			'status'          => RunStatus::Running->value,
			'viewer_may_tick' => true,
			'stalled'         => true,
		);
		$completed           = array(
			'status'          => RunStatus::Completed->value,
			'viewer_may_tick' => true,
		);

		$this->assertTrue( RunsScreen::needsYou( $parked_and_tickable ) );
		$this->assertFalse( RunsScreen::needsYou( $parked_not_tickable ), 'parked but not this viewer\'s to tick' );
		$this->assertFalse( RunsScreen::needsYou( $running_tickable ), 'running is not a park' );
		$this->assertFalse( RunsScreen::needsYou( $stalled_running ), 'stalled is not a park kind either' );
		$this->assertFalse( RunsScreen::needsYou( $completed ) );
	}
}
