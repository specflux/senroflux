<?php
/**
 * Vocabulary tests (stage 8, S11).
 *
 * TARGET REPO PATH: tests/Packs/Pages/VocabularyTest.php
 *
 * Verifies the seven-pattern vocabulary: registerability under the
 * `senroflux-pages` category, `serialize_blocks(parse_blocks())` round-trip
 * identity on ALL SEVEN markups, and the single-source assertion that
 * `listPayload`'s `constraints.stated` lines are exactly the copy lines the
 * `pages/copy-rules` skill is rendered from (so the tool payload and the
 * instruction can never drift).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use Specflux\SenroFlux\Packs\Site\SitePack;

final class VocabularyTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_patterns']           = array();
		$GLOBALS['senroflux_test_pattern_categories'] = array();
		\Specflux\SenroFlux\Packs\Pages\ThemePatterns::resetCache();
		$GLOBALS['senroflux_test_theme_patterns'] = array();
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/ThemePatterns';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/ThemePatterns';
	}

	/**
	 * wp.org review (0.3.2): a shipped file may not reference a remote URL.
	 * The image patterns carry a token that resolves to the bundled local
	 * placeholder at load time.
	 */
	public function test_no_shipped_pattern_file_contains_a_remote_url(): void {
		$files = glob( dirname( __DIR__, 3 ) . '/src/Packs/*/patterns/*.html' );
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$this->assertDoesNotMatchRegularExpression( '#https?://#i', (string) file_get_contents( $file ), basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local pattern file.
		}
		$this->assertFileExists( dirname( __DIR__, 3 ) . '/images/placeholder.jpg' );
	}

	public function test_image_patterns_resolve_the_placeholder_to_the_local_plugin_file(): void {
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			if ( ! in_array( $pattern['name'], array( 'senroflux/cover-hero', 'senroflux/media-text' ), true ) ) {
				continue;
			}
			$this->assertStringNotContainsString( '{{', $pattern['markup'] );
			$this->assertStringContainsString( SENROFLUX_URL . 'images/placeholder.jpg', $pattern['markup'] );
		}
	}

	/** 0.3 quality fix: nine, since `cover-hero`/`media-text` joined the curated set. */
	public function test_all_returns_seven_patterns(): void {
		$this->assertCount( 9, ( new Vocabulary() )->all() );
	}

	public function test_register_registers_all_seven_under_category(): void {
		$vocabulary = new Vocabulary();
		$count      = $vocabulary->register();

		$this->assertSame( 9, $count );
		$this->assertSame( 9, count( $GLOBALS['senroflux_test_patterns'] ) );
		$this->assertArrayHasKey( Vocabulary::CATEGORY, $GLOBALS['senroflux_test_pattern_categories'] );

		foreach ( $vocabulary->all() as $pattern ) {
			$this->assertArrayHasKey( $pattern['name'], $GLOBALS['senroflux_test_patterns'] );
			$this->assertContains(
				Vocabulary::CATEGORY,
				$GLOBALS['senroflux_test_patterns'][ $pattern['name'] ]['categories']
			);
		}
	}

	public function test_round_trip_identity_on_all_seven_markups(): void {
		$vocabulary = new Vocabulary();

		foreach ( $vocabulary->all() as $pattern ) {
			// BYTE-exact, not normalised: this runs on WordPress core's real
			// parser, so there is nothing to forgive.
			$this->assertSame(
				$pattern['markup'],
				serialize_blocks( parse_blocks( (string) $pattern['markup'] ) ),
				$pattern['name'] . ' must survive a parse→serialize round-trip'
			);
		}
	}

	public function test_no_pattern_carries_a_colour_attribute(): void {
		// S11: no colour attributes anywhere in the vocabulary — EXCEPT
		// `cover-hero`'s `overlayColor` (0.3 quality fix): a PRESET SLUG
		// (`contrast`), never `customOverlayColor`/a hex value, the same
		// distinction S11 already draws for spacing/typography presets vs
		// raw values. It is the whole point of an image-led hero (dim the
		// photo so the heading reads), so it is the one documented exception
		// to "no `-background-color` class" below.
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			$markup     = (string) $pattern['markup'];
			$is_overlay = 'senroflux/cover-hero' === $pattern['name'];

			$this->assertStringNotContainsString( 'backgroundColor', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'textColor', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'gradient', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( '"color"', $markup, $pattern['name'] );
			if ( ! $is_overlay ) {
				$this->assertStringNotContainsString( '-background-color', $markup, $pattern['name'] );
			}
			$this->assertStringNotContainsString( 'has-text-color', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'customOverlayColor', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'customGradient', $markup, $pattern['name'] );
		}
	}

	public function test_no_pattern_carries_an_image_or_a_placeholder(): void {
		// 0.3 quality fix: `cover-hero`/`media-text` are the two DELIBERATE
		// exceptions — the whole point of adding them was a real image.
		$image_patterns = array( 'senroflux/cover-hero', 'senroflux/media-text' );

		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			$markup = (string) $pattern['markup'];

			if ( ! in_array( $pattern['name'], $image_patterns, true ) ) {
				$this->assertStringNotContainsString( 'wp:image', $markup, $pattern['name'] );
				$this->assertStringNotContainsString( '<img', $markup, $pattern['name'] );
			}
			// A registered pattern a human inserts has to be writable back
			// through create/update; a `{{placeholder}}` would be refused.
			$this->assertDoesNotMatchRegularExpression( '/\{\{.*?\}\}/', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'style="..."', $markup, $pattern['name'] );
		}
	}

	public function test_every_pattern_names_itself_canonically(): void {
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			$this->assertStringContainsString(
				'"metadata":{"name":"senroflux/' . $pattern['slug'] . '"}',
				(string) $pattern['markup'],
				$pattern['name']
			);
		}
	}

	public function test_every_registered_pattern_writes_back_unchanged(): void {
		// The round-trip decision, asserted end to end: take the markup the
		// editor would insert, run it through the write validator, and require
		// that it both passes and comes back byte for byte.
		$vocabulary = new Vocabulary();
		$validator  = new Validator( $vocabulary );
		$hero       = (string) $vocabulary->all()[0]['markup'];

		foreach ( $vocabulary->all() as $pattern ) {
			if ( 'hero' === $pattern['slug'] ) {
				continue;
			}

			// A second hero carries its own H1, and a page may have only one:
			// it leads the page instead, followed by a text-section.
			$content = str_contains( (string) $pattern['markup'], '<h1' )
				? (string) $pattern['markup'] . "\n\n" . (string) $vocabulary->all()[1]['markup']
				: $hero . "\n\n" . (string) $pattern['markup'];
			$result  = $validator->clean( $content );

			$this->assertTrue( $result['ok'], $pattern['name'] . ': ' . ( $result['wp_error'] ? $result['wp_error']->get_error_code() : '' ) );
			$this->assertSame( $content, $result['content'], $pattern['name'] );
		}
	}

	public function test_every_pattern_declares_the_child_it_may_repeat(): void {
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			$this->assertArrayHasKey( 'repeatable', $pattern, $pattern['name'] );
			$this->assertNotEmpty( $pattern['repeatable'], $pattern['name'] );
			foreach ( $pattern['repeatable'] as $name ) {
				$this->assertContains( $name, ( new Vocabulary() )->blockNames(), $pattern['name'] );
			}
		}
	}

	/**
	 * The single-source contract: `list-patterns` is the ONE place a pattern's
	 * copy limits live. `pages/copy-rules` points the model at it rather than
	 * restating the `stated` lines, so the instruction cannot drift from the
	 * payload and does not grow with the pattern count.
	 */
	public function test_copy_rules_points_at_list_patterns_instead_of_restating_stated_lines(): void {
		$vocabulary = new Vocabulary();
		// `copyRulesBody()` is built from `all()`, exactly as `PagesPack` calls
		// it — NOT from `listPayload()`'s (now index-shaped) default.
		$full_entries = $vocabulary->all();
		$body         = ( new PagesPack() )->copyRulesBody( $full_entries );

		$this->assertStringContainsString( 'pages/list-patterns', $body );
		$this->assertStringContainsString( 'constraints.stated', $body );

		foreach ( $full_entries as $pattern ) {
			$this->assertNotEmpty( $pattern['constraints']['stated'], $pattern['name'] . ' must ship its copy limits in the payload' );
			foreach ( $pattern['constraints']['stated'] as $line ) {
				$this->assertStringNotContainsString( $line, $body );
			}
		}

		// The full-entry payload (requested by name) still carries the
		// same `stated` lines the skill body deliberately does not restate.
		$names   = array_column( $full_entries, 'name' );
		$payload = $vocabulary->listPayload( $names );
		$this->assertArrayNotHasKey( 'not_found', $payload );
		foreach ( $payload['patterns'] as $pattern ) {
			$this->assertNotEmpty( $pattern['constraints']['stated'], $pattern['name'] . ' full entry must still carry its copy limits' );
		}

		// Global copy limits are appended by the same builder.
		$this->assertStringContainsString( 'at most 40 words', $body );
		$this->assertStringContainsString( 'verb-first', $body );
		$this->assertStringContainsString( 'never write a placeholder price', $body );
	}

	/**
	 * The theme-derived half of the same contract: a theme pattern's `stated`
	 * lines (one per text/url slot) are NOT restated in the skill body — only
	 * ONE compact summary line naming it, pointing at `pages/list-patterns`
	 * and the `{pattern, slots}` fill shape.
	 */
	public function test_theme_derived_patterns_get_one_summary_line_not_stated_lines(): void {
		$GLOBALS['senroflux_test_theme_patterns'] = array(
			array(
				'name'        => 'sometheme/hero',
				'title'       => 'Hero banner',
				'description' => 'A hero.',
				'content'     => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Welcome to our site</h2><!-- /wp:heading --></div><!-- /wp:group -->',
				'filePath'    => dirname( __DIR__, 2 ) . '/ThemePatterns/fake-hero.php',
			),
		);
		\Specflux\SenroFlux\Packs\Pages\ThemePatterns::resetCache();

		$vocabulary   = new Vocabulary();
		$full_entries = $vocabulary->all();
		$theme        = array_values(
			array_filter( $full_entries, static fn ( array $p ): bool => ! empty( $p['theme_derived'] ) )
		);
		$this->assertNotEmpty( $theme, 'the fixture theme pattern must be eligible' );

		$body = ( new PagesPack() )->copyRulesBody( $full_entries );

		// None of the theme pattern's per-slot `stated` lines are restated.
		foreach ( $theme[0]['constraints']['stated'] as $line ) {
			$this->assertStringNotContainsString( $line, $body );
		}

		// One summary line counts them and points at list-patterns; titles live in the index.
		$this->assertStringNotContainsString( 'Hero banner', $body );
		$this->assertStringContainsString( 'offers 1 patterns', $body );
		$this->assertStringContainsString( 'pages/list-patterns', $body );
		$this->assertStringContainsString( '{pattern, slots}', $body );
	}

	/**
	 * The live Twenty Twenty-Five site offers 22 eligible patterns, and listing
	 * their titles pushed the site skills past the ceiling. The copy rules must
	 * stay the same size however many patterns a theme ships.
	 */
	public function test_copy_rules_do_not_grow_with_the_number_of_theme_patterns(): void {
		$lengths = array();
		foreach ( array( 1, 60 ) as $count ) {
			$GLOBALS['senroflux_test_theme_patterns'] = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$GLOBALS['senroflux_test_theme_patterns'][] = array(
					'name'        => 'sometheme/section-' . $i,
					'title'       => 'A fairly long theme pattern title number ' . $i,
					'description' => 'A section.',
					'content'     => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Welcome to our site</h2><!-- /wp:heading --></div><!-- /wp:group -->',
					'filePath'    => dirname( __DIR__, 2 ) . '/ThemePatterns/fake-section-' . $i . '.php',
				);
			}
			\Specflux\SenroFlux\Packs\Pages\ThemePatterns::resetCache();
			$vocabulary = ( new Vocabulary() )->all();
			$this->assertCount( $count, array_filter( $vocabulary, static fn ( array $p ): bool => ! empty( $p['theme_derived'] ) ) );
			$lengths[ $count ] = mb_strlen( ( new PagesPack() )->copyRulesBody( $vocabulary ) );
		}
		\Specflux\SenroFlux\Packs\Pages\ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'] );

		// Only the digit count may differ ("1" versus "60").
		$this->assertSame( $lengths[1] + 1, $lengths[60] );
	}

	/**
	 * The pages and site packs build theme patterns through layouts, so their
	 * `list-patterns` never offers them for numbered-slot filling; a
	 * vocabulary that opts in still does.
	 */
	public function test_list_payload_leaves_out_theme_patterns_unless_the_vocabulary_offers_slots(): void {
		$GLOBALS['senroflux_test_theme_patterns'] = array(
			array(
				'name'        => 'sometheme/hero',
				'title'       => 'Hero banner',
				'description' => 'A hero.',
				'content'     => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2>Welcome to our site</h2><!-- /wp:heading --></div><!-- /wp:group -->',
				'filePath'    => dirname( __DIR__, 2 ) . '/ThemePatterns/fake-hero.php',
			),
		);
		\Specflux\SenroFlux\Packs\Pages\ThemePatterns::resetCache();

		$pages = new Vocabulary();
		$this->assertNotNull( $pages->resolveThemePattern( 'sometheme/hero' ), 'layouts and the validator still see it' );
		$this->assertNotContains( 'sometheme/hero', array_column( $pages->listPayload()['patterns'], 'name' ) );
		$this->assertSame( array( 'sometheme/hero' ), $pages->listPayload( array( 'sometheme/hero' ) )['not_found'] );

		$site = new \Specflux\SenroFlux\Packs\Site\Vocabulary();
		$this->assertNotContains( 'sometheme/hero', array_column( $site->listPayload()['patterns'], 'name' ) );

		$slots = new class() extends Vocabulary {
			public function offersThemeSlots(): bool {
				return true;
			}
		};
		$this->assertContains( 'sometheme/hero', array_column( $slots->listPayload()['patterns'], 'name' ) );

		\Specflux\SenroFlux\Packs\Pages\ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'] );
	}

	/**
	 * With no `names`, `listPayload()` returns a compact
	 * INDEX — name/title/description only, never markup/constraints/slots.
	 */
	public function test_list_payload_with_no_names_is_a_compact_index(): void {
		$payload = ( new Vocabulary() )->listPayload();

		$this->assertArrayHasKey( 'patterns', $payload );
		$this->assertCount( 9, $payload['patterns'] );

		foreach ( $payload['patterns'] as $pattern ) {
			$this->assertArrayHasKey( 'name', $pattern );
			$this->assertArrayHasKey( 'title', $pattern );
			$this->assertArrayHasKey( 'description', $pattern );
			$this->assertArrayNotHasKey( 'constraints', $pattern );
			$this->assertArrayNotHasKey( 'markup', $pattern );
			$this->assertArrayNotHasKey( 'slots', $pattern );
		}
	}

	public function test_list_payload_with_names_has_constraints_per_pattern(): void {
		$vocabulary = new Vocabulary();
		$names      = array_column( $vocabulary->all(), 'name' );
		$payload    = $vocabulary->listPayload( $names );

		$this->assertArrayHasKey( 'patterns', $payload );
		$this->assertCount( 9, $payload['patterns'] );
		$this->assertArrayNotHasKey( 'not_found', $payload );

		foreach ( $payload['patterns'] as $pattern ) {
			$this->assertArrayHasKey( 'name', $pattern );
			$this->assertArrayHasKey( 'constraints', $pattern );
			$this->assertArrayHasKey( 'slots', $pattern['constraints'] );
			$this->assertArrayHasKey( 'stated', $pattern['constraints'] );
		}
	}

	/**
	 * 0.3 S7 gap fix: the payload's `markup` must be the SAME markup
	 * `all()`/the Validator ship — a copy would drift silently. Only
	 * requested when the caller names the pattern.
	 */
	public function test_list_payload_markup_matches_the_shipped_pattern_markup(): void {
		$vocabulary = new Vocabulary();
		$by_name    = array();
		foreach ( $vocabulary->all() as $pattern ) {
			$by_name[ $pattern['name'] ] = $pattern['markup'];
		}

		$payload = $vocabulary->listPayload( array_keys( $by_name ) );

		foreach ( $payload['patterns'] as $pattern ) {
			$this->assertArrayHasKey( 'markup', $pattern );
			$this->assertSame( $by_name[ $pattern['name'] ], $pattern['markup'] );
		}
	}

	/** Filtered call: full entries only for the requested names, in vocabulary order. */
	public function test_list_payload_with_names_returns_only_requested_in_vocabulary_order(): void {
		$vocabulary = new Vocabulary();

		$payload = $vocabulary->listPayload( array( 'senroflux/cta', 'senroflux/hero' ) );

		$this->assertCount( 2, $payload['patterns'] );
		$this->assertSame( 'senroflux/hero', $payload['patterns'][0]['name'] );
		$this->assertSame( 'senroflux/cta', $payload['patterns'][1]['name'] );
	}

	/** An unrecognised name is reported back, not a call failure. */
	public function test_list_payload_unknown_name_is_reported_not_found(): void {
		$vocabulary = new Vocabulary();

		$payload = $vocabulary->listPayload( array( 'senroflux/hero', 'senroflux/no-such-pattern' ) );

		$this->assertCount( 1, $payload['patterns'] );
		$this->assertSame( array( 'senroflux/no-such-pattern' ), $payload['not_found'] );
	}

	/**
	 * Live batches 2026-09-29-cards2 and seed2: Contact and About pages came
	 * out as hero, text, text, cta — one photo and no rhythm (Visual 3).
	 */
	public function test_both_page_skills_ask_for_a_break_between_text_sections(): void {
		foreach ( array( new PagesPack(), new SitePack() ) as $pack ) {
			$this->assertStringContainsString( 'Never put two text sections back to back', implode( "\n", array_map( static fn ( $s ) => $s->body, $pack->skills() ) ), $pack::class );
		}
	}

	/**
	 * Live batch 2026-09-29-seed3 scenario 1: the Services page listed
	 * audiences instead of treatments, and three of the brief's five
	 * services appeared nowhere on the site.
	 */
	public function test_both_page_skills_ask_for_every_service_in_the_brief(): void {
		foreach ( array( new PagesPack(), new SitePack() ) as $pack ) {
			$this->assertStringContainsString( 'Name every service the brief lists', implode( "\n", array_map( static fn ( $s ) => $s->body, $pack->skills() ) ), $pack::class );
		}
	}
}
