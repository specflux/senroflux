<?php
/**
 * Old model turns drop bulky structured call arguments from the resent history.
 *
 * @package Specflux\SenroFlux\Tests\Run
 */

declare(strict_types=1);

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use wpdb;

final class HistoryCompactionTest extends TestCase {

	/**
	 * @param array<string,mixed> $message       Stored model message.
	 * @param array<string,true>  $protected_ids Call ids {@see compactModelMessage()} must never trim.
	 * @return array<string,mixed>
	 */
	private static function compact( array $message, bool $trim_args, array $protected_ids = array() ): array {
		$method = new ReflectionMethod( Runner::class, 'compactModelMessage' );

		return $method->invoke( null, $message, $trim_args, $protected_ids );
	}

	/**
	 * Live batch 2026-09-29-final scenario 4-1: nine refused page payloads
	 * (`sections`, ~22k characters, every string under the per-string cap)
	 * were resent on every later turn until max_tokens.
	 */
	public function test_a_bulky_sections_array_in_an_old_turn_is_left_out(): void {
		$sections = array_fill(
			0,
			12,
			array(
				'layout'     => 'text',
				'heading'    => 'What happens at your first visit',
				'paragraphs' => array( str_repeat( 'We assess how you move. ', 12 ) ),
			)
		);
		$message  = array(
			'role'  => 'model',
			'parts' => array(
				array(
					'channel'      => 'content',
					'type'         => 'function_call',
					'functionCall' => array(
						'id'   => 'call_1',
						'name' => 'wpab__senroflux__publish-post',
						'args' => array(
							'id'       => 19,
							'sections' => $sections,
						),
					),
				),
			),
		);

		$old  = self::compact( $message, true );
		$args = $old['parts'][0]['functionCall']['args'];

		$this->assertSame( 19, $args['id'], 'small arguments stay' );
		$this->assertIsString( $args['sections'] );
		$this->assertStringContainsString( 'left out of the history', $args['sections'] );

		$recent = self::compact( $message, false );
		$this->assertSame( $sections, $recent['parts'][0]['functionCall']['args']['sections'], 'recent turns keep the full payload' );
	}

	/**
	 * A protected call id is never trimmed, even in an old turn that would
	 * otherwise be trimmed — the seam {@see Runner::historyForPrompt()} uses
	 * to keep a write ability's latest attempt visible in full.
	 */
	public function test_a_protected_call_id_keeps_its_full_args_even_in_an_old_turn(): void {
		$sections = array_fill(
			0,
			12,
			array(
				'layout'     => 'text',
				'paragraphs' => array( str_repeat( 'We assess how you move. ', 12 ) ),
			)
		);
		$message  = array(
			'role'  => 'model',
			'parts' => array(
				array(
					'channel'      => 'content',
					'type'         => 'function_call',
					'functionCall' => array(
						'id'   => 'call_1',
						'name' => 'wpab__senroflux__create-post',
						'args' => array( 'sections' => $sections ),
					),
				),
			),
		);

		$trimmed   = self::compact( $message, true );
		$protected = self::compact( $message, true, array( 'call_1' => true ) );

		$this->assertIsString( $trimmed['parts'][0]['functionCall']['args']['sections'], 'unprotected: trimmed as before' );
		$this->assertSame( $sections, $protected['parts'][0]['functionCall']['args']['sections'], 'protected: full payload survives trimming' );
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-1-1 step 115: the model's
	 * `create-post` attempt fell out of the last-{@see Runner::HISTORY_FULL_ARGS_TURNS}
	 * window after two unrelated tool calls, got its bulky `sections` argument
	 * replaced by the history-compaction placeholder on the next resend — and
	 * the model echoed that placeholder straight back as its next `sections`
	 * value. The fix: a write ability's MOST RECENT call keeps its full
	 * arguments however old its turn is, so the model can always see what it
	 * actually sent last.
	 */
	public function test_the_latest_create_post_call_survives_history_compaction_across_later_turns(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$store           = new WpdbRunStore( $db );
		$run_id          = $store->createRun( 1, 'test-consumer', 'Publish a page', array( 'agsafe-smoke/*' ), Budget::defaults() );

		$sections = array_fill(
			0,
			12,
			array(
				'layout'     => 'text',
				'paragraphs' => array( str_repeat( 'We assess how you move. ', 12 ) ),
			)
		);

		// Turn 1: create-post with a bulky `sections` payload.
		$store->appendStep(
			$run_id,
			StepKind::Model,
			array(
				'role'  => 'model',
				'parts' => array(
					array(
						'channel'      => 'content',
						'type'         => 'function_call',
						'functionCall' => array(
							'id'   => 'call_create',
							'name' => 'wpab__senroflux__create-post',
							'args' => array( 'sections' => $sections ),
						),
					),
				),
			)
		);
		$store->appendStep(
			$run_id,
			StepKind::ToolResult,
			array(
				'role'  => 'user',
				'parts' => array(),
			),
			'wpab__senroflux__create-post',
			null,
			'error'
		);

		// Two unrelated model+tool_result turns push turn 1 out of the
		// last-HISTORY_FULL_ARGS_TURNS window.
		foreach ( array( 'call_2', 'call_3' ) as $call_id ) {
			$store->appendStep(
				$run_id,
				StepKind::Model,
				array(
					'role'  => 'model',
					'parts' => array(
						array(
							'channel'      => 'content',
							'type'         => 'function_call',
							'functionCall' => array(
								'id'   => $call_id,
								'name' => 'wpab__senroflux__read-post',
								'args' => array( 'id' => 1 ),
							),
						),
					),
				)
			);
			$store->appendStep(
				$run_id,
				StepKind::ToolResult,
				array(
					'role'  => 'user',
					'parts' => array(),
				),
				'wpab__senroflux__read-post',
				null,
				'ok'
			);
		}

		$runner = new Runner( $store, new ToolExecutor(), new FakeGateway(), new RecordingBridge() );
		$run    = $store->getRun( $run_id );

		$method   = new ReflectionMethod( Runner::class, 'historyForPrompt' );
		$messages = $method->invoke( $runner, $run );

		$found = false;
		foreach ( $messages as $message ) {
			foreach ( $message->toArray()['parts'] ?? array() as $part ) {
				if ( 'call_create' === ( $part['functionCall']['id'] ?? null ) ) {
					$found = true;
					$this->assertSame(
						$sections,
						$part['functionCall']['args']['sections'] ?? null,
						'the last create-post call keeps its full sections payload however old its turn is'
					);
				}
			}
		}
		$this->assertTrue( $found, 'the create-post call must still be present in the resent history' );
	}
}
