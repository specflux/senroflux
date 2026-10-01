<?php
/**
 * Dynamic slot filling (D1 amendment, S5b): the three bounded adaptations
 * applied to a theme pattern before it is filled, and the shape rules the
 * Validator uses to recognise the result.
 *
 * A theme pattern ships sample content the model's fields rarely match slot
 * for slot. Three changes, and only these, make it fit:
 *
 *   - DROP the block holding any slot the fields don't fill (an extra image,
 *     a second button, an emoji paragraph, a trailing call-to-action bar),
 *     together with any wrapper that is left empty;
 *   - CLONE or TRIM the pattern's repeated item group (cards, FAQ pairs) to
 *     the item count;
 *   - INSERT the layout's heading as a heading block at the top when the
 *     pattern has none.
 *
 * {@see plan()} applies them and maps the fields onto the slots that remain,
 * by kind and order. The Validator recognises a pattern as its shipped tree
 * with exactly those three changes and nothing else, using {@see signature()},
 * {@see sameShape()}, {@see droppable()} and {@see runLength()}; a block of
 * any other kind, a reordered or moved block, or a heading anywhere but the
 * top is not a pattern it knows.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class ThemeAdaptation {

	/** The most items a repeated group is cloned to (the faq layout's range ends here). */
	public const MAX_ITEMS = 8;

	/** Heading tags: the slots a layout's `heading` and an item's title can fill. */
	private const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * The structural identity of one block: its name plus the layout-defining
	 * attributes (align, layout type, heading level), with defaults normalised.
	 * Decorative attributes never take part.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	public static function signature( array $block ): string {
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = $block['attrs'] ?? array();
		$parts = array();

		if ( isset( $attrs['align'] ) && is_string( $attrs['align'] ) && '' !== $attrs['align'] ) {
			$parts[] = 'align=' . $attrs['align'];
		}

		// `{"layout":{"type":"default"}}` IS the absent-layout default.
		if ( isset( $attrs['layout']['type'] ) && is_string( $attrs['layout']['type'] ) && 'default' !== $attrs['layout']['type'] ) {
			$parts[] = 'layout=' . $attrs['layout']['type'];
		}

		// A heading with no `level` IS an h2; spell the effective value out so
		// `{"level":2}` and no attribute at all cannot diverge.
		if ( 'core/heading' === $name ) {
			$level   = isset( $attrs['level'] ) && is_numeric( $attrs['level'] ) ? (int) $attrs['level'] : 2;
			$parts[] = 'level=' . $level;
		}

		return array() === $parts ? $name : $name . '[' . implode( ',', $parts ) . ']';
	}

	/**
	 * Whether two blocks have the same structure: the same signature and
	 * children, pairwise, in order.
	 *
	 * @param array<string,mixed> $a One parsed block.
	 * @param array<string,mixed> $b Another.
	 */
	public static function sameShape( array $a, array $b ): bool {
		if ( self::signature( $a ) !== self::signature( $b ) ) {
			return false;
		}

		$kids_a = array_values( $a['innerBlocks'] ?? array() );
		$kids_b = array_values( $b['innerBlocks'] ?? array() );
		if ( count( $kids_a ) !== count( $kids_b ) ) {
			return false;
		}

		foreach ( $kids_a as $i => $kid ) {
			if ( ! self::sameShape( $kid, $kids_b[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How many consecutive siblings, from `$at`, have the same shape as the
	 * one at `$at`: 1 for a block that isn't repeated.
	 *
	 * @param list<array<string,mixed>> $siblings Sibling blocks.
	 */
	public static function runLength( array $siblings, int $at ): int {
		$run   = 1;
		$count = count( $siblings );
		while ( $at + $run < $count && self::sameShape( $siblings[ $at + $run ], $siblings[ $at ] ) ) {
			++$run;
		}

		return $run;
	}

	/**
	 * The text, link and image slots of a block's own markup (not its
	 * children's), in the order {@see ThemePatterns::textSlots()} counts them.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @return list<array<string,mixed>>
	 */
	public static function ownSlots( array $block ): array {
		$slots = array();
		foreach ( $block['innerContent'] ?? array() as $chunk ) {
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$slots = array_merge( $slots, ThemePatterns::textSlots( $chunk ) );
			}
		}

		return $slots;
	}

	/**
	 * Slots in a block's whole subtree.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	public static function slotCount( array $block ): int {
		$count = count( self::ownSlots( $block ) );
		foreach ( $block['innerBlocks'] ?? array() as $child ) {
			$count += self::slotCount( $child );
		}

		return $count;
	}

	/**
	 * Whether a block may be dropped whole: it holds at least one slot, and
	 * every block under it does (a spacer or an empty wrapper is not content
	 * the model's fields stand in for).
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	public static function droppable( array $block ): bool {
		$children = array_values( $block['innerBlocks'] ?? array() );
		if ( array() === $children ) {
			return array() !== self::ownSlots( $block );
		}

		foreach ( $children as $child ) {
			if ( ! self::droppable( $child ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a pattern has a heading outside its repeated item groups. One
	 * that hasn't (Ollie's `numbers-stacked` heads each card, nothing heads the
	 * section) takes the layout's heading at the top.
	 *
	 * @param array<string,mixed> $block The pattern's top-level block.
	 */
	public static function hasHeadingOutsideRuns( array $block ): bool {
		if ( 'core/heading' === ( $block['blockName'] ?? null ) ) {
			return true;
		}

		$children = array_values( $block['innerBlocks'] ?? array() );
		$count    = count( $children );
		$at       = 0;
		while ( $at < $count ) {
			$run = self::runLength( $children, $at );
			if ( 1 === $run && self::hasHeadingOutsideRuns( $children[ $at ] ) ) {
				return true;
			}
			$at += $run;
		}

		return false;
	}

	/**
	 * Adapt a theme pattern to a layout and map the fields onto its slots.
	 *
	 * `$options`: `images` (the fields give an image: a hero's `image`, a text
	 * with image's, every services card's), `strict` (an image the fields give
	 * must have a slot, else no fit), `eyebrow` and `button2` (the optional
	 * fields are given) and `items` (the item count, for services and faq).
	 *
	 * @param string              $layout  hero, text-with-image, services, faq or cta.
	 * @param string              $markup  The pattern's shipped markup.
	 * @param array<string,mixed> $options See above.
	 * @return array{markup:string, paths:list<string>, dropped:int, total:int}|null Null when the
	 *         pattern cannot be fitted to the layout by the three adaptations.
	 */
	public static function plan( string $layout, string $markup, array $options ): ?array {
		/** @var list<array<string,mixed>> $blocks */
		$blocks = array_values( parse_blocks( $markup ) );
		$named  = array_filter( $blocks, static fn ( array $block ): bool => is_string( $block['blockName'] ?? null ) );
		if ( 1 !== count( $named ) ) {
			return null;
		}

		$items = (int) ( $options['items'] ?? 0 );
		$proto = null;
		if ( 'services' === $layout || 'faq' === $layout ) {
			$proto = self::itemShape( $blocks );
			if ( null === $proto || $items < 1 || $items > self::MAX_ITEMS ) {
				return null;
			}
			$blocks = self::resize( $blocks, $proto, $items );
		}

		if ( ! self::hasHeading( $blocks, $proto ) ) {
			$inserted = self::withHeadingFirst( $blocks, 'hero' === $layout );
			if ( null === $inserted ) {
				return null;
			}
			$blocks = $inserted;
		}

		$cursor = 0;
		$nodes  = array();
		$slots  = array();
		foreach ( $blocks as $block ) {
			$nodes[] = self::annotate( $block, $cursor, $slots );
		}

		$assign = self::assign( $layout, $slots, $nodes, $proto, $options );
		if ( null === $assign ) {
			return null;
		}

		$ok     = true;
		$pruned = array();
		foreach ( $nodes as $node ) {
			$kept = self::prune( $node, $assign, true, $ok );
			if ( null !== $kept ) {
				$pruned[] = $kept;
			}
		}
		if ( ! $ok ) {
			return null;
		}

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $pruned */
		$adapted = serialize_blocks( $pruned );
		ksort( $assign );
		$paths = array_values( $assign );
		if ( count( ThemePatterns::textSlots( $adapted ) ) !== count( $paths ) ) {
			return null;
		}

		return array(
			'markup'  => $adapted,
			'paths'   => $paths,
			'dropped' => count( $slots ) - count( $paths ),
			'total'   => count( $slots ),
		);
	}

	/**
	 * One block's subtree with each slot's number in the pattern's document
	 * order, counted the way {@see ThemePatterns::textSlots()} counts them.
	 *
	 * @param array<string,mixed>            $block  One parsed block.
	 * @param int                            $cursor The next slot number.
	 * @param array<int,array<string,mixed>> $slots  Slot number => slot, filled in.
	 * @return array{block:array<string,mixed>, start:int, count:int, own:list<array<string,mixed>>, kids:list<array<string,mixed>>}
	 */
	private static function annotate( array $block, int &$cursor, array &$slots ): array {
		$node  = array(
			'block' => $block,
			'start' => $cursor,
			'count' => 0,
			'own'   => array(),
			'kids'  => array(),
		);
		$given = array_values( $block['innerBlocks'] ?? array() );
		$next  = 0;
		foreach ( $block['innerContent'] ?? array() as $chunk ) {
			if ( is_string( $chunk ) ) {
				if ( '' === trim( $chunk ) ) {
					continue;
				}
				foreach ( ThemePatterns::textSlots( $chunk ) as $slot ) {
					$slot['gi']       = $cursor;
					$slots[ $cursor ] = $slot;
					$node['own'][]    = $slot;
					++$cursor;
				}
			} elseif ( isset( $given[ $next ] ) ) {
				$node['kids'][] = self::annotate( $given[ $next ], $cursor, $slots );
				++$next;
			}
		}
		$node['count'] = $cursor - $node['start'];

		return $node;
	}

	/**
	 * The shape of the pattern's repeated item (a card, a question and its
	 * answer): the smallest block that repeats in a run of two or more and
	 * holds a title (a heading or a summary) followed by a paragraph.
	 *
	 * @param list<array<string,mixed>> $blocks The pattern's parsed blocks.
	 * @return array<string,mixed>|null
	 */
	private static function itemShape( array $blocks ): ?array {
		$runs = array();
		self::findRuns( $blocks, $runs );

		$best       = null;
		$best_slots = PHP_INT_MAX;
		$best_count = 0;
		foreach ( $runs as $proto ) {
			if ( ! self::droppable( $proto ) || ! self::hasTitleAndText( $proto ) ) {
				continue;
			}
			$instances = self::instancePaths( $blocks, $proto );
			$slots     = self::slotCount( $proto );
			if ( count( $instances ) < 2 || $slots > $best_slots || ( $slots === $best_slots && count( $instances ) <= $best_count ) ) {
				continue;
			}
			$best       = $proto;
			$best_slots = $slots;
			$best_count = count( $instances );
		}

		return $best;
	}

	/**
	 * The first block of every run of two or more same-shaped siblings that
	 * holds a slot, anywhere in the tree.
	 *
	 * @param list<array<string,mixed>> $siblings Sibling blocks.
	 * @param list<array<string,mixed>> $runs     Filled in.
	 */
	private static function findRuns( array $siblings, array &$runs ): void {
		$count = count( $siblings );
		$at    = 0;
		while ( $at < $count ) {
			$run = self::runLength( $siblings, $at );
			if ( $run >= 2 && self::slotCount( $siblings[ $at ] ) > 0 ) {
				$runs[] = $siblings[ $at ];
			}
			for ( $k = 0; $k < $run; $k++ ) {
				self::findRuns( array_values( $siblings[ $at + $k ]['innerBlocks'] ?? array() ), $runs );
			}
			$at += $run;
		}
	}

	/**
	 * Whether a block's own slots start with a title and have a paragraph
	 * after it: what an item's fields fill.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private static function hasTitleAndText( array $block ): bool {
		$cursor = 0;
		$slots  = array();
		self::annotate( $block, $cursor, $slots );

		return null !== self::itemRoles( array_values( $slots ) );
	}

	/**
	 * Which slot of an item is its title, which its text and which its image:
	 * the first heading (or summary), the first paragraph after it and the
	 * first image. Positions within `$slots`.
	 *
	 * @param list<array<string,mixed>> $slots One item's slots, in order.
	 * @return array{title:int, text:int, image:int|null}|null
	 */
	private static function itemRoles( array $slots ): ?array {
		$title = null;
		$text  = null;
		$image = null;
		foreach ( $slots as $position => $slot ) {
			if ( 'image' === $slot['kind'] && null === $image ) {
				$image = $position;
			}
			if ( 'text' !== $slot['kind'] ) {
				continue;
			}
			if ( null === $title && ( in_array( $slot['tag'], self::HEADING_TAGS, true ) || 'summary' === $slot['tag'] ) ) {
				$title = $position;
			} elseif ( null !== $title && null === $text && 'p' === $slot['tag'] ) {
				$text = $position;
			}
		}

		return null === $title || null === $text ? null : array(
			'title' => $title,
			'text'  => $text,
			'image' => $image,
		);
	}

	/**
	 * Paths (child positions from the top) of every outermost block with the
	 * item's shape, in document order.
	 *
	 * @param list<array<string,mixed>> $siblings Sibling blocks.
	 * @param array<string,mixed>       $proto    The item's shape.
	 * @param list<int>                 $path     The siblings' parent path.
	 * @return list<string>
	 */
	private static function instancePaths( array $siblings, array $proto, array $path = array() ): array {
		$found = array();
		foreach ( array_values( $siblings ) as $i => $block ) {
			$here = array_merge( $path, array( $i ) );
			if ( self::sameShape( $block, $proto ) ) {
				$found[] = implode( '.', $here );
			} else {
				$found = array_merge( $found, self::instancePaths( array_values( $block['innerBlocks'] ?? array() ), $proto, $here ) );
			}
		}

		return $found;
	}

	/**
	 * Clone or trim the item group to `$count`: trim from the end, dropping
	 * any wrapper it empties; clone by repeating the last item after itself.
	 *
	 * @param list<array<string,mixed>> $blocks The pattern's parsed blocks.
	 * @param array<string,mixed>       $proto  The item's shape.
	 * @return list<array<string,mixed>>
	 */
	private static function resize( array $blocks, array $proto, int $count ): array {
		$paths  = self::instancePaths( $blocks, $proto );
		$have   = count( $paths );
		$remove = array();
		$repeat = null;
		if ( $have > $count ) {
			$remove = array_flip( array_slice( $paths, $count ) );
		} elseif ( $have < $count ) {
			$repeat = array(
				'path'  => $paths[ $have - 1 ],
				'extra' => $count - $have,
			);
		}

		$out = array();
		foreach ( array_values( $blocks ) as $i => $block ) {
			$out = array_merge( $out, self::edit( $block, array( $i ), $remove, $repeat ) );
		}

		return $out;
	}

	/**
	 * One block after the resize: itself (with its children edited), nothing
	 * when it is trimmed or has been emptied, or itself followed by its clones.
	 *
	 * @param array<string,mixed>               $block  One parsed block.
	 * @param list<int>                         $here   Its path.
	 * @param array<string,int>                 $remove Paths to remove (keys).
	 * @param array{path:string,extra:int}|null $repeat  Where to repeat a block.
	 * @return list<array<string,mixed>>
	 */
	private static function edit( array $block, array $here, array $remove, ?array $repeat ): array {
		$key = implode( '.', $here );
		if ( isset( $remove[ $key ] ) ) {
			return array();
		}

		if ( array() !== ( $block['innerBlocks'] ?? array() ) ) {
			$block = self::rewriteChildren(
				$block,
				static fn ( array $child, int $at ): array => self::edit( $child, array_merge( $here, array( $at ) ), $remove, $repeat )
			);
			if ( array() === $block['innerBlocks'] && count( $here ) > 1 ) {
				return array(); // An emptied wrapper goes with its items.
			}
		}

		$out = array( $block );
		if ( null !== $repeat && $repeat['path'] === $key ) {
			for ( $n = 0; $n < $repeat['extra']; $n++ ) {
				$out[] = $block;
			}
		}

		return $out;
	}

	/**
	 * Rebuild a block's children through `$replace`, which returns the blocks (none,
	 * one or several) that replace each child, keeping `innerContent`'s null
	 * markers in step.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @param callable(array<string,mixed>,int):list<array<string,mixed>> $replace Replacement for child `$at`.
	 * @return array<string,mixed>
	 */
	private static function rewriteChildren( array $block, callable $replace ): array {
		$given   = array_values( $block['innerBlocks'] ?? array() );
		$content = array();
		$kept    = array();
		$next    = 0;
		foreach ( $block['innerContent'] ?? array() as $chunk ) {
			if ( null !== $chunk ) {
				$content[] = $chunk;
				continue;
			}
			$at = $next++;
			if ( ! isset( $given[ $at ] ) ) {
				continue;
			}
			foreach ( $replace( $given[ $at ], $at ) as $replacement ) {
				$kept[]    = $replacement;
				$content[] = null;
			}
		}

		$block['innerBlocks']  = $kept;
		$block['innerContent'] = $content;

		return $block;
	}

	/**
	 * Whether the pattern has a heading the layout's `heading` can fill: one
	 * outside its item group (inside it, a heading is a card's title).
	 *
	 * @param list<array<string,mixed>> $blocks The pattern's parsed blocks.
	 * @param array<string,mixed>|null  $proto  The item's shape, or null.
	 */
	private static function hasHeading( array $blocks, ?array $proto ): bool {
		foreach ( $blocks as $block ) {
			if ( null !== $proto && self::sameShape( $block, $proto ) ) {
				continue;
			}
			if ( 'core/heading' === ( $block['blockName'] ?? null ) ) {
				return true;
			}
			if ( self::hasHeading( array_values( $block['innerBlocks'] ?? array() ), $proto ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The blocks with an empty heading as the first child of the pattern's
	 * top-level block, or null when that block has no children to put it among.
	 *
	 * @param list<array<string,mixed>> $blocks The pattern's parsed blocks.
	 * @return list<array<string,mixed>>|null
	 */
	private static function withHeadingFirst( array $blocks, bool $page_title ): ?array {
		foreach ( $blocks as $offset => $block ) {
			if ( ! is_string( $block['blockName'] ?? null ) ) {
				continue;
			}

			$content = array();
			$done    = false;
			foreach ( $block['innerContent'] ?? array() as $chunk ) {
				if ( null === $chunk && ! $done ) {
					$content[] = null;
					$done      = true;
				}
				$content[] = $chunk;
			}
			if ( ! $done ) {
				return null;
			}

			array_unshift( $block['innerBlocks'], self::emptyHeading( $page_title ) );
			$block['innerContent'] = $content;
			$blocks[ $offset ]     = $block;

			return $blocks;
		}

		return null;
	}

	/**
	 * Which field fills each slot: slot number => field path. A slot with no
	 * path is dropped. Null when a field the layout needs has no slot.
	 *
	 * @param array<int,array<string,mixed>>  $slots   Slot number => slot.
	 * @param list<array<string,mixed>>       $nodes   Annotated top-level blocks.
	 * @param array<string,mixed>|null        $proto   The item's shape, or null.
	 * @param array<string,mixed>             $options {@see plan()}.
	 * @return array<int,string>|null
	 */
	private static function assign( string $layout, array $slots, array $nodes, ?array $proto, array $options ): ?array {
		$strict = ! empty( $options['strict'] );
		$images = ! empty( $options['images'] );
		$assign = array();

		$is_heading = static fn ( array $slot ): bool => 'text' === $slot['kind'] && in_array( $slot['tag'], self::HEADING_TAGS, true );

		$ranges = array();
		if ( null !== $proto ) {
			foreach ( $nodes as $node ) {
				self::instanceRanges( $node, $proto, $ranges );
			}
		}
		$inside = static function ( int $gi ) use ( $ranges ): bool {
			foreach ( $ranges as $range ) {
				if ( $gi >= $range[0] && $gi < $range[0] + $range[1] ) {
					return true;
				}
			}

			return false;
		};

		$heading = null;
		foreach ( $slots as $gi => $slot ) {
			if ( $is_heading( $slot ) && ! $inside( $gi ) ) {
				$heading = $gi;
				break;
			}
		}
		if ( null === $heading ) {
			return null;
		}
		// The page has one H1, the hero's.
		if ( 'hero' !== $layout && 'h1' === $slots[ $heading ]['tag'] ) {
			return null;
		}
		$assign[ $heading ] = 'heading';

		if ( null !== $proto ) {
			$prefix = 'faq' === $layout ? array( 'question', 'answer' ) : array( 'title', 'text' );
			foreach ( $ranges as $i => $range ) {
				$item  = array_values( array_slice( $slots, $range[0], $range[1] ) );
				$roles = self::itemRoles( $item );
				if ( null === $roles ) {
					return null;
				}
				// A number or a price in the title ("32", "2000+") is a statistic, not
				// a service or a question the fields could stand in for.
				if ( $strict && 1 === preg_match( '/^[\d\s.,+%$\/-]+$/', (string) ( $item[ $roles['title'] ]['shipped_text'] ?? '' ) ) ) {
					return null;
				}
				$assign[ $range[0] + $roles['title'] ] = 'items.' . $i . '.' . $prefix[0];
				$assign[ $range[0] + $roles['text'] ]  = 'items.' . $i . '.' . $prefix[1];
				if ( 'services' === $layout && $images ) {
					if ( null !== $roles['image'] ) {
						$assign[ $range[0] + $roles['image'] ] = 'items.' . $i . '.image';
					} elseif ( $strict ) {
						return null;
					}
				}
			}
			if ( count( $ranges ) !== (int) ( $options['items'] ?? 0 ) ) {
				return null;
			}

			return $assign;
		}

		if ( ! empty( $options['eyebrow'] ) ) {
			$before = null;
			foreach ( $slots as $gi => $slot ) {
				if ( $gi < $heading && 'text' === $slot['kind'] && 'p' === $slot['tag'] ) {
					$before = $gi;
				}
			}
			if ( null !== $before ) {
				$assign[ $before ] = 'eyebrow';
			}
		}

		$text = null;
		foreach ( $slots as $gi => $slot ) {
			if ( $gi > $heading && 'text' === $slot['kind'] && 'p' === $slot['tag'] ) {
				$text = $gi;
				break;
			}
		}
		if ( null === $text ) {
			return null;
		}
		$assign[ $text ] = 'text';

		if ( 'hero' === $layout || 'cta' === $layout ) {
			$pairs = array();
			foreach ( $slots as $gi => $slot ) {
				if ( 'text' === $slot['kind'] && 'a' === $slot['tag'] && isset( $slots[ $gi + 1 ] ) && 'url' === $slots[ $gi + 1 ]['kind'] ) {
					$pairs[] = $gi;
				}
			}
			if ( array() === $pairs ) {
				return null;
			}
			$assign[ $pairs[0] ]     = 'button.label';
			$assign[ $pairs[0] + 1 ] = 'button.url';
			if ( ! empty( $options['button2'] ) && isset( $pairs[1] ) ) {
				$assign[ $pairs[1] ]     = 'button2.label';
				$assign[ $pairs[1] + 1 ] = 'button2.url';
			}
		}

		if ( 'hero' === $layout || 'text-with-image' === $layout ) {
			$image = null;
			foreach ( $slots as $gi => $slot ) {
				if ( 'image' === $slot['kind'] ) {
					$image = $gi;
					break;
				}
			}
			if ( null !== $image && $images ) {
				$assign[ $image ] = 'image';
			} elseif ( $images && ( $strict || 'text-with-image' === $layout ) ) {
				return null;
			}
		}

		return $assign;
	}

	/**
	 * `[first slot number, slot count]` of each outermost block with the item's shape.
	 *
	 * @param array<string,mixed> $node   An annotated node.
	 * @param array<string,mixed> $proto  The item's shape.
	 * @param list<array{0:int,1:int}> $ranges Filled in.
	 */
	private static function instanceRanges( array $node, array $proto, array &$ranges ): void {
		if ( self::sameShape( $node['block'], $proto ) ) {
			$ranges[] = array( (int) $node['start'], (int) $node['count'] );

			return;
		}
		foreach ( $node['kids'] as $kid ) {
			self::instanceRanges( $kid, $proto, $ranges );
		}
	}

	/**
	 * The block with every subtree whose slots all go unfilled dropped, or null
	 * when this block itself is dropped. `$ok` goes false when a block that stays
	 * keeps a slot of its own that nothing fills (a cover's photo, say): that
	 * pattern cannot be adapted.
	 *
	 * @param array<string,mixed> $node     An annotated node.
	 * @param array<int,string>   $assigned Slot number => field path.
	 * @return array<string,mixed>|null
	 */
	private static function prune( array $node, array $assigned, bool $top, bool &$ok ): ?array {
		$any = false;
		for ( $gi = (int) $node['start']; $gi < (int) $node['start'] + (int) $node['count']; $gi++ ) {
			if ( isset( $assigned[ $gi ] ) ) {
				$any = true;
				break;
			}
		}

		if ( ! $top && (int) $node['count'] > 0 && ! $any ) {
			if ( self::droppable( $node['block'] ) ) {
				return null;
			}
			$ok = false;

			return $node['block'];
		}

		foreach ( $node['own'] as $slot ) {
			if ( ! isset( $assigned[ (int) $slot['gi'] ] ) ) {
				$ok = false;
			}
		}

		return self::rewriteChildren(
			$node['block'],
			static function ( array $child, int $at ) use ( $node, $assigned, &$ok ): array {
				$kept = self::prune( $node['kids'][ $at ], $assigned, false, $ok );

				return null === $kept ? array() : array( $kept );
			}
		);
	}

	/**
	 * A heading block with no text yet, the layout's heading to be filled.
	 *
	 * @return array<string,mixed>
	 */
	private static function emptyHeading( bool $page_title ): array {
		$markup = $page_title
			? '<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="wp-block-heading has-text-align-center"></h1><!-- /wp:heading -->'
			: '<!-- wp:heading --><h2 class="wp-block-heading"></h2><!-- /wp:heading -->';

		$blocks = parse_blocks( $markup );

		return $blocks[0];
	}
}
