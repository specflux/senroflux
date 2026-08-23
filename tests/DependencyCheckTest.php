<?php
/**
 * Tests for the Agent Safety dependency gate (stage 2).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Plugin;

/**
 * The hard-dependency contract: without Agent Safety the plugin is inert —
 * available() is false, a notice is registered, and nothing else wires up.
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

	public function test_boot_without_dependency_registers_only_the_notice(): void {
		$GLOBALS['senroflux_test_actions'] = array();

		Plugin::set_dependency_probe( false );

		$plugin = Plugin::instance();
		$plugin->boot();

		$this->assertSame(
			array( 'admin_notices' ),
			array_keys( $GLOBALS['senroflux_test_actions'] ),
			'an ungoverned harness must wire nothing beyond its warning notice'
		);
		$this->assertFalse( $plugin->available() );
	}

	public function test_missing_notice_renders_the_ungoverned_warning(): void {
		ob_start();
		Plugin::instance()->render_missing_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Agent Safety', $html );
	}
}
