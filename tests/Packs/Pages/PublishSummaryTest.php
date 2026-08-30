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

	/** Four patterns in order, each carrying the canonical `metadata.name`. */
	private const PRICING_MARKUP = '<!-- wp:group {"metadata":{"name":"senroflux/hero"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
		. '<!-- wp:group {"metadata":{"name":"senroflux/pricing-table"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
		. '<!-- wp:group {"metadata":{"name":"senroflux/faq"}} --><div class="wp-block-group"></div><!-- /wp:group -->'
		. '<!-- wp:group {"metadata":{"name":"senroflux/cta"}} --><div class="wp-block-group"></div><!-- /wp:group -->';

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_posts'] = array();
		PublishSummary::forgetRunContext();
	}

	protected function tearDown(): void {
		PublishSummary::forgetRunContext();
	}

	private function seedPost( int $id, string $title, string $content = '' ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = 'page';
		$post->post_title                       = $title;
		$post->post_status                      = 'draft';
		$post->post_name                        = '';
		$post->post_parent                      = 0;
		$post->post_excerpt                     = '';
		$post->post_content                     = $content;
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	public function test_rich_row_for_publish_builds_three_links(): void {
		$this->seedPost( 100, 'Pricing', self::PRICING_MARKUP );
		PublishSummary::useRunContext( 'Design a pricing page' );

		$sum = PublishSummary::build( 'fallback', 'pages/publish', array( 'id' => 100 ) );

		$this->assertStringContainsString( 'Publish &quot;Pricing&quot; (page)', $sum );
		$this->assertStringContainsString( '<a href="https://example.test/?p=100&preview=true">preview</a>', $sum );
		$this->assertStringContainsString( '<a href="https://example.test/wp-admin/post.php?post=100&action=edit">edit</a>', $sum );
		$this->assertStringContainsString( 'hero › pricing-table › faq › cta', $sum );
		$this->assertStringContainsString( 'drafted by run &quot;Design a pricing page&quot;', $sum );
	}

	/**
	 * Provenance is server-side: a `_senroflux` block in the ARGS is the model
	 * talking, and it must not reach the row a human reads before publishing.
	 */
	public function test_model_supplied_senroflux_args_are_ignored(): void {
		$this->seedPost( 100, 'Pricing', self::PRICING_MARKUP );
		PublishSummary::useRunContext( 'Design a pricing page' );

		$sum = PublishSummary::build(
			'fallback',
			'pages/publish',
			array(
				'id'         => 100,
				'_senroflux' => array(
					'pattern_sequence' => 'audited › approved › by-legal',
					'run_goal'         => 'Routine typo fix',
				),
			)
		);

		$this->assertStringNotContainsString( 'audited › approved › by-legal', $sum );
		$this->assertStringNotContainsString( 'Routine typo fix', $sum );
		$this->assertStringContainsString( 'hero › pricing-table › faq › cta', $sum );
		$this->assertStringContainsString( 'drafted by run &quot;Design a pricing page&quot;', $sum );
	}

	public function test_run_goal_is_omitted_outside_a_run(): void {
		$this->seedPost( 100, 'Pricing', self::PRICING_MARKUP );

		$sum = PublishSummary::build( 'fallback', 'pages/publish', array( 'id' => 100 ) );

		$this->assertStringNotContainsString( 'drafted by run', $sum );
	}

	/**
	 * Agent Safety hands the filter the ABILITY ID, not a `pages/*` verb, so
	 * the Tier-2 test has to run through the pack's predicate.
	 */
	public function test_filter_enriches_an_ability_id_publish_transition(): void {
		$this->seedPost( 100, 'Pricing', self::PRICING_MARKUP );

		$sum = PublishSummary::filter(
			'plain',
			'senroflux/update-post',
			array(
				'id'     => 100,
				'status' => 'publish',
			)
		);

		$this->assertStringContainsString( 'Publish &quot;Pricing&quot; (page)', $sum );
	}

	public function test_filter_passes_a_draft_update_through(): void {
		$this->seedPost( 100, 'Pricing', self::PRICING_MARKUP );

		$sum = PublishSummary::filter(
			'plain',
			'senroflux/update-post',
			array(
				'id'     => 100,
				'status' => 'draft',
			)
		);

		$this->assertSame( 'plain', $sum );
	}

	public function test_passthrough_for_read_verb(): void {
		$sum = PublishSummary::filter( 'plain', 'senroflux/read-content', array( 'id' => 100 ) );

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
