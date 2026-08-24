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
 * One run's lifecycle, per S4:
 *
 *   pending → running → (awaiting_approval ↔ running)* → completed
 *                     \→ failed (budget exhausted / fatal)
 *                     \→ cancelled (user or admin)
 */
enum RunStatus: string {

	case Pending          = 'pending';
	case Running          = 'running';
	case AwaitingApproval = 'awaiting_approval';
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
