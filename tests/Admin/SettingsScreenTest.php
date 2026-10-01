<?php
/**
 * The Settings screen's theme-patterns switch (D2, S8): registered, shown as
 * one checkbox and saved by the screen's own admin-post handler.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\SettingsScreen;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;

final class SettingsScreenTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_user_caps']  = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_options']    = array();
		$GLOBALS['senroflux_test_transients'] = array();
		$_POST                                = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['senroflux_test_options'], $GLOBALS['senroflux_test_registered_settings'], $GLOBALS['senroflux_test_user_caps'] );
		$_POST = array();
	}

	/** A screen whose redirect is recorded rather than followed. */
	private function screen(): SettingsScreen {
		return new class() extends SettingsScreen {
			public ?string $redirected_with = 'not redirected';

			protected function redirect( ?string $error_code ): void {
				$this->redirected_with = $error_code;
			}
		};
	}

	private function html( SettingsScreen $screen ): string {
		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}

	public function test_the_option_is_registered_as_a_bool_defaulting_to_on(): void {
		$this->screen()->registerSettings();

		$registered = $GLOBALS['senroflux_test_registered_settings'][ ThemePatterns::OPTION ] ?? array();
		$this->assertSame( 'boolean', $registered['type'] ?? null );
		$this->assertTrue( $registered['default'] ?? null );
		$this->assertFalse( call_user_func( $registered['sanitize_callback'], '0' ) );
		$this->assertTrue( call_user_func( $registered['sanitize_callback'], '1' ) );
	}

	public function test_the_screen_shows_one_checkbox_ticked_by_default(): void {
		$html = $this->html( $this->screen() );

		$this->assertSame( 1, substr_count( $html, 'type="checkbox"' ) );
		$this->assertMatchesRegularExpression( '/name="senroflux_use_theme_patterns"[^>]* checked=/', $html );
	}

	public function test_the_checkbox_is_unticked_once_the_switch_is_off(): void {
		update_option( ThemePatterns::OPTION, false );

		$this->assertDoesNotMatchRegularExpression( '/name="senroflux_use_theme_patterns"[^>]* checked=/', $this->html( $this->screen() ) );
	}

	public function test_saving_with_the_box_unticked_turns_the_switch_off(): void {
		$_POST  = array(
			'senroflux_site_brief'             => 'Friendly.',
			'senroflux_theme_patterns_present' => '1',
		);
		$screen = $this->screen();
		$screen->handleSave();

		$this->assertNull( $screen->redirected_with );
		$this->assertFalse( ThemePatterns::enabled() );
		$this->assertSame( 'Friendly.', get_option( 'senroflux_site_brief' ) );
	}

	public function test_saving_with_the_box_ticked_turns_the_switch_on(): void {
		update_option( ThemePatterns::OPTION, false );
		$_POST = array(
			'senroflux_theme_patterns_present' => '1',
			'senroflux_use_theme_patterns'     => '1',
		);
		$this->screen()->handleSave();

		$this->assertTrue( ThemePatterns::enabled() );
	}

	public function test_a_post_without_the_marker_leaves_the_switch_alone(): void {
		update_option( ThemePatterns::OPTION, false );
		$_POST = array( 'senroflux_site_brief' => 'Only the brief.' );
		$this->screen()->handleSave();

		$this->assertFalse( ThemePatterns::enabled() );
	}

	public function test_a_refused_brief_saves_nothing_not_even_the_switch(): void {
		$_POST  = array(
			'senroflux_site_brief'             => str_repeat( 'x', 2001 ),
			'senroflux_theme_patterns_present' => '1',
		);
		$screen = $this->screen();
		$screen->handleSave();

		$this->assertSame( 'brief_too_long', $screen->redirected_with );
		$this->assertTrue( ThemePatterns::enabled() );
	}
}
