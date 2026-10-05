<?php
/**
 * Section layouts (0.3 quality trial): named fields in, theme pattern markup
 * out, checked against the real Twenty Twenty-Five fixtures and the pack's
 * own Validator.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use WP_Error;

final class LayoutsTest extends TestCase {

	private const FIXTURES = array(
		'hero-full-width-image',
		// Same block structure as heading-and-paragraph-with-image and
		// registered before it, as on a live Twenty Twenty-Five site.
		'banner-about-book',
		'heading-and-paragraph-with-image',
		'services-3-col',
		'text-faqs',
		'cta-centered-heading',
	);

	private const IMAGE = array(
		'url' => 'http://localhost:8897/wp-content/uploads/2026/09/runner-knee.webp',
		'alt' => 'A physiotherapist checks a runner\'s knee on the treatment table',
	);

	protected function setUp(): void {
		self::registerThemeFixtures();
	}

	/**
	 * Register the real fixtures every layout builds from as the active
	 * theme's patterns. Also used by `tests/editor/export-fixtures.php`.
	 */
	public static function registerThemeFixtures(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$dir = dirname( __DIR__, 2 ) . '/ThemePatterns';

		$GLOBALS['senroflux_test_theme_patterns'] = array_map( static fn ( string $slug ): array => self::loadFixture( $dir, $slug ), self::FIXTURES );
		$GLOBALS['senroflux_test_stylesheet_dir'] = $dir;
		$GLOBALS['senroflux_test_template_dir']   = $dir;
		ThemePatterns::resetCache();
	}

	protected function tearDown(): void {
		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'], $GLOBALS['senroflux_test_stylesheet'], $GLOBALS['senroflux_test_template'] );
		remove_all_filters( 'senroflux_layout_profiles' );
	}

	/**
	 * Render one real fixture file the way `WP_Theme::get_block_patterns()` does.
	 *
	 * @return array<string,mixed>
	 */
	private static function loadFixture( string $dir, string $slug ): array {
		$path = $dir . '/' . $slug . '.php';
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

		return array(
			'name'        => $header['Slug'] ?? $slug,
			'title'       => $header['Title'] ?? $slug,
			'description' => $header['Description'] ?? '',
			'content'     => $content,
			'filePath'    => $path,
			'categories'  => isset( $header['Categories'] ) ? array_map( 'trim', explode( ',', $header['Categories'] ) ) : array(),
		);
	}

	/**
	 * One section of every layout, the way a model would send them.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function outline(): array {
		$image   = static fn ( string $file ): array => array(
			'url' => 'http://localhost:8897/wp-content/uploads/2026/09/' . $file . '.webp',
			'alt' => 'A physiotherapist working with a patient: ' . $file,
		);
		$service = static fn ( string $title ): array => array(
			'title' => $title,
			'text'  => 'A first visit starts with a full assessment, then a plan you can follow at home between sessions.',
			'image' => $image( strtolower( str_replace( ' ', '-', $title ) ) ),
		);

		return array(
			array(
				'layout'  => 'hero',
				'image'   => self::IMAGE,
				'heading' => 'Back to running, properly',
				'text'    => 'Sports and injury physiotherapy in Northside, with evening appointments for people who train after work.',
				'button'  => array(
					'label' => 'Book an assessment',
					'url'   => 'tel:+441234567890',
				),
			),
			array(
				'layout'     => 'text',
				'heading'    => 'What happens at your first visit',
				'paragraphs' => array(
					'Your first appointment lasts an hour. We talk through how the injury happened, what makes it worse and what you want to get back to, then assess how you move so the plan fits your body and your goals rather than a template.',
					'You leave with a clear explanation of the problem, hands-on treatment where it helps, and two or three exercises to start that evening. Follow-up visits are shorter and focus on progressing the plan as you improve.',
				),
			),
			array(
				'layout'  => 'services',
				'heading' => 'How we help',
				'items'   => array( $service( 'Sports injuries' ), $service( 'Post-surgery rehab' ), $service( 'Back pain' ) ),
			),
			array(
				'layout'  => 'text-with-image',
				'heading' => 'Care that fits your week',
				'text'    => 'Appointments run until eight on weekdays, so you can come after work or training.',
				'image'   => $image( 'evening-clinic' ),
			),
			array(
				'layout'  => 'faq',
				'heading' => 'Questions before booking',
				'items'   => array(
					array(
						'question' => 'Do I need a referral?',
						'answer'   => 'No. You can book directly with us.',
					),
					array(
						'question' => 'What should I wear?',
						'answer'   => 'Loose clothing that lets us see the injured area.',
					),
					array(
						'question' => 'How long is a session?',
						'answer'   => 'The first visit is an hour; follow-ups are forty-five minutes.',
					),
					array(
						'question' => 'Can I claim on insurance?',
						'answer'   => 'Ask your insurer which physiotherapy providers they cover before you book.',
					),
				),
			),
			array(
				'layout'  => 'cta',
				'heading' => 'Ready to start?',
				'text'    => 'Call the clinic and we will find a time that works around your training.',
				'button'  => array(
					'label' => 'Call us',
					'url'   => 'tel:+441234567890',
				),
			),
		);
	}

	public function test_a_page_of_every_layout_passes_the_pages_validator(): void {
		$vocabulary = new Vocabulary();
		$parts      = array();
		foreach ( self::outline() as $index => $section ) {
			$built = Layouts::render( $section, $index, $vocabulary );
			$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
			$parts[] = $built;
		}

		$clean = ( new Validator( $vocabulary ) )->clean( implode( "\n\n", $parts ) );

		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
		$names = array();
		foreach ( parse_blocks( $clean['content'] ) as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = $block['attrs']['metadata']['name'] ?? '';
			}
		}
		$this->assertSame(
			array(
				'senroflux/twentytwentyfive/hero-full-width-image',
				'senroflux/text-section',
				'senroflux/twentytwentyfive/services-3-col',
				'senroflux/twentytwentyfive/heading-and-paragraph-with-image',
				'senroflux/twentytwentyfive/text-faqs',
				'senroflux/twentytwentyfive/cta-centered-heading',
			),
			$names
		);
		$this->assertStringContainsString( 'Back to running, properly', $clean['content'] );
		$this->assertStringContainsString( 'Do I need a referral?', $clean['content'] );
		$this->assertStringContainsString( 'href="tel:+441234567890"', $clean['content'] );
	}

	public function test_the_hero_cover_names_the_new_image_in_its_block_attributes(): void {
		$built = Layouts::render( self::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built );
		$cover = parse_blocks( $built )[0];
		$this->assertSame( self::IMAGE['url'], $cover['attrs']['url'] );
		$this->assertSame( self::IMAGE['alt'], $cover['attrs']['alt'] );
		$this->assertStringNotContainsString( 'northern-buttercups', $built );
	}

	public function test_a_field_over_its_limit_is_refused_by_name(): void {
		$section = self::outline()[2];
		// 8 words against a 6-word limit: past the 25% tolerance (floor(6*1.25) = 7).
		$section['items'][1]['title'] = 'Rehabilitation after knee and hip joint replacement surgery';

		$built = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_field', $built->get_error_code() );
		$this->assertSame( 'Section 3 (services): `items[1].title` has 8 words; the limit is 6. Shorten it, or move the detail into a `text` section.', $built->get_error_message() );
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-1-1: a 6-word hero heading was
	 * refused three times in a row against a 5-word limit. A field within
	 * {@see \Specflux\SenroFlux\Tools\PlanTools::LENGTH_TOLERANCE_PERCENT} (25%)
	 * of its word limit is accepted, not refused — `items[1].title`'s 6-word
	 * limit tolerates up to `floor(6 * 1.25) = 7` words. This is the exact
	 * 7-word string {@see test_a_field_over_its_limit_is_refused_by_name()}'s
	 * predecessor used to REFUSE.
	 */
	public function test_a_field_within_25_percent_of_its_limit_is_accepted(): void {
		$section                      = self::outline()[2];
		$section['items'][1]['title'] = 'Rehabilitation after knee and hip replacement surgery';

		$built = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
	}

	public function test_a_service_description_may_run_past_the_theme_sample_length(): void {
		$section = self::outline()[2];
		// 75 words against a 60-word limit is exactly the tolerated ceiling (floor(60*1.25) = 75).
		$section['items'][0]['text'] = trim( str_repeat( 'word ', 75 ) );
		$built                       = Layouts::render( $section, 2, new Vocabulary() );
		$section['items'][0]['text'] = trim( str_repeat( 'word ', 76 ) );
		$too_long                    = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertIsString( $built, 'the theme sample has 14 words; a layout allows 60, tolerated to 75' );
		$this->assertInstanceOf( WP_Error::class, $too_long );
		$this->assertStringContainsString( '`items[0].text` has 76 words; the limit is 60', $too_long->get_error_message() );
	}

	public function test_a_services_heading_may_say_what_the_services_are(): void {
		$section = self::outline()[2];
		// 9 words against an 8-word limit is within the 25% tolerance (floor(8*1.25) = 10).
		$section['heading'] = 'Physiotherapy for sports injuries, surgery, everyday pain and more';
		$built              = Layouts::render( $section, 2, new Vocabulary() );
		$section['heading'] = trim( str_repeat( 'word ', 11 ) );
		$too_long           = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertInstanceOf( WP_Error::class, $too_long );
		$this->assertStringContainsString( '`heading` has 11 words; the limit is 8', $too_long->get_error_message() );
	}

	public function test_the_hero_heading_is_the_page_h1(): void {
		$built = Layouts::render( self::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( '"level":1', $built );
		$this->assertMatchesRegularExpression( '#<h1 class="wp-block-heading[^"]*">Back to running, properly</h1>#', $built );
		$this->assertStringNotContainsString( '<h2', $built );
	}

	public function test_a_second_h1_after_the_hero_is_refused(): void {
		$vocabulary = new Vocabulary();
		$hero       = (string) Layouts::render( self::outline()[0], 0, $vocabulary );

		$clean = ( new Validator( $vocabulary ) )->clean( $hero . "\n\n" . $hero );

		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'Only the hero may have a level-1 heading, and only one; use level 2 or 3 elsewhere.', $clean['wp_error']->get_error_message() );
	}

	public function test_image_urls_lists_every_image_a_section_names(): void {
		$this->assertSame(
			array(
				'http://localhost:8897/wp-content/uploads/2026/09/sports-injuries.webp',
				'http://localhost:8897/wp-content/uploads/2026/09/post-surgery-rehab.webp',
				'http://localhost:8897/wp-content/uploads/2026/09/back-pain.webp',
			),
			Layouts::imageUrls( self::outline()[2] )
		);
		$this->assertSame( array( self::IMAGE['url'] ), Layouts::imageUrls( self::outline()[0] ) );
		$this->assertSame( array(), Layouts::imageUrls( self::outline()[1] ) );
	}

	public function test_the_wrong_number_of_items_is_refused(): void {
		$section = self::outline()[2];
		array_pop( $section['items'] );

		$built = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertStringContainsString( 'takes exactly 3 items; it has 2', $built->get_error_message() );
	}

	public function test_an_image_without_alt_text_is_refused(): void {
		$section                 = self::outline()[3];
		$section['image']['alt'] = '';

		$built = Layouts::render( $section, 3, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertStringContainsString( '`image` needs a `url`', $built->get_error_message() );
	}

	/**
	 * Bug 3 (images budget 0): a live budget-0 run got "items[0].image needs
	 * a url from the media library (media-search or media-generate)" — but
	 * media-generate is withheld from a budget-0 run's tool surface entirely
	 * (see ToolRegistry::forRun()), so the message must never name it.
	 */
	public function test_a_missing_image_at_budget_zero_never_mentions_media_generate(): void {
		$section                             = self::outline()[2];
		$section['items'][0]['image']['url'] = '';

		$built = Layouts::render( $section, 2, new Vocabulary(), true );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertStringContainsString( 'items[0].image` needs a `url`', $built->get_error_message() );
		$this->assertStringNotContainsString( 'media-generate', $built->get_error_message() );
		$this->assertStringContainsString( 'stock-image-search', $built->get_error_message() );
	}

	/**
	 * 0.3 quality fix (images budget 0): with a photo on every card, the
	 * services layout renders exactly as it always has.
	 */
	public function test_services_with_a_photo_on_every_card_renders_as_before(): void {
		$vocabulary = new Vocabulary();
		$hero       = (string) Layouts::render( self::outline()[0], 0, $vocabulary );
		$built      = Layouts::render( self::outline()[2], 2, $vocabulary );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 3, substr_count( $built, '<!-- wp:image ' ) );

		// A page needs 2+ patterns (Validator::checkPageShape()); the hero
		// makes this section's own page-shape valid on its own.
		$clean = ( new Validator( $vocabulary ) )->clean( $hero . "\n\n" . $built );
		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
	}

	/**
	 * 0.3 quality fix (images budget 0): there is rarely a third distinct,
	 * relevant CC0 photo for every industry, so when NO card gives one the
	 * pattern's own `core/image` blocks are dropped rather than refused —
	 * and the result still has to pass the pages Validator, not just
	 * {@see Layouts::render()}.
	 */
	public function test_services_with_no_photos_drops_the_image_blocks_and_passes_the_validator(): void {
		$vocabulary = new Vocabulary();
		$hero       = (string) Layouts::render( self::outline()[0], 0, $vocabulary );
		$section    = self::outline()[2];
		foreach ( $section['items'] as &$item ) {
			unset( $item['image'] );
		}
		unset( $item );

		$built = Layouts::render( $section, 2, $vocabulary );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 0, substr_count( $built, '<!-- wp:image' ) );

		$clean = ( new Validator( $vocabulary ) )->clean( $hero . "\n\n" . $built );
		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );

		$names = array();
		foreach ( parse_blocks( $clean['content'] ) as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = $block['attrs']['metadata']['name'] ?? '';
			}
		}
		$this->assertSame(
			array( 'senroflux/twentytwentyfive/hero-full-width-image', 'senroflux/twentytwentyfive/services-3-col' ),
			$names
		);
		$this->assertStringContainsString( 'Sports injuries', $clean['content'] );
	}

	/**
	 * Some cards with a full photo and others with none is refused outright
	 * (0.3 quality fix, images budget 0): never favour one card's business
	 * over another's by letting some go photo-less and not others.
	 */
	public function test_services_with_a_mix_of_photo_and_no_photo_cards_is_refused(): void {
		$section = self::outline()[2];
		unset( $section['items'][2]['image'] );

		$built = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_field', $built->get_error_code() );
		$this->assertStringContainsString( 'give every card a photo, or none', $built->get_error_message() );
	}

	/**
	 * A card image with a `url` but no `alt` (or the reverse) is still an
	 * incomplete image, refused exactly as before by field name — never
	 * treated as "this card opted out" the way a wholly absent image is.
	 */
	public function test_a_services_image_with_a_url_but_no_alt_is_still_refused_by_field(): void {
		$section                             = self::outline()[2];
		$section['items'][1]['image']['alt'] = '';

		$built = Layouts::render( $section, 2, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_field', $built->get_error_code() );
		$this->assertStringContainsString( '`items[1].image` needs a `url`', $built->get_error_message() );
		$this->assertStringContainsString( 'leave every card without a photo', $built->get_error_message() );
	}

	public function test_a_button_without_a_real_destination_is_refused(): void {
		$section                  = self::outline()[5];
		$section['button']['url'] = '#';

		$built = Layouts::render( $section, 5, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertStringContainsString( '`button.url` needs a real destination', $built->get_error_message() );
	}

	public function test_an_unknown_layout_lists_the_real_ones(): void {
		$built = Layouts::render( array( 'layout' => 'gallery' ), 0, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_unknown', $built->get_error_code() );
		$this->assertStringContainsString( 'hero, text-with-image, services, faq, cta, text', $built->get_error_message() );
	}

	/**
	 * D1 step 3 (S5): a known layout whose pattern the theme lacks is built from
	 * the curated pattern, never refused.
	 */
	public function test_a_layout_whose_pattern_the_theme_lacks_falls_back_to_the_curated_section(): void {
		$GLOBALS['senroflux_test_theme_patterns'] = array();
		ThemePatterns::resetCache();

		$built = Layouts::render( self::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/cover-hero', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
	}

	public function test_text_escapes_copy_and_counts_its_paragraphs(): void {
		$section               = self::outline()[1];
		$section['heading']    = 'Fees & <b>times</b>';
		$built                 = Layouts::render( $section, 1, new Vocabulary() );
		$section['paragraphs'] = array( 'Only one.' );
		$too_few               = Layouts::render( $section, 1, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Fees &amp; &lt;b&gt;times&lt;/b&gt;</h2>', $built );
		$this->assertInstanceOf( WP_Error::class, $too_few );
		$this->assertStringContainsString( 'takes 2 to 4 paragraphs; it has 1', $too_few->get_error_message() );
	}

	/**
	 * The `text` layout's heading limit ({@see Layouts} TEXT_HEADING_MAX_WORDS
	 * is 9) tolerates up to `floor(9 * 1.25) = 11` words, the same 25% rule as
	 * every other layout field.
	 */
	public function test_the_text_heading_tolerates_25_percent_over_its_word_limit(): void {
		$section            = self::outline()[1];
		$section['heading'] = trim( str_repeat( 'word ', 11 ) );
		$built              = Layouts::render( $section, 1, new Vocabulary() );
		$section['heading'] = trim( str_repeat( 'word ', 12 ) );
		$too_long           = Layouts::render( $section, 1, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertInstanceOf( WP_Error::class, $too_long );
		$this->assertStringContainsString( '`heading` has 12 words; the limit is 9', $too_long->get_error_message() );
	}

	/**
	 * A profile another theme contributes through `senroflux_layout_profiles`
	 * is the map `render()` builds from: here `hero` is backed by the theme's
	 * CTA pattern, so it takes no image.
	 */
	public function test_a_filtered_profile_for_the_active_theme_drives_render(): void {
		self::contributeFakeProfile();
		$GLOBALS['senroflux_test_stylesheet'] = 'fake-theme';

		$built = Layouts::render( self::hero_as_cta_section(), 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertStringContainsString( 'Fake hero heading', $built );
	}

	public function test_a_theme_with_no_profile_maps_its_patterns_automatically(): void {
		self::contributeFakeProfile();
		$GLOBALS['senroflux_test_stylesheet'] = 'unprofiled-theme';

		// D1 step 2 (S5b): no profile, so the theme's own patterns are matched to the layout by category and slot fit.
		$built = Layouts::render( self::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/twentytwentyfive/hero-full-width-image', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
		$this->assertSame( array( 'hero', 'text-with-image', 'services', 'faq', 'cta', 'text' ), Layouts::names() );
	}

	public function test_a_theme_with_no_profile_and_no_matching_pattern_gets_the_curated_fallback(): void {
		self::contributeFakeProfile();
		$GLOBALS['senroflux_test_stylesheet']     = 'unprofiled-theme';
		$GLOBALS['senroflux_test_theme_patterns'] = array();
		ThemePatterns::resetCache();

		$built = Layouts::render( self::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/cover-hero', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
	}

	public function test_a_child_theme_falls_back_to_its_parents_profile(): void {
		self::contributeFakeProfile();
		$GLOBALS['senroflux_test_stylesheet'] = 'fake-theme-child';
		$GLOBALS['senroflux_test_template']   = 'fake-theme';

		$built = Layouts::render( self::hero_as_cta_section(), 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
	}

	public function test_a_themes_own_profile_is_not_merged_with_its_parents(): void {
		self::contributeFakeProfile();
		$GLOBALS['senroflux_test_stylesheet'] = 'fake-theme';
		$GLOBALS['senroflux_test_template']   = 'twentytwentyfive';

		$built = Layouts::render( self::outline()[4], 4, new Vocabulary() );

		// The fake profile has no `faq`, and the parent's `text-faqs` is not borrowed: the curated faq is built.
		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/faq', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
	}

	private static function contributeFakeProfile(): void {
		add_filter(
			'senroflux_layout_profiles',
			static function ( array $profiles ): array {
				$profiles['fake-theme'] = array(
					'hero' => array(
						'pattern' => 'twentytwentyfive/cta-centered-heading',
						'slots'   => array( 'heading', 'text', 'button.label', 'button.url' ),
					),
				);

				return $profiles;
			}
		);
	}

	/** @return array<string,mixed> */
	private static function hero_as_cta_section(): array {
		return array(
			'layout'  => 'hero',
			'heading' => 'Fake hero heading',
			'text'    => 'Short supporting copy.',
			'button'  => array(
				'label' => 'Call us',
				'url'   => 'tel:+441234567890',
			),
		);
	}
}
