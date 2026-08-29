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
use Specflux\SenroFlux\Packs\Pages\Vocabulary;

final class VocabularyTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_patterns']           = array();
		$GLOBALS['senroflux_test_pattern_categories'] = array();
	}

	public function test_all_returns_seven_patterns(): void {
		$this->assertCount( 7, ( new Vocabulary() )->all() );
	}

	public function test_register_registers_all_seven_under_category(): void {
		$vocabulary = new Vocabulary();
		$count      = $vocabulary->register();

		$this->assertSame( 7, $count );
		$this->assertSame( 7, count( $GLOBALS['senroflux_test_patterns'] ) );
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
			$this->assertSame(
				$this->normalize( $pattern['markup'] ),
				$this->normalize( serialize_blocks( parse_blocks( $pattern['markup'] ) ) ),
				$pattern['name'] . ' must survive a parse→serialize round-trip'
			);
		}
	}

	public function test_list_payload_constraints_match_copy_rules_single_source(): void {
		$vocabulary = new Vocabulary();
		$payload    = $vocabulary->listPayload();
		$body       = ( new PagesPack() )->copyRulesBody( $payload['patterns'] );

		// Every `stated` line from the payload must be present in the skill body.
		foreach ( $payload['patterns'] as $pattern ) {
			foreach ( $pattern['constraints']['stated'] as $line ) {
				$this->assertStringContainsString( $line, $body );
			}
		}

		// Global copy limits are appended by the same builder.
		$this->assertStringContainsString( 'at most 18 words', $body );
		$this->assertStringContainsString( 'verb-first', $body );
		$this->assertStringContainsString( 'price TBC', $body );
	}

	public function test_list_payload_has_constraints_per_pattern(): void {
		$payload = ( new Vocabulary() )->listPayload();

		$this->assertArrayHasKey( 'patterns', $payload );
		$this->assertCount( 7, $payload['patterns'] );

		foreach ( $payload['patterns'] as $pattern ) {
			$this->assertArrayHasKey( 'name', $pattern );
			$this->assertArrayHasKey( 'constraints', $pattern );
			$this->assertArrayHasKey( 'slots', $pattern['constraints'] );
			$this->assertArrayHasKey( 'stated', $pattern['constraints'] );
		}
	}

	private function normalize( string $text ): string {
		return preg_replace( '/\s+/', ' ', trim( $text ) );
	}
}
