<?php
/**
 * The Runs-screen capability and the delegation allowance that rides with it.
 *
 * TARGET REPO PATH: src/Admin/ScreenCapability.php
 *
 * S13 gives one capability (`manage_options`, filterable through
 * `senroflux_runs_capability`) the right to see/start/answer/act/cancel ANY
 * run from the Runs screen, not only the runs they own. The Runner enforces
 * owner-only ticking by default and opens the `senroflux_can_tick` seam for
 * exactly this delegation.
 *
 * Both human seams need the SAME allowance:
 *   - the admin-post park handlers ({@see RunsScreen}), and
 *   - the admin-ajax poll the same screen drives ({@see \Specflux\SenroFlux\Http\Ajax}).
 *
 * Before this helper existed only the first installed it, so polling a
 * DELEGATED run 403'd while the form submission on the very same page worked.
 * The allowance lives here so there is one implementation of "the screen may
 * tick this run", and it is:
 *   - re-checked against `current_user_can()` at call time (never trusted from
 *     the caller);
 *   - scoped to ONE run id (a screen tick never blanket-allows other runs);
 *   - removed in a `finally`, so it cannot leak past the call.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Admin;

use Specflux\SenroFlux\Packs\PackRegistry;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The screen capability, and the scoped `senroflux_can_tick` allowance.
 */
final class ScreenCapability {

	/**
	 * Pre-0.3 fallback only: the fixed capability the screen used before S10's
	 * per-viewer computation. No 0.3 code path reads this any more; it exists
	 * so a caller that imported the constant keeps compiling.
	 */
	public const DEFAULT_CAPABILITY = 'manage_options';

	/** The capability nobody holds (WP_Role has no such cap) — the fail-closed floor. */
	public const NOBODY = 'do_not_allow';

	/** The filter a site uses to move the screen to another capability. */
	public const FILTER = 'senroflux_runs_capability';

	/**
	 * The capability required for the Runs screen (0.3 S10): the FIRST
	 * registered pack's run capability the CURRENT viewer holds, else
	 * {@see NOBODY}. `senroflux_runs_capability` is applied AFTER that
	 * computation, as the existing escape hatch.
	 *
	 * This one capability gates the top-level menu's visibility (S10) and
	 * every screen action (start/answer/act/cancel, S13) — a site that wants
	 * a fixed capability for both still gets it by filtering as before; the
	 * computed default just means an editor who can run only the pages pack
	 * sees the menu and can start pages, without an owner having to filter
	 * anything (S10's "editor with edit_pages… gets the menu capability
	 * edit_pages").
	 *
	 * Packs are asked in REGISTRATION order, which is a deliberate priority:
	 * the first pack a viewer is capable of running decides the screen
	 * capability for everything they see there (a viewer capable of more than
	 * one pack still only needs ONE to unlock the screen; which pack they may
	 * actually start is decided again, per pack, by {@see \Specflux\SenroFlux\Packs\Pack::preflight()}).
	 */
	public static function current(): string {
		$computed = self::computedDefault();

		// The hook name is spelled out (not `self::FILTER`) so static analysis
		// and hook scanners can see it; the constant exists for callers/tests.
		/** Filters the capability required for the Runs screen. */
		$capability = apply_filters( 'senroflux_runs_capability', $computed );

		// Fail closed: a filter that returns something unusable must not turn
		// into an empty capability string (which `current_user_can` treats as
		// an unknown cap, i.e. deny — but be explicit rather than lucky).
		return ( is_string( $capability ) && '' !== $capability ) ? $capability : $computed;
	}

	/**
	 * The first registered pack's run capability the CURRENT user holds, or
	 * {@see NOBODY} when none is (S10). A pack with no run capability of its
	 * own (`runCapability() === ''`) is skipped — it declares nothing to test.
	 */
	private static function computedDefault(): string {
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			$capability = $pack->runCapability();
			if ( '' !== $capability && function_exists( 'current_user_can' ) && current_user_can( $capability ) ) {
				return $capability;
			}
		}

		return self::NOBODY;
	}

	/** Does the CURRENT user hold the screen capability? */
	public static function held(): bool {
		return current_user_can( self::current() );
	}

	/**
	 * Run `$tick` with the screen's delegation allowance installed for `$run_id`.
	 *
	 * Fails closed: a caller without the capability never gets the allowance,
	 * it gets a 403 WP_Error and `$tick` is not called at all.
	 *
	 * @param int      $run_id The ONE run the allowance covers.
	 * @param callable $tick   Closure performing the actual `tick()` call.
	 * @return array<string,mixed>|WP_Error RunState, or a 403 WP_Error.
	 */
	public static function tickAsScreen( int $run_id, callable $tick ): array|WP_Error {
		if ( ! self::held() ) {
			return new WP_Error(
				'senroflux_forbidden',
				__( 'Insufficient permissions.', 'senroflux' ),
				array( 'status' => 403 )
			);
		}

		// Scoped to this ONE run: any other run keeps the Runner's owner-only
		// default, so the allowance can never widen beyond what is being acted on.
		$callback = static function ( $can, $run = null ) use ( $run_id ) {
			if ( is_object( $run ) && isset( $run->id ) && (int) $run->id === $run_id ) {
				return true;
			}

			return $can;
		};

		add_filter( 'senroflux_can_tick', $callback, 10, 2 );

		try {
			$result = $tick();

			return ( is_array( $result ) || $result instanceof WP_Error )
				? $result
				: new WP_Error(
					'senroflux_bad_request',
					__( 'The run could not be advanced.', 'senroflux' ),
					array( 'status' => 400 )
				);
		} finally {
			remove_filter( 'senroflux_can_tick', $callback, 10 );
		}
	}
}
