<?php
/**
 * AS-15's approval-summary builder for the 0.3 content-pack Tier-2 calls
 * that reach Pending Agent Actions but are NOT the pages pack's own
 * `pages/publish`/`pages/update-live` (those stay
 * {@see \Specflux\SenroFlux\Packs\Pages\PublishSummary}'s, unchanged).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the AS-15 content-pack approval summaries.
 *
 * Hooks the same `agent_safety_approval_summary` filter (AS-11/AS-15) for
 * four cases:
 *
 *   - `senroflux/create-post` and `senroflux/update-post` when the call
 *     names `categories` or `tags`: the terms the write will set. Without
 *     them there is no card here (the call stays as it was), and the
 *     publish-post card below carries the same clause.
 *   - `senroflux/publish-post` when the target object is an ACTUAL blog
 *     post (`post_type` `post`, a posts-pack run): title + preview link,
 *     "as AS-11 does for pages" (S15/AS-15).
 *   - `senroflux/update-navigation`: the current item list, read by the
 *     server, beside the call's proposed list (S15/AS-15).
 *   - `senroflux/set-front-page`: the current front page (or "latest
 *     posts") beside the replacement (S15/AS-15).
 *
 * WHY A SIBLING, NOT AN EXTENSION OF PublishSummary: `senroflux/publish-post`
 * is ONE shared ability across three packs (pages, posts, site) — Pages',
 * Posts' and Site's own `verbFor()` all classify a transitioning call the
 * same way regardless of the object's real `post_type`, so PublishSummary's
 * existing `(new PagesPack())->verbFor()` dispatch already matches (and
 * renders) EVERY publish-post call, page or post alike, labelling it
 * "(page)" unconditionally. Rather than touch that class's pages behaviour
 * (explicitly out of scope for this stage) or the string it renders, this
 * class discriminates on the STORED OBJECT's real post_type: it is inert
 * for anything that is not a `post`, so a page's card is left exactly as
 * PublishSummary already built it, and a post's card is filed by THIS
 * class's own final row — which fully replaces whatever PublishSummary
 * produced, because `agent_safety_approval_summary` is a plain string
 * filter and the last matching callback's return value wins.
 *
 * PROVENANCE RULE (same discipline as PublishSummary/CommerceSummary):
 * every "current" value is read SERVER-SIDE — the navigation's resolved
 * item list, the front page's stored settings, the post's own title/type.
 * The call's own arguments are read only for the PROPOSED half, and a
 * proposed page id is itself resolved back to a title server-side rather
 * than trusting anything past the id.
 */
final class ContentSummary {

	/**
	 * The Agent Safety approval-summary filter (AS-11/AS-15).
	 */
	public const HOOK = 'agent_safety_approval_summary';

	/**
	 * Wire the hook (call once, from the composition root).
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( self::HOOK, array( self::class, 'filter' ), 10, 3 );
	}

	/**
	 * The hook callback. Anything this class does not own passes through
	 * unchanged.
	 *
	 * @param mixed               $summary The summary so far.
	 * @param string              $verb    The Agent Safety verb (an ability id).
	 * @param array<string,mixed> $input   The call input.
	 */
	public static function filter( mixed $summary, string $verb, array $input ): string {
		$summary = is_string( $summary ) ? $summary : '';

		return match ( self::baseName( $verb ) ) {
			'publish-post' => self::publishPostCard( $summary, $input ),
			'create-post' => self::termsCard( $summary, $input, null ),
			'update-post' => self::termsCard( $summary, $input, self::storedPost( $input ) ),
			'update-navigation' => self::navigationCard( $input ),
			'set-front-page' => self::frontPageCard( $input ),
			default => $summary,
		};
	}

	// ------------------------------------------------------------------
	// Cards
	// ------------------------------------------------------------------

	/**
	 * `publish-post` for an actual blog post. Inert (passthrough) for
	 * anything that is not a `post` — a page, or an id that does not
	 * resolve — so a page's card stays PublishSummary's alone.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function publishPostCard( string $summary, array $input ): string {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) || ! function_exists( 'get_post' ) ) {
			return $summary;
		}

		$id   = (int) $input['id'];
		$post = get_post( $id );
		if ( ! is_object( $post ) || 'post' !== ( self::field( $post, 'post_type' ) ?? '' ) ) {
			return $summary;
		}

		$title   = self::field( $post, 'post_title' );
		$title   = is_string( $title ) && '' !== $title ? $title : __( 'Untitled', 'senroflux' );
		$preview = function_exists( 'get_preview_post_link' ) ? (string) get_preview_post_link( $id ) : '';
		$edit    = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $id, 'raw' ) : '';

		$when = ( 'future' === ( $input['status'] ?? null ) && is_string( $input['date'] ?? null ) ) ? trim( $input['date'] ) : '';
		$row  = '' !== $when
			? sprintf( 'Schedule &quot;%1$s&quot; (post) for %2$s (site time)', esc_html( $title ), esc_html( $when ) )
			: sprintf( 'Publish &quot;%s&quot; (post)', esc_html( $title ) );
		if ( '' !== $preview ) {
			$row .= sprintf( ' — <a href="%s">preview</a>', esc_url( $preview ) );
		}
		if ( '' !== $edit ) {
			$row .= sprintf( ' · <a href="%s">edit</a>', esc_url( $edit ) );
		}

		return $row . self::termsClause( $input );
	}

	/**
	 * `create-post` / `update-post` carrying `categories` or `tags`: what the
	 * write is, and the terms it will set. Passthrough when it sets none.
	 * `$post` is the stored target of an update, null for a create.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function termsCard( string $summary, array $input, ?object $post ): string {
		$clause = self::termsClause( $input );
		if ( '' === $clause ) {
			return $summary;
		}

		if ( null === $post ) {
			$title = is_string( $input['title'] ?? null ) && '' !== trim( $input['title'] ) ? trim( $input['title'] ) : __( 'Untitled', 'senroflux' );

			return sprintf( 'Create draft &quot;%s&quot; (post)', esc_html( $title ) ) . $clause;
		}

		$title = self::field( $post, 'post_title' );
		$title = is_string( $title ) && '' !== $title ? $title : __( 'Untitled', 'senroflux' );

		return sprintf( 'Update &quot;%s&quot; (post)', esc_html( $title ) ) . $clause;
	}

	/**
	 * The stored post an `id` input names, when it is an actual blog post.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function storedPost( array $input ): ?object {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) || ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( (int) $input['id'] );

		return is_object( $post ) && 'post' === ( self::field( $post, 'post_type' ) ?? '' ) ? $post : null;
	}

	/**
	 * " — categories: "A", "B" — tags: "x"" for the term names the call sets,
	 * escaped, or '' when it names none. Names come from the call: they are
	 * what will be set, shown beside the approval, not provenance.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function termsClause( array $input ): string {
		$clause = '';
		foreach ( array( 'categories', 'tags' ) as $field ) {
			if ( ! is_array( $input[ $field ] ?? null ) ) {
				continue;
			}

			$names = array();
			foreach ( $input[ $field ] as $name ) {
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					$names[] = sprintf( '&quot;%s&quot;', esc_html( trim( $name ) ) );
				}
			}
			if ( array() !== $names ) {
				$clause .= sprintf( ' — %1$s: %2$s', $field, implode( ', ', $names ) );
			}
		}

		return $clause;
	}

	/**
	 * `update-navigation`: the resolved navigation's CURRENT item list,
	 * read by the server, beside the call's proposed list.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function navigationCard( array $input ): string {
		$current  = self::currentNavigationItems();
		$proposed = is_array( $input['items'] ?? null ) ? $input['items'] : array();

		$row  = 'Update site navigation';
		$row .= sprintf( ' — current: %s', self::itemLabels( $current ) );
		$row .= sprintf( ' — proposed: %s', self::itemLabels( Navigation::orderedItems( $proposed ) ) );

		$removed = self::removedLabels( $current, $proposed );
		if ( array() !== $removed ) {
			$row .= sprintf( ' — removes: %s', implode( ', ', $removed ) );
		}

		return $row;
	}

	/**
	 * The CURRENT items the proposed list leaves out, as escaped quoted
	 * paths. A page item is matched by its page id, a custom link by its
	 * url — the label may be reworded without it counting as removed. Shown,
	 * never refused: a person may want the removal.
	 *
	 * @param list<array<string,mixed>> $current  The current items.
	 * @param array<int|string,mixed>   $proposed The call's proposed items.
	 * @return list<string>
	 */
	private static function removedLabels( array $current, array $proposed ): array {
		$page_ids = array();
		$urls     = array();
		foreach ( $proposed as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['page_id'] ) && is_numeric( $item['page_id'] ) ) {
				$page_ids[ (int) $item['page_id'] ] = true;
			}
			if ( isset( $item['url'] ) && is_string( $item['url'] ) ) {
				$urls[ rtrim( $item['url'], '/' ) ] = true;
			}
		}

		$removed = array();
		foreach ( $current as $item ) {
			$page_id = $item['page_id'] ?? null;
			if ( is_int( $page_id ) && 0 !== $page_id ) {
				$kept = isset( $page_ids[ $page_id ] );
			} else {
				$url  = is_string( $item['url'] ?? null ) ? rtrim( $item['url'], '/' ) : '';
				$kept = '' === $url || isset( $urls[ $url ] );
			}
			if ( ! $kept ) {
				$removed[] = self::itemLabels( array( $item ), $current );
			}
		}

		return $removed;
	}

	/**
	 * `set-front-page`: the current front page (or "latest posts") beside
	 * the replacement. The replacement's title is resolved server-side from
	 * the proposed `page_id` — the call's args carry only the id.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function frontPageCard( array $input ): string {
		$current = self::currentFrontPageSettings();

		$current_label = self::frontPageLabel(
			is_string( $current['show_on_front'] ?? null ) ? $current['show_on_front'] : 'posts',
			self::namedPage( $current['page_on_front'] ?? null )
		);

		$proposed_show = is_string( $input['show_on_front'] ?? null ) ? $input['show_on_front'] : 'posts';
		$proposed_page = null;
		if ( 'page' === $proposed_show && isset( $input['page_id'] ) && is_numeric( $input['page_id'] ) ) {
			$page_id       = (int) $input['page_id'];
			$title         = function_exists( 'get_the_title' ) ? (string) get_the_title( $page_id ) : '';
			$proposed_page = array(
				'id'    => $page_id,
				'title' => $title,
			);
		}
		$proposed_label = self::frontPageLabel( $proposed_show, $proposed_page );

		return sprintf( 'Set front page — current: %s — replacement: %s', $current_label, $proposed_label );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * The resolved navigation's CURRENT item list, or an empty list when
	 * {@see Navigation} is unavailable — read-only, records no S8 marker.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function currentNavigationItems(): array {
		if ( ! class_exists( Navigation::class ) ) {
			return array();
		}

		return Navigation::currentItemsForSummary()['items'];
	}

	/**
	 * The CURRENT front-page settings, or an empty set when {@see FrontPage}
	 * is unavailable.
	 *
	 * @return array<string,mixed>
	 */
	private static function currentFrontPageSettings(): array {
		if ( ! class_exists( FrontPage::class ) ) {
			return array();
		}

		return FrontPage::currentSettingsForSummary();
	}

	/**
	 * Narrow a loosely-typed "named page" value (from
	 * {@see currentFrontPageSettings()}'s `page_on_front`/`page_for_posts`
	 * shape) to `{id,title}`, or null when it does not carry a valid id.
	 *
	 * @return array{id:int,title:string}|null
	 */
	private static function namedPage( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! isset( $value['id'] ) || ! is_numeric( $value['id'] ) ) {
			return null;
		}

		return array(
			'id'    => (int) $value['id'],
			'title' => is_string( $value['title'] ?? null ) ? $value['title'] : '',
		);
	}

	/**
	 * @param string                           $show_on_front 'page' or 'posts'.
	 * @param array{id:int,title:string}|null $page   The named page, when 'page'.
	 */
	private static function frontPageLabel( string $show_on_front, ?array $page ): string {
		if ( 'page' === $show_on_front && null !== $page ) {
			return sprintf( '&quot;%s&quot; (page)', esc_html( $page['title'] ) );
		}

		return esc_html__( 'Latest posts', 'senroflux' );
	}

	/**
	 * A comma-joined, escaped list of item labels, or a placeholder when
	 * there are none. A nested item reads as its path ("Services › Web
	 * Design"), resolved through the `key`/`parent` the items carry.
	 *
	 * @param array<int|string,mixed>       $items   {@see Navigation}'s item shape,
	 *                                               or a call's proposed item list —
	 *                                               each entry is checked, never
	 *                                               assumed, since the proposed side
	 *                                               comes straight from the call.
	 * @param array<int|string,mixed>|null  $context The full list the paths resolve
	 *                                               against, when `$items` is a subset.
	 */
	private static function itemLabels( array $items, ?array $context = null ): string {
		if ( array() === $items ) {
			return esc_html__( '(none)', 'senroflux' );
		}

		$by_key = array();
		foreach ( $context ?? $items as $item ) {
			if ( is_array( $item ) && is_string( $item['key'] ?? null ) ) {
				$by_key[ $item['key'] ] = $item;
			}
		}

		$labels = array();
		foreach ( $items as $item ) {
			$path   = array();
			$seen   = array();
			$cursor = is_array( $item ) ? $item : array();
			while ( true ) {
				$path[] = esc_html( is_string( $cursor['label'] ?? null ) ? $cursor['label'] : '' );
				$parent = $cursor['parent'] ?? null;
				if ( ! is_string( $parent ) || ! isset( $by_key[ $parent ] ) || isset( $seen[ $parent ] ) ) {
					break;
				}
				$seen[ $parent ] = true;
				$cursor          = $by_key[ $parent ];
			}

			$labels[] = sprintf( '&quot;%s&quot;', implode( ' › ', array_reverse( $path ) ) );
		}

		return implode( ', ', $labels );
	}

	/**
	 * Read one field off a duck-typed post row (mirrors PublishSummary's own
	 * `get_object_vars()` discipline — the test shim's `get_post()` returns
	 * a genuine runtime object, never guessed at).
	 */
	private static function field( object $post, string $field ): mixed {
		$fields = get_object_vars( $post );

		return $fields[ $field ] ?? null;
	}

	/**
	 * The ability id's final name segment (mirrors `Pack::baseName()`,
	 * duplicated here because that helper is `protected` and this class is
	 * not a `Pack`).
	 */
	private static function baseName( string $ability ): string {
		$pos = strrpos( $ability, '/' );

		return false === $pos ? $ability : substr( $ability, $pos + 1 );
	}
}
