<?php
/**
 * Site\Validator tests (stage 7, S7).
 *
 * TARGET REPO PATH: tests/Packs/Site/ValidatorTest.php
 *
 * Covers only what the site pack ADDS on top of the inherited pages
 * behaviour (fully covered by tests/Packs/Pages/ValidatorTest.php against
 * the same shared engine): the `page-links`/`intro` patterns validate, the
 * `columns` slot on `page-links` is enforced, and `page-links` is capped at
 * one per page exactly like `cta`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Validator;
use Specflux\SenroFlux\Packs\Site\Vocabulary;
use WP_Error;

final class ValidatorTest extends TestCase {

	private Vocabulary $vocabulary;
	private Validator $validator;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$this->vocabulary = new Vocabulary();
		$this->validator  = new Validator( $this->vocabulary );
	}

	private function markup( string $slug ): string {
		foreach ( $this->vocabulary->all() as $pattern ) {
			if ( $slug === $pattern['slug'] ) {
				return (string) $pattern['markup'];
			}
		}

		return '';
	}

	private function page( string ...$slugs ): string {
		$parts = array();
		foreach ( $slugs as $slug ) {
			$parts[] = $this->markup( $slug );
		}

		return implode( "\n\n", $parts );
	}

	public function test_hero_plus_page_links_and_intro_validate(): void {
		$result = $this->validator->clean( $this->page( 'hero', 'page-links', 'intro' ) );

		$this->assertTrue( $result['ok'] );
	}

	public function test_page_links_refuses_a_single_card(): void {
		// The shipped page-links markup ships two cards; stripping any card
		// markup drops the count below the 2-card minimum.
		$markup   = $this->markup( 'page-links' );
		$one_card = preg_replace(
			'#<!-- wp:column --><div class="wp-block-column">.*?</div><!-- /wp:column -->(?=</div><!-- /wp:columns -->)#s',
			'',
			$markup,
			1
		);
		$this->assertIsString( $one_card );
		$this->assertNotSame( $markup, $one_card );

		$result = $this->validator->clean( $this->page( 'hero' ) . "\n\n" . $one_card );

		$this->assertFalse( $result['ok'] );
		$this->assertInstanceOf( WP_Error::class, $result['wp_error'] );
		$this->assertSame( 'slot_count', $result['wp_error']->get_error_code() );
	}

	public function test_page_links_appears_at_most_once_per_page(): void {
		$page = $this->page( 'hero', 'page-links', 'page-links' );

		$result = $this->validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'page_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'max_once', $result['wp_error']->get_error_data()['rule'] );
	}

	public function test_cta_still_capped_at_one_on_the_site_pack_too(): void {
		$page = $this->page( 'hero', 'cta', 'cta' );

		$result = $this->validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'page_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'max_once', $result['wp_error']->get_error_data()['rule'] );
	}

	public function test_hero_must_still_be_first(): void {
		$page = $this->page( 'intro', 'hero' );

		$result = $this->validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'page_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'hero_first', $result['wp_error']->get_error_data()['rule'] );
	}

	/**
	 * REPRODUCTION (live run 57): the model's third `create-post` attempt for
	 * the site skeleton's home page — an h2-headline hero with a plain
	 * (non-flex) buttons block and no paragraph align/fontSize, followed by a
	 * valid text-section. The site pack's `site/layout-rules` skill never told
	 * it hero needs an h1, a centered/large paragraph and a flex buttons
	 * block, so it guessed the text-section shape instead. This must still be
	 * refused (never trimmed) — the fix is to the skill and message, not to
	 * loosen matching.
	 */
	public function test_live_run57_home_markup_is_refused_as_unknown_pattern(): void {
		$markup = (string) file_get_contents( __DIR__ . '/markup/live-run57-home.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.
		$this->assertNotSame( '', $markup );

		$result = $this->validator->clean( $markup );

		$this->assertFalse( $result['ok'] );
		$this->assertInstanceOf( WP_Error::class, $result['wp_error'] );
		$this->assertSame( 'unknown_pattern', $result['wp_error']->get_error_code() );
	}

	/**
	 * The corrected equivalent (real hero shape: h1, centered/large-font
	 * paragraph, flex buttons) validates for a site run once the model is
	 * told the shape.
	 */
	public function test_live_run57_home_markup_corrected_to_the_real_hero_shape_validates(): void {
		// Same shells the vocabulary ships (BlockShells step 4b matches the
		// HTML shell verbatim, padding included) with only the live run's copy
		// swapped in, and the real hero shape: h1, centered/large-font
		// paragraph, flex buttons.
		$hero = <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/hero"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="wp-block-heading has-text-align-center">Your neighborhood sourdough bakery</h1><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","fontSize":"large"} --><p class="has-text-align-center has-large-font-size">Welcome to Crumb &amp; Co, a small sourdough bakery at 12 Mill Lane.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="mailto:hello@crumbandco.example">Email us</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML;

		$text_section = <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/text-section"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading --><h2 class="wp-block-heading">Visit Crumb &amp; Co</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Find us at 12 Mill Lane, Tuesday through Sunday, 7am&#8211;3pm. We're closed on Mondays.</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
HTML;

		$result = $this->validator->clean( $hero . "\n\n" . $text_section );

		$this->assertTrue( $result['ok'], (string) ( $result['wp_error']?->get_error_message() ?? '' ) );
	}

	/**
	 * A genuinely wrong shape (a paragraph swapped for a list inside hero)
	 * must still be refused — the fix must not loosen matching.
	 */
	public function test_a_genuinely_wrong_shape_is_still_refused(): void {
		$wrong = <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/hero"},"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull"><!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">A headline</h1>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><li>Not a paragraph</li></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->
HTML;

		$result = $this->validator->clean( $wrong );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_pattern', $result['wp_error']->get_error_code() );
	}
}
