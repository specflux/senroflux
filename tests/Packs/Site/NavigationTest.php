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

	// ------------------------------------------------------------------
	// S7 quality fix (2026-09-28): a site-pack plan that creates or
	// publishes a page must not leave WordPress's stock "Sample Page"
	// visible in the navigation. Live evidence (2026-09-28, scenario-1-1):
	// a fresh Twenty Twenty-Five header resolves to a `core/page-list`
	// fallback, which lists EVERY published page — including the stock
	// Sample Page — and the model correctly left it alone per the pack's
	// own (then-incomplete) guidance ("page_list already covers it").
	// GOVERNED, via {@see Navigation::filterPlanError()} refusing the plan
	// at propose-plan time — never a harness-side rewrite.
	// ------------------------------------------------------------------

	/**
	 * @param list<string> $verbs One step's verbs.
	 * @return list<array{text:string,verbs:list<string>,tier:int}>
	 */
	private function stepsWithVerbs( array $verbs ): array {
		return array(
			array(
				'text'  => 'A plan step',
				'verbs' => $verbs,
				'tier'  => 0,
			),
		);
	}

	private const STOCK_SAMPLE_CONTENT = 'This is an example page. It\'s different from a blog post because it will stay in one place.';

	/** Insert WordPress's own stock Sample Page, published. Returns its id. */
	private function insertStockSamplePage(): int {
		return wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Sample Page',
				'post_name'    => 'sample-page',
				'post_content' => self::STOCK_SAMPLE_CONTENT,
			)
		);
	}

	/** Insert an ordinary published page (never the sample page). */
	private function insertRealPage( string $title ): int {
		return wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => strtolower( str_replace( ' ', '-', $title ) ),
				'post_content' => 'Real content for ' . $title . '.',
			)
		);
	}

	public function test_plan_check_refuses_a_page_creating_plan_when_page_list_would_show_the_sample_page(): void {
		$this->insertStockSamplePage();
		$this->insertRealPage( 'Home' );

		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$error = Navigation::filterPlanError( null, $this->stepsWithVerbs( array( 'site/create-draft' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'navigation_shows_sample_page', $error->get_error_code() );
		$this->assertStringContainsString( 'Sample Page', $error->get_error_message() );
		$this->assertStringContainsString( 'update-navigation', $error->get_error_message() );
	}

	public function test_plan_check_refuses_a_page_publishing_plan_when_an_explicit_link_shows_the_sample_page(): void {
		$sample_id = $this->insertStockSamplePage();
		$home_id   = $this->insertRealPage( 'Home' );

		$nav_id = $this->insertNav(
			'<!-- wp:navigation-link {"label":"Home","id":' . $home_id . ',"kind":"post-type","type":"page"} /-->'
			. '<!-- wp:navigation-link {"label":"Sample Page","id":' . $sample_id . ',"kind":"post-type","type":"page"} /-->'
		);
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$error = Navigation::filterPlanError( null, $this->stepsWithVerbs( array( 'site/publish' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'navigation_shows_sample_page', $error->get_error_code() );
	}

	public function test_plan_check_accepts_a_plan_that_names_update_navigation(): void {
		$this->insertStockSamplePage();
		$this->insertRealPage( 'Home' );

		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$steps = array_merge(
			$this->stepsWithVerbs( array( 'site/create-draft' ) ),
			$this->stepsWithVerbs( array( 'site/update-navigation' ) )
		);

		$this->assertNull( Navigation::filterPlanError( null, $steps ) );
	}

	public function test_plan_check_accepts_when_the_sample_page_was_removed_by_the_user(): void {
		// No stock Sample Page exists at all (removed, or never installed).
		$this->insertRealPage( 'Home' );

		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$error = Navigation::filterPlanError( null, $this->stepsWithVerbs( array( 'site/create-draft' ) ) );

		$this->assertNull( $error );
	}

	public function test_plan_check_accepts_when_a_page_actually_titled_sample_page_carries_real_content(): void {
		// A user's OWN page happens to be named "Sample Page" but its
		// content is not the WordPress stock text: never treated as it.
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Sample Page',
				'post_name'    => 'sample-page',
				'post_content' => 'Our actual pricing samples live here.',
			)
		);

		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$error = Navigation::filterPlanError( null, $this->stepsWithVerbs( array( 'site/create-draft' ) ) );

		$this->assertNull( $error );
	}

	public function test_plan_check_leaves_non_site_pack_plans_unaffected(): void {
		$this->insertStockSamplePage();

		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		// 'pages/create-draft' is the PAGES pack's own verb, never the site
		// pack's — this plan is not a site-pack plan and must be untouched.
		$error = Navigation::filterPlanError( null, $this->stepsWithVerbs( array( 'pages/create-draft' ) ) );

		$this->assertNull( $error );
	}

	public function test_plan_check_never_overrides_an_earlier_refusal(): void {
		$this->insertStockSamplePage();

		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$earlier_error = new \WP_Error( 'page_needs_image', 'Some earlier check already refused this plan.' );

		$error = Navigation::filterPlanError( $earlier_error, $this->stepsWithVerbs( array( 'site/create-draft' ) ) );

		$this->assertSame( $earlier_error, $error );
	}

	public function test_read_navigation_reports_the_stock_sample_page_id_as_a_hint(): void {
		$sample_id = $this->insertStockSamplePage();
		$nav_id    = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$result = $this->readAbility()->execute();

		$this->assertSame( $sample_id, $result['stock_sample_page'] );
	}

	public function test_read_navigation_reports_null_stock_sample_page_when_there_is_none(): void {
		$this->insertRealPage( 'Home' );
		$nav_id = $this->insertNav( '<!-- wp:page-list /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$result = $this->readAbility()->execute();

		$this->assertNull( $result['stock_sample_page'] );
	}
}
