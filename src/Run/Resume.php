<?php
/**
 * Park-resolution validation (0.2 S5).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A park resolution is the human's reply that resumes a parked run, and its
 * SHAPE must match the run's park kind — anything else is `resume_mismatch`
 * (HTTP 400), never a best-effort interpretation. Fail closed: exact key
 * sets, exact value domains, no extra keys, no coercion. A string or any
 * other non-array payload (e.g. the removed 0.1 `approval_action` param)
 * cannot match any park kind.
 *
 * S5 shapes:
 *
 *   awaiting_approval : { "action": "approve" | "reject" }
 *   awaiting_user     : { "answer": { "text"?: string, "choice"?: string } } | { "skip": true }
 *   awaiting_plan     : { "plan": { "action": "accept" | "accept_preapprove" | "veto", "note"?: string ≤500 } }
 *
 * One rule beyond the bare shape: a veto carries a note (S7 returns it
 * verbatim; S15 makes the note field required on Veto), so a noteless veto is
 * a mismatch — the more restrictive reading.
 */
final class Resume {

	/** Human cap on the plan-resolution note (S5). */
	public const MAX_NOTE_CHARS = 500;

	/**
	 * Validate a park resolution against the run's park kind.
	 *
	 * @param RunStatus $status The run's CURRENT status (must be a park).
	 * @param mixed     $resume The caller-supplied resolution payload.
	 * @return bool|WP_Error True when the shape matches the park kind;
	 *                       WP_Error `resume_mismatch` (status 400) otherwise.
	 */
	public static function check( RunStatus $status, mixed $resume ): bool|WP_Error {
		$mismatch = static function ( string $message ): WP_Error {
			return new WP_Error(
				'resume_mismatch',
				$message,
				array( 'status' => 400 )
			);
		};

		if ( ! $status->isParked() ) {
			return $mismatch(
				__( 'This run is not waiting on a human; it accepts no park resolution.', 'senroflux' )
			);
		}

		if ( ! is_array( $resume ) ) {
			return $mismatch(
				__( 'The resume payload must be a JSON object matching the run\'s park kind.', 'senroflux' )
			);
		}

		return match ( $status ) {
			RunStatus::AwaitingApproval => self::checkApproval( $resume, $mismatch ),
			RunStatus::AwaitingUser     => self::checkUser( $resume, $mismatch ),
			RunStatus::AwaitingPlan     => self::checkPlan( $resume, $mismatch ),
			default                     => $mismatch(
				__( 'This run\'s park kind accepts no park resolution.', 'senroflux' )
			),
		};
	}

	/**
	 * @param array<string,mixed>        $resume
	 * @param callable(string): WP_Error $mismatch
	 */
	private static function checkApproval( array $resume, callable $mismatch ): bool|WP_Error {
		if ( array_keys( $resume ) !== array( 'action' ) ) {
			return $mismatch(
				__( 'An approval park resumes with exactly { "action": "approve" | "reject" }.', 'senroflux' )
			);
		}

		if ( ! in_array( $resume['action'], array( 'approve', 'reject' ), true ) ) {
			return $mismatch(
				__( 'An approval park resumes with action "approve" or "reject".', 'senroflux' )
			);
		}

		return true;
	}

	/**
	 * @param array<string,mixed>        $resume
	 * @param callable(string): WP_Error $mismatch
	 */
	private static function checkUser( array $resume, callable $mismatch ): bool|WP_Error {
		if ( array_keys( $resume ) === array( 'skip' ) ) {
			return true === $resume['skip']
				? true
				: $mismatch( __( 'A question park resumes with skip set to true.', 'senroflux' ) );
		}

		if ( array_keys( $resume ) !== array( 'answer' ) ) {
			return $mismatch(
				__( 'A question park resumes with { "answer": { "text"?, "choice"? } } or { "skip": true }.', 'senroflux' )
			);
		}

		$answer = $resume['answer'];
		if ( ! is_array( $answer ) ) {
			return $mismatch( __( 'The answer payload must be an object.', 'senroflux' ) );
		}

		foreach ( array_keys( $answer ) as $key ) {
			if ( ! in_array( $key, array( 'text', 'choice' ), true ) ) {
				return $mismatch(
					__( 'An answer may only carry "text" and/or "choice".', 'senroflux' )
				);
			}
			if ( ! is_string( $answer[ $key ] ) ) {
				return $mismatch( __( 'The answer text and choice must be strings.', 'senroflux' ) );
			}
		}

		if ( array() === $answer ) {
			return $mismatch( __( 'The answer payload must carry a "text" and/or a "choice".', 'senroflux' ) );
		}

		return true;
	}

	/**
	 * @param array<string,mixed>        $resume
	 * @param callable(string): WP_Error $mismatch
	 */
	private static function checkPlan( array $resume, callable $mismatch ): bool|WP_Error {
		if ( array_keys( $resume ) !== array( 'plan' ) ) {
			return $mismatch(
				__( 'A plan park resumes with exactly { "plan": { "action": ..., "note"? } }.', 'senroflux' )
			);
		}

		$plan = $resume['plan'];
		if ( ! is_array( $plan ) ) {
			return $mismatch( __( 'The plan payload must be an object.', 'senroflux' ) );
		}

		$keys = array_keys( $plan );
		sort( $keys );
		if ( array( 'action' ) !== $keys && array( 'action', 'note' ) !== $keys ) {
			return $mismatch(
				__( 'A plan resolution carries "action" and an optional "note".', 'senroflux' )
			);
		}

		if ( ! in_array( $plan['action'], array( 'accept', 'accept_preapprove', 'veto' ), true ) ) {
			return $mismatch(
				__( 'A plan resolution acts with "accept", "accept_preapprove" or "veto".', 'senroflux' )
			);
		}

		if ( isset( $plan['note'] ) ) {
			if ( ! is_string( $plan['note'] ) ) {
				return $mismatch( __( 'The plan note must be a string.', 'senroflux' ) );
			}
			if ( mb_strlen( $plan['note'] ) > self::MAX_NOTE_CHARS ) {
				return $mismatch(
					sprintf(
						// Translators: %d is the maximum number of characters.
						__( 'The plan note may be at most %d characters.', 'senroflux' ),
						self::MAX_NOTE_CHARS
					)
				);
			}
		}

		if ( 'veto' === $plan['action'] && ( ! isset( $plan['note'] ) || '' === trim( $plan['note'] ) ) ) {
			return $mismatch( __( 'A veto carries a note explaining it.', 'senroflux' ) );
		}

		return true;
	}
}
