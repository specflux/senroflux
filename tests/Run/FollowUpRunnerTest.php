<?php
/**
 * Runner::tick() tests for the S20 follow-up seed: the first user turn
 * carries the source run's own harness-built change rows, and a follow-up
 * of a follow-up never chains through the intermediate run's seed text
 * (stage-16 failable check).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use wpdb;

final class FollowUpRunnerTest extends TestCase {

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
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	private function finishRun( int $run_id, array $changes ): void {
		$this->store->updateRun(
			$run_id,
			array(
				'status'      => RunStatus::Completed->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'result_json' => array(
					'summary' => 'Done.',
					'changes' => $changes,
				),
			)
		);
	}

	public function test_the_first_user_turn_carries_the_sources_change_rows(): void {
		$source_id = $this->store->createRun( 1, 'test-consumer', 'Original goal', array( '*' ), Budget::defaults() );
		$this->finishRun(
			$source_id,
			array(
				array(
					'object_type' => 'post',
					'object_id'   => '42',
					'title'       => 'Pricing',
					'status'      => 'publish',
					'edit_url'    => 'https://example.test/edit/42',
					'verified'    => true,
				),
			)
		);

		$follow_up_id = $this->store->createRun(
			1,
			'test-consumer',
			'Now update the pricing copy',
			array( '*' ),
			Budget::defaults(),
			null,
			null,
			null,
			\Specflux\SenroFlux\Run\GateMode::AgentSafety,
			array(),
			$source_id
		);

		$this->gateway->script[] = self::textTurn( 'All done.' );
		$result                  = $this->runner->tick( $follow_up_id, 0, null );

		$this->assertIsArray( $result );
		$goal_text = $result['new_steps'][0]['message']['parts'][0]['text'] ?? '';

		$this->assertStringContainsString(
			'An earlier run touched these objects. Re-read any object before you change it.',
			$goal_text
		);
		$this->assertStringContainsString( '- post 42: Pricing (publish) — https://example.test/edit/42', $goal_text );
		$this->assertStringContainsString( 'Now update the pricing copy', $goal_text );

		// The seed precedes the goal.
		$this->assertLessThan(
			strpos( $goal_text, 'Now update the pricing copy' ),
			strpos( $goal_text, 'An earlier run touched' )
		);
	}

	public function test_a_follow_up_with_no_source_changes_carries_no_seed(): void {
		$source_id = $this->store->createRun( 1, 'test-consumer', 'Original goal', array( '*' ), Budget::defaults() );
		$this->finishRun( $source_id, array() );

		$follow_up_id = $this->store->createRun(
			1,
			'test-consumer',
			'Plain goal',
			array( '*' ),
			Budget::defaults(),
			null,
			null,
			null,
			\Specflux\SenroFlux\Run\GateMode::AgentSafety,
			array(),
			$source_id
		);

		$this->gateway->script[] = self::textTurn( 'All done.' );
		$result                  = $this->runner->tick( $follow_up_id, 0, null );

		$goal_text = $result['new_steps'][0]['message']['parts'][0]['text'] ?? '';
		$this->assertSame( 'Plain goal', $goal_text );
	}

	/**
	 * NO CHAINING (S20): a follow-up of a follow-up seeds from the MIDDLE
	 * run's own `result.changes` only — never from the middle run's seed
	 * text (which itself carried the FIRST run's changes), and never
	 * accumulating both.
	 */
	public function test_no_chaining_through_an_intermediate_follow_up(): void {
		$run_a = $this->store->createRun( 1, 'test-consumer', 'Goal A', array( '*' ), Budget::defaults() );
		$this->finishRun(
			$run_a,
			array(
				array(
					'object_type' => 'post',
					'object_id'   => '1',
					'title'       => 'Object A',
					'status'      => 'publish',
					'edit_url'    => null,
					'verified'    => true,
				),
			)
		);

		$run_b = $this->store->createRun(
			1,
			'test-consumer',
			'Goal B',
			array( '*' ),
			Budget::defaults(),
			null,
			null,
			null,
			\Specflux\SenroFlux\Run\GateMode::AgentSafety,
			array(),
			$run_a
		);
		// B's OWN changes are DIFFERENT objects from A's.
		$this->finishRun(
			$run_b,
			array(
				array(
					'object_type' => 'post',
					'object_id'   => '2',
					'title'       => 'Object B',
					'status'      => 'publish',
					'edit_url'    => null,
					'verified'    => true,
				),
			)
		);

		$run_c = $this->store->createRun(
			1,
			'test-consumer',
			'Goal C',
			array( '*' ),
			Budget::defaults(),
			null,
			null,
			null,
			\Specflux\SenroFlux\Run\GateMode::AgentSafety,
			array(),
			$run_b
		);

		$this->gateway->script[] = self::textTurn( 'All done.' );
		$result                  = $this->runner->tick( $run_c, 0, null );

		$goal_text = $result['new_steps'][0]['message']['parts'][0]['text'] ?? '';

		// C's seed carries B's own changes...
		$this->assertStringContainsString( 'Object B', $goal_text );
		// ...and NOT A's — chaining through B's seed (or through A directly)
		// would leak "Object A" into C's first turn.
		$this->assertStringNotContainsString( 'Object A', $goal_text );
	}
}
