<?php
/**
 * Step value object — one persisted event in a run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Messages\DTO\Message;

/**
 * message_json is EXACTLY the AI Client's `$message->toArray()` (S4): no
 * bespoke schema, so rehydration is `Message::fromArray()` and nothing can
 * drift between what was sent to the model and what we stored.
 */
final class Step {

	/**
	 * @param array<string,mixed>|null $messageArray Canonical toArray() shape.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $runId,
		public readonly int $seq,
		public readonly StepKind $kind,
		public readonly ?array $messageArray,
		public readonly ?string $toolName,
		public readonly ?string $approvalId,
		public readonly string $status,
		public readonly int $tokensIn,
		public readonly int $tokensOut,
		public readonly int $durationMs,
		public readonly ?string $createdAtUtc,
	) {
	}

	/**
	 * Rehydrate the AI Client message, when this step carries one.
	 *
	 * @return Message|null
	 */
	public function toMessage(): ?Message {
		if ( null === $this->messageArray ) {
			return null;
		}

		// The stored array is exactly Message::toArray() output (S4), so it
		// satisfies fromArray()'s documented input shape by construction.
		$message_array = $this->messageArray;

		return Message::fromArray( $message_array );
	}

	/**
	 * Build from a store row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 */
	public static function fromRow( array $row ): self {
		$message = json_decode( (string) ( $row['message_json'] ?? '' ), true );
		$kind    = StepKind::tryFrom( (string) ( $row['kind'] ?? '' ) );

		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			runId: (int) ( $row['run_id'] ?? 0 ),
			seq: (int) ( $row['seq'] ?? 0 ),
			kind: $kind ?? StepKind::System,
			messageArray: is_array( $message ) ? $message : null,
			toolName: isset( $row['tool_name'] ) && is_string( $row['tool_name'] ) && '' !== $row['tool_name'] ? $row['tool_name'] : null,
			approvalId: isset( $row['approval_id'] ) && is_string( $row['approval_id'] ) && '' !== $row['approval_id'] ? $row['approval_id'] : null,
			status: (string) ( $row['status'] ?? '' ),
			tokensIn: (int) ( $row['tokens_in'] ?? 0 ),
			tokensOut: (int) ( $row['tokens_out'] ?? 0 ),
			durationMs: (int) ( $row['duration_ms'] ?? 0 ),
			createdAtUtc: isset( $row['created_at'] ) && is_string( $row['created_at'] ) ? $row['created_at'] : null,
		);
	}
}
