<?php
/**
 * Step kinds — what one persisted row records.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

/**
 * Per S4:
 *   - user        — the goal text or a follow-up message.
 *   - model       — one assistant turn (an AI Client ModelMessage).
 *   - tool_result — one function response (a UserMessage carrying a
 *                   FunctionResponse part).
 *   - approval    — parked marker; carries the Agent Safety approval id.
 *   - system      — budget/cancel/error notes written by the harness itself.
 */
enum StepKind: string {

	case User       = 'user';
	case Model      = 'model';
	case ToolResult = 'tool_result';
	case Approval   = 'approval';
	case System     = 'system';

	/**
	 * Kinds whose message_json re-enters the prompt as history verbatim.
	 *
	 * @return list<self>
	 */
	public static function historyKinds(): array {
		return array( self::User, self::Model, self::ToolResult );
	}

	/**
	 * All kinds, for validation of untrusted input.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(
			static fn ( self $kind ): string => $kind->value,
			self::cases()
		);
	}
}
