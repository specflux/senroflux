<?php
/**
 * The tail of a run's instruction block.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The closing lines appended to a run's instructions: a one-line budget recap
 * plus contextual notes the harness surfaces for the current turn. Immutable
 * and plain text — it renders to exactly the phrasing the model is trained to
 * act on.
 */
final class Tail {

	/**
	 * @param int               $remaining_questions  Questions left in the budget.
	 * @param int               $remaining_plans       Plans left in the budget.
	 * @param int               $remaining_steps       Steps left in the budget.
	 * @param int               $remaining_tool_calls  Tool calls left in the budget.
	 * @param int               $remaining_tokens      Tokens left in the budget.
	 * @param string|null       $last_refusal          'plan_required' | 'not_in_plan' | null.
	 * @param list<string>|null $verify_objects        Object titles to re-read before finishing.
	 * @param string|null       $conversation_language Language NAME, e.g. 'English'.
	 */
	public function __construct(
		public readonly int $remaining_questions,
		public readonly int $remaining_plans,
		public readonly int $remaining_steps,
		public readonly int $remaining_tool_calls,
		public readonly int $remaining_tokens,
		public readonly ?string $last_refusal = null,
		public readonly ?array $verify_objects = null,
		public readonly ?string $conversation_language = null,
	) {}

	/**
	 * Render the tail lines. One line per non-empty item, plain text, joined
	 * with a single newline; an empty tail field contributes no line.
	 *
	 * @return string
	 */
	public function render(): string {
		$lines = array();

		$lines[] = sprintf(
			'Budget: %1$d questions, %2$d plans, %3$d tool calls and %4$d steps remain.',
			$this->remaining_questions,
			$this->remaining_plans,
			$this->remaining_tool_calls,
			$this->remaining_steps
		);

		if ( $this->remaining_questions <= 0 ) {
			$lines[] = 'No questions remain: state your assumptions in the plan.';
		}

		if ( 'plan_required' === $this->last_refusal ) {
			$lines[] = 'Your last write was refused: `plan_required` — propose a plan first.';
		} elseif ( 'not_in_plan' === $this->last_refusal ) {
			$lines[] = 'Your last write was refused: `not_in_plan` — stay inside the accepted plan or propose a new one.';
		}

		if ( ! empty( $this->verify_objects ) ) {
			$lines[] = 'Before finishing, re-read: ' . implode( ', ', $this->verify_objects ) . '.';
		}

		if ( null !== $this->conversation_language && '' !== $this->conversation_language ) {
			$lines[] = 'Speak to the user in ' . $this->conversation_language . '.';
		}

		return implode( "\n", $lines );
	}
}
