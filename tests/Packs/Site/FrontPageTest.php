<?php
/**
 * Site\FrontPage tests (stage 7, S7).
 *
 * TARGET REPO PATH: tests/Packs/Site/FrontPageTest.php
 *
 * Covers: reading the current settings with titles, and that switching from
 * "latest posts" to a static front page sets `page_for_posts` in the SAME
 * write when given, while never touching the OLD front page's post.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\FrontPage;

final class FrontPageTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_next_post_id']       = 100;
		$GLOBALS['senroflux_test_options']            = array();
		$GLOBALS['senroflux_test_user_caps']          = array( 'manage_options' => true );

		FrontPage::reset();
		FrontPage::register();
	}

	private function readAbility(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/read-front-page'];
	}

	private function setAbility(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/set-front-page'];
	}

	public function test_read_reports_latest_posts_by_default(): void {
		$result = $this->readAbility()->execute();

		$this->assertSame( 'posts', $result['show_on_front'] );
		$this->assertNull( $result['page_on_front'] );
		$this->assertNull( $result['page_for_posts'] );
	}

	public function test_set_front_page_from_posts_to_static_sets_page_for_posts_in_one_write_and_leaves_old_front_page_untouched(): void {
		update_option( 'show_on_front', 'posts' );

		$old_front_page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Old front page (unused while show_on_front is posts)',
			)
		);
		// Simulate a stale page_on_front left over from an earlier static
		// setting (S7: "the old front page is never modified").
		update_option( 'page_on_front', $old_front_page );

		$new_home  = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Home',
			)
		);
		$blog_page = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Blog',
			)
		);

		$before_modified = get_post( $old_front_page )->post_modified_gmt;

		$result = $this->setAbility()->execute(
			array(
				'show_on_front'     => 'page',
				'page_id'           => $new_home,
				'page_for_posts_id' => $blog_page,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'page', $result['show_on_front'] );
		$this->assertSame( $new_home, $result['page_on_front']['id'] );
		$this->assertSame( 'Home', $result['page_on_front']['title'] );
		$this->assertSame( $blog_page, $result['page_for_posts']['id'] );
		$this->assertSame( 'Blog', $result['page_for_posts']['title'] );

		// The OLD front page's post itself is untouched.
		$this->assertSame( $before_modified, get_post( $old_front_page )->post_modified_gmt );
		$this->assertSame( 'Old front page (unused while show_on_front is posts)', get_post( $old_front_page )->post_title );
	}

	public function test_set_front_page_refuses_an_unknown_page(): void {
		$result = $this->setAbility()->execute(
			array(
				'show_on_front' => 'page',
				'page_id'       => 999999,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
