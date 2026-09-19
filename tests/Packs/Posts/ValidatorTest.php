<?php
/**
 * Posts Validator tests (S5): vocabulary refusals + the two feature patterns.
 *
 * TARGET REPO PATH: tests/Packs/Posts/ValidatorTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Posts;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Posts\Validator;
use Specflux\SenroFlux\Packs\Posts\Vocabulary;

final class ValidatorTest extends TestCase {

	private Validator $validator;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$this->validator = new Validator( new Vocabulary() );
	}

	public function test_a_single_paragraph_is_valid(): void {
		$content = '<!-- wp:paragraph --><p>Hello there.</p><!-- /wp:paragraph -->';
		$result  = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $content, $result['content'] );
	}

	public function test_prose_blocks_repeat_without_limit(): void {
		$content = str_repeat( '<!-- wp:paragraph --><p>One line.</p><!-- /wp:paragraph -->', 40 );
		$result  = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'], $result['wp_error'] ? $result['wp_error']->get_error_code() : '' );
	}

	public function test_invalid_markup_is_refused(): void {
		$result = $this->validator->clean( '<!-- wp:paragraph --><p>Unclosed' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_markup', $result['wp_error']->get_error_code() );
	}

	public function test_unknown_block_is_refused(): void {
		$content = '<!-- wp:senroflux/hero --><div>x</div><!-- /wp:senroflux/hero -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_block', $result['wp_error']->get_error_code() );
	}

	public function test_disallowed_html_tag_is_refused(): void {
		$content = '<!-- wp:paragraph --><p><script>alert(1)</script></p><!-- /wp:paragraph -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'disallowed_markup', $result['wp_error']->get_error_code() );
	}

	public function test_decorative_color_is_refused(): void {
		$content = '<!-- wp:paragraph {"textColor":"vivid-red"} --><p class="has-vivid-red-color">x</p><!-- /wp:paragraph -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'decorative_color', $result['wp_error']->get_error_code() );
	}

	public function test_unresolved_placeholder_is_refused(): void {
		$content = '<!-- wp:paragraph --><p>{{Headline}}</p><!-- /wp:paragraph -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unresolved_placeholder', $result['wp_error']->get_error_code() );
	}

	public function test_image_with_alt_text_is_valid(): void {
		$content = '<!-- wp:image {"alt":"A red bicycle leaning on a wall"} --><figure class="wp-block-image"><img src="https://example.test/bike.jpg" alt="A red bicycle leaning on a wall"/></figure><!-- /wp:image -->';
		$result  = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'], $result['wp_error'] ? $result['wp_error']->get_error_code() : '' );
	}

	public function test_image_with_missing_alt_is_refused(): void {
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/bike.jpg" alt=""/></figure><!-- /wp:image -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'missing_alt', $result['wp_error']->get_error_code() );
	}

	public function test_image_src_must_use_an_allowed_scheme(): void {
		$content = '<!-- wp:image {"alt":"x"} --><figure class="wp-block-image"><img src="data:image/png;base64,AAAA" alt="x"/></figure><!-- /wp:image -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'disallowed_markup', $result['wp_error']->get_error_code() );
	}

	public function test_closing_cta_is_accepted_and_normalised(): void {
		$cta = $this->ctaMarkup();

		$result = $this->validator->clean( $cta );

		$this->assertTrue( $result['ok'], $result['wp_error'] ? $result['wp_error']->get_error_code() : '' );
		$this->assertStringContainsString( '"name":"senroflux/closing-cta"', $result['content'] );
	}

	public function test_a_second_closing_cta_is_refused(): void {
		$content = $this->ctaMarkup() . $this->ctaMarkup();
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'post_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'max_closing_cta', $result['wp_error']->get_error_data()['rule'] ?? null );
	}

	public function test_pull_quote_is_accepted_up_to_the_cap(): void {
		$content = str_repeat( $this->pullQuoteMarkup(), Vocabulary::RULES_MAX_PULL_QUOTE );
		$result  = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'], $result['wp_error'] ? $result['wp_error']->get_error_code() : '' );
	}

	public function test_a_pull_quote_over_the_cap_is_refused(): void {
		$content = str_repeat( $this->pullQuoteMarkup(), Vocabulary::RULES_MAX_PULL_QUOTE + 1 );
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'post_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'max_pull_quote', $result['wp_error']->get_error_data()['rule'] ?? null );
	}

	public function test_a_malformed_group_is_refused_as_unknown_pattern(): void {
		$content = '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull"><!-- wp:paragraph --><p>Not a real CTA shape.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_pattern', $result['wp_error']->get_error_code() );
	}

	public function test_empty_content_is_refused_as_too_few_patterns(): void {
		$result = $this->validator->clean( '' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'post_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'too_few_patterns', $result['wp_error']->get_error_data()['rule'] ?? null );
	}

	public function test_the_runaway_guard_refuses_over_200_instances(): void {
		$content = str_repeat( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', Vocabulary::RUNAWAY_GUARD + 1 );
		$result  = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'too_many_blocks', $result['wp_error']->get_error_code() );
	}

	private function ctaMarkup(): string {
		return '<!-- wp:group {"align":"full"} --><div class="wp-block-group alignfull">'
			. '<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Keep reading</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">One line.</p><!-- /wp:paragraph -->'
			. '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Read more</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
			. '</div><!-- /wp:group -->';
	}

	private function pullQuoteMarkup(): string {
		return '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>A short line worth pulling out.</p><cite>Someone</cite></blockquote></figure><!-- /wp:pullquote -->';
	}
}
