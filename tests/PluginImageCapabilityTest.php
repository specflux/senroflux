<?php
/**
 * Proof-run defect (journey J1, run 1): a run on a text-only model parked an
 * approval on `media-generate`, the human approved it, and the call failed.
 * When the site's AI Client cannot produce images, start() pins the run's
 * `images` budget at 0 — the tool, the plan's verb vocabulary and the pack's
 * guidance already key off that — so the human is never asked.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ImageCapability;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use wpdb;

final class PluginImageCapabilityTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_user_caps_by_id'] = array();
		$GLOBALS['senroflux_test_user_caps']       = array();
		$GLOBALS['wpdb']                           = new wpdb();
		Plugin::set_dependency_probe( true );
	}

	protected function tearDown(): void {
		ImageCapability::setProbe( null );
		unset( $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	public function test_a_site_that_cannot_make_images_starts_runs_with_no_image_budget(): void {
		ImageCapability::setProbe( false );

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array( 'senroflux/*' ), array() );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 0, $result['run']['budget'][ Budget::IMAGES ] );
	}

	public function test_a_caller_cannot_buy_images_the_site_cannot_make(): void {
		ImageCapability::setProbe( false );

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array( 'senroflux/*' ), array( Budget::IMAGES => 6 ) );

		$this->assertSame( 0, $result['run']['budget'][ Budget::IMAGES ] );
	}

	public function test_a_site_that_can_make_images_keeps_the_default_budget(): void {
		ImageCapability::setProbe( true );

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array( 'senroflux/*' ), array() );

		$this->assertSame( Budget::defaults()[ Budget::IMAGES ], $result['run']['budget'][ Budget::IMAGES ] );
	}

	public function test_a_pack_run_on_a_text_only_site_is_told_once_that_generation_is_unavailable(): void {
		foreach ( array( new PostsPack(), new PagesPack() ) as $pack ) {
			$bodies = array();
			foreach ( $pack->skills( false ) as $skill ) {
				$bodies[] = $skill->body;
			}
			$text = implode( "\n", $bodies );

			$this->assertStringContainsString( 'Image generation is not available in this run', $text, $pack->name() );
			$this->assertStringContainsString( 'stock-image-search', $text, $pack->name() );
			$this->assertStringNotContainsString( 'media-generate', $text, $pack->name() );
		}
	}
}
