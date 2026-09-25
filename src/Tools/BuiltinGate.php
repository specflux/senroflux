<?php
/**
 * The built-in gate's classification of one call (0.3 S3).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Carries everything {@see ToolExecutor::call()} needs to park a call BEFORE
 * `check_permissions` runs, in {@see \Specflux\SenroFlux\Run\GateMode::BuiltIn}.
 * Computed by the Runner (which knows the pack verb map) so ToolExecutor
 * itself stays ignorant of packs, tiers and verbs — it only reads the
 * decision it is handed.
 */
final class BuiltinGate {

	public function __construct(
		/** Whether this call needs a park: tier above 0, or unmapped (S3). */
		public readonly bool $active,
		/** The call's tier (0/1/2), from the pack verb map (VerbTier). */
		public readonly int $tier,
		/** The pack verb the call is fenced/reported as. */
		public readonly string $verb,
		/** The synthetic approval id this park is recorded under. */
		public readonly string $approvalId,
		/** True on the re-run of an already-approved park: skip re-parking. */
		public readonly bool $approved,
	) {
	}
}
