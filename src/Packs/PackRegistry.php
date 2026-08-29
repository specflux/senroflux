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
}
