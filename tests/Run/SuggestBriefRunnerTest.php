<?php
/**
 * Runner::tick() tests for the S20 suggest-brief-addition harness tool: it
 * parks nothing, is accepted at most 3 times per run, and refuses a repeat
 * of a dismissed suggestion — against a mocked gateway (stage-16 failable
 * check).
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
use Specflux\SenroFlux\Run\SuggestionResolver;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\SuggestBriefTool;
use Specflux\SenroFlux\Tools\ToolExecutor;
use SenroFlux_Test_Fake_Ability;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class SuggestBriefRunnerTest extends TestCase {

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
			'agsafe-smoke/read' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/read', permission_result: true, execute_result: array( 'ok' => true ) ),
		);
		add_filter( 'senroflux_verb_map', static fn (): array => array( 'agsafe-smoke/read' => 0 ), 10, 0 );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
	}

	private function createRun(): int {
		return $this->store->createRun( 1, 'test-consumer', 'Clear the cache', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	private static function suggestTurn( string $call_id, string $text ): ModelTurn {
		return new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( $call_id, SuggestBriefTool::FUNCTION_NAME, array( 'text' => $text ) ) ) ) ),
			10,
			5
		);
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	/** A park-forcing ask-user turn, used purely to create a tick boundary. */
	private static function askTurn( string $call_id ): ModelTurn {
		return new ModelTurn(
			new ModelMessage(
				array(
					new MessagePart(
						new FunctionCall(
							$call_id,
							HarnessTools::FUNCTION_NAME,
							array(
								'text'      => 'Continue?',
								'rationale' => 'Checkpoint.',
							)
						)
					),
				)
			),
			10,
			5
		);
	}

	public function test_a_valid_suggestion_is_stored_as_a_suggestion_step_and_parks_nothing(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::suggestTurn( 'call_1', 'Mention free shipping.' );
		$this->gateway->script[] = self::textTurn( 'Noted.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'the suggestion never parks the run' );

		$suggestion_steps = array_values(
			array_filter(
				$this->store->getSteps( $run_id ),
				static fn ( $step ) => StepKind::Suggestion === $step->kind
			)
		);
		$this->assertCount( 1, $suggestion_steps );
		$this->assertSame( 'Mention free shipping.', $suggestion_steps[0]->messageArray['text'] ?? null );
		$this->assertSame( 'ok', $suggestion_steps[0]->status );
	}

	public function test_the_fourth_suggestion_in_a_run_is_refused_with_suggestion_limit(): void {
		$run_id = $this->createRun();

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->gateway->script[] = self::suggestTurn( "call_{$i}", "Suggestion {$i}." );
		}
		// The 4th call, then a final text turn so the run completes.
		$this->gateway->script[] = self::suggestTurn( 'call_4', 'Suggestion 4.' );
		$this->gateway->script[] = self::textTurn( 'Done.' );

		// One call per tick: drive 5 ticks (goal + 4 suggestion turns + final text turn).
		$expected = 0;
		for ( $i = 0; $i < 6; $i++ ) {
			$result = $this->runner->tick( $run_id, $expected, null );
			if ( ! is_array( $result ) ) {
				break;
			}
			$expected = $result['run']['step_count'];
			if ( 'completed' === $result['run']['status'] ) {
				break;
			}
		}

		$suggestion_steps = array_values(
			array_filter(
				$this->store->getSteps( $run_id ),
				static fn ( $step ) => StepKind::Suggestion === $step->kind
			)
		);
		$this->assertCount( 3, $suggestion_steps, 'only 3 suggestions are ever accepted' );

		$error_results = array_values(
			array_filter(
				$this->store->getSteps( $run_id ),
				static fn ( $step ) => StepKind::ToolResult === $step->kind
					&& SuggestBriefTool::toolName() === $step->toolName
					&& 'error' === $step->status
			)
		);
		$this->assertNotEmpty( $error_results, 'the 4th call gets a tool_result error' );

		$found_limit = false;
		foreach ( $error_results as $step ) {
			foreach ( (array) ( $step->messageArray['parts'] ?? array() ) as $part ) {
				if ( ( $part['functionResponse']['response']['error'] ?? null ) === SuggestBriefTool::ERROR_SUGGESTION_LIMIT ) {
					$found_limit = true;
				}
			}
		}
		$this->assertTrue( $found_limit, 'the 4th suggestion is refused with suggestion_limit' );
	}

	public function test_a_suggestion_matching_a_dismissed_one_is_refused_with_suggestion_dismissed(): void {
		$run_id = $this->createRun();

		// Suggestion 1, then an ask-user park purely as a tick BOUNDARY (a
		// tick drives to completion or a park in one go; the dismiss decision
		// is a human action that happens BETWEEN ticks in real use).
		$this->gateway->script[] = self::suggestTurn( 'call_1', 'Free shipping over $50.' );
		$this->gateway->script[] = self::askTurn( 'call_ask' );
		$this->gateway->script[] = self::suggestTurn( 'call_2', '  FREE SHIPPING OVER $50.  ' );
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );
		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_user', $result['run']['status'] );

		// Dismiss suggestion 1 (seq of the first Suggestion step).
		$suggestion_steps = array_values(
			array_filter( $this->store->getSteps( $run_id ), static fn ( $step ) => StepKind::Suggestion === $step->kind )
		);
		$this->assertNotEmpty( $suggestion_steps );
		$first_seq = $suggestion_steps[0]->seq;

		$resolved = SuggestionResolver::resolve( $this->store, $run_id, $first_seq, 'dismiss', null );
		$this->assertIsArray( $resolved );

		// Resume the park (skip) and drive the rest of the run (suggestion 2
		// = same text, different case/whitespace, then completion). The
		// dismiss decision appended a system note (a real step), so the
		// optimistic-lock echo must be refreshed from the store, not the
		// pre-dismiss $result.
		$expected = (int) $this->store->getRun( $run_id )->stepCount;
		$resume   = array( 'skip' => true );
		while ( true ) {
			$result = $this->runner->tick( $run_id, $expected, $resume );
			$resume = null;
			$this->assertIsArray( $result );
			$expected = $result['run']['step_count'];
			if ( 'completed' === $result['run']['status'] ) {
				break;
			}
		}

		$error_results = array_values(
			array_filter(
				$this->store->getSteps( $run_id ),
				static fn ( $step ) => StepKind::ToolResult === $step->kind
					&& SuggestBriefTool::toolName() === $step->toolName
					&& 'error' === $step->status
			)
		);

		$found_dismissed = false;
		foreach ( $error_results as $step ) {
			foreach ( (array) ( $step->messageArray['parts'] ?? array() ) as $part ) {
				if ( ( $part['functionResponse']['response']['error'] ?? null ) === SuggestBriefTool::ERROR_SUGGESTION_DISMISSED ) {
					$found_dismissed = true;
				}
			}
		}
		$this->assertTrue( $found_dismissed, 'a normalised repeat of a dismissed suggestion is refused' );
	}
}
