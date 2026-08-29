<?php
/**
 * The S10 publish approval-summary builder (AS-11).
 *
 * TARGET REPO PATH: src/Packs/Pages/PublishSummary.php
 *
 * Hooks `agent_safety_approval_summary` (S14 AS-11): for the Tier-2 verbs
 * `pages/publish` and `pages/update-live` it returns a rich HTML row — the
 * AS-11 side then renders it through `wp_kses($summary, ['a' => ['href' =>
 * true]])`, so ONLY `<a href>` survives; a `<script>` in an input is stripped
 * there, never here. This class returns raw HTML and does NOT sanitise script
 * tags (that is the wp_kses render job).
 *
 * Same builder is shared with the Runs-screen / MAC approval card (S10). The
 * pattern sequence and the run goal are read from the input's `_senroflux`
 * context when present, else omitted (documented — the approval call does not
 * carry the Run object, so the pack keeps this simple and context-passed
 * rather than trying to resolve the current run).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the publish approval summary.
 */
final class PublishSummary {

	/**
	 * The Agent Safety approval-summary filter (AS-11).
	 */
	public const HOOK = 'agent_safety_approval_summary';

	/**
	 * The verbs this builder enriches.
	 *
	 * @var list<string>
	 */
	private const TIER2_VERBS = array( 'pages/publish', 'pages/update-live' );

	/**
	 * Wire the hook (call once, from the pack's own bootstrap).
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( self::HOOK, array( self::class, 'filter' ), 10, 3 );
	}

	/**
	 * The hook callback. Non-Tier-2 verbs (and a missing id) pass the summary
	 * through unchanged.
	 *
	 * @param mixed               $summary The summary so far.
	 * @param string              $verb    The Agent Safety verb.
	 * @param array<string,mixed> $input   The call input.
	 */
	public static function filter( mixed $summary, string $verb, array $input ): string {
		$summary = is_string( $summary ) ? $summary : '';

		if ( ! in_array( $verb, self::TIER2_VERBS, true ) ) {
			return $summary;
		}

		return self::build( $summary, $verb, $input );
	}

	/**
	 * Build the rich HTML summary for a Tier-2 publish verb.
	 *
	 *   Publish "<title>" (page) — <a>preview</a> · <a>edit</a> — <seq> — drafted by run "<goal>"
	 *
	 * @param string              $summary Fallback summary (returned when no id).
	 * @param string              $verb    The Tier-2 verb.
	 * @param array<string,mixed> $input   Call input (id + optional `_senroflux`).
	 */
	public static function build( string $summary, string $verb, array $input ): string {
		unset( $verb );

		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) ) {
			return $summary;
		}

		$id      = (int) $input['id'];
		$title   = function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';
		$title   = '' !== $title ? $title : __( 'Untitled', 'senroflux' );
		$preview = function_exists( 'get_preview_post_link' ) ? (string) get_preview_post_link( $id ) : '';
		$edit    = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
		$preview = '' !== $preview ? $preview : '';
		$edit    = '' !== $edit ? $edit : '';

		$context = $input['_senroflux'] ?? array();
		$context = is_array( $context ) ? $context : array();
		$seq     = isset( $context['pattern_sequence'] ) && is_string( $context['pattern_sequence'] )
			? $context['pattern_sequence']
			: '';
		$goal    = isset( $context['run_goal'] ) && is_string( $context['run_goal'] )
			? $context['run_goal']
			: '';

		$row = sprintf(
			'Publish &quot;%1$s&quot; (page)',
			esc_html( $title )
		);

		if ( '' !== $preview ) {
			$row .= sprintf( ' — <a href="%s">preview</a>', esc_url( $preview ) );
		}
		if ( '' !== $edit ) {
			$row .= sprintf( ' · <a href="%s">edit</a>', esc_url( $edit ) );
		}
		if ( '' !== $seq ) {
			$row .= sprintf( ' — %s', esc_html( $seq ) );
		}
		if ( '' !== $goal ) {
			$row .= sprintf( ' — drafted by run &quot;%s&quot;', esc_html( $goal ) );
		}

		return $row;
	}
}
