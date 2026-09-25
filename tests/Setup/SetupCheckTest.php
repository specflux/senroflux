<?php
/**
 * The SetupCheck value object (0.3 S11).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Setup\SetupCheck;

final class SetupCheckTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_user_caps'] = array();
	}

	public function test_a_viewer_holding_the_fix_capability_gets_the_fix_message(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );

		$check = new SetupCheck(
			'senroflux/provider',
			SetupCheck::BLOCKING,
			false,
			'Configure a provider.',
			'https://example.test/options-connectors.php',
			'manage_options',
			'Ask an administrator to configure a model provider.'
		);

		$this->assertSame( 'Configure a provider.', $check->messageFor( 1 ) );
		$this->assertTrue( $check->fixVisibleFor( 1 ) );
	}

	public function test_a_viewer_without_the_fix_capability_gets_the_blocked_message_and_no_link(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => false );

		$check = new SetupCheck(
			'senroflux/provider',
			SetupCheck::BLOCKING,
			false,
			'Configure a provider.',
			'https://example.test/options-connectors.php',
			'manage_options',
			'Ask an administrator to configure a model provider in Settings → Connectors.'
		);

		$this->assertSame(
			'Ask an administrator to configure a model provider in Settings → Connectors.',
			$check->messageFor( 1 )
		);
		$this->assertFalse( $check->fixVisibleFor( 1 ) );
	}

	public function test_a_null_fix_capability_means_nobody_gets_the_fix_message(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );

		$check = new SetupCheck(
			'pages/capability',
			SetupCheck::BLOCKING,
			false,
			'You do not have the capability this pack needs to start a run.',
			null,
			null,
			'You do not have the capability this pack needs to start a run.'
		);

		$this->assertFalse( $check->fixVisibleFor( 1 ) );
		$this->assertSame(
			'You do not have the capability this pack needs to start a run.',
			$check->messageFor( 1 )
		);
	}

	public function test_an_empty_blocked_message_falls_back_to_the_fix_message(): void {
		$check = new SetupCheck( 'x/y', SetupCheck::ADVISORY, true, 'fix text' );

		$this->assertSame( 'fix text', $check->blockedMessage() );
	}

	public function test_severity_predicate(): void {
		$blocking = new SetupCheck( 'a/b', SetupCheck::BLOCKING, true, '' );
		$advisory = new SetupCheck( 'a/c', SetupCheck::ADVISORY, true, '' );

		$this->assertTrue( $blocking->isBlocking() );
		$this->assertFalse( $advisory->isBlocking() );
	}

	public function test_error_code_falls_back_when_unset(): void {
		$check = new SetupCheck( 'a/b', SetupCheck::BLOCKING, false, 'x' );

		$this->assertSame( 'senroflux_setup_check_failed', $check->errorCode() );
	}
}
