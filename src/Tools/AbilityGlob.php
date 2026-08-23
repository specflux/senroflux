<?php
/**
 * The one glob dialect used everywhere SenroFlux names abilities.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Copied from Agent Safety's Packs\VerbGlob (S5: same matching semantics, no
 * Composer dependency on agent-safety-core): "*" alone matches anything; in
 * any other pattern "*" is a wildcard segment and the rest is literal. Keeping
 * the dialect identical means a run's allow-list and an Agent Safety pack's
 * allow-list can never disagree about what a pattern covers.
 */
final class AbilityGlob {

	/**
	 * Does the pattern admit the subject?
	 */
	public static function matches( string $pattern, string $subject ): bool {
		if ( '*' === $pattern ) {
			return true;
		}

		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';

		return 1 === preg_match( $regex, $subject );
	}
}
