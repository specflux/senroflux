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
 * Same builder is shared with the Runs-screen / MAC approval card (S10).
 *
 * PROVENANCE RULE: every part of this row is read SERVER-SIDE. The title,
 * links and pattern sequence come from the stored post; the run goal comes
 * from the run row, handed over by the composition root for the current tick
 * ({@see useRunContext()}). Nothing here is read out of the tool-call
 * arguments except the post `id` the call is acting on. An earlier version
 * took the sequence and the goal from an `_senroflux` key inside the input —
 * i.e. from fields the MODEL writes — and rendered them to the approving human
 * as provenance. That is precisely the sentence a human reads before clicking
 * publish, so it may never be agent-authored.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use Specflux\SenroFlux\Packs\Pack;

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
	 * The goal of the run whose tick is executing, or null outside a run.
	 * Server-side: set from the run row by the composition root.
	 */
	private static ?string $run_goal = null;

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
	 * Enter the run context for one tick. The goal MUST come from the run row,
	 * never from a tool call: it is rendered to the approving human as the
	 * reason this publish exists.
	 *
	 * @param string|null $goal The run's goal, or null to leave the row's
	 *                          "drafted by run" clause off.
	 */
	public static function useRunContext( ?string $goal ): void {
		self::$run_goal = ( null !== $goal && '' !== $goal ) ? $goal : null;
	}

	/**
	 * Leave the run context. Ticks are not one-per-process (PHPUnit, WP-CLI,
	 * cron), so the context is scoped, never set once per request — a leaked
	 * goal would credit one run's publish to another.
	 */
	public static function forgetRunContext(): void {
		self::$run_goal = null;
	}

	/**
	 * The hook callback. Non-Tier-2 calls (and a missing id) pass the summary
	 * through unchanged.
	 *
	 * Agent Safety's `$verb` is the ABILITY ID at the gate seam that governs
	 * this plugin (see {@see \Specflux\SenroFlux\Packs\Pack}), so it is
	 * resolved through the pack's own predicate before the Tier-2 test — a
	 * plain `'pages/publish' === $verb` comparison here would never match a
	 * real approval row.
	 *
	 * @param mixed               $summary The summary so far.
	 * @param string              $verb    The Agent Safety verb (an ability id).
	 * @param array<string,mixed> $input   The call input.
	 */
	public static function filter( mixed $summary, string $verb, array $input ): string {
		$summary  = is_string( $summary ) ? $summary : '';
		$resolved = ( new PagesPack() )->verbFor( $verb, $input );

		if ( ! in_array( $resolved, self::TIER2_VERBS, true ) ) {
			return $summary;
		}

		return self::build( $summary, $resolved, $input );
	}

	/**
	 * Build the rich HTML summary for a Tier-2 publish verb.
	 *
	 *   Publish "<title>" (page) — <a>preview</a> · <a>edit</a> — <seq> — drafted by run "<goal>"
	 *
	 * @param string              $summary Fallback summary (returned when no id).
	 * @param string              $verb    The Tier-2 pack verb.
	 * @param array<string,mixed> $input   Call input; only `id` is read from it.
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

		$seq  = self::patternSequence( $id );
		$goal = self::$run_goal ?? '';

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

	/**
	 * The page's pattern sequence — `hero › pricing-table › faq › cta` — read
	 * from the STORED post content, which is the markup this approval would
	 * publish. Each top-level block carries the canonical `metadata.name` the
	 * pack's Validator normalised on write (`senroflux/<slug>`); a block
	 * without one is skipped rather than guessed at.
	 *
	 * @param int $id The post id.
	 */
	private static function patternSequence( int $id ): string {
		if ( ! function_exists( 'get_post' ) || ! function_exists( 'parse_blocks' ) ) {
			return '';
		}

		// Read through get_object_vars(), not `$post->post_content`: get_post()
		// returns a duck-typed row here (as it does across the pack's other
		// post reads) and the field must stay a genuine runtime check.
		$post    = get_post( $id );
		$fields  = is_object( $post ) ? get_object_vars( $post ) : array();
		$content = $fields['post_content'] ?? null;
		if ( ! is_string( $content ) || '' === $content ) {
			return '';
		}

		$slugs = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = $block['attrs']['metadata']['name'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$slugs[] = str_starts_with( $name, Pack::POLYFILL_NAMESPACE )
				? substr( $name, strlen( Pack::POLYFILL_NAMESPACE ) )
				: $name;
		}

		return implode( ' › ', $slugs );
	}
}
