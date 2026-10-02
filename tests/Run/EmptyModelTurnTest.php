<?php
/**
 * Runner::tick() tests for an EMPTY model turn (no text, no function calls).
 *
 * Live proof shakedown (journey J6): after a refused `propose-plan` the model
 * answered `{"role":"model","parts":[]}` and the run was marked `completed`
 * with nothing written. An empty turn is never the model finishing: the
 * Runner nudges once, and a second empty turn fails the run with a reason
 * the Runs screen can show. A turn with TEXT but no calls still completes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use wpdb;

final class EmptyModelTurnTest extends TestCase {

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
		$GLOBALS['senroflux_test_abilities']       = array();
	}

	/** @param array<string,int> $budget_overrides */
	private function createRun( array $budget_overrides = array() ): int {
		return $this->store->createRun( 1, 'test-consumer', 'Publish three pages', array( 'agsafe-smoke/*' ), array_merge( Budget::defaults(), $budget_overrides ) );
	}

	private function stepCount( int $run_id ): int {
		return (int) $this->store->getRun( $run_id )->stepCount;
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	private static function emptyTurn(): ModelTurn {
		return new ModelTurn( new ModelMessage( array() ), 10, 0 );
	}

	public function test_an_empty_turn_is_nudged_once_and_the_run_keeps_going(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::emptyTurn();
		$this->gateway->script[] = self::textTurn( 'Done: nothing more to do.' );

		$first = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'running', $first['run']['status'], 'an empty turn must not complete the run' );
		$this->assertSame( array( 'user', 'model', 'system', 'user' ), array_column( $first['new_steps'], 'kind' ), 'seeded goal, the empty turn, then the nudge is recorded as visible steps' );
		$this->assertSame( 'empty_turn_nudge', $first['new_steps'][2]['message']['note'] ?? null );

		$second = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $second['run']['status'], 'the model continued after the nudge' );
		$this->assertCount( 2, $this->gateway->calls );
		$last_history = end( $this->gateway->histories );
		$this->assertSame( 'user', $last_history[ count( $last_history ) - 1 ]->getRole()->value, 'the next turn is sent after a user nudge' );
	}

	public function test_a_second_empty_turn_fails_the_run_with_a_reason(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::emptyTurn();
		$this->gateway->script[] = self::emptyTurn();

		$this->runner->tick( $run_id, 0, null );
		$second = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'failed', $second['run']['status'] );
		$this->assertSame( 'empty_model_turn', $second['run']['error']['code'] ?? null );
		$this->assertNotSame( '', (string) ( $second['run']['error']['message'] ?? '' ), 'a human-readable reason rides on the run' );
		$this->assertCount( 2, $this->gateway->calls, 'exactly one nudge: no third model call' );
	}

	public function test_a_text_only_turn_still_completes(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'All finished.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( array( 'user', 'model' ), array_column( $result['new_steps'], 'kind' ) );
	}

	public function test_the_nudge_counts_against_the_step_budget(): void {
		$run_id                  = $this->createRun( array( 'max_steps' => 4 ) );
		$this->gateway->script[] = self::emptyTurn();
		$this->gateway->script[] = self::textTurn( 'unreachable: the budget is spent' );

		$this->runner->tick( $run_id, 0, null );
		$this->assertSame( 4, $this->stepCount( $run_id ), 'goal + empty model turn + system note + user nudge' );

		$second = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'failed', $second['run']['status'] );
		$this->assertSame( 'budget_exceeded', $second['run']['error']['code'] ?? null );
		$this->assertCount( 1, $this->gateway->calls, 'the spent budget stops the run before another model call' );
	}

	public function test_an_empty_turn_is_never_resent_to_the_model_as_history(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::emptyTurn();
		$this->gateway->script[] = self::textTurn( 'Carrying on.' );

		$this->runner->tick( $run_id, 0, null );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		foreach ( end( $this->gateway->histories ) as $message ) {
			$this->assertNotSame( array(), $message->getParts(), 'a zero-part message is rejected by some providers' );
		}
		$kinds = array_map( static fn ( $s ) => $s->kind, $this->store->getSteps( $run_id ) );
		$this->assertContains( StepKind::Model, $kinds, 'the empty turn stays in the audit trail' );
	}
}
