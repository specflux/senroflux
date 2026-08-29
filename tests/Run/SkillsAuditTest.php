<?php
/**
 * Runner-level skills integration tests (stage-2 check, 0.2 S8): the seq-0
 * instruction record, the skills_changed drift note, the ceiling failure,
 * and the start-time snapshot.
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
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use wpdb;

final class SkillsAuditTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		$this->db      = new wpdb();
		$this->store   = new WpdbRunStore( $this->db );
		$this->gateway = new FakeGateway();
		$this->runner  = new Runner( $this->store, new ToolExecutor(), $this->gateway, new \Specflux\SenroFlux\Approval\ApprovalBridge() );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_abilities']       = array();
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_run_skills' );
		remove_all_filters( 'senroflux_system_instruction' );
		remove_all_filters( 'senroflux_skills_max_tokens' );
	}

	private function createRun(): int {
		return $this->store->createRun( 1, 'test-consumer', 'Clear the cache', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	private function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	public function test_first_tick_writes_the_seq0_instruction_record(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = $this->textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );

		$steps = $this->store->getSteps( $run_id );
		$seq0  = $steps[0] ?? null;
		$this->assertNotNull( $seq0 );
		$this->assertSame( 0, $seq0->seq, 'the instruction record is seq 0' );
		$this->assertSame( StepKind::System, $seq0->kind );

		$payload = $seq0->messageArray ?? array();
		$this->assertSame( 'system_instruction', $payload['note'] ?? '' );
		$this->assertArrayHasKey( 'skills', $payload );
		$this->assertArrayHasKey( 'harness/identity', $payload['skills'] );

		// Seq 0 does NOT shift the optimistic lock: the goal user step is seq 1.
		$this->assertSame( 1, $steps[1]->seq );
		$this->assertSame( 2, $this->store->getRun( $run_id )->stepCount, 'seq0 + user step... minus seq0 itself' );

		// The model received the rendered instruction, harness skill first.
		$this->assertStringContainsString( '# Identity', $this->gateway->systemInstructions[0] ?? '' );
	}

	public function test_skills_drift_appends_a_skills_changed_note(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = $this->textTurn( 'Turn one.' );
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->script = array( $this->textTurn( 'Turn two.' ) );

		// The first turn completed the run; put it back in the driving state
		// (a fresh consumer tick on an owned non-terminal run) so the second
		// render happens against the drifted skill set.
		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Running->value ) );

		// A consumer adds a skill between ticks: the fingerprint drifts.
		add_filter(
			'senroflux_run_skills',
			static fn ( array $skills ): array => array_merge(
				$skills,
				array( new Skill( 'consumer/extra', 'Extra', 'Extra guidance.', false, SkillSource::Consumer ) )
			),
			10,
			1
		);

		$step_count = $this->store->getRun( $run_id )->stepCount;
		$result     = $this->runner->tick( $run_id, $step_count, null );

		$this->assertIsArray( $result );

		$notes = array_filter(
			$this->store->getSteps( $run_id ),
			static fn ( $step ): bool => StepKind::System === $step->kind
				&& 'skills_changed' === ( $step->messageArray['note'] ?? '' )
		);
		$this->assertCount( 1, $notes, 'exactly one skills_changed note' );

		$note = array_values( $notes )[0];
		$this->assertContains( 'consumer/extra', $note->messageArray['ids'] ?? array() );

		// The run CONTINUES with the new text: the second model call got it.
		$this->assertStringContainsString( 'Extra guidance.', $this->gateway->systemInstructions[1] ?? '' );
	}

	public function test_a_ceiling_breach_fails_the_run_without_truncating(): void {
		$run_id = $this->createRun();
		add_filter(
			'senroflux_skills_max_tokens',
			static fn (): int => 1,
			10,
			0
		);
		$this->gateway->script[] = $this->textTurn( 'never reached' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'skills_too_large', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 0, $this->gateway->calls, 'no model call on a failed render' );

		// The harness skills' bodies are untouched (never truncated).
		foreach ( \Specflux\SenroFlux\Skills\SkillSet::harnessSkills() as $skill ) {
			$this->assertGreaterThan( 40, strlen( $skill->body ) );
		}
	}

	public function test_the_post_render_filter_reshapes_the_final_string(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = $this->textTurn( 'Done.' );
		add_filter(
			'senroflux_system_instruction',
			static fn ( string $text ): string => $text . "\nHOST OVERRIDE",
			10,
			1
		);

		$this->runner->tick( $run_id, 0, null );

		$this->assertStringEndsWith( 'HOST OVERRIDE', $this->gateway->systemInstructions[0] ?? '' );
	}
}
