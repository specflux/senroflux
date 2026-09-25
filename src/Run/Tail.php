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
	 * @param int|null          $elapsed_gap_seconds   S9: seconds since the run's previous step, when
	 *                                                 this tick starts more than 10 minutes after it. Null
	 *                                                 below the threshold, or on a run's first tick.
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
		public readonly ?int $elapsed_gap_seconds = null,
	) {}

	/**
	 * S9: a tick starting more than this many seconds after the run's
	 * previous step gets the "Resumed after ..." sentence. `[assumed]` per
	 * S9 — overturn to any threshold the owner prefers.
	 */
	public const ELAPSED_GAP_THRESHOLD_SECONDS = 600;

	/**
	 * Render the tail lines. One line per non-empty item, plain text, joined
	 * with a single newline; an empty tail field contributes no line.
	 *
	 * @return string
	 */
	public function render(): string {
		$lines = array();

		// S8 lists the token budget among the numbers the tail carries: a model
		// that cannot see it cannot decide to wrap up before it is cut off.
		$lines[] = sprintf(
			'Budget: %1$d questions, %2$d plans, %3$d tool calls, %4$d steps and %5$d tokens remain.',
			$this->remaining_questions,
			$this->remaining_plans,
			$this->remaining_tool_calls,
			$this->remaining_steps,
			$this->remaining_tokens
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

		// S9: the elapsed gap. No forced re-read — this is a nudge, not a
		// rule the harness enforces.
		if ( null !== $this->elapsed_gap_seconds && $this->elapsed_gap_seconds > self::ELAPSED_GAP_THRESHOLD_SECONDS ) {
			$lines[] = sprintf(
				'Resumed after %s. The site may have changed since your last read.',
				self::humanDuration( $this->elapsed_gap_seconds )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * A human duration for the S9 elapsed-gap sentence ("26 hours", "3 days",
	 * "10 minutes") — S9's own example names a 26-hour gap in HOURS, not "1
	 * day", so hours stay hours up to two full days; only a gap of 48 hours
	 * or more switches to days.
	 *
	 * @param int $seconds Elapsed seconds (must be > 0).
	 */
	private static function humanDuration( int $seconds ): string {
		$minutes = intdiv( $seconds, MINUTE_IN_SECONDS );
		if ( $minutes < 60 ) {
			$minutes = max( 1, $minutes );

			return $minutes . ' ' . ( 1 === $minutes ? 'minute' : 'minutes' );
		}

		$hours = intdiv( $seconds, HOUR_IN_SECONDS );
		if ( $hours < 48 ) {
			return $hours . ' ' . ( 1 === $hours ? 'hour' : 'hours' );
		}

		$days = intdiv( $seconds, DAY_IN_SECONDS );

		return $days . ' ' . ( 1 === $days ? 'day' : 'days' );
	}

	/**
	 * The human-readable language name for a locale (S15). Best-effort: an
	 * unknown locale renders as-is. Filterable via `senroflux_language_name`.
	 *
	 * @param string $locale Locale code, e.g. 'en_US'.
	 */
	public static function languageName( string $locale ): ?string {
		$names = array(
			'en_US' => 'English (US)',
			'en_GB' => 'English (UK)',
			'ms_MY' => 'Malay',
			'zh_CN' => 'Chinese (Simplified)',
			'zh_TW' => 'Chinese (Traditional)',
		);

		/**
		 * Filters the locale => language-name map for the tail line.
		 *
		 * @param array<string,string> $names Known locale names.
		 */
		$names = apply_filters( 'senroflux_language_name', $names );

		$name = is_array( $names ) ? ( $names[ $locale ] ?? $locale ) : $locale;

		return ( is_string( $name ) && '' !== $name ) ? $name : null;
	}
}
