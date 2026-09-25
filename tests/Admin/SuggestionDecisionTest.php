<?php
/**
 * RunsScreen::handleSuggestionDecision() tests (0.3 S20): save requires
 * `manage_options` — re-checked in the handler, not only trusted from a
 * caller — and a held capability carries the decision through to
 * SuggestionResolver (stage-16 failable check).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\SiteBrief;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use wpdb;

final class SuggestionDecisionTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_user_caps']       = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_options']         = array();
		unset( $_POST );
	}

	protected function tearDown(): void {
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	private function seedRunnerGraphWithSuggestion(): int {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$store  = new WpdbRunStore( $db );
		$runner = new Runner(
			$store,
			new ToolExecutor(),
			new class() implements \Specflux\SenroFlux\Model\ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools ): \Specflux\SenroFlux\Model\ModelTurn|WP_Error {
					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );

		$run_id = $store->createRun( 1, 'test-consumer', 'Goal', array( '*' ), Budget::defaults() );
		$store->appendStep(
			$run_id,
			StepKind::Suggestion,
			array( 'text' => 'Mention free shipping.' ),
			'senroflux/suggest-brief-addition',
			null,
			'ok'
		);

		return $run_id;
	}

	private function recordingScreen(): RunsScreen {
		return new class() extends RunsScreen {
			public ?int $redirectedRunId    = null;
			public ?string $redirectedError = null;

			protected function redirectBack( int $run_id, ?string $error_code = null ): void {
				$this->redirectedRunId = $run_id;
				$this->redirectedError = $error_code;
			}
		};
	}

	public function test_without_manage_options_the_handler_dies(): void {
		$run_id                              = $this->seedRunnerGraphWithSuggestion();
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => false );

		$screen = $this->recordingScreen();
		$_POST  = array(
			'run_id'                      => (string) $run_id,
			'seq'                         => '1',
			'senroflux_suggestion_action' => 'save',
		);

		$this->expectException( \RuntimeException::class );
		$screen->handleSuggestionDecision();
	}

	public function test_with_manage_options_save_carries_the_suggestion_into_the_brief(): void {
		$run_id = $this->seedRunnerGraphWithSuggestion();

		$store      = new WpdbRunStore( $GLOBALS['wpdb'] );
		$suggestion = array_values(
			array_filter( $store->getSteps( $run_id ), static fn ( $step ) => StepKind::Suggestion === $step->kind )
		)[0];

		$screen = $this->recordingScreen();
		$_POST  = array(
			'run_id'                      => (string) $run_id,
			'seq'                         => (string) $suggestion->seq,
			'senroflux_suggestion_action' => 'save',
		);

		$screen->handleSuggestionDecision();

		$this->assertSame( $run_id, $screen->redirectedRunId );
		$this->assertNull( $screen->redirectedError );
		$this->assertSame( 'Mention free shipping.', SiteBrief::get() );
	}

	public function test_dismiss_never_touches_the_brief(): void {
		$run_id = $this->seedRunnerGraphWithSuggestion();
		SiteBrief::set( 'Untouched.' );

		$store      = new WpdbRunStore( $GLOBALS['wpdb'] );
		$suggestion = array_values(
			array_filter( $store->getSteps( $run_id ), static fn ( $step ) => StepKind::Suggestion === $step->kind )
		)[0];

		$screen = $this->recordingScreen();
		$_POST  = array(
			'run_id'                      => (string) $run_id,
			'seq'                         => (string) $suggestion->seq,
			'senroflux_suggestion_action' => 'dismiss',
		);

		$screen->handleSuggestionDecision();

		$this->assertNull( $screen->redirectedError );
		$this->assertSame( 'Untouched.', SiteBrief::get() );
	}
}
