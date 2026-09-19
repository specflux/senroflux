<?php
/**
 * A run's gate mode (0.3 S3).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Which enforcement a run was started under. Resolved once at `start()` from
 * whether Agent Safety's gate class is loaded, stored on the run row, and
 * never changed afterwards — a mismatch between the pinned mode and the
 * environment's current one fails the run (`gate_mode_changed`) rather than
 * silently switching enforcement under it.
 */
enum GateMode: string {

	/** Every Tier ≥ 1 call is governed by the Agent Safety plugin's gate. */
	case AgentSafety = 'agent_safety';

	/** No other plugin governs calls; SenroFlux parks Tier ≥ 1 calls itself. */
	case BuiltIn = 'built_in';
}
