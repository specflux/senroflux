<?php
/**
 * S19 commerce tier parity: SenroFlux's own `verbFor()` + verb map against
 * the shared fixture, and a byte-identical check against Agent Safety's copy
 * when the sibling repo is checked out.
 *
 * TARGET REPO PATH: tests/Parity/CommerceTierParityTest.php
 *
 * SCOPE (stage 12, catalogue slice only): the fixture also carries rows for
 * `orders-query`, `order-add-note`, `order-update-status` and
 * `product-delete` — abilities the commerce pack does not yet register a
 * role for (stage 13). Those rows are SKIPPED here, by ability base name,
 * rather than asserted against a verb this pack cannot yet produce; stage 13
 * extends this same test to cover them once their roles exist.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Parity;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Tools\VerbTier;

final class CommerceTierParityTest extends TestCase {

	/** Ability base names this pack governs at stage 12 (catalogue only). */
	private const STAGE_12_ABILITIES = array( 'products-query', 'product-create', 'product-update' );

	private function fixturePath(): string {
		return __DIR__ . '/commerce-tier-parity.json';
	}

	/** @return list<array{ability:string,args:array<string,mixed>,tier:int}> */
	private function fixture(): array {
		$decoded = json_decode( (string) file_get_contents( $this->fixturePath() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.
		$this->assertIsArray( $decoded, 'the parity fixture must be valid JSON' );

		return $decoded;
	}

	public function test_the_fixture_file_exists_and_parses(): void {
		$this->assertFileExists( $this->fixturePath() );
		$this->assertNotEmpty( $this->fixture() );
	}

	/**
	 * SenroFlux's own `verbFor()` + `verbMap()` classify every STAGE-12 row of
	 * the fixture at the same tier the fixture (and, on the Agent Safety side,
	 * `TierClassifier`) expects.
	 */
	public function test_senroflux_classifies_every_stage_12_fixture_row_at_the_fixture_tier(): void {
		$pack      = new CommercePack();
		$verbMap   = $pack->verbMap();
		$exercised = 0;

		foreach ( $this->fixture() as $case ) {
			$ability = (string) ( $case['ability'] ?? '' );
			$base    = str_contains( $ability, '/' ) ? substr( $ability, strrpos( $ability, '/' ) + 1 ) : $ability;

			if ( ! in_array( $base, self::STAGE_12_ABILITIES, true ) ) {
				continue; // Stage 13 row (orders/notes/status/delete) — not yet in scope.
			}

			$args = is_array( $case['args'] ?? null ) ? $case['args'] : array();
			$verb = $pack->verbFor( $ability, $args );
			$tier = VerbTier::tierFor( $verb, $verbMap );

			$this->assertSame(
				(int) $case['tier'],
				$tier,
				sprintf( 'ability %s args %s => verb %s expected tier %d, got %d', $ability, wp_json_encode( $args ), $verb, (int) $case['tier'], $tier )
			);
			++$exercised;
		}

		$this->assertGreaterThan( 0, $exercised, 'the fixture must actually exercise stage-12 abilities' );
	}

	/**
	 * The two copies (this repo's and Agent Safety's) must be byte-identical
	 * — the shared-file mechanism S19 documents in place of a composer
	 * package. Skips with a clear message when the sibling repo is not
	 * checked out next to this one (a normal, non-failing state for a
	 * SenroFlux-only checkout).
	 */
	public function test_byte_identical_to_agent_safetys_copy_when_the_sibling_repo_is_present(): void {
		$candidates = array(
			dirname( __DIR__, 3 ) . '/agent-safety/plugin/tests/Fixtures/commerce-tier-parity.json',
			dirname( __DIR__, 3 ) . '/agent-safety-wt-0.3/plugin/tests/Fixtures/commerce-tier-parity.json',
		);

		$sibling = null;
		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				$sibling = $candidate;
				break;
			}
		}

		if ( null === $sibling ) {
			$this->markTestSkipped(
				'Agent Safety is not checked out alongside SenroFlux (checked: ' . implode( ', ', $candidates ) . ') — nothing to diff against.'
			);
		}

		$this->assertSame(
			file_get_contents( $sibling ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local sibling-repo fixture, not a remote URL.
			file_get_contents( $this->fixturePath() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.
			'SenroFlux\'s and Agent Safety\'s copies of the parity fixture have drifted apart'
		);
	}
}
