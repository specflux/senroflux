<?php
/**
 * Tests for the multisite refusal (0.3 S2).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\SenroFlux\MultisiteGuard;

/**
 * Activation on a multisite install dies before WordPress records the plugin
 * as active; a single-site install passes through untouched.
 */
final class MultisiteGuardTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['senroflux_test_multisite'] );
		unset( $GLOBALS['senroflux_test_deactivated_plugins'] );
	}

	public function test_single_site_is_not_refused(): void {
		$GLOBALS['senroflux_test_multisite'] = false;

		$this->assertFalse( MultisiteGuard::refused() );
		MultisiteGuard::refuse_activation( '/plugins/senroflux/senroflux.php' );
		MultisiteGuard::refuse_runtime( '/plugins/senroflux/senroflux.php' );
		$this->assertSame( array(), $GLOBALS['senroflux_test_deactivated_plugins'] ?? array() );
	}

	public function test_multisite_activation_deactivates_and_dies_with_the_refusal(): void {
		$GLOBALS['senroflux_test_multisite'] = true;

		$this->assertTrue( MultisiteGuard::refused() );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'does not support WordPress multisite' );

		try {
			MultisiteGuard::refuse_activation( '/plugins/senroflux/senroflux.php' );
		} finally {
			$this->assertSame( array( 'senroflux/senroflux.php' ), $GLOBALS['senroflux_test_deactivated_plugins'] ?? array() );
		}
	}

	public function test_multisite_runtime_guard_deactivates_and_notices(): void {
		$GLOBALS['senroflux_test_multisite'] = true;

		MultisiteGuard::refuse_runtime( '/plugins/senroflux/senroflux.php' );

		$this->assertSame( array( 'senroflux/senroflux.php' ), $GLOBALS['senroflux_test_deactivated_plugins'] ?? array() );
		$this->assertNotEmpty( $GLOBALS['senroflux_test_actions']['admin_notices'] ?? array() );
	}

	public function test_runtime_notice_names_multisite(): void {
		ob_start();
		MultisiteGuard::render_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'multisite is not supported', $html );
	}
}
