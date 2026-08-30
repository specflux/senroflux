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
		// S11: no colour attributes anywhere in the vocabulary.
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			$markup = (string) $pattern['markup'];

			$this->assertStringNotContainsString( 'backgroundColor', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'textColor', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'gradient', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( '"color"', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( '-background-color', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( 'has-text-color', $markup, $pattern['name'] );
		}
	}

	public function test_no_pattern_carries_an_image_or_a_placeholder(): void {
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			$markup = (string) $pattern['markup'];

			$this->assertStringNotContainsString( 'wp:image', $markup, $pattern['name'] );
			$this->assertStringNotContainsString( '<img', $markup, $pattern['name'] );
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

			$content = $hero . "\n\n" . (string) $pattern['markup'];
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
}
