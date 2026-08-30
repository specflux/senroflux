<?php
/**
 * Runner resume-dispatch tests (stage-1 check, 0.2 S5): the tick protocol
 * refuses park resolutions whose shape does not match the run's park kind,
 * and refuses any resolution on a non-parked run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use SenroFlux_Test_Fake_Ability;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class RunnerResumeTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, $this->bridge );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_abilities']       = array(
			'agsafe-smoke/blocked' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', permission_result: true ),
		);
		// The S7 plan fence is separately tested in PlanParkTest. These tests
		// exercise the approval/park mechanics with fence-free (tier-0) verbs.
		add_filter(
			'senroflux_verb_map',
			static fn (): array => array(
				'agsafe-smoke/spend'   => 0,
				'agsafe-smoke/blocked' => 0,
				'agsafe-smoke/read'    => 0,
				'other-plugin/refund'  => 0,
			),
			10,
			0
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
	}


	private function createRun(): int {
		return $this->store->createRun( 1, 'test-consumer', 'Clear the cache', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	/** Force a run into any status (parks the loop cannot reach before S6/S7). */
	private function forceStatus( int $run_id, RunStatus $status ): void {
		$this->store->updateRun( $run_id, array( 'status' => $status->value ) );
	}

	private function assertMismatch( array|WP_Error $result, string $label ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $label );
		$this->assertSame( 'resume_mismatch', $result->get_error_code(), $label );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0, $label );
	}

	public function test_a_running_run_accepts_no_resolution(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new ModelTurn(
			new ModelMessage( array( new MessagePart( 'Working on it.' ) ) ),
			10,
			5
		);

		$result = $this->runner->tick( $run_id, 0, array( 'action' => 'approve' ) );

		$this->assertMismatch( $result, 'running + approval-shaped resolution' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertSame( RunStatus::Pending, $run->status, 'the mismatch must not advance the run' );
		$this->assertSame( 0, $run->stepCount, 'no model call happened for a refused tick' );
	}

	public function test_approval_park_refuses_question_and_plan_shapes(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( 'call_1', 'wpab__agsafe-smoke__blocked', array() ) ) ) ),
			10,
			5
		);
		// A permission check that parks.
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] = new SenroFlux_Test_Fake_Ability(
			'agsafe-smoke/blocked',
			permission_result: new WP_Error(
				'approval_required',
				'needs a human',
				array(
					'approval_id' => 'apr_1',
					'verb'        => 'agsafe-smoke/blocked',
					'tier'        => 2,
				)
			)
		);
		$this->runner->tick( $run_id, 0, null );
		$step_count = $this->store->getRun( $run_id )->stepCount;

		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'answer' => array( 'text' => 'ok' ) ) ),
			'approval park + question shape'
		);
		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'plan' => array( 'action' => 'accept' ) ) ),
			'approval park + plan shape'
		);
		$this->assertMismatch(
			$this->runner->tick(
				$run_id,
				$step_count,
				array(
					'action' => 'approve',
					'extra'  => 1,
				)
			),
			'approval park + extra keys'
		);

		// The run must still be parked, untouched by the refused ticks.
		$this->assertSame( RunStatus::AwaitingApproval, $this->store->getRun( $run_id )->status );
	}

	public function test_question_park_refuses_approval_and_plan_shapes(): void {
		$run_id = $this->createRun();
		$this->forceStatus( $run_id, RunStatus::AwaitingUser );
		$step_count = $this->store->getRun( $run_id )->stepCount;

		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'action' => 'approve' ) ),
			'question park + approval shape'
		);
		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'plan' => array( 'action' => 'accept' ) ) ),
			'question park + plan shape'
		);
	}

	public function test_plan_park_refuses_approval_and_question_shapes(): void {
		$run_id = $this->createRun();
		$this->forceStatus( $run_id, RunStatus::AwaitingPlan );
		$step_count = $this->store->getRun( $run_id )->stepCount;

		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'action' => 'reject' ) ),
			'plan park + approval shape'
		);
		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'answer' => array( 'choice' => 'a' ) ) ),
			'plan park + question shape'
		);
	}

	/**
	 * Shape-correct diagonals for parks whose handlers may not exist yet:
	 * a question park now RESOLVES (S6, stage 3) and a plan park (S7, stage
	 * 4) — a resolution on a park whose context is missing fails explicitly
	 * rather than looping; neither is ever a silent no-op.
	 */
	public function test_shape_correct_question_and_plan_resolves_are_recognized(): void {
		$run_id = $this->createRun();
		$this->forceStatus( $run_id, RunStatus::AwaitingUser );

		// S6 (stage 3): a skip on a question park with NO parked question is a
		// corrupted-state failure — never a mismatch, never a silent no-op.
		$result = $this->runner->tick( $run_id, $this->store->getRun( $run_id )->stepCount, array( 'skip' => true ) );
		$this->assertIsArray( $result, 'the tick returns the (failed) state' );
		$this->assertSame( 'failed', $result['run']['status'], 'missing question context fails the run explicitly' );

		$this->forceStatus( $run_id, RunStatus::AwaitingPlan );
		$result = $this->runner->tick(
			$run_id,
			$this->store->getRun( $run_id )->stepCount,
			array(
				'plan' => array(
					'action' => 'veto',
					'note'   => 'Too broad.',
				),
			)
		);
		$this->assertIsArray( $result, 'the tick returns the (failed) state' );
		$this->assertSame( 'failed', $result['run']['status'], 'missing plan context fails the run explicitly' );
	}

	public function test_a_terminal_run_refuses_a_resolution_instead_of_echoing_its_state(): void {
		$run_id = $this->createRun();
		$this->forceStatus( $run_id, RunStatus::Completed );
		$step_count = $this->store->getRun( $run_id )->stepCount;

		// A finished run answering a decision with its unchanged state reads as
		// "your approval landed" when nothing happened. S5's guard covers it.
		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'action' => 'approve' ) ),
			'completed run + approval shape'
		);

		$this->forceStatus( $run_id, RunStatus::Cancelled );
		$this->assertMismatch(
			$this->runner->tick( $run_id, $step_count, array( 'skip' => true ) ),
			'cancelled run + question shape'
		);

		// A terminal run WITHOUT a resolution still just reports its state.
		$state = $this->runner->tick( $run_id, $step_count, null );
		$this->assertIsArray( $state );
		$this->assertSame( 'cancelled', $state['run']['status'] );
	}

	/**
	 * S7, defence in depth: approving a parked Tier-2 call re-runs it, and the
	 * plan fence must be re-checked on that path too — the accepted plan can be
	 * gone by the time the human clicks Approve.
	 */
	public function test_approving_a_parked_call_still_passes_the_plan_fence(): void {
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => array_merge( $map, array( 'agsafe-smoke/blocked' => 2 ) ),
			20,
			1
		);

		$executions          = 0;
		$ability             = new SenroFlux_Test_Fake_Ability(
			'agsafe-smoke/blocked',
			permission_result: new WP_Error(
				'approval_required',
				'needs a human',
				array(
					'approval_id' => 'apr_1',
					'verb'        => 'agsafe-smoke/blocked',
					'tier'        => 2,
				)
			)
		);
		$ability->on_execute = static function () use ( &$executions ): void {
			++$executions;
		};
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] = $ability;

		$run_id = $this->createRun();
		// An accepted plan covering the verb, so the call reaches the executor
		// and parks for approval in the first place.
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'Do the blocked thing',
				'steps'       => array(
					array(
						'text'  => 'Do it',
						'verbs' => array( 'agsafe-smoke/blocked' ),
						'tier'  => 2,
					),
				),
				'assumptions' => array(),
			),
			'senroflux/propose-plan',
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		$this->gateway->script[] = new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( 'call_1', 'wpab__agsafe-smoke__blocked', array() ) ) ) ),
			10,
			5
		);
		$parked                  = $this->runner->tick( $run_id, $this->store->getRun( $run_id )->stepCount, null );
		$this->assertSame( RunStatus::AwaitingApproval->value, $parked['run']['status'] );

		// The human vetoes the plan elsewhere while the approval sits pending.
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => null ) );

		$this->gateway->script[] = new ModelTurn(
			new ModelMessage( array( new MessagePart( 'Understood.' ) ) ),
			10,
			5
		);
		$this->runner->tick(
			$run_id,
			$this->store->getRun( $run_id )->stepCount,
			array( 'action' => 'approve' )
		);

		$this->assertSame( 0, $executions, 'an approval never bypasses the plan fence' );

		$codes = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::ToolResult === $step->kind ) {
				$codes[] = $step->messageArray['parts'][0]['functionResponse']['response']['error'] ?? null;
			}
		}
		$this->assertContains( 'plan_required', $codes );
	}

	public function test_the_removed_approval_action_parameter_is_a_php_type_error(): void {
		// The 0.1 contract carried the decision as a bare string. The PHP API
		// is typed `?array $resume` (S5), so the old call shape is a TypeError
		// — a loud, unignorable rejection. The HTTP seam's own refusal of the
		// legacy field is covered by tests/Http/ResumeContractTest.php.
		$this->expectException( \TypeError::class );
		senroflux()->tick( 1, 0, 'approve' );
	}
}
