<?php
/**
 * Global service locator for SenroFlux consumers.
 *
 * Defined in the global namespace so any consumer (first one:
 * marketing-analytics-chat) can feature-detect the plugin without knowing
 * its namespace:
 *
 *   if ( function_exists( 'senroflux' )
 *       && null !== senroflux()
 *       && senroflux()->available() ) {
 *       $run = senroflux()->start( ... );
 *   }
 *
 * Absent entirely before this file loads; null after load but before
 * plugins_loaded, or when the plugin bailed. Null-safe by contract.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SenroFlux\Plugin;

if ( ! function_exists( 'senroflux' ) ) {
	/**
	 * The SenroFlux plugin instance. Check available() before starting runs;
	 * feature-detect THIS FUNCTION with function_exists first — it does not
	 * exist while SenroFlux is inactive.
	 *
	 * @return Plugin
	 */
	function senroflux(): Plugin {
		return Plugin::instance();
	}
}
