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
	 * The shape-correct diagonals for the two parks whose HANDLERS land in
	 * stages 3/4: accepted as matching (NOT a mismatch), held by an explicit
	 * placeholder until S6/S7 fill the behaviour in.
	 */
	public function test_shape_correct_question_and_plan_resolves_are_recognized(): void {
		$run_id = $this->createRun();
		$this->forceStatus( $run_id, RunStatus::AwaitingUser );

		$result = $this->runner->tick( $run_id, $this->store->getRun( $run_id )->stepCount, array( 'skip' => true ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'resume_mismatch', $result->get_error_code(), 'a skip on a question park is not a mismatch' );

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
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'resume_mismatch', $result->get_error_code(), 'a veto on a plan park is not a mismatch' );
	}

	public function test_the_removed_approval_action_parameter_is_refused_at_the_http_seam(): void {
		// The 0.1 contract carried the decision as a bare string. The PHP API
		// is typed `?array $resume` (S5), so the old call shape is a TypeError
		// — a loud, unignorable rejection.
		$this->expectException( \TypeError::class );
		senroflux()->tick( 1, 0, 'approve' );
	}
}
