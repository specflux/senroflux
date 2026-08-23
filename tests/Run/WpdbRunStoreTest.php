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

		$this->assertSame( 42, $id, 'insert_id from the stub' );

		$insert = $this->db->lastInsert;
		$this->assertNotNull( $insert );
		$this->assertStringEndsWith( 'senroflux_runs', $insert['table'] );
		$this->assertSame( 'pending', $insert['data']['status'] );
		$this->assertSame( 0, $insert['data']['step_count'] );
		$this->assertSame( '["agsafe-smoke\/*"]', $insert['data']['allow_json'] );
		$this->assertSame( 9, json_decode( (string) $insert['data']['budget_json'], true )['max_steps'] );
		$this->assertArrayNotHasKey( 'bogus', (array) json_decode( (string) $insert['data']['budget_json'], true ) );
	}

	public function test_get_run_maps_a_row_into_the_value_object(): void {
		$this->db->rowReturn = $this->runRow();
		$store               = new WpdbRunStore( $this->db );

		$run = $store->getRun( 7 );

		$this->assertNotNull( $run );
		$this->assertSame( 7, $run->id );
		$this->assertSame( RunStatus::AwaitingApproval, $run->status );
		$this->assertSame( array( 'agsafe-smoke/*' ), $run->allow );
		$this->assertSame( 20, $run->budget['max_steps'] );
		$this->assertNull( $run->error );
	}

	public function test_model_message_with_function_call_survives_the_store_round_trip(): void {
		// The exact S4 contract: message_json is toArray(); rehydration is
		// Message::fromArray(). A model turn carrying a function call must come
		// back byte-identical.
		$message_array = (
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_1_0', 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) ) ),
					new MessagePart( 'Let me clear that for you.' ),
				)
			)
		)->toArray();

		$this->db->rowReturn = array(
			'id'           => 11,
			'run_id'       => 7,
			'seq'          => 1,
			'kind'         => StepKind::Model->value,
			'message_json' => (string) wp_json_encode( $message_array ),
			'tool_name'    => null,
			'approval_id'  => null,
			'status'       => 'ok',
			'tokens_in'    => 10,
			'tokens_out'   => 5,
			'duration_ms'  => 120,
			'created_at'   => '2026-08-23 10:01:00',
		);

		// get_results drives getSteps; reuse the same canned row.
		$this->db->resultsReturn = array( $this->db->rowReturn );

		$store = new WpdbRunStore( $this->db );
		$steps = $store->getSteps( 7 );

		$this->assertCount( 1, $steps );
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

	public function test_append_step_claims_the_next_position_from_step_count(): void {
		$this->db->queryReturn = 1;
		$this->db->varReturn   = 5; // step_count after the bump
		$store                 = new WpdbRunStore( $this->db );

		$seq = $store->appendStep(
			7,
			StepKind::Approval,
			null,
			'agsafe-smoke/blocked',
			'apr_xyz',
			'parked'
		);

		$this->assertSame( 5, $seq );

		$insert = $this->db->lastInsert;
		$this->assertNotNull( $insert );
		$this->assertSame( 5, $insert['data']['seq'] );
		$this->assertSame( 'approval', $insert['data']['kind'] );
		$this->assertSame( 'apr_xyz', $insert['data']['approval_id'] );
		$this->assertNull( $insert['data']['message_json'] );

		// The bump + read pair hit the RUNS table (step_count is authoritative).
		$bump = $this->db->queries[0] ?? '';
		$this->assertStringContainsString( 'SET step_count = step_count + 1', $bump );
	}

	public function test_update_run_only_takes_known_columns_and_stamps_updated_at(): void {
		$store = new WpdbRunStore( $this->db );

		$store->updateRun(
			7,
			array(
				'status'     => RunStatus::Failed->value,
				'error_json' => array( 'code' => 'budget_exceeded' ),
				'hacker'     => 'DROP TABLE users',
			)
		);

		$update = $this->db->lastUpdate;
		$this->assertNotNull( $update );
		$this->assertSame( 'failed', $update['data']['status'] );
		$this->assertSame( '{"code":"budget_exceeded"}', $update['data']['error_json'] );
		$this->assertArrayNotHasKey( 'hacker', $update['data'] );
		$this->assertArrayHasKey( 'updated_at', $update['data'] );
	}
}
