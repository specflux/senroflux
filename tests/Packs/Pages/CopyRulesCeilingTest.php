<?php
/**
 * Regression coverage for the `skills_too_large` fix (0.3): the pages/site
 * `copy-rules` skill used to restate every theme-derived pattern's
 * `constraints.stated` lines (one per text/url slot), which pushed a real
 * Twenty Twenty-Five site's skill set over {@see \Specflux\SenroFlux\Skills\SkillSet}'s
 * 2000-token ceiling (measured: pages ~4227 tokens, site ~4571, over 9
 * eligible theme patterns). {@see \Specflux\SenroFlux\Packs\Pack::copyRulesLines()}
 * now points the model at `list-patterns` for every pattern's copy limits
 * and names theme patterns once instead.
 *
 * The 9 fixtures under `tests/ThemePatterns/` used here are the REAL Twenty
 * Twenty-Five pattern files (fetched from a live Twenty Twenty-Five 1.5
 * install) that a full scan of the theme's `patterns/` directory found
 * eligible (see the provenance note in {@see \Specflux\SenroFlux\Tests\Packs\Pages\ThemePatternsTest}):
 * `banner-intro`, `cta-book-links`, `cta-book-locations`, `cta-centered-heading`,
 * `cta-events-list`, `format-link`, `pricing-3-col`, `testimonials-6-col`,
 * `text-faqs`.
 *
 * TARGET REPO PATH: tests/Packs/Pages/CopyRulesCeilingTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Site\SitePack;
use Specflux\SenroFlux\Skills\SkillSet;

final class CopyRulesCeilingTest extends TestCase {

	/**
	 * The 9 real Twenty Twenty-Five patterns a full theme scan found eligible
	 * (see class docblock).
	 *
	 * @var list<string>
	 */
	private const TT25_ELIGIBLE = array(
		'banner-intro',
		'cta-book-links',
		'cta-book-locations',
		'cta-centered-heading',
		'cta-events-list',
		'format-link',
		'pricing-3-col',
		'testimonials-6-col',
		'text-faqs',
	);

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';
	}

	/**
	 * Render one real fixture file exactly as `WP_Theme::get_block_patterns()`
	 * would, same approach {@see \Specflux\SenroFlux\Tests\Packs\Pages\ThemePatternsTest::loadFixture()}
	 * uses.
	 *
	 * @return array<string,mixed>
	 */
	private function loadFixture( string $slug ): array {
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

	protected function setUp(): void {
		$this->loadShims();

		ThemePatterns::resetCache();
		$GLOBALS['senroflux_test_theme_patterns'] = array_map(
			fn ( string $slug ) => $this->loadFixture( $slug ),
			self::TT25_ELIGIBLE
		);
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/ThemePatterns';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/ThemePatterns';
	}

	protected function tearDown(): void {
		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'] );
	}

	/** Sanity: the fixture set is really the 9 eligible TT25 patterns, not a subset skipped for some other reason. */
	public function test_fixture_set_is_all_nine_eligible(): void {
		$this->assertCount( 9, ThemePatterns::eligible() );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_pages_pack_skill_set_is_within_ceiling_with_real_theme_patterns(): void {
		$pack   = new PagesPack();
		$skills = SkillSet::collect( 'test', 'a goal', $pack );

		$this->assertNull( SkillSet::ceilingError( $skills ) );
		$this->assertLessThanOrEqual( 1900, self::totalTokens( $skills ), 'pages must stay under 1900 for headroom, not just under the 2000 hard ceiling' );
	}

	public function test_site_pack_skill_set_is_within_ceiling_with_real_theme_patterns(): void {
		$pack   = new SitePack();
		$skills = SkillSet::collect( 'test', 'a goal', $pack );

		$this->assertNull( SkillSet::ceilingError( $skills ) );
		$this->assertLessThanOrEqual( 1900, self::totalTokens( $skills ), 'site must stay under 1900 for headroom, not just under the 2000 hard ceiling' );
	}

	/**
	 * Pages are written as layouts built from the theme's patterns; a live
	 * run that was also told about numbered-slot theme patterns mixed the two
	 * and left a pattern's sample text on the page.
	 */
	public function test_pages_pack_skills_never_offer_numbered_theme_slots(): void {
		$bodies = implode( "\n", array_map( static fn ( $s ) => $s->body, ( new PagesPack() )->skills() ) );

		$this->assertStringNotContainsString( '{pattern, slots}', $bodies );
		$this->assertStringNotContainsString( 'theme ones first', $bodies );
		$this->assertStringContainsString( 'layout', $bodies );
	}

	/**
	 * A live Services page sent both buttons to the Contact page while the
	 * brief gave a phone number and email, and its copy said what care "may"
	 * do rather than what happens.
	 */
	public function test_pages_pack_skills_ask_for_the_given_contact_details_and_plain_claims(): void {
		$bodies = implode( "\n", array_map( static fn ( $s ) => $s->body, ( new PagesPack() )->skills() ) );

		$this->assertStringContainsString( 'tel:', $bodies );
		$this->assertStringContainsString( 'mailto:', $bodies );
		$this->assertStringContainsString( 'may help', $bodies );
	}

	/**
	 * A live Services page came out as hero, three one-line cards and a
	 * button: nothing on who each service suits or what a first visit is.
	 */
	public function test_pages_pack_skills_ask_for_depth_on_each_page(): void {
		$bodies = implode( "\n", array_map( static fn ( $s ) => $s->body, ( new PagesPack() )->skills() ) );

		$this->assertStringContainsString( 'first visit', $bodies );
		$this->assertStringContainsString( 'who it is for', $bodies );
	}

	public function test_pages_pack_skills_rewrite_existing_pages_with_layouts(): void {
		$bodies = implode( "\n", array_map( static fn ( $s ) => $s->body, ( new PagesPack() )->skills() ) );

		$this->assertStringContainsString( 'To rewrite an existing page', $bodies );
	}

	/**
	 * The same chars/4 estimate {@see SkillSet::ceilingError()} uses
	 * internally, summed rather than compared — 0.3 quality fix (instruction
	 * ceiling: cover-hero/media-text grew the shapes list by two patterns).
	 *
	 * @param list<\Specflux\SenroFlux\Skills\Skill> $skills
	 */
	private static function totalTokens( array $skills ): int {
		$chars = 0;
		foreach ( $skills as $skill ) {
			$chars += mb_strlen( $skill->body );
		}

		return (int) ceil( $chars / 4 );
	}
}
