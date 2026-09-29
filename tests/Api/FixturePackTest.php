<?php
/**
 * S23: a third-party pack built from ONLY `@api` symbols registers, resolves
 * its roles, gets its verbs tiered, and imports nothing outside the declared
 * surface.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Api;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Tests\Api\Fixtures\FixturePack;

require_once __DIR__ . '/Fixtures/FixturePack.php';
require_once __DIR__ . '/Fixtures/FixtureAbility.php';

final class FixturePackTest extends TestCase {

	protected function setUp(): void {
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_abilities'] = array();
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_abilities'] = array();
	}

	/**
	 * The fixture pack registers through `senroflux_packs` (`@api`) exactly
	 * as a real third-party pack would.
	 */
	public function test_registers_through_senroflux_packs_filter(): void {
		add_filter(
			'senroflux_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new FixturePack();

				return $packs;
			}
		);

		$registry = PackRegistry::fromFilters();
		$pack     = $registry->get( 'fixture' );

		$this->assertNotNull( $pack, 'FixturePack must survive PackRegistry::register()\'s S19 namespace-governance check.' );
		$this->assertSame( 'fixture', $pack->name() );
	}

	/**
	 * Roles resolve to the polyfill namespace (no fixture ability needs to be
	 * registered for this — the last `abilityNamespaces()` entry is always
	 * accepted without an existence check).
	 */
	public function test_resolves_its_roles(): void {
		$pack     = new FixturePack();
		$resolved = $pack->resolveAbilities();

		$this->assertSame(
			array(
				'read'  => 'senroflux/fixture-read',
				'write' => 'senroflux/fixture-write',
			),
			$resolved
		);
		$this->assertSame(
			array( 'senroflux/fixture-read', 'senroflux/fixture-write' ),
			$pack->allowList()
		);
	}

	/**
	 * The fixture ability exists in the abilities fixture registry (proving
	 * a real registration is possible), and the pack's own verbMap() tiers
	 * both resolved verbs.
	 */
	public function test_gets_its_verbs_tiered(): void {
		$GLOBALS['senroflux_test_abilities']['senroflux/fixture-read'] =
			new \SenroFlux_Test_Fake_Ability( 'senroflux/fixture-read' );

		$pack = new FixturePack();

		$this->assertTrue( wp_has_ability( 'senroflux/fixture-read' ) );

		$verb_map = $pack->verbMap();
		$this->assertSame( 0, $verb_map['senroflux/fixture-read'] );
		$this->assertSame( 1, $verb_map['senroflux/fixture-write'] );

		// agentSafetyVerbMap() (@api) — the base's default roleVerbs()
		// implementation (no override here) means every role fails closed
		// to Tier 2 unless the pack states which verbs each role can
		// produce; FixturePack does not override roleVerbs(), so this
		// documents the fail-closed default rather than asserting a tier
		// the pack never actually declared.
		$as_map = $pack->agentSafetyVerbMap();
		$this->assertSame( 2, $as_map['senroflux/fixture-read'] );
		$this->assertSame( 2, $as_map['senroflux/fixture-write'] );
	}

	/**
	 * Static check: FixturePack.php imports and references ONLY the
	 * declared `@api` SenroFlux symbols (Pack, Skill, SkillSource,
	 * SetupCheck) — never an `@internal` one. Scans the fixture's own `use`
	 * statements and every fully-qualified `Specflux\SenroFlux\...`
	 * occurrence in its body.
	 */
	public function test_uses_only_api_symbols(): void {
		$allowed = array(
			'Specflux\\SenroFlux\\Packs\\Pack',
			'Specflux\\SenroFlux\\Skills\\Skill',
			'Specflux\\SenroFlux\\Skills\\SkillSource',
			'Specflux\\SenroFlux\\Setup\\SetupCheck',
		);

		$source = (string) file_get_contents( __DIR__ . '/Fixtures/FixturePack.php' );

		preg_match_all( '/use\s+(Specflux\\\\SenroFlux\\\\[A-Za-z0-9_\\\\]+);/', $source, $use_matches );
		foreach ( $use_matches[1] as $imported ) {
			$this->assertContains(
				$imported,
				$allowed,
				sprintf( 'FixturePack.php imports non-@api symbol "%s".', $imported )
			);
		}

		preg_match_all( '/\\\\?Specflux\\\\SenroFlux\\\\[A-Za-z0-9_\\\\]+/', $source, $fqcn_matches );
		foreach ( $fqcn_matches[0] as $reference ) {
			$normalized = ltrim( $reference, '\\' );
			// The fixture's OWN namespace (Tests\Api\Fixtures) is not a
			// dependency on plugin internals — only a real plugin symbol
			// reference counts.
			if ( str_starts_with( $normalized, 'Specflux\\SenroFlux\\Tests\\' ) ) {
				continue;
			}
			$this->assertContains(
				$normalized,
				$allowed,
				sprintf( 'FixturePack.php references non-@api symbol "%s".', $normalized )
			);
		}
	}
}
