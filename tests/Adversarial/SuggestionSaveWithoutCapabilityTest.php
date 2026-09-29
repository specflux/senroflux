<?php
/**
 * S22 gate robustness suite: a brief suggestion saved by a user who does
 * NOT hold `manage_options` must be refused — the S20 admin-post handler
 * re-checks the capability itself (never trusts a caller), and the
 * suggestion must stay unresolved.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\SiteBrief;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WP_Error;
use wpdb;

final class SuggestionSaveWithoutCapabilityTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_options']         = array();
		unset( $_POST );
		SiteBrief::set( '' );
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
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

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
			array( 'text' => 'Mention our loyalty programme.' ),
			'senroflux/suggest-brief-addition',
			null,
			'ok'
		);

		return $run_id;
	}

	public function test_a_non_manage_options_user_saving_a_suggestion_is_refused_and_the_brief_is_untouched(): void {
		$run_id                              = $this->seedRunnerGraphWithSuggestion();
		$GLOBALS['senroflux_test_user_caps'] = array(
			'manage_options' => false,
			'read'           => true,
		);

		$store      = new WpdbRunStore( $GLOBALS['wpdb'] );
		$suggestion = array_values(
			array_filter( $store->getSteps( $run_id ), static fn ( $step ) => StepKind::Suggestion === $step->kind )
		)[0];

		$screen = new RunsScreen();
		$_POST  = array(
			'run_id'                      => (string) $run_id,
			'seq'                         => (string) $suggestion->seq,
			'senroflux_suggestion_action' => 'save',
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Insufficient permissions.' );

		try {
			$screen->handleSuggestionDecision();
		} finally {
			// Whatever the outer expectation asserts, the brief must never
			// have been written to by the refused attempt.
			$this->assertSame( '', SiteBrief::get(), 'a refused save must never carry the suggestion into the brief' );
		}
	}
}
