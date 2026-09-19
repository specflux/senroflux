<?php
/**
 * Site\Navigation tests (stage 7, S7/S8).
 *
 * TARGET REPO PATH: tests/Packs/Site/NavigationTest.php
 *
 * Covers: block-theme ref resolution (with and without a `ref` on the
 * header's Navigation block), the fallback-resolver path, classic-theme
 * first-assigned-location resolution, `core/page-list` reporting, the S7
 * `navigation_links_unpublished` ordering refusal, and the S8 stale-write
 * refusal.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Navigation;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\WpdbRunStore;
use wpdb;

final class NavigationTest extends TestCase {

	private WpdbRunStore $store;

	private int $runId;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/navigation.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']               = array();
		$GLOBALS['senroflux_test_ability_categories']      = array();
		$GLOBALS['senroflux_test_posts']                   = array();
		$GLOBALS['senroflux_test_next_post_id']            = 100;
		$GLOBALS['senroflux_test_user_caps']               = array( 'edit_theme_options' => true );
		$GLOBALS['senroflux_test_is_block_theme']          = true;
		$GLOBALS['senroflux_test_header_template_content'] = null;
		$GLOBALS['senroflux_test_nav_fallback']            = null;
		$GLOBALS['senroflux_test_registered_nav_menus']    = array();
		$GLOBALS['senroflux_test_nav_menu_locations']      = array();
		$GLOBALS['senroflux_test_nav_menu_items']          = array();

		Navigation::reset();
		Navigation::registerCategory();
		Navigation::register();

		$this->store = new WpdbRunStore( new wpdb() );
		$this->runId = $this->store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Navigation::useRunContext( $this->runId, $this->store );
	}

	protected function tearDown(): void {
		Navigation::forgetRunContext();
	}

	private function readAbility(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/read-navigation'];
	}

	private function updateAbility(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/update-navigation'];
	}

	/** Insert a wp_navigation post with the given content, return its id. */
	private function insertNav( string $content ): int {
		return wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Navigation',
				'post_content' => $content,
			)
		);
	}

	public function test_block_theme_resolves_via_header_ref(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Home","url":"https://example.test/"} /-->' );

		$GLOBALS['senroflux_test_header_template_content'] =
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation {"ref":' . $nav_id . '} /--></div><!-- /wp:group -->';

		$result = $this->readAbility()->execute();

		$this->assertSame( 'items', $result['kind'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Home', $result['items'][0]['label'] );
	}

	public function test_block_theme_falls_back_when_header_navigation_has_no_ref(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Fallback item","url":"https://example.test/"} /-->' );

		// The header DOES have a Navigation block, but it carries no ref —
		// SenroFlux must never assume one and must fall through to the
		// fallback resolver instead.
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation /-->';
		$GLOBALS['senroflux_test_nav_fallback']            = $nav_id;

		$result = $this->readAbility()->execute();

		$this->assertSame( 'items', $result['kind'] );
		$this->assertSame( 'Fallback item', $result['items'][0]['label'] );
	}

	public function test_block_theme_falls_back_when_no_header_template_exists(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Only via fallback","url":"https://example.test/"} /-->' );

		$GLOBALS['senroflux_test_header_template_content'] = null;
		$GLOBALS['senroflux_test_nav_fallback']            = $nav_id;

		$result = $this->readAbility()->execute();

		$this->assertSame( 'Only via fallback', $result['items'][0]['label'] );
	}

	public function test_classic_theme_resolves_first_registered_location_with_an_assignment(): void {
		$GLOBALS['senroflux_test_is_block_theme']       = false;
		$GLOBALS['senroflux_test_registered_nav_menus'] = array(
			'primary' => 'Primary',
			'footer'  => 'Footer',
		);
		// Only "footer" has an assignment — "primary" is registered FIRST but
		// unassigned, so resolution must still land on "footer".
		$GLOBALS['senroflux_test_nav_menu_locations'] = array( 'footer' => 5 );
		$GLOBALS['senroflux_test_nav_menu_items'][5]  = array(
			1 => (object) array(
				'ID'         => 1,
				'title'      => 'Classic item',
				'url'        => 'https://example.test/',
				'object'     => 'custom',
				'object_id'  => 0,
				'menu_order' => 1,
			),
		);

		$result = $this->readAbility()->execute();

		$this->assertSame( 'items', $result['kind'] );
		$this->assertSame( 'Classic item', $result['items'][0]['label'] );
	}

	public function test_page_list_navigation_is_reported_as_page_list(): void {
		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] =
			'<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$result = $this->readAbility()->execute();

		$this->assertSame( 'page_list', $result['kind'] );
		$this->assertSame( array(), $result['items'] );
	}

	public function test_update_navigation_refuses_a_link_to_an_unpublished_page(): void {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Draft page',
			)
		);
		$nav_id  = $this->insertNav( '' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';
		$this->readAbility()->execute(); // Prime the read marker.

		$result = $this->updateAbility()->execute(
			array(
				'items' => array(
					array(
						'label'   => 'Draft link',
						'page_id' => $page_id,
						'order'   => 0,
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'navigation_links_unpublished', $result->get_error_code() );
	}

	public function test_update_navigation_refuses_a_stale_write(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Original","url":"https://example.test/"} /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		// Never read via the ability — the run has no recorded marker at all,
		// which fails closed exactly like an external edit would.
		$result = $this->updateAbility()->execute(
			array(
				'items' => array(
					array(
						'label' => 'New link',
						'url'   => 'https://example.test/new',
						'order' => 0,
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
	}

	public function test_update_navigation_succeeds_after_a_read_and_persists_items(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Original","url":"https://example.test/"} /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';
		$this->readAbility()->execute();

		$result = $this->updateAbility()->execute(
			array(
				'items' => array(
					array(
						'label' => 'New link',
						'url'   => 'https://example.test/new',
						'order' => 0,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New link', $result['items'][0]['label'] );

		// Re-reading confirms the write actually persisted.
		$reread = $this->readAbility()->execute();
		$this->assertSame( 'New link', $reread['items'][0]['label'] );
	}
}
