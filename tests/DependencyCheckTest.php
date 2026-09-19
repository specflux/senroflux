<?php
/**
 * Tests for the Agent Safety dependency probe (0.3 S3: no longer a hard
 * dependency — its absence only decides the gate mode a run starts in).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;

/**
 * The 0.3 contract: `available()` still reports whether Agent Safety is
 * present, but its absence no longer stops `boot()` from wiring the runtime —
 * it only resolves {@see GateMode::BuiltIn} and shows an advisory notice.
 */
final class DependencyCheckTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
	}

	public function test_available_is_false_when_agent_safety_is_absent(): void {
		Plugin::set_dependency_probe( false );

		$plugin = Plugin::instance();
		$this->assertFalse( $plugin->available() );
	}

	public function test_available_is_true_once_agent_safety_is_present(): void {
		Plugin::set_dependency_probe( true );

		$plugin = Plugin::instance();
		$plugin->boot();

		$this->assertTrue( $plugin->available() );
	}

	public function test_current_gate_mode_follows_availability(): void {
		Plugin::set_dependency_probe( true );
		$this->assertSame( GateMode::AgentSafety, Plugin::currentGateMode() );

		Plugin::reset();
		Plugin::set_dependency_probe( false );
		$this->assertSame( GateMode::BuiltIn, Plugin::currentGateMode() );
	}

	public function test_boot_without_dependency_still_wires_the_runtime(): void {
		$GLOBALS['senroflux_test_actions'] = array();

		Plugin::set_dependency_probe( false );

		$plugin = Plugin::instance();
		$plugin->boot();

		// 0.3 S3: Agent Safety's absence is advisory, not fail-closed — the
		// runtime wires up (HTTP surfaces, init hooks) exactly as it would
		// with Agent Safety present. It does NOT register a site-wide
		// notice: per S11 the advisory lives ONLY in the Runs-screen setup
		// panel ({@see \Specflux\SenroFlux\Setup\Checks::agentSafetyAdvisory}).
		$this->assertContains(
			'rest_api_init',
			array_keys( $GLOBALS['senroflux_test_actions'] ),
			'the HTTP surface must wire up even without Agent Safety (built-in mode still runs)'
		);
		$this->assertFalse( $plugin->available() );
	}

	/**
	 * Defect 3 (0.3 live run + S11/S3): a site-wide "SenroFlux is running
	 * without Agent Safety…" notice used to render on EVERY admin screen
	 * (e.g. the Dashboard) via an unconditional admin_notices hook. S11
	 * places that advisory in the Runs-screen setup panel only.
	 */
	public function test_no_admin_notice_is_registered_for_the_agent_safety_state(): void {
		$GLOBALS['senroflux_test_actions'] = array();

		Plugin::set_dependency_probe( false );

		$plugin = Plugin::instance();
		$plugin->boot();

		$this->assertArrayNotHasKey(
			'admin_notices',
			$GLOBALS['senroflux_test_actions'],
			'no callback advertising the Agent Safety state may run on every admin screen'
		);
	}
}
