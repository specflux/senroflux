<?php
/**
 * Run/step persistence contract.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The store owns runs + steps rows. Implementations must keep the
 * (run_id, seq) pair unique and the step_count column authoritative for a
 * run's optimistic-lock check (the tick protocol echoes it back).
 */
interface RunStore {

	/**
	 * Create a pending run; returns its id.
	 *
	 * @param int                $user_id  Owner (get_current_user_id()).
	 * @param string             $consumer Consumer plugin identifier.
	 * @param string             $goal     Goal text.
	 * @param list<string>       $allow    Ability allow-list.
	 * @param array<string,int>  $budget   Sanitized budget.
	 */
	public function createRun(
		int $user_id,
		string $consumer,
		string $goal,
		array $allow,
		array $budget,
		?string $pack = null,
		?string $conversation_locale = null,
		?string $content_locale = null
	): int;

	/**
	 * One run, or null.
	 */
	public function getRun( int $run_id ): ?Run;

	/**
	 * All steps of a run, ordered by seq.
	 *
	 * @return list<Step>
	 */
	public function getSteps( int $run_id ): array;

	/**
	 * Append one step; the store assigns the next seq. Returns the seq used.
	 *
	 * @param StepKind               $kind        Step kind.
	 * @param array<string,mixed>|null $message_array Canonical toArray() shape, when the kind carries one.
	 * @param string|null            $tool_name   Ability name for tool steps.
	 * @param string|null            $approval_id Agent Safety approval id for parked calls.
	 */
	public function appendStep(
		int $run_id,
		StepKind $kind,
		?array $message_array = null,
		?string $tool_name = null,
		?string $approval_id = null,
		string $status = 'ok',
		int $tokens_in = 0,
		int $tokens_out = 0,
		int $duration_ms = 0
	): int;

	/**
	 * Patch mutable run columns (status, counters, error, finished_at).
	 *
	 * @param array<string,mixed> $fields Column => value; unknown keys are ignored.
	 */
	public function updateRun( int $run_id, array $fields ): void;

	/**
	 * Persist the seq-0 system step (the rendered system instruction's audit
	 * record, 0.2 S8). Seq 0 is reserved: it precedes the goal step and does
	 * NOT bump step_count — the optimistic lock counts only steps the
	 * browser's tick protocol has seen.
	 *
	 * @param array<string,mixed> $message_array The system-step payload.
	 */
	public function prependSystemStep( int $run_id, array $message_array ): int;

	/**
	 * Append a harness system-note step (e.g. `skills_changed`, 0.2 S8):
	 * message_json holds the note payload (`{"note": ..., ...}`), the step
	 * occupies the next seq and bumps step_count like any recorded step.
	 *
	 * @param array<string,mixed> $message_array The note payload.
	 */
	public function appendSystemNote( int $run_id, array $message_array ): int;

	/**
	 * Most recently updated runs first (the Runs screen's list).
	 *
	 * @param int $limit Max rows.
	 * @return list<Run>
	 */
	public function listRecent( int $limit = 50 ): array;
}
