<?php
/**
 * Run lifecycle states.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * One run's lifecycle, per S4 (as amended by SPEC-SENROFLUX-0.2 S4):
 *
 *   pending → running → (awaiting_approval ↔ running)*
 *                     → (awaiting_user ↔ running)*
 *                     → (awaiting_plan ↔ running)*
 *                     → completed
 *                     \→ failed (budget exhausted / fatal)
 *                     \→ cancelled (user or admin, or plan ceiling vetoes)
 *
 * The three awaiting_* states are PARKS (S3 glossary): a run stops and waits
 * for a human. They are distinct statuses so a consumer can pick the park
 * card by status alone.
 */
enum RunStatus: string {

	case Pending          = 'pending';
	case Running          = 'running';
	case AwaitingApproval = 'awaiting_approval';
	case AwaitingUser     = 'awaiting_user';
	case AwaitingPlan     = 'awaiting_plan';
	case Completed        = 'completed';
	case Failed           = 'failed';
	case Cancelled        = 'cancelled';

	/**
	 * Terminal states accept no further ticks; their state is returned as-is.
	 */
	public function isTerminal(): bool {
		return self::Completed === $this
			|| self::Failed === $this
			|| self::Cancelled === $this;
	}

	/**
	 * The park states: a run waiting on a human decision, resumable only with
	 * a park resolution whose shape matches the park kind (S5).
	 */
	public function isParked(): bool {
		return self::AwaitingApproval === $this
			|| self::AwaitingUser === $this
			|| self::AwaitingPlan === $this;
	}

	/**
	 * All statuses, for validation of untrusted input.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(
			static fn ( self $status ): string => $status->value,
			self::cases()
		);
	}
}
