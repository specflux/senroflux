<?php
/**
 * Plugin::get() carries the plan-park UI facts (`ui.plan`) for a parked plan.
 *
 * Live proof shakedown: the Runs screen never offered "Accept and
 * pre-approve" because `preapprove_available` only rode the tick response
 * (`Runner::planUi()`), and the React app builds its plan card from the stored
 * plan step plus the run-detail read — so it survived neither a reload nor
 * the first render. The run detail now computes it fresh, with the same
 * function the tick uses.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Tools\PlanTools;
use wpdb;

final class PluginPlanUiTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_filters']         = array();
		$GLOBALS['wpdb']                           = new wpdb();
		Plugin::set_dependency_probe( true );
	}

	protected function tearDown(): void {
		remove_all_filters();
		senroflux_test_no_agent_safety();
		unset( $GLOBALS['wpdb'] );
		Plugin::reset();
		Plugin::set_dependency_probe( null );
	}

	/** @return int Run id parked on a plan. */
	private function seedParkedPlan( string $status = 'awaiting_plan' ): int {
		$method = new \ReflectionMethod( Plugin::class, 'runner' );
		$method->setAccessible( true );
		$store = $method->invoke( Plugin::instance() )->store();

		$run_id = $store->createRun( 1, 'test-consumer', 'Schedule three posts', array( 'senroflux/read-content' ), array() );
		$store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'Schedule three posts',
				'steps'       => array(
					array(
						'text'  => 'Draft',
						'verbs' => array( 'senroflux/read-content' ),
					),
				),
				'assumptions' => array(),
			),
			PlanTools::toolName(),
			null,
			'parked'
		);
		$store->updateRun( $run_id, array( 'status' => $status ) );

		return $run_id;
	}

	public function test_a_parked_plan_offers_preapproval_when_both_switches_are_on(): void {
		senroflux_test_grants( true );
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true );
		$run_id = $this->seedParkedPlan();

		$detail = Plugin::instance()->get( $run_id );

		$this->assertIsArray( $detail );
		$this->assertTrue( $detail['ui']['plan']['preapprove_available'] );
		$this->assertSame( 1, $detail['ui']['plan']['step_id'] );
		$this->assertArrayHasKey( 'remaining_plans', $detail['ui']['plan'] );
		$this->assertArrayHasKey( 'review_url', $detail['ui']['plan'] );
	}

	public function test_a_parked_plan_does_not_offer_preapproval_when_the_filter_is_off(): void {
		senroflux_test_grants( true );
		$run_id = $this->seedParkedPlan();

		$detail = Plugin::instance()->get( $run_id );

		$this->assertFalse( $detail['ui']['plan']['preapprove_available'] );
	}

	public function test_a_parked_plan_does_not_offer_preapproval_when_agent_safety_grants_are_off(): void {
		senroflux_test_grants( false );
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true );
		$run_id = $this->seedParkedPlan();

		$detail = Plugin::instance()->get( $run_id );

		$this->assertFalse( $detail['ui']['plan']['preapprove_available'] );
	}

	public function test_a_run_that_is_not_parked_on_a_plan_carries_no_plan_ui(): void {
		senroflux_test_grants( true );
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true );
		$run_id = $this->seedParkedPlan( 'running' );

		$detail = Plugin::instance()->get( $run_id );

		$this->assertSame( array(), $detail['ui'] );
	}
}
