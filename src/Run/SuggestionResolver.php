<?php
/**
 * Resolves a human's Save/Dismiss decision on a brief suggestion (0.3 S20).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

use Specflux\SenroFlux\Tools\SuggestBriefTool;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The ONE place `save`/`dismiss` on a suggestion is carried out — shared by
 * the REST endpoint and the Runs-screen admin-post handler, so there is one
 * implementation of the capability + already-resolved + cap rules.
 *
 * Capability and nonce checks are the CALLER's job (S20: "requires
 * `manage_options` and a REST nonce", re-checked in the handler) — this class
 * only carries out the decision once the caller has already fail-closed on
 * who may call it.
 *
 * Steps are append-only (S4 convention): a decision never rewrites the
 * suggestion step, it appends a `suggestion_resolved` system note the same
 * way `skills_changed` and `answered_by` already do.
 */
final class SuggestionResolver {

	public const ERROR_INVALID_ACTION   = 'invalid_action';
	public const ERROR_NOT_FOUND        = 'suggestion_not_found';
	public const ERROR_ALREADY_RESOLVED = 'suggestion_already_resolved';

	/**
	 * @param string      $action 'save' | 'dismiss'.
	 * @param string|null $text   Optional edited text; defaults to the suggestion's own text.
	 * @return array{run_id:int,seq:int,action:string,text:string}|WP_Error
	 */
	public static function resolve( RunStore $store, int $run_id, int $seq, string $action, ?string $text ): array|WP_Error {
		if ( ! in_array( $action, array( 'save', 'dismiss' ), true ) ) {
			return new WP_Error( self::ERROR_INVALID_ACTION, __( 'action must be "save" or "dismiss".', 'senroflux' ), array( 'status' => 400 ) );
		}

		if ( null === $store->getRun( $run_id ) ) {
			return new WP_Error( 'senroflux_not_found', __( 'Run not found.', 'senroflux' ), array( 'status' => 404 ) );
		}

		$suggestion = null;
		$resolved   = false;
		foreach ( $store->getSteps( $run_id ) as $step ) {
			if ( StepKind::Suggestion === $step->kind && $seq === $step->seq ) {
				$suggestion = $step;
			}
			if ( StepKind::System === $step->kind && is_array( $step->messageArray )
				&& 'suggestion_resolved' === ( $step->messageArray['note'] ?? '' )
				&& (int) ( $step->messageArray['suggestion_seq'] ?? -1 ) === $seq
			) {
				$resolved = true;
			}
		}

		if ( null === $suggestion ) {
			return new WP_Error( self::ERROR_NOT_FOUND, __( 'No such suggestion.', 'senroflux' ), array( 'status' => 404 ) );
		}
		if ( $resolved ) {
			return new WP_Error( self::ERROR_ALREADY_RESOLVED, __( 'That suggestion was already resolved.', 'senroflux' ), array( 'status' => 409 ) );
		}

		$original      = is_array( $suggestion->messageArray ) && is_string( $suggestion->messageArray['text'] ?? null )
			? $suggestion->messageArray['text']
			: '';
		$resolved_text = ( is_string( $text ) && '' !== trim( $text ) ) ? trim( $text ) : $original;

		if ( mb_strlen( $resolved_text ) > SuggestBriefTool::MAX_TEXT_CHARS ) {
			return new WP_Error(
				'invalid_suggestion_text',
				sprintf(
					/* translators: %d is the character cap. */
					__( 'The suggestion text may be at most %d characters.', 'senroflux' ),
					SuggestBriefTool::MAX_TEXT_CHARS
				),
				array( 'status' => 400 )
			);
		}

		if ( 'save' === $action ) {
			// S20: append one line under the SAME brief cap; refuse rather
			// than truncate, and record nothing — the suggestion stays
			// pending, retryable with shorter text.
			$candidate = SiteBrief::withAppendedLine( SiteBrief::get(), $resolved_text );
			$saved     = SiteBrief::set( $candidate );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$store->appendSystemNote(
			$run_id,
			array(
				'note'           => 'suggestion_resolved',
				'suggestion_seq' => $seq,
				'action'         => $action,
				'text'           => $resolved_text,
			)
		);

		return array(
			'run_id' => $run_id,
			'seq'    => $seq,
			'action' => $action,
			'text'   => $resolved_text,
		);
	}
}
