<?php
/**
 * Posts Vocabulary tests (S5).
 *
 * TARGET REPO PATH: tests/Packs/Posts/VocabularyTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Posts;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Packs\Posts\Vocabulary;

final class VocabularyTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_patterns']           = array();
		$GLOBALS['senroflux_test_pattern_categories'] = array();
	}

	public function test_all_returns_six_prose_and_two_feature_entries(): void {
		$all = ( new Vocabulary() )->all();

		$this->assertCount( 8, $all );
		$prose   = array_filter( $all, static fn ( $p ) => 'prose' === $p['kind'] );
		$feature = array_filter( $all, static fn ( $p ) => 'feature' === $p['kind'] );
		$this->assertCount( 6, $prose );
		$this->assertCount( 2, $feature );
	}

	public function test_list_payload_names_prose_by_bare_block_name(): void {
		$payload = ( new Vocabulary() )->listPayload();

		$names = array_column( $payload['patterns'], 'name' );
		$this->assertContains( 'core/paragraph', $names );
		$this->assertContains( 'core/image', $names );
		$this->assertContains( 'senroflux/closing-cta', $names );
		$this->assertContains( 'senroflux/pull-quote', $names );
	}

	/**
	 * 0.3 S7 gap fix (same treatment as the pages pack): a feature entry
	 * carries its shipped sample markup; a prose entry (no fixed markup to
	 * ship) omits the key rather than sending an empty string.
	 */
	public function test_list_payload_includes_markup_only_for_feature_patterns(): void {
		$payload = ( new Vocabulary() )->listPayload();

		foreach ( $payload['patterns'] as $pattern ) {
			if ( str_starts_with( (string) $pattern['name'], 'senroflux/' ) ) {
				$this->assertArrayHasKey( 'markup', $pattern, $pattern['name'] );
				$this->assertStringContainsString( '<!-- wp:', $pattern['markup'] );
			} else {
				$this->assertArrayNotHasKey( 'markup', $pattern, $pattern['name'] );
			}
		}
	}

	public function test_register_registers_only_the_two_feature_patterns(): void {
		$count = ( new Vocabulary() )->register();

		$this->assertSame( 2, $count );
		$this->assertCount( 2, $GLOBALS['senroflux_test_patterns'] );
		$this->assertArrayHasKey( 'senroflux/closing-cta', $GLOBALS['senroflux_test_patterns'] );
		$this->assertArrayHasKey( 'senroflux/pull-quote', $GLOBALS['senroflux_test_patterns'] );
	}

	public function test_feature_pattern_markup_round_trips(): void {
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			if ( 'feature' !== $pattern['kind'] ) {
				continue;
			}
			$this->assertSame(
				$pattern['markup'],
				serialize_blocks( parse_blocks( (string) $pattern['markup'] ) ),
				$pattern['name'] . ' must survive a parse→serialize round-trip'
			);
		}
	}

	public function test_list_payload_constraints_match_copy_rules_single_source(): void {
		$vocabulary = new Vocabulary();
		$payload    = $vocabulary->listPayload();
		$body       = ( new PostsPack() )->copyRulesBody( $vocabulary->all() );

		foreach ( $payload['patterns'] as $pattern ) {
			foreach ( $pattern['constraints']['stated'] as $line ) {
				$this->assertStringContainsString( $line, $body );
			}
		}
	}

	public function test_block_names_include_prose_and_feature_blocks(): void {
		$names = ( new Vocabulary() )->blockNames();

		foreach ( array( 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'core/image', 'core/code', 'core/group', 'core/buttons', 'core/button', 'core/pullquote' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	/**
	 * 0.3 S21: "the posts pack never sees theme patterns." The posts
	 * vocabulary does not implement {@see \Specflux\SenroFlux\Packs\Content\ThemePatternSource}
	 * at all — there is no seam through which a theme pattern could ever
	 * reach it, regardless of what the active theme registers.
	 */
	public function test_vocabulary_does_not_implement_theme_pattern_source(): void {
		$this->assertNotInstanceOf(
			\Specflux\SenroFlux\Packs\Content\ThemePatternSource::class,
			new Vocabulary()
		);
	}

	public function test_list_payload_never_carries_a_theme_derived_entry(): void {
		$payload = ( new Vocabulary() )->listPayload();

		foreach ( $payload['patterns'] as $pattern ) {
			$this->assertArrayNotHasKey( 'theme_derived', $pattern );
		}
	}
}
