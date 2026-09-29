<?php
/**
 * Site\Style tests (0.3 quality feature 5).
 *
 * TARGET REPO PATH: tests/Packs/Site/StyleTest.php
 *
 * Mirrors {@see FrontPageTest}: reading the current variation plus the list
 * available, and the S8 stale-write discipline — `set-style` refuses when the
 * run never read the style, when an external change happened between the
 * read and the write, when the slug is unknown, and when the active theme is
 * not a block theme.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Style;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\WpdbRunStore;
use wpdb;

final class StyleTest extends TestCase {

	private WpdbRunStore $store;

	private int $runId;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/style.php';
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function sampleVariations(): array {
		return array(
			array(
				'title'    => 'Default',
				'slug'     => 'default',
				'settings' => array( 'color' => array( 'palette' => array( 'theme' => array( array( 'slug' => 'a' ), array( 'slug' => 'b' ) ) ) ) ),
				'styles'   => array( 'color' => array( 'background' => '#fff' ) ),
			),
			array(
				'title'    => 'Bold',
				'slug'     => 'bold',
				'settings' => array( 'color' => array( 'palette' => array( 'theme' => array( array( 'slug' => 'a' ) ) ) ) ),
				'styles'   => array( 'color' => array( 'background' => '#000' ) ),
			),
		);
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']             = array();
		$GLOBALS['senroflux_test_ability_categories']    = array();
		$GLOBALS['senroflux_test_posts']                 = array();
		$GLOBALS['senroflux_test_next_post_id']          = 100;
		$GLOBALS['senroflux_test_postmeta']              = array();
		$GLOBALS['senroflux_test_options']               = array();
		$GLOBALS['senroflux_test_user_caps']             = array( 'edit_theme_options' => true );
		$GLOBALS['senroflux_test_is_block_theme']        = true;
		$GLOBALS['senroflux_test_style_variations']      = $this->sampleVariations();
		$GLOBALS['senroflux_test_global_styles_post_id'] = 0;

		Style::reset();
		Style::register();

		$this->store = new WpdbRunStore( new wpdb() );
		$this->runId = $this->store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Style::useRunContext( $this->runId, $this->store );
	}

	protected function tearDown(): void {
		Style::forgetRunContext();
	}

	private function readAbility(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/read-style'];
	}

	private function setAbility(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/set-style'];
	}

	public function test_read_reports_the_default_variation_and_the_full_list(): void {
		$result = $this->readAbility()->execute();

		$this->assertSame( 'default', $result['current']['slug'] );
		$this->assertCount( 2, $result['variations'] );
		$this->assertSame( 'bold', $result['variations'][1]['slug'] );
		$this->assertSame( 'Bold', $result['variations'][1]['title'] );
	}

	public function test_set_style_refuses_without_a_prior_read(): void {
		$result = $this->setAbility()->execute( array( 'slug' => 'bold' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_set_style_succeeds_after_a_read(): void {
		$this->readAbility()->execute();

		$result = $this->setAbility()->execute( array( 'slug' => 'bold' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'bold', $result['current']['slug'] );
		$this->assertSame( 'Bold', $result['current']['title'] );
	}

	public function test_set_style_refuses_when_changed_externally_between_read_and_set(): void {
		$this->readAbility()->execute();

		// Simulate an external Site Editor style change between the read and
		// this run's write: the same slug, but different post content.
		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"settings":{"color":{}},"styles":{}}',
			)
		);

		$result = $this->setAbility()->execute( array( 'slug' => 'bold' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
	}

	public function test_set_style_refuses_an_unknown_slug(): void {
		$this->readAbility()->execute();

		$result = $this->setAbility()->execute( array( 'slug' => 'nonexistent' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_set_style_refuses_on_a_non_block_theme(): void {
		$GLOBALS['senroflux_test_is_block_theme'] = false;

		$this->readAbility()->execute();

		$result = $this->setAbility()->execute( array( 'slug' => 'bold' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_block_theme', $result->get_error_code() );
	}

	public function test_set_style_may_keep_editing_after_its_own_write_without_re_reading(): void {
		$this->readAbility()->execute();

		$first = $this->setAbility()->execute( array( 'slug' => 'bold' ) );
		$this->assertIsArray( $first );

		// A second write in the same run, with no intervening read, must
		// succeed off the first write's own after-write re-record.
		$second = $this->setAbility()->execute( array( 'slug' => 'default' ) );

		$this->assertIsArray( $second );
		$this->assertSame( 'default', $second['current']['slug'] );
	}
}
