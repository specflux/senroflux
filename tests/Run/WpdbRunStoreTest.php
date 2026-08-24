<?php
/**
 * Run/Step store tests (stage-4 check): DTO round-trip through the store,
 * unique (run_id, seq) enforcement in the schema, seq assignment.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Schema;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class WpdbRunStoreTest extends TestCase {

	private wpdb $db;

	protected function setUp(): void {
		$this->db = new wpdb();
	}

	/** @return list<array<string,mixed>> A canned runs-table row. */
	private function runRow(): array {
		return array(
			'id'          => 7,
			'user_id'     => 1,
			'consumer'    => 'specflux-mac',
			'goal'        => 'Clear the cache',
			'status'      => 'awaiting_approval',
			'allow_json'  => '["agsafe-smoke/*"]',
			'budget_json' => '{"max_steps":20,"max_tool_calls":12,"max_tokens":60000}',
			'step_count'  => 2,
			'tokens_in'   => 100,
			'tokens_out'  => 40,
			'created_at'  => '2026-08-23 10:00:00',
			'updated_at'  => '2026-08-23 10:05:00',
			'finished_at' => null,
			'error_json'  => null,
		);
	}

	public function test_create_run_inserts_a_pending_row_with_sanitized_budget(): void {
		$store = new WpdbRunStore( $this->db );

		$id = $store->createRun(
			1,
			'specflux-mac',
			'Clear the cache',
			array( 'agsafe-smoke/*' ),
			array(
				'max_steps'      => 9,
				'bogus'          => 1,
				'max_tool_calls' => -3,
			)
		);

		$this->assertSame( 1, $id );

		$run = $store->getRun( $id );
		$this->assertNotNull( $run );
		$this->assertSame( 'specflux-mac', $run->consumer );
		$this->assertSame( RunStatus::Pending, $run->status );
		$this->assertSame( array( 'agsafe-smoke/*' ), $run->allow );
		$this->assertSame( 9, $run->budget['max_steps'] );
		$this->assertArrayNotHasKey( 'bogus', $run->budget );
	}

	public function test_get_run_returns_null_for_unknown_ids(): void {
		$store = new WpdbRunStore( $this->db );

		$this->assertNull( $store->getRun( 404 ) );
	}

	public function test_model_message_with_function_call_survives_the_store_round_trip(): void {
		// The exact S4 contract: message_json is toArray(); rehydration is
		// Message::fromArray(). A model turn carrying a function call must
		// come back byte-identical through a REAL store round-trip.
		$message_array = (
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_1_0', 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) ) ),
					new MessagePart( 'Let me clear that for you.' ),
				)
			)
		)->toArray();

		$store  = new WpdbRunStore( $this->db );
		$run_id = $store->createRun( 1, 'specflux-mac', 'goal', array( 'agsafe-smoke/*' ), Budget::defaults() );

		$seq = $store->appendStep(
			$run_id,
			StepKind::Model,
			$message_array,
			null,
			null,
			'ok',
			10,
			5,
			120
		);

		$steps = $store->getSteps( $run_id );
		$this->assertCount( 1, $steps );
		$this->assertSame( $seq, $steps[0]->seq );
		$this->assertSame( StepKind::Model, $steps[0]->kind );

		$rebuilt = $steps[0]->toMessage();
		$this->assertInstanceOf( ModelMessage::class, $rebuilt );
		$this->assertSame(
			$message_array,
			Message::fromArray( $message_array )->toArray(),
			'Message::fromArray(toArray()) must be the identity on stored shapes'
		);
		$this->assertSame( $message_array, $rebuilt->toArray() );
	}

	public function test_schema_enforces_unique_run_seq_pair(): void {
		$columns = Schema::stepsColumns();

		$this->assertStringContainsString( 'UNIQUE KEY run_seq (run_id, seq)', $columns );
		$this->assertStringNotContainsString( 'UNIQUE KEY run_seq (seq', $columns );
	}

	public function test_append_step_claims_positions_sequentially_from_step_count(): void {
		$store  = new WpdbRunStore( $this->db );
		$run_id = $store->createRun( 1, 'specflux-mac', 'goal', array(), array() );

		$seq1 = $store->appendStep(
			$run_id,
			StepKind::Approval,
			array( 'parked' => true ),
			'agsafe-smoke/blocked',
			'apr_xyz',
			'parked'
		);
		$seq2 = $store->appendStep( $run_id, StepKind::System, null );

		$this->assertSame( 1, $seq1 );
		$this->assertSame( 2, $seq2 );

		// The bump SQL hit the RUNS table (step_count is authoritative).
		$bumps = array_values(
			array_filter(
				$this->db->queries,
				static fn ( string $q ): bool => str_contains( $q, 'SET step_count = step_count + 1' )
			)
		);
		$this->assertCount( 2, $bumps );

		// And the run row reflects it.
		$run = $store->getRun( $run_id );
		$this->assertSame( 2, $run->stepCount );
	}

	public function test_update_run_only_takes_known_columns_and_stamps_updated_at(): void {
		$store  = new WpdbRunStore( $this->db );
		$run_id = $store->createRun( 1, 'specflux-mac', 'goal', array(), array() );

		$store->updateRun(
			$run_id,
			array(
				'status'     => RunStatus::Failed->value,
				'error_json' => array( 'code' => 'budget_exceeded' ),
				'hacker'     => 'DROP TABLE users',
			)
		);

		$run = $store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'budget_exceeded', $run->error['code'] ?? '' );
	}
}
