<?php
/**
 * HeroTemplate tests (0.3 quality feature 2: one H1 per page).
 *
 * TARGET REPO PATH: tests/Packs/Pages/HeroTemplateTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\HeroTemplate;

final class HeroTemplateTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_stylesheet_dir'] = '/no-such-theme';
		$GLOBALS['senroflux_test_template_dir']   = '/no-such-theme';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'] );
	}

	private function themeDir(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';
	}

	// --- containsH1 ----------------------------------------------------

	public function test_contains_h1_true_for_a_hero_heading(): void {
		$this->assertTrue( HeroTemplate::containsH1( '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">A headline</h1><!-- /wp:heading -->' ) );
	}

	public function test_contains_h1_false_without_one(): void {
		$this->assertFalse( HeroTemplate::containsH1( '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">A subhead</h2><!-- /wp:heading -->' ) );
	}

	public function test_contains_h1_does_not_false_positive_on_h10_or_similar_tags(): void {
		// A naive substring search for "<h1" would also match a hypothetical
		// "<h10" tag; the real regex requires a word boundary after "h1".
		$this->assertFalse( HeroTemplate::containsH1( '<h10-not-a-real-tag>x</h10-not-a-real-tag>' ) );
	}

	// --- themeHasNoTitleTemplate -----------------------------------------

	public function test_theme_has_no_title_template_false_when_theme_lacks_it(): void {
		$this->assertFalse( HeroTemplate::themeHasNoTitleTemplate() );
	}

	public function test_theme_has_no_title_template_true_when_theme_ships_it(): void {
		$GLOBALS['senroflux_test_stylesheet_dir'] = $this->themeDir();
		$GLOBALS['senroflux_test_template_dir']   = $this->themeDir();

		$this->assertTrue( HeroTemplate::themeHasNoTitleTemplate() );
	}

	// --- shouldAssign (the decision the write path acts on) ---------------

	public function test_should_assign_true_for_a_page_with_h1_on_a_theme_that_has_the_template(): void {
		$GLOBALS['senroflux_test_stylesheet_dir'] = $this->themeDir();
		$GLOBALS['senroflux_test_template_dir']   = $this->themeDir();

		$this->assertTrue( HeroTemplate::shouldAssign( 'page', '<h1>Headline</h1>' ) );
	}

	public function test_should_assign_false_without_the_template(): void {
		$this->assertFalse( HeroTemplate::shouldAssign( 'page', '<h1>Headline</h1>' ) );
	}

	public function test_should_assign_false_without_an_h1(): void {
		$GLOBALS['senroflux_test_stylesheet_dir'] = $this->themeDir();
		$GLOBALS['senroflux_test_template_dir']   = $this->themeDir();

		$this->assertFalse( HeroTemplate::shouldAssign( 'page', '<h2>Not a headline</h2>' ) );
	}

	public function test_should_assign_false_for_a_post_even_with_an_h1(): void {
		$GLOBALS['senroflux_test_stylesheet_dir'] = $this->themeDir();
		$GLOBALS['senroflux_test_template_dir']   = $this->themeDir();

		$this->assertFalse( HeroTemplate::shouldAssign( 'post', '<h1>Headline</h1>' ) );
	}
}
