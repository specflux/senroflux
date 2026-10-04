<?php
/**
 * Runner::tick() tests for a TEXT-ONLY model turn before any write.
 *
 * Live proof shakedown (S9, "Design a new site"): after two ask-user
 * questions the model replied with narration only ("…so I'll write around it.
 * Saving a shorter brief addition.") and no function call. A text-only turn is
 * the final answer, so the run ended `completed` having built nothing. Until
 * a write has landed the Runner nudges ONCE per run; a repeat is a deliberate
 * stop and completes as before, and after any write a text-only turn is the
 * final answer straight away.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use SenroFlux_Test_Fake_Ability;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class TextOnlyTurnTest extends TestCase {

	private const NARRATION = 'The answer repeated the hours, so I will write around it. Saving a shorter brief addition.';

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_abilities']       = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: true,
				execute_result: array( 'id' => 42 )
			),
			'agsafe-smoke/read'  => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/read',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
		);

		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'agsafe-smoke/read'  => VerbTier::TIER_0,
				'agsafe-smoke/write' => VerbTier::TIER_1,
			),
			10,
			1
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	/** @param array<string,int> $budget_override Keys to merge over Budget::defaults(). */
	private function createRun( array $budget_override = array() ): int {
		$run_id = $this->store->createRun( 1, 'test-consumer', 'Build the site', array( 'agsafe-smoke/*' ), array_merge( Budget::defaults(), $budget_override ) );

		$this->store->appendStep( $run_id, StepKind::User, ( new UserMessage( array( new MessagePart( 'Build the site' ) ) ) )->toArray() );
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'Write the page',
				'steps'       => array(
					array(
						'text'  => 'Write it',
						'verbs' => array( 'agsafe-smoke/write' ),
						'tier'  => VerbTier::TIER_1,
					),
				),
				'assumptions' => array(),
			),
			'senroflux/propose-plan',
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		return $run_id;
	}

	private function stepCount( int $run_id ): int {
		return $this->store->getRun( $run_id )->stepCount;
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	private static function callTurn( string $call_id, string $name, array $args ): ModelTurn {
		return new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( $call_id, $name, $args ) ) ) ),
			10,
			5
		);
	}

	private static function writeTurn(): ModelTurn {
		return self::callTurn( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) );
	}

	private static function readBackTurn(): ModelTurn {
		return self::callTurn( 'call_r', 'wpab__agsafe-smoke__read', array( 'id' => 42 ) );
	}

	private function nudgeCount( int $run_id ): int {
		$count = 0;
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::System === $step->kind && 'text_only_nudge' === ( $step->messageArray['note'] ?? null ) ) {
				++$count;
			}
		}

		return $count;
	}

	public function test_a_text_only_turn_before_any_write_is_nudged_and_the_next_tool_call_runs(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( self::NARRATION );
		$this->gateway->script[] = self::writeTurn();
		$this->gateway->script[] = self::readBackTurn();
		$this->gateway->script[] = self::textTurn( 'Wrote the page.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 4, $this->gateway->calls, 'the nudge sends the loop straight back to the model' );
		$this->assertSame( 1, $this->nudgeCount( $run_id ) );
		$this->assertArrayHasKey( '42', $this->store->getRun( $run_id )->objects ?? array(), 'the tool call after the nudge executed' );

		$last_history = $this->gateway->histories[1];
		$nudge        = $last_history[ count( $last_history ) - 1 ];
		$this->assertSame( 'user', $nudge->getRole()->value, 'the prompt ends on the nudge, a user turn' );
		$this->assertSame(
			'You replied without calling a tool and nothing has been written yet. If the work is not finished, call the next tool now. If you are deliberately stopping without changing anything, reply again with your final summary.',
			$nudge->getParts()[0]->getText()
		);
	}

	public function test_a_second_text_only_turn_completes_the_run(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( self::NARRATION );
		$this->gateway->script[] = self::textTurn( 'Wrong site, so I did not write anything.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $result['run']['status'], 'a deliberate stop is still allowed to end the run' );
		$this->assertCount( 2, $this->gateway->calls );
		$this->assertSame( 1, $this->nudgeCount( $run_id ) );
	}

	public function test_a_text_only_turn_after_a_write_completes_without_a_nudge(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::writeTurn();
		$this->gateway->script[] = self::readBackTurn();
		$this->gateway->script[] = self::textTurn( 'Wrote the page.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 3, $this->gateway->calls );
		$this->assertSame( 0, $this->nudgeCount( $run_id ) );
	}

	public function test_a_failed_write_does_not_count_as_a_write(): void {
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/write'] = new SenroFlux_Test_Fake_Ability(
			'agsafe-smoke/write',
			permission_result: true,
			execute_result: new \WP_Error( 'boom', 'The write failed.' )
		);
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::writeTurn();
		$this->gateway->script[] = self::textTurn( 'It failed, giving up.' );
		$this->gateway->script[] = self::textTurn( 'It failed, giving up.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( 1, $this->nudgeCount( $run_id ), 'nothing was written, so the first text-only turn is nudged' );
	}

	public function test_the_nudge_is_sent_once_per_run_across_ticks(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( self::NARRATION );
		$this->gateway->script[] = self::textTurn( 'Stopping here.' );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Running->value ) );
		$this->gateway->script[] = self::textTurn( 'Still stopping.' );
		$result                  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 3, $this->gateway->calls, 'the later tick made one model call and was not nudged again' );
		$this->assertSame( 1, $this->nudgeCount( $run_id ) );
	}

	public function test_a_question_park_is_not_nudged_and_the_answer_resumes_normally(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new ModelTurn(
			new ModelMessage(
				array(
					new MessagePart( 'I need one fact first.' ),
					new MessagePart(
						new FunctionCall(
							'call_q',
							HarnessTools::FUNCTION_NAME,
							array(
								'text'      => 'What are your opening hours?',
								'rationale' => 'The page lists them.',
							)
						)
					),
				)
			),
			10,
			5
		);

		$parked = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'awaiting_user', $parked['run']['status'] );
		$this->assertSame( 0, $this->nudgeCount( $run_id ), 'a parked question is not a text-only turn' );

		$this->gateway->script[] = self::writeTurn();
		$this->gateway->script[] = self::readBackTurn();
		$this->gateway->script[] = self::textTurn( 'Wrote the page.' );
		$result                  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), array( 'answer' => array( 'text' => '9 to 5' ) ) );

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( 0, $this->nudgeCount( $run_id ) );
	}

	public function test_the_nudge_still_counts_against_the_step_budget(): void {
		$run_id                  = $this->createRun( array( 'max_steps' => 5 ) );
		$this->gateway->script[] = self::textTurn( self::NARRATION );
		$this->gateway->script[] = self::textTurn( 'unreachable: the budget is spent' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'budget_exceeded', $result['run']['error']['code'] ?? null );
		$this->assertCount( 1, $this->gateway->calls, 'user + plan + model + system note + nudge fills the budget before another call' );
	}
}
