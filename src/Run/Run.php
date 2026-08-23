<?php
/**
 * Run value object.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

/**
 * One run: a goal pursued on behalf of one user, via one consumer plugin.
 * Immutable snapshot — mutations go through the store, which returns fresh
 * instances.
 */
final class Run {

	/**
	 * @param list<string>   $allow      Ability-name allow-list (globs allowed).
	 * @param array{max_steps:int,max_tool_calls:int,max_tokens:int} $budget
	 * @param array<string,mixed>|null $error Structured failure info (our own convention; code + message keys expected).
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly string $consumer,
		public readonly string $goal,
		public readonly RunStatus $status,
		public readonly array $allow,
		public readonly array $budget,
		public readonly int $stepCount = 0,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly ?string $createdAtUtc = null,
		public readonly ?string $updatedAtUtc = null,
		public readonly ?string $finishedAtUtc = null,
		public readonly ?array $error = null,
	) {
	}

	/**
	 * Build from a store row (column => value).
	 *
	 * @param array<string,mixed> $row Raw row.
	 */
	public static function fromRow( array $row ): self {
		$allow  = json_decode( (string) ( $row['allow_json'] ?? '[]' ), true );
		$budget = json_decode( (string) ( $row['budget_json'] ?? '{}' ), true );
		$error  = json_decode( (string) ( $row['error_json'] ?? 'null' ), true );
		$status = RunStatus::tryFrom( (string) ( $row['status'] ?? '' ) );

		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			userId: (int) ( $row['user_id'] ?? 0 ),
			consumer: (string) ( $row['consumer'] ?? '' ),
			goal: (string) ( $row['goal'] ?? '' ),
			status: $status ?? RunStatus::Failed,
			allow: is_array( $allow ) ? array_values( $allow ) : array(),
			budget: is_array( $budget ) ? Budget::sanitize( $budget ) : Budget::defaults(),
			stepCount: (int) ( $row['step_count'] ?? 0 ),
			tokensIn: (int) ( $row['tokens_in'] ?? 0 ),
			tokensOut: (int) ( $row['tokens_out'] ?? 0 ),
			createdAtUtc: isset( $row['created_at'] ) && is_string( $row['created_at'] ) ? $row['created_at'] : null,
			updatedAtUtc: isset( $row['updated_at'] ) && is_string( $row['updated_at'] ) ? $row['updated_at'] : null,
			finishedAtUtc: isset( $row['finished_at'] ) && is_string( $row['finished_at'] ) ? $row['finished_at'] : null,
			error: is_array( $error ) ? $error : null,
		);
	}

	/**
	 * Column row for insert/update.
	 *
	 * @return array<string,mixed>
	 */
	public function toRow(): array {
		return array(
			'id'          => $this->id,
			'user_id'     => $this->userId,
			'consumer'    => $this->consumer,
			'goal'        => $this->goal,
			'status'      => $this->status->value,
			'allow_json'  => (string) wp_json_encode( $this->allow ),
			'budget_json' => (string) wp_json_encode( $this->budget ),
			'step_count'  => $this->stepCount,
			'tokens_in'   => $this->tokensIn,
			'tokens_out'  => $this->tokensOut,
			'created_at'  => $this->createdAtUtc,
			'updated_at'  => $this->updatedAtUtc,
			'finished_at' => $this->finishedAtUtc,
			'error_json'  => null !== $this->error ? (string) wp_json_encode( $this->error ) : null,
		);
	}
}
