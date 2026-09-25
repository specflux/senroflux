<?php
/**
 * Plugin-level tests for 0.3 S3: a third-party consumer may not drive a
 * built-in-mode run, on either start() or tick().
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use wpdb;

final class PluginGateModeTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_agent_safety']    = null;
	}

	protected function tearDown(): void {
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	/** Wires a real Runner (no model calls expected) into the singleton. */
	private function seedRunnerGraph(): Runner {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );

		return $runner;
	}

	public function test_a_third_party_consumer_cannot_start_a_built_in_mode_run(): void {
		Plugin::set_dependency_probe( false ); // Agent Safety absent => built-in mode.
		$this->seedRunnerGraph();

		$result = senroflux()->start( 'marketing-analytics-chat', 'Do a thing', array( 'agsafe-smoke/*' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_ungoverned', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
	}

	public function test_the_runs_screen_s_own_consumer_can_start_a_built_in_mode_run(): void {
		Plugin::set_dependency_probe( false );
		$this->seedRunnerGraph();

		$result = senroflux()->start( RunsScreen::CONSUMER, 'Do a thing', array( 'agsafe-smoke/*' ) );

		$this->assertIsArray( $result );
	}

	public function test_agent_safety_mode_never_refuses_on_consumer_identity(): void {
		Plugin::set_dependency_probe( true ); // Agent Safety present => AS mode.
		$this->seedRunnerGraph();

		$result = senroflux()->start( 'marketing-analytics-chat', 'Do a thing', array( 'agsafe-smoke/*' ) );

		$this->assertIsArray( $result );
	}

	public function test_a_third_party_consumer_cannot_tick_a_built_in_mode_run(): void {
		Plugin::set_dependency_probe( false );
		$runner = $this->seedRunnerGraph();

		// Started by a third party directly against the store (bypassing the
		// start() refusal on purpose, the way a stale/misbehaving consumer
		// that already has a run id could) — tick() must refuse it too.
		$run_id = $runner->store()->createRun(
			1,
			'marketing-analytics-chat',
			'Do a thing',
			array( 'agsafe-smoke/*' ),
			\Specflux\SenroFlux\Run\Budget::defaults(),
			null,
			null,
			null,
			\Specflux\SenroFlux\Run\GateMode::BuiltIn
		);

		$result = senroflux()->tick( $run_id, 0, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_ungoverned', $result->get_error_code() );
	}
}
