<?php
/**
 * A minimal third-party capability pack, built ONLY from `@api` SenroFlux
 * symbols (S23). Exists to prove the declared extension surface is enough
 * to write a real pack outside the plugin's own source tree — see
 * `tests/Api/FixturePackTest.php`.
 *
 * DELIBERATE CONSTRAINT: every `use Specflux\SenroFlux\...` import below, and
 * every fully-qualified `Specflux\SenroFlux\...` reference in this file's
 * body, must be one of Pack / Skill / SkillSource / SetupCheck (the declared
 * `@api` symbols a pack is written against) — `FixturePackStaticCheckTest`
 * enforces this mechanically, not just by convention.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Api\Fixtures;

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Setup\SetupCheck;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A trivial two-role, two-tier pack: a Tier-0 read and a Tier-1 write, both
 * resolved to the `senroflux/` polyfill namespace (the default
 * `abilityNamespaces()` — never overridden here, since the default is
 * already `@api`-safe and needs no fixture ability to be registered for
 * `resolveAbilities()`/`allowList()` to work: the LAST namespace in the list
 * is always accepted without an existence check).
 */
final class FixturePack extends Pack {

	public function __construct() {
		parent::__construct(
			array(
				'read'  => 'fixture-read',
				'write' => 'fixture-write',
			)
		);
	}

	public function name(): string {
		return 'fixture';
	}

	public function runCapability(): string {
		return 'read';
	}

	/**
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'senroflux/fixture-read'  => 0,
			'senroflux/fixture-write' => 1,
		);
	}

	/**
	 * @return list<Skill>
	 */
	public function skills( bool $images_available = true ): array {
		return array(
			new Skill(
				'fixture/rules',
				'Fixture rules',
				'This is a fixture pack for the S23 extension-API surface test.',
				false,
				SkillSource::Pack,
				'1'
			),
		);
	}

	/**
	 * @param int $user_id The user the run would be started for.
	 * @return list<SetupCheck>
	 */
	public function setupChecks( int $user_id ): array {
		return array(
			new SetupCheck(
				'fixture/capability',
				SetupCheck::BLOCKING,
				true,
				'Fixture pack is always ready.',
				null,
				null,
				'Fixture pack is always ready.',
				'fixture_unbound'
			),
		);
	}

	/**
	 * Always bound: this fixture has nothing to bind against and exists only
	 * to exercise the `@api` surface, not real Agent Safety governance.
	 *
	 * @param int $user_id The user the run would be started for.
	 */
	protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
		unset( $user_id );

		return null;
	}
}
