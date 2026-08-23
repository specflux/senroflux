<?php
/**
 * The outcome of one tool call, before any model turn.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Four terminal kinds (S5):
 *   - result            — the ability ran; carries its output array.
 *   - approval_required — Agent Safety demands a human; carries the
 *                         approval id (+ verb/tier from the gate's error data).
 *   - denied            — the gate refused; carries code + message. execute()
 *                         was NEVER called.
 *   - unknown_tool      — no such ability; never executed either.
 */
final class ToolOutcome {

	/**
	 * @param array<string,mixed>|null $output Ability output (result kind).
	 */
	private function __construct(
		public readonly string $kind,
		public readonly ?array $output = null,
		public readonly ?string $approvalId = null,
		public readonly ?string $verb = null,
		public readonly ?string $tier = null,
		public readonly ?string $errorCode = null,
		public readonly ?string $errorMessage = null,
	) {
	}

	/**
	 * Successful execution.
	 *
	 * @param array<string,mixed> $output Ability output array.
	 */
	public static function result( array $output ): self {
		return new self( 'result', output: $output );
	}

	/**
	 * Agent Safety parked this call for human approval.
	 */
	public static function approvalRequired( string $approval_id, string $verb, ?string $tier ): self {
		return new self( 'approval_required', approvalId: $approval_id, verb: $verb, tier: $tier );
	}

	/**
	 * Gate refusal — never executed.
	 */
	public static function denied( string $code, string $message ): self {
		return new self( 'denied', errorCode: $code, errorMessage: $message );
	}

	/**
	 * Unknown ability — never executed.
	 */
	public static function unknownTool( string $name ): self {
		return new self( 'unknown_tool', errorMessage: $name );
	}

	/**
	 * The ability itself failed.
	 */
	public static function executionError( string $message ): self {
		return new self( 'error', errorMessage: $message );
	}
}
