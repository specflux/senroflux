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
}
