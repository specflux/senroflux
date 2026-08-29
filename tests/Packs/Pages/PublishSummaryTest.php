<?php
/**
 * PublishSummary tests (stage 8, S10 / AS-11).
 *
 * TARGET REPO PATH: tests/Packs/Pages/PublishSummaryTest.php
 *
 * Verifies the rich summary row for the Tier-2 verbs `pages/publish` and
 * `pages/update-live`, the passthrough for other verbs, and that the builder
 * emits RAW HTML (the three anchors) — it does NOT strip scripts here; the
 * AS-11 `wp_kses($summary, ['a' => ['href' => true]])` render is what removes
 * them downstream (outside this class).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\PublishSummary;

final class PublishSummaryTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_posts'] = array();
	}

	private function seedPost( int $id, string $title ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = 'page';
		$post->post_title                       = $title;
		$post->post_status                      = 'draft';
		$post->post_name                        = '';
		$post->post_parent                      = 0;
		$post->post_excerpt                     = '';
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	public function test_rich_row_for_publish_builds_three_links(): void {
		$this->seedPost( 100, 'Pricing' );

		$sum = PublishSummary::build(
			'fallback',
			'pages/publish',
			array(
				'id'         => 100,
				'_senroflux' => array(
					'pattern_sequence' => 'hero › pricing-table › faq › cta',
					'run_goal'         => 'Design a pricing page',
				),
			)
		);

		$this->assertStringContainsString( 'Publish &quot;Pricing&quot; (page)', $sum );
		$this->assertStringContainsString( '<a href="https://example.test/?p=100&preview=true">preview</a>', $sum );
		$this->assertStringContainsString( '<a href="https://example.test/wp-admin/post.php?post=100&action=edit">edit</a>', $sum );
		$this->assertStringContainsString( 'hero › pricing-table › faq › cta', $sum );
		$this->assertStringContainsString( 'drafted by run &quot;Design a pricing page&quot;', $sum );
	}

	public function test_passthrough_for_read_verb(): void {
		$sum = PublishSummary::filter( 'plain', 'pages/read', array( 'id' => 100 ) );

		$this->assertSame( 'plain', $sum );
	}

	public function test_build_returns_raw_html_not_plain_text(): void {
		$this->seedPost( 100, 'Landing' );

		$sum = PublishSummary::build( '', 'pages/update-live', array( 'id' => 100 ) );

		// It is an HTML snippet (anchors present), NOT a sanitised plain string.
		$this->assertStringContainsString( 'Publish &quot;Landing&quot; (page)', $sum );
		$this->assertStringContainsString( '<a href=', $sum );
		$this->assertStringContainsString( '>preview</a>', $sum );
		$this->assertStringContainsString( '>edit</a>', $sum );
	}

	public function test_missing_id_returns_summary_unchanged(): void {
		$this->assertSame( 'fallback', PublishSummary::build( 'fallback', 'pages/publish', array() ) );
	}
}
