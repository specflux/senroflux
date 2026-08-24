<?php
/**
 * One model turn's outcome.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WordPress\AiClient\Messages\DTO\Message;

/**
 * The gateway's unit of work: one assistant message plus the token usage the
 * provider reported for it. Kept as a value object so the Runner can persist
 * both onto the model step without knowing anything about the AI Client's
 * result types.
 */
final class ModelTurn {

	public function __construct(
		public readonly Message $message,
		public readonly int $tokensIn,
		public readonly int $tokensOut,
	) {
	}
}
