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
 * disallowed_markup | decorative_color | unresolved_placeholder |
 * missing_alt (0.3 quality feature 4) | unknown_pattern | slot_count | block_mismatch |
 * page_shape (pattern_count, hero_first, max_cta, max_repeat) — plus the step-5
 * mutations and the stored-XSS payloads the pack must refuse.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Content\ImageAlt;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
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

	private function errorCode( bool|WP_Error $result ): ?string {
		return is_wp_error( $result ) ? $result->get_error_code() : null;
	}

	/**
	 * Inject a payload into the text-section's first paragraph.
	 */
	private function pageWithPayload( string $payload ): string {
		$section = str_replace(
			'<p>Open with the point a visitor came for: what this is, who it suits and what they get from it. Use the business\'s own facts, such as its services, place, hours and people, and name them exactly.</p>',
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

	public function test_explicit_core_namespace_passes_the_round_trip(): void {
		$sample = <<<'HTML'
<!-- wp:core/paragraph -->
<p>Most desk-based days are built from small, repeated stillness.</p>
<!-- /wp:core/paragraph -->

<!-- wp:core/heading {"level":2} -->
<h2 class="wp-block-heading">Neck side stretch</h2>
<!-- /wp:core/heading -->

<!-- wp:core/list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:core/list-item -->
<li>Sit tall, let your right hand rest on your desk.</li>
<!-- /wp:core/list-item -->

<!-- wp:core/list-item -->
<li>Drop your left ear toward your left shoulder.</li>
<!-- /wp:core/list-item --></ol>
<!-- /wp:core/list -->
HTML;
		$result = $this->validator->validate( $sample );

		// Later steps (vocabulary) may still refuse; the round trip must not.
		$this->assertTrue( true === $result || 'invalid_markup' !== $this->errorCode( $result ) );
	}

	public function test_mismatched_closer_refusal_carries_a_near_excerpt(): void {
		$content = '<!-- wp:core/paragraph --><p>hi</p><!-- /wp:core/heading -->';
		$error   = $this->validator->validate( $content );

		$this->assertSame( 'invalid_markup', $this->errorCode( $error ) );
		$this->assertStringStartsWith( 'The page content is not well-formed block markup.', $error->get_error_message() );
		$this->assertStringContainsString( 'near: "', $error->get_error_message() );
	}

	// --- Step 2: unknown_block --------------------------------------------

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

	// --- Step 3b (0.3 quality feature 4): missing_alt ----------------------

	public function test_missing_alt_refused_for_image_with_no_alt(): void {
		// 0.3 quality feature 4: `core/image` is no longer a bare
		// `unknown_block` — it is admitted, but only with alt text. This
		// content never matches a pattern shape either, but the alt check
		// (step 3b) runs BEFORE pattern identity (step 4), so an image with
		// no alt is refused as `missing_alt`, not `unknown_pattern`.
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/x.jpg" alt=""/></figure><!-- /wp:image -->';

		$this->assertSame( 'missing_alt', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	// --- 0.3 quality fix: cover-hero / media-text ---------------------------

	public function test_cover_hero_and_media_text_validate_as_a_page(): void {
		$content = $this->page( 'cover-hero', 'media-text' );

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	public function test_media_text_without_a_size_slug_is_accepted(): void {
		// A live run omitted `mediaSizeSlug` (and so the `size-full` class);
		// both only name which image size was picked, not the pattern.
		$content = str_replace(
			array( ',"mediaSizeSlug":"full"', ' size-full' ),
			'',
			$this->page( 'cover-hero', 'media-text' )
		);
		$this->assertStringNotContainsString( 'mediaSizeSlug', $content );

		$this->assertTrue( $this->validator->clean( $content )['ok'] );
	}

	public function test_a_preset_css_variable_written_with_a_pipe_is_rewritten_not_refused(): void {
		$content = str_replace( 'var(--wp--preset--spacing--60)', 'var(--wp--preset--spacing|60)', $this->page( 'hero', 'text-section' ) );
		$this->assertStringContainsString( 'spacing|60)', $content );

		$clean = $this->validator->clean( $content );

		$this->assertTrue( $clean['ok'] );
		$this->assertStringNotContainsString( 'spacing|60)', $clean['content'] );
		$this->assertStringContainsString( 'var(--wp--preset--spacing--60)', $clean['content'] );
	}

	public function test_a_cover_hero_with_a_focal_point_is_accepted(): void {
		// core/cover's own save() output for a chosen focal point.
		$content = str_replace(
			array( '"url":"https://example.test/photo.jpg",', 'data-object-fit="cover"' ),
			array( '"focalPoint":{"x":0.5,"y":0.3},"url":"https://example.test/photo.jpg",', 'style="object-position:50% 30%" data-object-fit="cover" data-object-position="50% 30%"' ),
			$this->page( 'cover-hero', 'text-section' )
		);
		$this->assertStringContainsString( 'data-object-position', $content );
		$this->assertStringContainsString( '"focalPoint"', $content );

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	public function test_a_data_attribute_other_than_the_focal_point_is_still_refused_on_a_cover_image(): void {
		$content = str_replace( 'data-object-fit="cover"', 'data-object-fit="cover" data-track="1"', $this->page( 'cover-hero', 'text-section' ) );

		$this->assertSame( 'disallowed_markup', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	/**
	 * Sets a cover-hero's `dimRatio` (and the derived `has-background-dim[-N]`
	 * classes) to an arbitrary value, mirroring `@wordpress/block-library`'s
	 * own `dimRatioToClass()`: no numbered class at 50, a numbered class
	 * (rounded to the nearest 10) alongside the bare class otherwise.
	 */
	private function withCoverDimRatio( string $content, int $ratio ): string {
		$content = (string) preg_replace( '/"dimRatio":\d+/', '"dimRatio":' . $ratio, $content, 1 );
		$content = (string) preg_replace( '/has-background-dim-\d+\s*/', '', $content, 1 );
		if ( 50 !== $ratio ) {
			$rounded = 10 * (int) round( $ratio / 10 );
			$content = (string) preg_replace( '/has-background-dim"/', 'has-background-dim-' . $rounded . ' has-background-dim"', $content, 1 );
		}

		return $content;
	}

	// --- 0.3 quality fix (hero readability): dimRatio enforcement -----------

	public function test_a_cover_with_a_low_dim_ratio_is_raised_to_the_minimum_not_refused(): void {
		$content = $this->withCoverDimRatio( $this->page( 'cover-hero', 'text-section' ), 20 );
		$this->assertStringContainsString( '"dimRatio":20', $content );

		$clean = $this->validator->clean( $content );

		$this->assertTrue( $clean['ok'], is_wp_error( $clean['wp_error'] ) ? $clean['wp_error']->get_error_message() : '' );
		$this->assertStringContainsString( '"dimRatio":50', $clean['content'] );
		$this->assertStringNotContainsString( 'dimRatio":20', $clean['content'] );
		$this->assertStringNotContainsString( 'has-background-dim-20', $clean['content'] );
		$this->assertStringContainsString( 'has-background-dim"', $clean['content'] );
	}

	public function test_the_shipped_cover_hero_uses_a_stronger_default_dim_ratio(): void {
		$markup = $this->markup( 'cover-hero' );

		$this->assertSame( 1, preg_match( '/"dimRatio":(\d+)/', $markup, $matches ) );
		$this->assertGreaterThanOrEqual( 60, (int) $matches[1], 'the shipped hero shell should default to a stronger overlay than the bare minimum' );
	}

	public function test_a_cover_with_explicit_dark_text_keeps_its_low_dim_ratio(): void {
		// A theme pattern may hand-author a dark preset-colour class directly
		// in its markup without a matching `textColor` comment attribute —
		// {@see Validator::checkDecorativeColor()} only refuses a colour
		// ATTRIBUTE, never a literal class already baked into the HTML — so
		// this content still validates, and the dark text needs no darker
		// overlay to stay readable.
		$content = $this->withCoverDimRatio( $this->page( 'cover-hero', 'text-section' ), 20 );
		$content = str_replace(
			'<h1 class="wp-block-heading has-text-align-center">',
			'<h1 class="wp-block-heading has-text-align-center has-black-color has-text-color">',
			$content
		);

		$clean = $this->validator->clean( $content );

		$this->assertTrue( $clean['ok'], is_wp_error( $clean['wp_error'] ) ? $clean['wp_error']->get_error_message() : '' );
		$this->assertStringContainsString( '"dimRatio":20', $clean['content'] );
		$this->assertStringContainsString( 'has-background-dim-20', $clean['content'] );
	}

	public function test_missing_alt_refused_for_cover_hero_with_no_alt(): void {
		// `core/cover` stores alt TWICE — the block's own `alt` comment
		// attribute and the rendered `<img alt="...">` — both must be blanked
		// or the JSON copy alone satisfies {@see ImageAlt::missing()}.
		$blanked = str_replace(
			array( '"alt":"A descriptive alt"', 'alt="A descriptive alt"' ),
			array( '"alt":""', 'alt=""' ),
			$this->markup( 'cover-hero' )
		);
		$content = $blanked . "\n\n" . $this->markup( 'text-section' );

		$this->assertSame( 'missing_alt', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	public function test_alt_not_required_on_a_colour_only_cover_but_required_on_a_featured_image_cover(): void {
		$plain                                 = array(
			'blockName' => 'core/cover',
			'attrs'     => array( 'overlayColor' => 'base' ),
			'innerHTML' => '<div class="wp-block-cover"><span class="wp-block-cover__background"></span></div>',
		);
		$featured                              = $plain;
		$featured['attrs']['useFeaturedImage'] = true;

		$this->assertFalse( ImageAlt::missing( $plain ) );
		$this->assertTrue( ImageAlt::missing( $featured ) );
	}

	public function test_missing_alt_refused_for_media_text_with_no_alt(): void {
		$content = $this->markup( 'hero' ) . "\n\n"
			. str_replace( 'alt="A descriptive alt"', 'alt=""', $this->markup( 'media-text' ) );

		$this->assertSame( 'missing_alt', $this->errorCode( $this->validator->validate( $content ) ) );
	}

	/** `cover-hero` is an alternative hero: it satisfies "hero first" on its own. */
	public function test_cover_hero_alone_satisfies_hero_first(): void {
		$content = $this->page( 'cover-hero', 'text-section' );

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	/** A page that leads with `text-section` before any hero is still refused. */
	public function test_cover_hero_not_first_still_refuses_hero_first(): void {
		$content = $this->page( 'text-section', 'cover-hero' );
		$result  = $this->validator->validate( $content );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'hero_first', is_wp_error( $result ) ? $result->get_error_data()['rule'] : null );
	}

	public function test_missing_alt_refused_for_image_nested_inside_a_pattern(): void {
		// An image slipped inside an otherwise-valid pattern (e.g. a group) is
		// still caught — the check walks the WHOLE tree, not just top-level
		// blocks.
		$content = '<!-- wp:group {"metadata":{"name":"senroflux/text-section"}} --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->'
			. '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/x.jpg" alt=""/></figure><!-- /wp:image -->'
			. '</div><!-- /wp:group -->';

		$this->assertSame( 'missing_alt', $this->errorCode( $this->validator->validate( $content ) ) );
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

	public function test_text_section_may_repeat_beyond_twice_when_not_back_to_back(): void {
		$this->assertTrue(
			$this->validator->validate( $this->page( 'hero', 'text-section', 'text-section', 'feature-grid', 'text-section' ) )
		);
	}

	/**
	 * Live batch 2026-09-29-final4 scenario 1-1: a Services page written as
	 * raw markup was a hero and five text-sections in a row (Visual 2); the
	 * layouts-path check never saw it.
	 */
	public function test_page_shape_refused_for_three_text_sections_in_a_row(): void {
		$result = $this->validator->validate( $this->page( 'hero', 'text-section', 'text-section', 'text-section', 'cta' ) );

		$this->assertSame( 'page_shape', $this->errorCode( $result ) );
		$this->assertSame( 'text_run', $result->get_error_data()['rule'] );
		$this->assertStringContainsString( 'patterns 2 to 4', $result->get_error_message() );
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

	// --- Step 2c: decorative_color --------------------------------------

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function colorProvider(): array {
		return array(
			'backgroundColor preset' => array(
				'"align":"full"',
				'"align":"full","backgroundColor":"base-2"',
				'wp-block-group alignfull has-base-2-background-color has-background',
				'backgroundColor',
			),
			'textColor preset'       => array(
				'"align":"full"',
				'"align":"full","textColor":"contrast"',
				'wp-block-group alignfull has-contrast-color has-text-color',
				'textColor',
			),
			'gradient preset'        => array(
				'"align":"full"',
				'"align":"full","gradient":"vivid-cyan-blue"',
				'wp-block-group alignfull has-vivid-cyan-blue-gradient-background has-background',
				'gradient',
			),
			'custom style.color'     => array(
				'"style":{"spacing"',
				'"style":{"color":{"background":"#5140A5"},"spacing"',
				'wp-block-group alignfull has-background',
				'style.color',
			),
		);
	}

	/**
	 * Stripping the colour from the block comment would leave it in the HTML:
	 * an inline colour style then fails editor validation, and preset classes
	 * survive as a custom class. So colour is refused, not stripped.
	 *
	 * @dataProvider colorProvider
	 */
	public function test_decorative_color_is_refused_not_stripped( string $search, string $replace, string $classes, string $attr ): void {
		$hero    = str_replace(
			array( $search, 'wp-block-group alignfull' ),
			array( $replace, $classes ),
			$this->markup( 'hero' )
		);
		$content = $hero . "\n\n" . $this->markup( 'text-section' );

		$result = $this->validator->clean( $content );

		$this->assertFalse( $result['ok'] );
		$this->assertInstanceOf( WP_Error::class, $result['wp_error'] );
		$this->assertSame( 'decorative_color', $result['wp_error']->get_error_code() );
		$this->assertSame( 0, $result['wp_error']->get_error_data()['index'] );
		$this->assertSame( $attr, $result['wp_error']->get_error_data()['attr'] );
		$this->assertSame( $content, $result['content'], 'a refusal returns the input untouched' );
	}

	public function test_decorative_color_on_a_nested_block_reports_its_pattern_index(): void {
		$section = str_replace(
			'<!-- wp:paragraph -->',
			'<!-- wp:paragraph {"textColor":"contrast"} -->',
			$this->markup( 'text-section' )
		);

		$result = $this->validator->validate( $this->markup( 'hero' ) . "\n\n" . $section );

		$this->assertSame( 'decorative_color', $this->errorCode( $result ) );
		$this->assertSame( 1, $result->get_error_data()['index'] );
		$this->assertSame( 'core/paragraph', $result->get_error_data()['name'] );
	}

	// --- Step 4b: block_mismatch (editor parity) --------------------------

	public function test_block_mismatch_refuses_html_that_disagrees_with_attributes(): void {
		$content = str_replace(
			'<h3 class="wp-block-heading">First feature</h3>',
			'<h4 class="wp-block-heading">First feature</h4>',
			$this->page( 'hero', 'feature-grid' )
		);

		$result = $this->validator->validate( $content );

		$this->assertSame( 'block_mismatch', $this->errorCode( $result ) );
		$data = $result->get_error_data();
		$this->assertSame( 1, $data['index'] );
		$this->assertSame( 'core/heading', $data['name'] );
		$this->assertSame( 'html', $data['reason'] );
		$this->assertStringContainsString( '<h3 class="wp-block-heading">…</h3>', $result->get_error_message() );
		$this->assertStringContainsString( '<h4 class="wp-block-heading">…</h4>', $result->get_error_message() );
	}

	public function test_block_mismatch_refuses_a_missing_base_class(): void {
		$content = str_replace(
			'wp-block-button__link wp-element-button',
			'wp-block-button__link',
			$this->page( 'hero', 'text-section' )
		);

		$result = $this->validator->validate( $content );

		$this->assertSame( 'block_mismatch', $this->errorCode( $result ) );
		$this->assertSame( 'core/button', $result->get_error_data()['name'] );
	}

	public function test_block_mismatch_refuses_attributes_the_vocabulary_never_uses(): void {
		$content = str_replace( '<!-- wp:button -->', '<!-- wp:button {"width":50} -->', $this->page( 'hero', 'text-section' ) );

		$result = $this->validator->validate( $content );

		$this->assertSame( 'block_mismatch', $this->errorCode( $result ) );
		$data = $result->get_error_data();
		$this->assertSame( 'attributes', $data['reason'] );
		$this->assertSame( '{"width":50}', $data['found'] );
		$this->assertSame( '{}', $data['expected'] );
	}

	public function test_block_mismatch_refuses_a_preset_changed_on_one_side_only(): void {
		$content = preg_replace( '#spacing\|60#', 'spacing|40', $this->page( 'hero', 'text-section' ), 1 );

		$result = $this->validator->validate( (string) $content );

		$this->assertSame( 'block_mismatch', $this->errorCode( $result ) );
		$this->assertSame( 'attributes', $result->get_error_data()['reason'] );
	}

	public function test_a_preset_changed_consistently_is_accepted(): void {
		$content = str_replace(
			array( 'spacing|60', 'spacing--60', '"fontSize":"large"', 'has-large-font-size' ),
			array( 'spacing|40', 'spacing--40', '"fontSize":"x-large"', 'has-x-large-font-size' ),
			$this->page( 'hero', 'text-section' )
		);

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	public function test_extra_classes_are_accepted(): void {
		$content = str_replace( 'wp-block-group alignfull', 'wp-block-group alignfull is-style-hero', $this->page( 'hero', 'text-section' ) );

		$this->assertTrue( $this->validator->validate( $content ) );
	}

	// --- Step 5: mutations ------------------------------------------------

	public function test_clean_normalises_pattern_metadata_name(): void {
		$hero    = str_replace( '"name":"senroflux/hero"', '"name":"My hero"', $this->markup( 'hero' ) );
		$content = $hero . "\n\n" . $this->markup( 'text-section' );

		$result = $this->validator->clean( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( '"name":"senroflux/hero"', $result['content'] );
		$this->assertStringNotContainsString( 'My hero', $result['content'] );
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

	// --- 0.3 S21: curated wins a shape tie against a theme pattern ---------

	/**
	 * A theme pattern structurally IDENTICAL to the curated `hero` (same
	 * blockName tree, same layout-defining attrs — the only thing
	 * {@see Validator::matchesShape()} keys on) still resolves to the
	 * CURATED slug, because {@see Vocabulary::all()} appends theme-derived
	 * patterns AFTER the curated seven and `matchPatternSchema()` returns the
	 * first structural match it finds.
	 */
	public function test_curated_pattern_wins_a_shape_tie_against_a_theme_pattern(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$hero_markup = $this->markup( 'hero' );

		$GLOBALS['senroflux_test_theme_patterns'] = array(
			array(
				'name'        => 'twentytwentyfive/fake-hero',
				'title'       => 'Fake hero',
				'description' => 'A theme pattern with the exact same shape as the curated hero.',
				// Same structure, different sample copy — matchesShape() never
				// looks at rich-text content, only block names/layout attrs.
				'content'     => str_replace(
					array( 'A headline that states the promise', 'One supporting sentence saying who this is for and what they get.' ),
					array( 'A totally different headline', 'A totally different supporting line here.' ),
					$hero_markup
				),
				'filePath'    => dirname( __DIR__, 2 ) . '/ThemePatterns/fake-hero.php',
				'categories'  => array( 'banner' ),
			),
		);
		ThemePatterns::resetCache();
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/ThemePatterns';

		// A fresh Vocabulary/Validator pair so `all()` picks up the stubbed
		// theme pattern registered just above.
		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );

		$this->assertNotEmpty( $vocabulary->themeDerived(), 'the stub must actually register a theme pattern' );

		$content = $hero_markup . "\n\n" . $this->markup( 'text-section' );
		$result  = $validator->clean( $content );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( '"name":"senroflux/hero"', $result['content'], 'the curated slug wins the tie' );
		$this->assertStringNotContainsString( 'fake-hero', $result['content'] );

		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function loadThemeFixture( string $slug ): array {
		$path = dirname( __DIR__, 2 ) . '/ThemePatterns/' . $slug . '.php';
		$raw  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.

		$header = array();
		foreach ( array( 'Title', 'Slug', 'Description', 'Categories' ) as $key ) {
			if ( preg_match( '/^\s*\*\s*' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $raw, $m ) ) {
				$header[ $key ] = trim( $m[1] );
			}
		}

		ob_start();
		include $path;
		$content = trim( (string) ob_get_clean() );

		$entry = array(
			'name'        => $header['Slug'] ?? $slug,
			'title'       => $header['Title'] ?? $slug,
			'description' => $header['Description'] ?? '',
			'content'     => $content,
			'filePath'    => $path,
		);

		if ( isset( $header['Categories'] ) ) {
			$entry['categories'] = array_map( 'trim', explode( ',', $header['Categories'] ) );
		}

		return $entry;
	}

	/**
	 * 0.3 quality fix 1 (theme patterns first): a page built almost entirely
	 * from the active theme's OWN patterns — a real banner as the hero, two
	 * unrelated real content patterns, and one real dedicated CTA pattern —
	 * still passes the S11 page-shape rules (hero first, at most one cta, 2–8
	 * patterns, no repeats). Uses the same real Twenty Twenty-Five fixtures
	 * as {@see \Specflux\SenroFlux\Tests\Packs\Pages\CopyRulesCeilingTest}.
	 * `pricing-3-col` is included deliberately: it carries `banner,
	 * call-to-action, services` all at once, so it exercises the same
	 * multi-category fix as
	 * {@see \Specflux\SenroFlux\Tests\Packs\Pages\ThemePatternsTest::test_a_pattern_with_multiple_categories_is_neither_hero_nor_cta()}
	 * inside a real page (it must NOT count as a second hero or a second
	 * cta alongside `banner-intro`/`cta-centered-heading`).
	 * `testimonials-6-col` is left out: its shipped CSS `var(--...)` value
	 * round-trips differently through this suite's `serialize_blocks()`
	 * stub (a test-double limitation, not a Validator bug — see
	 * `tests/stubs/blocks.php`), which is orthogonal to this test's purpose.
	 */
	public function test_a_page_built_mostly_from_theme_patterns_passes_page_shape(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$slugs = array( 'banner-intro', 'text-faqs', 'pricing-3-col', 'cta-centered-heading' );

		$GLOBALS['senroflux_test_theme_patterns'] = array_map(
			fn ( string $slug ) => $this->loadThemeFixture( $slug ),
			$slugs
		);
		ThemePatterns::resetCache();
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/ThemePatterns';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/ThemePatterns';

		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );

		$this->assertCount( 4, $vocabulary->themeDerived(), 'all four fixtures must be eligible' );

		$page = implode(
			"\n\n",
			array_map(
				static fn ( array $fixture ): string => $fixture['content'],
				$GLOBALS['senroflux_test_theme_patterns']
			)
		);

		$result = $validator->clean( $page );

		$this->assertTrue( $result['ok'], $result['wp_error']?->get_error_message() ?? '' );

		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'] );
	}

	/**
	 * 0.3 quality feature 4, end to end: a theme pattern's own image slot,
	 * filled with a NEW attachment (never the shipped sample's), passes the
	 * pack's real Validator — the same block-shape match ({@see BlockShells}'s
	 * `core/image` `id`-attribute exception) and the same alt-text gate
	 * ({@see \Specflux\SenroFlux\Packs\Content\ImageAlt}) a live run's write
	 * goes through.
	 */
	public function test_a_theme_patterns_image_slot_filled_with_a_new_attachment_passes_validation(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$path = dirname( __DIR__, 2 ) . '/ThemePatterns/banner-about-book.php';
		ob_start();
		include $path; // Same technique CopyRulesCeilingTest/ThemePatternsTest use to render a fixture exactly as WP_Theme::get_block_patterns() would.
		$content = trim( (string) ob_get_clean() );

		$GLOBALS['senroflux_test_theme_patterns'] = array(
			array(
				'name'        => 'twentytwentyfive/banner-about-book',
				'title'       => 'Banner with book description',
				'description' => 'Banner with book description and accompanying image for promotion.',
				'content'     => $content,
				'filePath'    => dirname( __DIR__, 2 ) . '/ThemePatterns/banner-about-book.php',
				'categories'  => array( 'banner' ),
			),
		);
		ThemePatterns::resetCache();
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/ThemePatterns';

		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );

		$slots  = ThemePatterns::textSlots( $content );
		$values = array();
		foreach ( $slots as $slot ) {
			$values[ $slot['index'] ] = 'image' === $slot['kind']
				? 'https://example.test/wp-content/uploads/new-cover.jpg||A new book cover'
				: 'A new short heading';
		}

		$filled = ThemePatterns::fill( $content, $values );
		$this->assertTrue( $filled['ok'] );

		// A page needs 2–8 patterns (page_shape); the image pattern alone
		// (hero-like: `is_hero` true, per its `banner` category) is joined by
		// a second, unrelated curated pattern.
		$page   = $filled['content'] . "\n\n" . $this->markup( 'text-section' );
		$result = $validator->clean( $page );

		$this->assertTrue( $result['ok'], $result['wp_error']?->get_error_message() ?? '' );
		$this->assertStringContainsString( 'new-cover.jpg', $result['content'] );
		$this->assertStringContainsString( 'A new book cover', $result['content'] );

		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'] );
	}

	// --- D3a (S4): theme-shipped colour ----------------------------------

	/**
	 * Registers the REAL Ollie fixture `tests/ThemePatterns/ollie/numbers-stacked.php`
	 * (GPL, same licence as this plugin; original header kept for
	 * provenance — `.scratch/senroflux-design/themes/ollie/patterns/numbers-stacked.php`
	 * from the S3 scan) as the active theme's own pattern, under Ollie's
	 * real palette (`.scratch/senroflux-design/theme-scan.md`'s ollie
	 * section) rather than the stub's Twenty Twenty-Five default — this
	 * pattern's shipped `backgroundColor`/`textColor` (`primary`/`base`/
	 * `primary-accent`) are Ollie slugs, not Twenty Twenty-Five ones.
	 *
	 * @return array<string,mixed> The registered pattern's own fixture entry (`content` included).
	 */
	private function registerOllieNumbersStacked(): array {
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$path = dirname( __DIR__, 2 ) . '/ThemePatterns/ollie/numbers-stacked.php';
		ob_start();
		include $path;
		$content = trim( (string) ob_get_clean() );

		$fixture = array(
			'name'        => 'ollie/numbers-stacked',
			'title'       => 'Numbers Stacked',
			'description' => 'Display impressive numbers with a short description',
			'content'     => $content,
			'filePath'    => $path,
			'categories'  => array( 'ollie/features' ),
		);

		$GLOBALS['senroflux_test_theme_patterns']  = array( $fixture );
		$GLOBALS['senroflux_test_stylesheet_dir']  = dirname( __DIR__, 2 ) . '/ThemePatterns/ollie';
		$GLOBALS['senroflux_test_template_dir']    = dirname( __DIR__, 2 ) . '/ThemePatterns/ollie';
		$GLOBALS['senroflux_test_global_settings'] = array(
			'color' => array(
				'palette'   => array(
					array( 'slug' => 'primary' ),
					array( 'slug' => 'primary-accent' ),
					array( 'slug' => 'primary-alt' ),
					array( 'slug' => 'primary-alt-accent' ),
					array( 'slug' => 'main' ),
					array( 'slug' => 'main-accent' ),
					array( 'slug' => 'base' ),
					array( 'slug' => 'secondary' ),
					array( 'slug' => 'tertiary' ),
					array( 'slug' => 'border-light' ),
					array( 'slug' => 'border-dark' ),
				),
				'gradients' => array(),
			),
		);
		ThemePatterns::resetCache();

		return $fixture;
	}

	private function teardownOllieFixture(): void {
		ThemePatterns::resetCache();
		unset(
			$GLOBALS['senroflux_test_theme_patterns'],
			$GLOBALS['senroflux_test_stylesheet_dir'],
			$GLOBALS['senroflux_test_template_dir'],
			$GLOBALS['senroflux_test_global_settings']
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function ollieNumbersStackedValues( array $slots ): array {
		$values = array();
		foreach ( $slots as $slot ) {
			$values[ $slot['index'] ] = 'New short copy';
		}

		return $values;
	}

	/**
	 * D3a (S4): the pattern's own shipped `backgroundColor`/`textColor`
	 * (`primary`/`base` on the outer group) survive a slot fill —
	 * {@see ThemePatterns::fill()} never touches a block's comment
	 * attributes, only rich-text/url/image slots — and the cleaned page
	 * still carries them afterwards; `Validator::clean()` does not refuse
	 * them as `decorative_color` even though the block was never part of
	 * the curated vocabulary to begin with.
	 */
	public function test_theme_pattern_shipped_colour_survives_a_slot_fill_and_clean(): void {
		$fixture = $this->registerOllieNumbersStacked();

		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );
		$this->assertNotEmpty( $vocabulary->themeDerived(), 'the Ollie fixture must be eligible under its own palette' );

		$slots  = ThemePatterns::textSlots( $fixture['content'] );
		$values = $this->ollieNumbersStackedValues( $slots );

		$filled = ThemePatterns::fill( $fixture['content'], $values );
		$this->assertTrue( $filled['ok'], $filled['wp_error']?->get_error_message() ?? '' );
		$this->assertStringContainsString( '"backgroundColor":"primary","textColor":"base"', $filled['content'], 'fill() must never touch a comment colour attribute' );

		$page   = $this->markup( 'hero' ) . "\n\n" . $filled['content'];
		$result = $validator->clean( $page );

		$this->assertTrue( $result['ok'], $result['wp_error']?->get_error_message() ?? '' );
		$this->assertStringContainsString( '"backgroundColor":"primary","textColor":"base"', $result['content'] );
		$this->assertStringContainsString( 'has-primary-background-color', $result['content'] );
		$this->assertStringContainsString( '"textColor":"primary-accent"', $result['content'] );

		$this->teardownOllieFixture();
	}

	/**
	 * D3a (S4): `decorative_color` still refuses a colour the model writes
	 * itself, even on a block inside a pattern the write was otherwise
	 * recognised as — here the shipped outer `backgroundColor` (`primary`)
	 * is changed to another value that IS a valid Ollie preset
	 * (`primary-accent`), so this pins that admission is scoped to the
	 * EXACT shipped value at that position, not "any preset slug is fine
	 * because it's a theme pattern".
	 */
	public function test_theme_pattern_colour_changed_by_the_model_is_still_refused(): void {
		$fixture = $this->registerOllieNumbersStacked();

		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );

		$slots  = ThemePatterns::textSlots( $fixture['content'] );
		$values = $this->ollieNumbersStackedValues( $slots );

		$filled = ThemePatterns::fill( $fixture['content'], $values );
		$this->assertTrue( $filled['ok'] );

		$changed = str_replace( '"backgroundColor":"primary","textColor":"base"', '"backgroundColor":"primary-accent","textColor":"base"', $filled['content'] );
		$this->assertNotSame( $filled['content'], $changed, 'the replacement must actually apply' );

		$page   = $this->markup( 'hero' ) . "\n\n" . $changed;
		$result = $validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'decorative_color', $result['wp_error']->get_error_code() );
		$this->assertSame( 'backgroundColor', $result['wp_error']->get_error_data()['attr'] );

		$this->teardownOllieFixture();
	}

	/**
	 * D3a (S4): a colour the model adds on a block the theme pattern shipped
	 * with NO colour attribute at all is refused the same way — each
	 * "Feature" group ships plain, so giving the first one a `backgroundColor`
	 * (even a real Ollie preset) is still a model-written colour, not a
	 * theme-shipped one.
	 */
	public function test_theme_pattern_colour_added_where_none_was_shipped_is_still_refused(): void {
		$fixture = $this->registerOllieNumbersStacked();

		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );

		$slots  = ThemePatterns::textSlots( $fixture['content'] );
		$values = $this->ollieNumbersStackedValues( $slots );

		$filled = ThemePatterns::fill( $fixture['content'], $values );
		$this->assertTrue( $filled['ok'] );

		$changed = str_replace(
			'{"metadata":{"name":"Feature"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"constrained"}}',
			'{"metadata":{"name":"Feature"},"style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"backgroundColor":"main","layout":{"type":"constrained"}}',
			$filled['content'],
			$count
		);
		$this->assertGreaterThan( 0, $count, 'the replacement must actually apply' );

		$page   = $this->markup( 'hero' ) . "\n\n" . $changed;
		$result = $validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'decorative_color', $result['wp_error']->get_error_code() );
		$this->assertSame( 'backgroundColor', $result['wp_error']->get_error_data()['attr'] );

		$this->teardownOllieFixture();
	}
}
