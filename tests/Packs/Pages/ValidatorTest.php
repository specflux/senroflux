<?php
/**
 * Validator tests (stage 8, S10).
 *
 * TARGET REPO PATH: tests/Packs/Pages/ValidatorTest.php
 *
 * Must cover EVERY S10 refusal code (invalid_markup | unknown_block |
 * unresolved_placeholder | unknown_pattern | slot_count | page_shape) with a
 * fixture each, plus the step-5 mutations (decorative colour attrs stripped,
 * `metadata.name` normalised) and the serialize/parse round-trip the step-1
 * compare relies on.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
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

	// --- Helpers -----------------------------------------------------------

	private function markup( string $slug ): string {
		foreach ( $this->vocabulary->all() as $pattern ) {
			if ( $slug === $pattern['slug'] ) {
				return $pattern['markup'];
			}
		}

		return '';
	}

	private function fill( string $markup ): string {
		return (string) preg_replace( '/\{\{[a-z_]+\}\}/', 'lorem', $markup );
	}

	private function errorCode( true|WP_Error $result ): ?string {
		return is_wp_error( $result ) ? $result->get_error_code() : null;
	}

	// --- Step 1: invalid_markup -------------------------------------------

	public function test_invalid_markup_refused_on_attr_whitespace(): void {
		// A space inside the block-comment attrs JSON is normalised away on
		// re-serialise, so the round-trip compare fails.
		$content = '<!-- wp:paragraph {"align":"center" } --><p>hi</p><!-- /wp:paragraph -->';

		$this->assertSame( 'invalid_markup', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	// --- Step 2: unknown_block --------------------------------------------

	public function test_unknown_block_refused_for_core_image(): void {
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="x" alt=""/></figure><!-- /wp:image -->';

		$this->assertSame( 'unknown_block', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	public function test_unknown_block_refused_for_non_core_block(): void {
		$content = '<!-- wp:foo/bar --><div></div><!-- /wp:foo/bar -->';

		$this->assertSame( 'unknown_block', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	// --- Step 3: unresolved_placeholder -----------------------------------

	public function test_unresolved_placeholder_refused(): void {
		// The raw hero still carries unresolved {{...}} placeholders.
		$content = $this->markup( 'hero' );

		$result = $this->validator->validate( $content );

		$this->assertSame( 'unresolved_placeholder', $this->errorCode( $result ) );
		$this->assertSame( 'headline', $result->get_error_data()['placeholder'] );
	}

	// --- Step 4: unknown_pattern, slot_count, page_shape ------------------

	public function test_unknown_pattern_refused_for_group_with_only_heading(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Only a heading</h2><!-- /wp:heading --></div><!-- /wp:group -->';

		$this->assertSame( 'unknown_pattern', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	public function test_slot_count_refused_when_text_section_too_long(): void {
		$content = '<!-- wp:group {"metadata":{"name":"sf/text-section"},"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group"><!-- wp:heading --><h2>Section</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>c</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>d</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>e</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$result = $this->validator->validate( $content );

		$this->assertSame( 'slot_count', $this->errorCode( $result ) );
		$this->assertSame( 'paragraphs', $result->get_error_data()['slot'] );
	}

	public function test_page_shape_refused_when_hero_not_first(): void {
		$section = $this->fill( $this->markup( 'text-section' ) );
		$content = $section . $section;

		$result = $this->validator->validate( $content );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'hero_first', $result->get_error_data()['rule'] );
	}

	public function test_page_shape_refused_when_more_than_one_cta(): void {
		$hero    = $this->fill( $this->markup( 'hero' ) );
		$cta     = $this->fill( $this->markup( 'cta' ) );
		$content = $hero . $cta . $cta;

		$result = $this->validator->validate( $content );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'max_cta', $result->get_error_data()['rule'] );
	}

	// --- A valid page ------------------------------------------------------

	public function test_valid_page_passes(): void {
		$content = $this->fill( $this->markup( 'hero' ) ) . $this->fill( $this->markup( 'text-section' ) );

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	// --- Step 5: mutations ------------------------------------------------

	public function test_clean_strips_decorative_color_and_normalises_meta(): void {
		$content = $this->fill( $this->markup( 'hero' ) ) . $this->fill( $this->markup( 'text-section' ) );

		$result = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertStringNotContainsString( 'backgroundColor', $result['content'] );
		$this->assertStringNotContainsString( 'textColor', $result['content'] );
		$this->assertStringContainsString( '"name":"senroflux/hero"', $result['content'] );
	}

	public function test_clean_returns_wp_error_when_invalid(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Only a heading</h2><!-- /wp:heading --></div><!-- /wp:group -->';

		$result = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertInstanceOf( WP_Error::class, $result['wp_error'] );
		$this->assertSame( 'unknown_pattern', $result['wp_error']->get_error_code() );
	}

	// --- Round-trip helper for the fixtures --------------------------------

	private function normalize( string $text ): string {
		return preg_replace( '/\s+/', ' ', trim( $text ) );
	}
}
