<?php
/**
 * ContentSummary tests (stage 14, S15/AS-15).
 *
 * TARGET REPO PATH: tests/Packs/Site/ContentSummaryTest.php
 *
 * Verifies the `publish-post` card for an actual blog post (and that it
 * stays inert for a page, leaving PublishSummary's own card untouched), the
 * `update-navigation` current-vs-proposed card (current read by the
 * server), the `set-front-page` current-vs-replacement card, and the
 * passthrough/registration contract.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\ContentSummary;
use Specflux\SenroFlux\Packs\Site\FrontPage;
use Specflux\SenroFlux\Packs\Site\Navigation;

final class ContentSummaryTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/navigation.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_posts']                   = array();
		$GLOBALS['senroflux_test_options']                 = array();
		$GLOBALS['senroflux_test_nav_menu_items']          = array();
		$GLOBALS['senroflux_test_registered_nav_menus']    = array();
		$GLOBALS['senroflux_test_nav_menu_locations']      = array();
		$GLOBALS['senroflux_test_is_block_theme']          = true;
		$GLOBALS['senroflux_test_header_template_content'] = null;
		$GLOBALS['senroflux_test_nav_fallback']            = null;
		Navigation::reset();
		FrontPage::reset();
	}

	private function seedPost( int $id, string $title, string $post_type = 'post' ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = $post_type;
		$post->post_title                       = $title;
		$post->post_status                      = 'draft';
		$post->post_name                        = '';
		$post->post_parent                      = 0;
		$post->post_excerpt                     = '';
		$post->post_content                     = '';
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	// ------------------------------------------------------------------
	// publish-post
	// ------------------------------------------------------------------

	public function test_publish_post_card_for_an_actual_post_shows_title_and_preview(): void {
		$this->seedPost( 300, 'Spring launch', 'post' );

		$sum = ContentSummary::filter(
			'plain',
			'senroflux/publish-post',
			array(
				'id'     => 300,
				'status' => 'publish',
			)
		);

		$this->assertStringContainsString( 'Spring launch', $sum );
		$this->assertStringContainsString( '(post)', $sum );
		$this->assertStringContainsString( '<a href=', $sum );
		$this->assertStringContainsString( '>preview</a>', $sum );
	}

	public function test_publish_post_card_is_inert_for_a_page(): void {
		// A page's publish-post call is PublishSummary's card, not this
		// class's — this class must pass it through unchanged.
		$this->seedPost( 301, 'About us', 'page' );

		$sum = ContentSummary::filter(
			'already built by PublishSummary',
			'senroflux/publish-post',
			array(
				'id'     => 301,
				'status' => 'publish',
			)
		);

		$this->assertSame( 'already built by PublishSummary', $sum );
	}

	public function test_publish_post_card_is_inert_with_no_matching_object(): void {
		$sum = ContentSummary::filter( 'fallback', 'senroflux/publish-post', array( 'id' => 999 ) );

		$this->assertSame( 'fallback', $sum );
	}

	// ------------------------------------------------------------------
	// update-navigation
	// ------------------------------------------------------------------

	public function test_navigation_card_shows_current_beside_proposed(): void {
		// The CURRENT navigation is resolved by the server (classic-menu
		// world, no theme/menu fixtures registered — resolves to an empty
		// item list), never from the call's own args.
		$sum = ContentSummary::filter(
			'plain',
			'senroflux/update-navigation',
			array(
				'items' => array(
					array(
						'label' => 'Shop',
						'url'   => '/shop',
						'order' => 0,
					),
				),
			)
		);

		$this->assertStringContainsString( 'current: (none)', $sum );
		$this->assertStringContainsString( 'proposed: &quot;Shop&quot;', $sum );
	}

	public function test_navigation_card_escapes_item_labels(): void {
		$sum = ContentSummary::filter(
			'plain',
			'senroflux/update-navigation',
			array(
				'items' => array(
					array(
						'label' => '<script>alert(1)</script>',
						'url'   => '/x',
						'order' => 0,
					),
				),
			)
		);

		$this->assertStringNotContainsString( '<script>', $sum );
	}

	// ------------------------------------------------------------------
	// set-front-page
	// ------------------------------------------------------------------

	public function test_front_page_card_shows_current_page_beside_replacement(): void {
		$this->seedPost( 400, 'Old Home', 'page' );
		$this->seedPost( 401, 'New Home', 'page' );
		$GLOBALS['senroflux_test_options']['show_on_front'] = 'page';
		$GLOBALS['senroflux_test_options']['page_on_front'] = 400;

		$sum = ContentSummary::filter(
			'plain',
			'senroflux/set-front-page',
			array(
				'show_on_front' => 'page',
				'page_id'       => 401,
			)
		);

		$this->assertStringContainsString( 'current: &quot;Old Home&quot;', $sum );
		$this->assertStringContainsString( 'replacement: &quot;New Home&quot;', $sum );
	}

	public function test_front_page_card_labels_latest_posts(): void {
		$sum = ContentSummary::filter(
			'plain',
			'senroflux/set-front-page',
			array( 'show_on_front' => 'posts' )
		);

		$this->assertStringContainsString( 'current: Latest posts', $sum );
		$this->assertStringContainsString( 'replacement: Latest posts', $sum );
	}

	// ------------------------------------------------------------------
	// Passthrough
	// ------------------------------------------------------------------

	public function test_passthrough_for_an_unrelated_verb(): void {
		$sum = ContentSummary::filter( 'plain', 'senroflux/read-content', array( 'id' => 1 ) );

		$this->assertSame( 'plain', $sum );
	}
}
