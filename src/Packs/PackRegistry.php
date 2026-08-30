<?php
/**
 * Holds every registered capability pack for one request.
 *
 * Registration (S9): SenroFlux itself registers the pages pack (stage 8
 * provides it) through the `senroflux_packs` filter; the registry boots empty
 * here so the no-pack path keeps working untouched. `start(?string $pack)`
 * resolves a pack by name here; an unknown name is `pack_unknown`.
 *
 * The registry is request-scoped and must never leak into the RUN LOOP — it is
 * consulted by `Plugin::start()` / `preflight()` only, and hands the Runner an
 * explicit allow-list / skill set / verb map (never a Pack object).
 *
 * It is also where every registered pack's GOVERNANCE data is collected and
 * handed to Agent Safety ({@see contributeToAgentSafety()}). Without that,
 * Agent Safety's gate returns early for `senroflux/*` ability names and the
 * whole verdict / approval / audit chain is a no-op for pack writes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A per-request container of Pack instances, keyed by pack name.
 */
final class PackRegistry {

	/** @var array<string, Pack> pack name => pack. */
	private array $packs = array();

	/**
	 * Build the registry from the `senroflux_packs` filter: name => Pack. Every
	 * registered entry must satisfy the Pack contract; anything not a Pack is
	 * dropped (fail-closed, never a half-registered pack). SenroFlux's own pages
	 * pack registers through this filter (stage 8).
	 */
	public static function fromFilters(): self {
		$registry = new self();
		$filtered = apply_filters( 'senroflux_packs', array() );

		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $name => $pack ) {
				if ( is_string( $name ) && $pack instanceof Pack ) {
					$registry->register( $pack );
				}
			}
		}

		return $registry;
	}

	/**
	 * Register (or replace) a pack, keyed by its own `name()`.
	 *
	 * @param Pack $pack The pack to register.
	 * @return self For chaining.
	 */
	public function register( Pack $pack ): self {
		$this->packs[ $pack->name() ] = $pack;

		return $this;
	}

	/**
	 * One pack by name, or null when unknown.
	 *
	 * @param string $name Pack name.
	 */
	public function get( string $name ): ?Pack {
		return $this->packs[ $name ] ?? null;
	}

	/**
	 * Every registered pack, keyed by name.
	 *
	 * @return array<string, Pack>
	 */
	public function all(): array {
		return $this->packs;
	}

	/**
	 * Every ability namespace the registered packs ask Agent Safety to govern,
	 * deduped.
	 *
	 * @return list<string>
	 */
	public function governedNamespaces(): array {
		$namespaces = array();
		foreach ( $this->packs as $pack ) {
			foreach ( $pack->governedNamespaces() as $namespace ) {
				if ( '' !== $namespace ) {
					$namespaces[ $namespace ] = true;
				}
			}
		}

		return array_keys( $namespaces );
	}

	/**
	 * The merged Agent Safety verb map (ability id => tier) across every
	 * registered pack. Two packs claiming the same ability keep the HIGHER
	 * tier: a merge must never be the step that relaxes a pack's own reading.
	 *
	 * @return array<string,int>
	 */
	public function agentSafetyVerbMap(): array {
		$map = array();
		foreach ( $this->packs as $pack ) {
			foreach ( $pack->agentSafetyVerbMap() as $ability => $tier ) {
				$map[ $ability ] = isset( $map[ $ability ] ) ? max( $map[ $ability ], $tier ) : $tier;
			}
		}

		return $map;
	}

	/**
	 * Hand every registered pack's governance data to Agent Safety.
	 *
	 * The two filters are companions and neither is optional: governing a
	 * namespace without mapping its verbs denies every call in it as
	 * `unknown_verb`, and mapping verbs without governing the namespace leaves
	 * `AbilityPermissionGate::wrap()` returning early — no verdict, no approval
	 * park, no audit row (agent-safety plugin/src/Hooks/AbilityPermissionGate.php:84-88).
	 *
	 * TIMING, load-bearing: Agent Safety applies both filters inside its own
	 * `plugins_loaded` priority-0 bootstrap (agent-safety plugin/agent-safety.php:119,
	 * :171, :186), so these callbacks must be ADDED before that. The callbacks
	 * themselves read the registry lazily, so a pack registered on
	 * `senroflux_packs` any time before priority 0 is governed too.
	 */
	public static function contributeToAgentSafety(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter(
			'agent_safety_governed_namespaces',
			static function ( $namespaces ): array {
				$namespaces = is_array( $namespaces ) ? $namespaces : array();

				return array_values( array_unique( array_merge( $namespaces, self::fromFilters()->governedNamespaces() ) ) );
			},
			10,
			1
		);

		add_filter(
			'agent_safety_verb_map',
			static function ( $map ): array {
				$map = is_array( $map ) ? $map : array();

				// Ours are added, never allowed to overwrite an existing entry:
				// another contributor's tier for the same ability may be
				// stricter, and Agent Safety's own core map is authoritative
				// for anything it already answers.
				return $map + self::fromFilters()->agentSafetyVerbMap();
			},
			10,
			1
		);
	}
}
