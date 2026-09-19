<?php
/**
 * S19 commerce tier parity: SenroFlux's own `verbFor()` + verb map against
 * the shared fixture, and a byte-identical check against Agent Safety's copy
 * when the sibling repo is checked out.
 *
 * TARGET REPO PATH: tests/Parity/CommerceTierParityTest.php
 *
 * SCOPE (stage 13): every fixture row this pack now registers a role for
 * (`orders-query`, `order-add-note` both flags) is exercised against
 * `verbFor()` + `verbMap()`, same as the stage-12 catalogue rows. The
 * fixture also carries `order-update-status` and `product-delete` rows —
 * abilities S19 deliberately does NOT make pack roles ("product-delete and
 * order-update-status are deliberately not roles") — those are asserted the
 * OTHER way: `verbFor()` returns the ability id unchanged (the base-class
 * direct-allow fallback) and neither base name appears in any `roleVerbs()`
 * entry, rather than being skipped as out of scope.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Parity;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Tools\VerbTier;

final class CommerceTierParityTest extends TestCase {

	/** Ability base names this pack registers a role for (S19, full table). */
	private const PACK_ABILITIES = array( 'products-query', 'product-create', 'product-update', 'orders-query', 'order-add-note' );

	/**
	 * Ability base names S19 deliberately does NOT make pack roles for
	 * ("`product-delete` and `order-update-status` are deliberately not
	 * roles").
	 */
	private const DELIBERATELY_NOT_ROLES = array( 'order-update-status', 'product-delete' );

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
	 * SenroFlux's own `verbFor()` + `verbMap()` classify every fixture row for
	 * an ability this pack registers a role for at the same tier the fixture
	 * (and, on the Agent Safety side, `TierClassifier`) expects.
	 */
	public function test_senroflux_classifies_every_pack_ability_fixture_row_at_the_fixture_tier(): void {
		$pack      = new CommercePack();
		$verbMap   = $pack->verbMap();
		$exercised = 0;

		foreach ( $this->fixture() as $case ) {
			$ability = (string) ( $case['ability'] ?? '' );
			$base    = str_contains( $ability, '/' ) ? substr( $ability, strrpos( $ability, '/' ) + 1 ) : $ability;

			if ( ! in_array( $base, self::PACK_ABILITIES, true ) ) {
				continue; // order-update-status / product-delete — deliberately not roles, see below.
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

		$this->assertGreaterThan( 0, $exercised, 'the fixture must actually exercise the pack\'s abilities' );
	}

	/**
	 * `order-update-status` and `product-delete` are DELIBERATELY not pack
	 * roles (S19): `verbFor()` falls through to the base-class direct-allow
	 * default (the ability id unchanged) for both, and neither base name
	 * appears in any `roleVerbs()` entry.
	 */
	public function test_order_update_status_and_product_delete_are_deliberately_not_roles(): void {
		$pack       = new CommercePack();
		$role_verbs = $pack->roleVerbs();

		foreach ( $this->fixture() as $case ) {
			$ability = (string) ( $case['ability'] ?? '' );
			$base    = str_contains( $ability, '/' ) ? substr( $ability, strrpos( $ability, '/' ) + 1 ) : $ability;

			if ( ! in_array( $base, self::DELIBERATELY_NOT_ROLES, true ) ) {
				continue;
			}

			$args = is_array( $case['args'] ?? null ) ? $case['args'] : array();
			$this->assertSame( $ability, $pack->verbFor( $ability, $args ), "$ability must fall through to the ability id unchanged" );
		}

		foreach ( $role_verbs as $role => $verbs ) {
			foreach ( $verbs as $verb ) {
				foreach ( self::DELIBERATELY_NOT_ROLES as $not_role ) {
					$this->assertStringNotContainsString(
						$not_role,
						$verb,
						"role $role must not declare a verb naming $not_role"
					);
				}
			}
		}
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
