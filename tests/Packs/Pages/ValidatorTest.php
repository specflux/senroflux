<?php
/**
 * Validator tests (stage 8, S10).
 *
 * TARGET REPO PATH: tests/Packs/Pages/ValidatorTest.php
 *
 * Runs against WordPress core's REAL block parser (see
 * `tests/stubs/wp-block-parser.php`), so the round-trip contract and the
 * `innerBlocks` / `innerHTML` walks are exercised as they behave in production
 * rather than against a stub that agrees with them by construction.
 *
 * Covers EVERY S10 refusal code — invalid_markup | unknown_block |
 * disallowed_markup | unresolved_placeholder | unknown_pattern | slot_count |
 * page_shape (pattern_count, hero_first, max_cta, max_repeat) — plus the step-5
 * mutations and the stored-XSS payloads the pack must refuse.
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
				return (string) $pattern['markup'];
			}
		}

		return '';
	}

	/**
	 * A minimal valid page: hero first, then a text section, joined the way the
	 * editor joins top-level blocks (a blank line, which the real parser turns
	 * into a `blockName === null` freeform block).
	 */
	private function page( string ...$slugs ): string {
		$parts = array();
		foreach ( $slugs as $slug ) {
			$parts[] = $this->markup( $slug );
		}

		return implode( "\n\n", $parts );
	}

	private function errorCode( true|WP_Error $result ): ?string {
		return is_wp_error( $result ) ? $result->get_error_code() : null;
	}

	/**
	 * Inject a payload into the text-section's first paragraph.
	 */
	private function pageWithPayload( string $payload ): string {
		$section = str_replace(
			'<p>One short paragraph that makes a single concrete point.</p>',
			'<p>' . $payload . '</p>',
			$this->markup( 'text-section' )
		);

		return $this->markup( 'hero' ) . "\n\n" . $section;
	}

	// --- The real parser is really in play --------------------------------

	public function test_shipped_patterns_round_trip_through_the_real_parser(): void {
		foreach ( $this->vocabulary->all() as $pattern ) {
			$this->assertSame(
				$pattern['markup'],
				serialize_blocks( parse_blocks( (string) $pattern['markup'] ) ),
				$pattern['name'] . ' must survive a real parse→serialize round-trip byte for byte'
			);
		}
	}

	public function test_real_parser_emits_freeform_between_top_level_blocks(): void {
		// The guard for the assumption every other fixture rests on: whitespace
		// between patterns IS a null-named block, and the Validator has to see
		// past it rather than count it as a pattern.
		$blocks = parse_blocks( $this->page( 'hero', 'text-section' ) );
		$names  = array_column( $blocks, 'blockName' );

		$this->assertContains( null, $names );
		$this->assertTrue( $this->validator->validate( $this->page( 'hero', 'text-section' ) ) );
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

	public function test_unknown_block_refused_for_freeform_with_content(): void {
		$content = $this->markup( 'hero' ) . "\n<p>loose classic HTML</p>\n" . $this->markup( 'text-section' );

		$result = $this->validator->validate( $content );

		$this->assertSame( 'unknown_block', $this->errorCode( $result ) );
		$this->assertSame( 'core/freeform', $result->get_error_data()['name'] );
	}

	// --- Step 2b: disallowed_markup (the stored-XSS gate) -----------------

	/**
	 * @dataProvider xssPayloads
	 */
	public function test_disallowed_markup_refuses_xss_payload( string $payload, string $reason ): void {
		$result = $this->validator->validate( $this->pageWithPayload( $payload ) );

		$this->assertSame( 'disallowed_markup', $this->errorCode( $result ), $payload );
		$this->assertSame( $reason, $result->get_error_data()['reason'], $payload );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function xssPayloads(): array {
		return array(
			'script tag'         => array( 'Hello<script>alert(1)</script>', 'tag' ),
			'iframe'             => array( '<iframe src="https://evil.test"></iframe>', 'tag' ),
			'img with onerror'   => array( '<img src=x onerror=alert(1)>', 'tag' ),
			'raw img'            => array( '<img src="https://example.test/a.png" alt="a">', 'tag' ),
			'svg'                => array( '<svg onload="alert(1)"></svg>', 'tag' ),
			'style element'      => array( '<style>body{display:none}</style>', 'tag' ),
			'onclick handler'    => array( '<span onclick="alert(1)">x</span>', 'attr' ),
			'onmouseover'        => array( '<strong onmouseover="alert(1)">x</strong>', 'attr' ),
			'style url()'        => array( '<span style="background:url(https://evil.test/x)">x</span>', 'style' ),
			'style expression()' => array( '<span style="width:expression(alert(1))">x</span>', 'style' ),
		);
	}

	public function test_disallowed_markup_refuses_javascript_href(): void {
		$content = str_replace( 'href="#"', 'href="javascript:alert(1)"', $this->markup( 'hero' ) )
			. "\n\n" . $this->markup( 'text-section' );

		$result = $this->validator->validate( $content );

		$this->assertSame( 'disallowed_markup', $this->errorCode( $result ) );
		$this->assertSame( 'url', $result->get_error_data()['reason'] );
		$this->assertSame( 'href', $result->get_error_data()['attr'] );
	}

	public function test_disallowed_markup_refuses_entity_encoded_javascript_href(): void {
		// `&#106;` is `j`; a browser decodes the entity before resolving the
		// scheme, so a naive `str_starts_with( 'javascript:' )` would miss it.
		$content = str_replace( 'href="#"', 'href="&#106;avascript&#58;alert(1)"', $this->markup( 'hero' ) )
			. "\n\n" . $this->markup( 'text-section' );

		$this->assertSame( 'disallowed_markup', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	public function test_disallowed_markup_refuses_data_uri_href(): void {
		$content = str_replace( 'href="#"', 'href="data:text/html;base64,PHN2Zz4="', $this->markup( 'hero' ) )
			. "\n\n" . $this->markup( 'text-section' );

		$this->assertSame( 'disallowed_markup', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	public function test_ordinary_links_and_inline_formatting_still_pass(): void {
		$content = $this->pageWithPayload(
			'See <a href="https://example.test/pricing" rel="noopener">pricing</a>, '
			. '<a href="/local">local</a>, <a href="mailto:hi@example.test">mail</a> and <strong>bold</strong>.'
		);

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	// --- Step 3: unresolved_placeholder -----------------------------------

	public function test_unresolved_placeholder_refused(): void {
		$result = $this->validator->validate( $this->pageWithPayload( 'Say {{headline}} here.' ) );

		$this->assertSame( 'unresolved_placeholder', $this->errorCode( $result ) );
		$this->assertSame( 'headline', $result->get_error_data()['placeholder'] );
	}

	/**
	 * @dataProvider placeholderShapes
	 */
	public function test_unresolved_placeholder_matches_any_brace_pair( string $placeholder ): void {
		$result = $this->validator->validate( $this->pageWithPayload( 'Value: ' . $placeholder ) );

		$this->assertSame( 'unresolved_placeholder', $this->errorCode( $result ), $placeholder );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function placeholderShapes(): array {
		return array(
			'capitalised' => array( '{{Headline}}' ),
			'hyphenated'  => array( '{{price-1}}' ),
			'dotted'      => array( '{{plan.name}}' ),
			'spaced'      => array( '{{ cta label }}' ),
			'digits'      => array( '{{123}}' ),
		);
	}

	// --- Step 4: unknown_pattern, slot_count, page_shape ------------------

	public function test_unknown_pattern_refused_for_group_with_only_heading(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Only a heading</h2><!-- /wp:heading --></div><!-- /wp:group -->';

		$this->assertSame( 'unknown_pattern', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	public function test_unknown_pattern_refused_for_uncounted_extra_children(): void {
		// Ten EXTRA headings inside an otherwise-valid text-section. Headings
		// are not a counted slot there, so the pattern no longer matches — a
		// signature that collapsed repeats would have waved this through.
		$extra   = str_repeat(
			'<!-- wp:heading --><h2 class="wp-block-heading">Extra</h2><!-- /wp:heading -->',
			10
		);
		$section = str_replace(
			'</div><!-- /wp:group -->',
			$extra . '</div><!-- /wp:group -->',
			$this->markup( 'text-section' )
		);

		$result = $this->validator->validate( $this->markup( 'hero' ) . "\n\n" . $section );

		$this->assertSame( 'unknown_pattern', $this->errorCode( $result ) );
		$this->assertSame( 'senroflux/text-section', $result->get_error_data()['name'] );
	}

	public function test_explicit_default_attributes_keep_pattern_identity(): void {
		// `{"level":2}` IS the heading default, and `{"layout":{"type":"default"}}`
		// is the group default: spelling either out must not cost identity.
		$section = str_replace(
			'<!-- wp:heading -->',
			'<!-- wp:heading {"level":2} -->',
			$this->markup( 'text-section' )
		);

		$this->assertTrue( $this->validator->validate( $this->markup( 'hero' ) . "\n\n" . $section ) );
	}

	public function test_slot_count_refused_when_text_section_too_long(): void {
		$paragraph = '<!-- wp:paragraph --><p>Another point entirely.</p><!-- /wp:paragraph -->';
		$section   = str_replace(
			'</div><!-- /wp:group -->',
			str_repeat( $paragraph, 4 ) . '</div><!-- /wp:group -->',
			$this->markup( 'text-section' )
		);

		$result = $this->validator->validate( $this->markup( 'hero' ) . "\n\n" . $section );

		$this->assertSame( 'slot_count', $this->errorCode( $result ) );
		$this->assertSame( 'paragraphs', $result->get_error_data()['slot'] );
		$this->assertSame( 6, $result->get_error_data()['actual'] );
	}

	public function test_slot_count_counts_pricing_list_items_per_column(): void {
		// Two items in the FIRST column, three in the second: six across the
		// table, which a table-wide total would have accepted.
		$short = preg_replace(
			'#<li>Third thing this plan includes</li>#',
			'',
			$this->markup( 'pricing-table' ),
			1
		);

		$result = $this->validator->validate( $this->markup( 'hero' ) . "\n\n" . (string) $short );

		$this->assertSame( 'slot_count', $this->errorCode( $result ) );
		$this->assertSame( 'list_items', $result->get_error_data()['slot'] );
		$this->assertSame( 2, $result->get_error_data()['actual'] );
	}

	public function test_pricing_table_with_three_items_per_column_passes(): void {
		$this->assertTrue( $this->validator->validate( $this->page( 'hero', 'pricing-table' ) ) );
	}

	public function test_page_shape_refused_when_hero_not_first(): void {
		$result = $this->validator->validate( $this->page( 'text-section', 'text-section' ) );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'hero_first', $result->get_error_data()['rule'] );
	}

	public function test_page_shape_refused_when_more_than_one_cta(): void {
		$result = $this->validator->validate( $this->page( 'hero', 'cta', 'cta' ) );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'max_cta', $result->get_error_data()['rule'] );
	}

	public function test_page_shape_refused_when_too_few_patterns(): void {
		$result = $this->validator->validate( $this->page( 'hero' ) );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'pattern_count', $result->get_error_data()['rule'] );
		$this->assertSame( 1, $result->get_error_data()['count'] );
	}

	public function test_page_shape_refused_when_too_many_patterns(): void {
		$slugs   = array( 'hero' );
		$slugs   = array_merge( $slugs, array_fill( 0, 8, 'text-section' ) );
		$content = $this->page( ...$slugs );

		$result = $this->validator->validate( $content );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'pattern_count', $result->get_error_data()['rule'] );
		$this->assertSame( 9, $result->get_error_data()['count'] );
	}

	public function test_page_shape_refused_when_a_pattern_repeats_three_times(): void {
		$result = $this->validator->validate(
			$this->page( 'hero', 'feature-grid', 'feature-grid', 'feature-grid' )
		);

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'max_repeat', $result->get_error_data()['rule'] );
		$this->assertSame( 'feature-grid', $result->get_error_data()['slug'] );
		$this->assertSame( 3, $result->get_error_data()['seen'] );
	}

	public function test_text_section_may_repeat_beyond_twice(): void {
		$this->assertTrue(
			$this->validator->validate( $this->page( 'hero', 'text-section', 'text-section', 'text-section' ) )
		);
	}

	// --- A valid page ------------------------------------------------------

	public function test_valid_page_passes(): void {
		$this->assertTrue( $this->validator->validate( $this->page( 'hero', 'text-section' ) ) );
	}

	public function test_every_shipped_pattern_together_forms_a_valid_page(): void {
		$this->assertTrue(
			$this->validator->validate(
				$this->page( 'hero', 'text-section', 'feature-grid', 'pricing-table', 'faq', 'testimonials', 'cta' )
			)
		);
	}

	// --- Step 5: mutations ------------------------------------------------

	public function test_clean_strips_decorative_color_and_normalises_meta(): void {
		$hero    = str_replace(
			'"align":"full"',
			'"align":"full","backgroundColor":"base-2","textColor":"contrast"',
			$this->markup( 'hero' )
		);
		$content = $hero . "\n\n" . $this->markup( 'text-section' );

		$result = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertStringNotContainsString( 'backgroundColor', $result['content'] );
		$this->assertStringNotContainsString( 'textColor', $result['content'] );
		$this->assertStringContainsString( '"name":"senroflux/hero"', $result['content'] );
	}

	public function test_clean_is_idempotent_on_shipped_markup(): void {
		// A pattern inserted from the editor must survive a write-back byte for
		// byte — that is what makes the registered patterns round-trippable.
		$content = $this->page( 'hero', 'text-section' );

		$result = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $content, $result['content'] );
	}

	public function test_clean_returns_wp_error_when_invalid(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Only a heading</h2><!-- /wp:heading --></div><!-- /wp:group -->';

		$result = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertInstanceOf( WP_Error::class, $result['wp_error'] );
		$this->assertSame( 'unknown_pattern', $result['wp_error']->get_error_code() );
		$this->assertSame( $content, $result['content'], 'a refusal returns the input untouched' );
	}

	public function test_clean_refuses_rather_than_strips_a_script(): void {
		$content = $this->pageWithPayload( 'Hi<script>alert(1)</script>' );

		$result = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'disallowed_markup', $result['wp_error']->get_error_code() );
		$this->assertSame( $content, $result['content'] );
	}
}
