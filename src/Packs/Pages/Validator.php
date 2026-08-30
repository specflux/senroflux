<?php
/**
 * Pack-owned write validation for the pages pack (S10).
 *
 * TARGET REPO PATH: src/Packs/Pages/Validator.php
 *
 * Whole-write refusal — validation failure returns a WP_Error and NOTHING is
 * persisted. The checks run in the S10 order:
 *   1. `serialize_blocks(parse_blocks($content)) === $content` after
 *      normalisation, else `invalid_markup`.
 *   2. every block `core/*` and inside the vocabulary's block set; `core/freeform`
 *      (null blockName) carrying anything but whitespace, unknown or non-core →
 *      `unknown_block` (index + name).
 *   2b. every tag and attribute inside every block's `innerHTML` is on the
 *      pack's explicit allow-list → `disallowed_markup` (index + tag/attr).
 *      This is the XSS gate: runs execute as an administrator, who holds
 *      `unfiltered_html`, so WordPress's own kses is NOT installed on the way
 *      to `wp_insert_post()`. The pack therefore does its own, stricter pass
 *      and REFUSES rather than silently stripping — a silent strip would let a
 *      model believe it had written what it asked for.
 *   3. unresolved `{{placeholder}}` text → `unresolved_placeholder`.
 *   4. pattern identity is STRUCTURAL (blockName tree + layout-defining attrs +
 *      slot counts in min..max) → `unknown_pattern` (index + nearest name),
 *      `slot_count` (slot + allowed range), `page_shape` (rule broken).
 *   5. MUTATIONS, not refusals — strip decorative colour attributes and
 *      normalise `metadata.name`. Exposed only through {@see clean()}.
 *
 * Return shape (documented): {@see validate()} is the refusal gateway returning
 * `true|WP_Error`; {@see clean()} is the callers' single entry point returning
 * `{ok:bool, content:string, wp_error:?WP_Error}` — when `ok` is false the
 * `content` is the untouched input and `wp_error` carries the refusal; when
 * `ok` is true the `content` is the cleaned (mutated) markup to persist.
 *
 * The block parser / serializer are the WordPress core functions
 * (`parse_blocks` / `serialize_blocks`), guarded with `function_exists` so a
 * bare run fails closed rather than calling a missing function.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates and cleans pages-pack block markup.
 */
final class Validator {

	/**
	 * The ONLY HTML tags the seven patterns can legitimately contain, each
	 * mapped to the attributes it may carry. Anything else — `img`, `script`,
	 * `iframe`, `style`, `form`, `svg`, an `on*` handler — is a refusal, not a
	 * strip. `img` is absent on purpose: S11 forbids `core/image` anywhere, and
	 * a raw `<img>` is the same capability by another route.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED_TAGS = array(
		'div'        => array( 'class', 'style', 'id' ),
		'p'          => array( 'class', 'style', 'id' ),
		'h1'         => array( 'class', 'style', 'id' ),
		'h2'         => array( 'class', 'style', 'id' ),
		'h3'         => array( 'class', 'style', 'id' ),
		'h4'         => array( 'class', 'style', 'id' ),
		'h5'         => array( 'class', 'style', 'id' ),
		'h6'         => array( 'class', 'style', 'id' ),
		'ul'         => array( 'class', 'style', 'id' ),
		'ol'         => array( 'class', 'style', 'id', 'start', 'reversed', 'type' ),
		'li'         => array( 'class', 'style', 'id', 'value' ),
		'blockquote' => array( 'class', 'style', 'id', 'cite' ),
		'cite'       => array( 'class', 'style', 'id' ),
		'details'    => array( 'class', 'style', 'id', 'open' ),
		'summary'    => array( 'class', 'style', 'id' ),
		'figure'     => array( 'class', 'style', 'id' ),
		'figcaption' => array( 'class', 'style', 'id' ),
		'a'          => array( 'class', 'style', 'id', 'href', 'rel', 'target', 'title' ),
		'q'          => array( 'class', 'style', 'id', 'cite' ),
		'abbr'       => array( 'class', 'style', 'id', 'title' ),
		'span'       => array( 'class', 'style', 'id' ),
		'strong'     => array( 'class', 'style', 'id' ),
		'em'         => array( 'class', 'style', 'id' ),
		'b'          => array( 'class', 'style', 'id' ),
		'i'          => array( 'class', 'style', 'id' ),
		's'          => array( 'class', 'style', 'id' ),
		'u'          => array( 'class', 'style', 'id' ),
		'del'        => array( 'class', 'style', 'id' ),
		'ins'        => array( 'class', 'style', 'id' ),
		'sub'        => array( 'class', 'style', 'id' ),
		'sup'        => array( 'class', 'style', 'id' ),
		'small'      => array( 'class', 'style', 'id' ),
		'mark'       => array( 'class', 'style', 'id' ),
		'code'       => array( 'class', 'style', 'id' ),
		'kbd'        => array( 'class', 'style', 'id' ),
		'br'         => array( 'class', 'style', 'id' ),
	);

	/**
	 * Attributes whose value is a URL and therefore gets a scheme check.
	 *
	 * @var list<string>
	 */
	private const URL_ATTRIBUTES = array( 'href', 'cite' );

	/**
	 * The only URL schemes a page may link to. Anything else — `javascript:`,
	 * `data:`, `vbscript:` — is a refusal. A scheme-less value (relative path,
	 * `#anchor`, `?query`, protocol-relative `//host`) is allowed.
	 *
	 * @var list<string>
	 */
	private const ALLOWED_SCHEMES = array( 'http', 'https', 'mailto', 'tel' );

	/**
	 * Substrings that make a `style` attribute value a refusal. `style` itself
	 * stays allowed because WordPress emits it for the standard spacing presets
	 * (`padding-top:var(--wp--preset--spacing--50)`), which S11 permits.
	 *
	 * @var list<string>
	 */
	private const STYLE_DENY = array( 'expression(', 'url(', 'javascript', '@import', '\\', '<', '&#' );

	/**
	 * @param Vocabulary $vocabulary The pattern vocabulary (identity + constraints).
	 */
	public function __construct( private readonly Vocabulary $vocabulary ) {
	}

	/**
	 * Step-1..4 refusal gateway.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context (e.g. post_type); used for messaging.
	 * @return true|WP_Error true when the markup passes, else a refusal WP_Error.
	 */
	public function validate( string $content, array $ctx = array() ): true|WP_Error {
		$res = $this->run( $content, $ctx );
		if ( ! $res['ok'] ) {
			/** @var WP_Error $error */
			$error = $res['wp_error'];

			return $error;
		}

		return true;
	}

	/**
	 * The single entry point for create/update: validates, then applies the
	 * step-5 mutations. Callers persist ONLY `$result['content']` when
	 * `$result['ok']` is true.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context (e.g. post_type).
	 * @return array{ok:bool, content:string, wp_error:WP_Error|null}
	 */
	public function clean( string $content, array $ctx = array() ): array {
		$res = $this->run( $content, $ctx );
		if ( ! $res['ok'] ) {
			return array(
				'ok'       => false,
				'content'  => $content,
				'wp_error' => $res['wp_error'],
			);
		}

		return array(
			'ok'       => true,
			'content'  => $this->mutate( $res['blocks'], $res['identities'] ),
			'wp_error' => null,
		);
	}

	/**
	 * The full pipeline. Returns the parsed blocks + the per-top-level pattern
	 * identity so {@see clean()} can re-serialise the mutated tree.
	 *
	 * @param string              $content Serialized block markup.
	 * @param array<string,mixed> $ctx     Context.
	 * @return array{ok:bool, content:string, blocks:list<array<string,mixed>>, identities:array<int,string>, wp_error:WP_Error|null}
	 */
	private function run( string $content, array $ctx ): array {
		// Step 1 — round-trip after normalisation.
		$blocks = $this->parse( $content );
		if ( null === $blocks ) {
			return $this->refuse(
				new WP_Error( 'invalid_markup', $this->message( 'invalid_markup', $ctx ), array( 'status' => 400 ) )
			);
		}

		$reserialized = $this->serialize( $blocks );
		if ( $this->normalize( $content ) !== $this->normalize( $reserialized ) ) {
			return $this->refuse(
				new WP_Error( 'invalid_markup', $this->message( 'invalid_markup', $ctx ), array( 'status' => 400 ) )
			);
		}

		// Step 2 — block-name allow-list.
		$block_error = $this->checkBlockNames( $blocks );
		if ( null !== $block_error ) {
			return $this->refuse( $block_error );
		}

		// Step 2b — tag/attribute allow-list (the XSS gate).
		$markup_error = $this->checkMarkupSafety( $blocks );
		if ( null !== $markup_error ) {
			return $this->refuse( $markup_error );
		}

		// Step 3 — unresolved `{{placeholder}}`.
		$placeholder = $this->findPlaceholder( $content );
		if ( null !== $placeholder ) {
			return $this->refuse(
				new WP_Error(
					'unresolved_placeholder',
					$this->message( 'unresolved_placeholder', $ctx, array( 'placeholder' => $placeholder ) ),
					array(
						'status'      => 400,
						'placeholder' => $placeholder,
					)
				)
			);
		}

		// Step 4 — structural pattern identity, slot counts, page shape.
		$identities = array();
		$position   = 0;
		foreach ( $blocks as $offset => $block ) {
			if ( ! $this->isPatternBlock( $block ) ) {
				continue;
			}

			$slug = $this->matchPatternSchema( $block );
			if ( null === $slug ) {
				$nearest = $this->nearestPattern( $block );

				return $this->refuse(
					new WP_Error(
						'unknown_pattern',
						$this->message(
							'unknown_pattern',
							$ctx,
							array(
								'index' => $position,
								'name'  => $nearest,
							)
						),
						array(
							'status' => 400,
							'index'  => $position,
							'name'   => $nearest,
						)
					)
				);
			}

			$slot_error = $this->checkSlots( $slug, $block );
			if ( null !== $slot_error ) {
				return $this->refuse( $slot_error );
			}

			$identities[ $offset ] = $slug;
			++$position;
		}

		$shape_error = $this->checkPageShape( $identities );
		if ( null !== $shape_error ) {
			return $this->refuse( $shape_error );
		}

		return array(
			'ok'         => true,
			'content'    => $content,
			'blocks'     => $blocks,
			'identities' => $identities,
			'wp_error'   => null,
		);
	}

	/**
	 * @return array{ok:bool, content:string, blocks:list<array<string,mixed>>, identities:array<int,string>, wp_error:WP_Error}
	 */
	private function refuse( WP_Error $error ): array {
		return array(
			'ok'         => false,
			'content'    => '',
			'blocks'     => array(),
			'identities' => array(),
			'wp_error'   => $error,
		);
	}

	/**
	 * A top-level block that carries a pattern. WordPress's real parser emits a
	 * `blockName === null` freeform block for the newlines BETWEEN top-level
	 * blocks; those are structurally invisible (step 2 already refused any
	 * freeform carrying more than whitespace).
	 *
	 * @param array<string,mixed> $block One parsed top-level block.
	 */
	private function isPatternBlock( array $block ): bool {
		return is_string( $block['blockName'] ?? null );
	}

	/**
	 * Collapse all whitespace runs to a single space and trim — the documented
	 * normalisation the round-trip compare uses (the prototype's `$norm`).
	 */
	private function normalize( string $text ): string {
		return (string) preg_replace( '/\s+/', ' ', trim( $text ) );
	}

	/**
	 * @return list<array<string,mixed>>|null Null when the functions are absent
	 *                                        (fail closed → invalid_markup).
	 */
	private function parse( string $content ): ?array {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		/** @var list<array<string,mixed>> $blocks */
		return $blocks;
	}

	/**
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function serialize( array $blocks ): string {
		if ( ! function_exists( 'serialize_blocks' ) ) {
			// Absent here after a successful parse means a half-loaded WP; refuse.
			return '';
		}

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
		return (string) serialize_blocks( $blocks );
	}

	/**
	 * Step 2 — every blockName must be non-null, `core/*`, and within the
	 * vocabulary's block set. `core/freeform`, unknown or non-core → the first
	 * offending block, with its top-level index + name. A top-level freeform
	 * block holding only whitespace is the parser's representation of the
	 * newlines between patterns and is not an offence.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkBlockNames( array $blocks ): ?WP_Error {
		$allowed = array_flip( $this->vocabulary->blockNames() );
		$index   = 0;

		$walk = static function ( array $node, callable $recurse, bool $top ) use ( &$allowed, &$index ): ?array {
			$name = $node['blockName'] ?? null;
			if ( ! is_string( $name ) ) {
				// Freeform: parse_blocks leaves blockName null for un-attributable
				// HTML. Whitespace between top-level blocks is expected; anything
				// else is a refusal.
				if ( $top && '' === trim( (string) ( $node['innerHTML'] ?? '' ) ) ) {
					return null;
				}

				return array(
					'index' => $index,
					'name'  => 'core/freeform',
				);
			}
			if ( ! str_starts_with( $name, 'core/' ) || ! isset( $allowed[ $name ] ) ) {
				return array(
					'index' => $index,
					'name'  => $name,
				);
			}

			$children = $node['innerBlocks'] ?? array();
			/** @var list<array<string,mixed>> $children */
			foreach ( $children as $child ) {
				$found = $recurse( $child, $recurse, false );
				if ( null !== $found ) {
					return $found;
				}
			}

			return null;
		};

		$found = null;
		foreach ( $blocks as $block ) {
			$found = $walk( $block, $walk, true );
			if ( null !== $found ) {
				break;
			}
			if ( $this->isPatternBlock( $block ) ) {
				++$index;
			}
		}

		if ( null === $found ) {
			return null;
		}

		return new WP_Error(
			'unknown_block',
			$this->message(
				'unknown_block',
				array(),
				array(
					'index' => $found['index'],
					'name'  => $found['name'],
				)
			),
			array(
				'status' => 400,
				'index'  => $found['index'],
				'name'   => $found['name'],
			)
		);
	}

	/**
	 * Step 2b — the XSS gate. Walks every block's `innerHTML` (which, in a real
	 * parse, holds exactly that block's own literal HTML: a child block's markup
	 * lives in the CHILD's `innerHTML`) and refuses the whole write on the first
	 * tag or attribute outside {@see ALLOWED_TAGS}.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed top-level blocks.
	 */
	private function checkMarkupSafety( array $blocks ): ?WP_Error {
		$index = 0;
		foreach ( $blocks as $block ) {
			$found = $this->findDisallowedMarkup( $block );
			if ( null !== $found ) {
				$data = array(
					'index'  => $index,
					'reason' => $found['reason'],
					'tag'    => $found['tag'],
					'attr'   => $found['attr'],
				);

				return new WP_Error(
					'disallowed_markup',
					$this->message( 'disallowed_markup', array(), $data ),
					array_merge( array( 'status' => 400 ), $data )
				);
			}
			if ( $this->isPatternBlock( $block ) ) {
				++$index;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $block One parsed block.
	 * @return array{reason:string, tag:string, attr:string}|null
	 */
	private function findDisallowedMarkup( array $block ): ?array {
		$found = $this->scanHtml( (string) ( $block['innerHTML'] ?? '' ) );
		if ( null !== $found ) {
			return $found;
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			$found = $this->findDisallowedMarkup( $child );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Find the first disallowed tag or attribute in a fragment of HTML.
	 *
	 * @return array{reason:string, tag:string, attr:string}|null
	 */
	private function scanHtml( string $html ): ?array {
		if ( ! str_contains( $html, '<' ) ) {
			return null;
		}

		$matches = array();
		if ( ! preg_match_all( '#<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>?#', $html, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $matches as $match ) {
			$tag = strtolower( $match[2] );
			if ( ! isset( self::ALLOWED_TAGS[ $tag ] ) ) {
				return array(
					'reason' => 'tag',
					'tag'    => $tag,
					'attr'   => '',
				);
			}

			if ( '/' === $match[1] ) {
				continue;
			}

			$found = $this->scanAttributes( $tag, $match[3] );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @return array{reason:string, tag:string, attr:string}|null
	 */
	private function scanAttributes( string $tag, string $attribute_text ): ?array {
		$allowed = array_flip( self::ALLOWED_TAGS[ $tag ] );
		$attrs   = array();
		preg_match_all(
			'#([a-zA-Z_:][a-zA-Z0-9_:.\-]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#',
			$attribute_text,
			$attrs,
			PREG_SET_ORDER
		);

		foreach ( $attrs as $attr ) {
			$name = strtolower( $attr[1] );
			if ( ! isset( $allowed[ $name ] ) ) {
				return array(
					'reason' => 'attr',
					'tag'    => $tag,
					'attr'   => $name,
				);
			}

			$value = trim( $attr[2] ?? '', "\"'" );

			if ( 'style' === $name && $this->styleIsUnsafe( $value ) ) {
				return array(
					'reason' => 'style',
					'tag'    => $tag,
					'attr'   => $name,
				);
			}

			if ( in_array( $name, self::URL_ATTRIBUTES, true ) && ! $this->urlIsSafe( $value ) ) {
				return array(
					'reason' => 'url',
					'tag'    => $tag,
					'attr'   => $name,
				);
			}
		}

		return null;
	}

	/**
	 * A `style` value is refused when it carries anything that can fetch or
	 * execute (`url()`, `expression()`, `@import`, an escape sequence).
	 */
	private function styleIsUnsafe( string $value ): bool {
		$flat = strtolower( $this->flatten( $value ) );
		foreach ( self::STYLE_DENY as $needle ) {
			if ( str_contains( $flat, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A URL is safe when it carries no scheme (relative, `#anchor`, `//host`)
	 * or a scheme on {@see ALLOWED_SCHEMES}. The value is entity-decoded and
	 * stripped of the control/whitespace characters browsers ignore first, so
	 * `java&#9;script:` and `&#106;avascript:` are caught.
	 */
	private function urlIsSafe( string $value ): bool {
		$flat = strtolower( $this->flatten( $value ) );
		if ( '' === $flat ) {
			return true;
		}

		$scheme = array();
		if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#', $flat, $scheme ) ) {
			// No scheme at all — but a bare colon before any slash is still a
			// scheme attempt the regex could not name; refuse it.
			$colon = strpos( $flat, ':' );
			$slash = strpos( $flat, '/' );

			return false === $colon || ( false !== $slash && $slash < $colon );
		}

		return in_array( $scheme[1], self::ALLOWED_SCHEMES, true );
	}

	/**
	 * Decode HTML entities and drop every character a browser ignores inside an
	 * attribute value (NUL through space, plus DEL).
	 */
	private function flatten( string $value ): string {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $decoded );
	}

	/**
	 * Step 3 — unresolved `{{placeholder}}`. Matches ANY `{{...}}` pair whose
	 * body carries no further braces, so `{{Headline}}` and `{{price-1}}` are
	 * caught as well as `{{snake_case}}`. The negative lookarounds keep the
	 * inner pair of a triple-brace `{{{x}}}` from matching.
	 *
	 * @return string|null The captured placeholder, or null when clean.
	 */
	private function findPlaceholder( string $content ): ?string {
		if ( preg_match_all( '/(?<!\{)\{\{([^{}]{0,200})\}\}(?!\})/', $content, $matches ) ) {
			return trim( $matches[1][0] );
		}

		return null;
	}

	/**
	 * Structural signature for one parsed block: blockName + layout-defining
	 * attrs (align, layout.type, heading level) + recursive child signatures.
	 * Decorative attrs (backgroundColor/textColor/style.color) are excluded so
	 * identity never depends on them.
	 *
	 * Explicit DEFAULT values are normalised away first, so a model that spells
	 * out `{"level":2}` on an h2 or `{"layout":{"type":"default"}}` on a group
	 * produces the same signature as the pattern that omits them.
	 *
	 * Used for the `unknown_pattern` "nearest pattern" hint; identity itself is
	 * decided by {@see matchesShape()}, which is count-aware.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function signatureOf( array $block ): string {
		$label = $this->localSignature( $block );

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		if ( array() !== $children ) {
			$child_sigs = array();
			foreach ( $children as $child ) {
				$child_sigs[] = $this->signatureOf( $child );
			}
			$child_sigs = array_values( array_unique( $child_sigs ) );
			$label     .= '{' . implode( ',', $child_sigs ) . '}';
		}

		return $label;
	}

	/**
	 * The non-recursive part of a block's identity: its name plus the
	 * layout-defining attributes, with known defaults normalised.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function localSignature( array $block ): string {
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
	 * The expected structural tree for a pattern's TOP-level block, cached.
	 *
	 * @var array<string, array<string,mixed>|null>
	 */
	private array $pattern_trees = array();

	/**
	 * Match a top-level block against the vocabulary. Returns the slug when the
	 * block's structure matches a pattern's, else null.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function matchPatternSchema( array $block ): ?string {
		foreach ( $this->vocabulary->all() as $pattern ) {
			$expected = $this->expectedTree( $pattern );
			if ( null === $expected ) {
				continue;
			}

			/** @var list<string> $repeatable */
			$repeatable = $pattern['repeatable'] ?? array();
			if ( $this->matchesShape( $block, $expected, $repeatable ) ) {
				return (string) $pattern['slug'];
			}
		}

		return null;
	}

	/**
	 * Recursive structural match. Every child of the shipped pattern must be
	 * present, in order, exactly as many times as the pattern ships it — EXCEPT
	 * a child whose blockName the pattern declares repeatable, which may appear
	 * any number of times (0..n here; {@see checkSlots()} then applies the
	 * declared min..max). That is what stops ten extra headings from riding
	 * along inside an otherwise-valid text-section.
	 *
	 * @param array<string,mixed> $candidate  The parsed block being validated.
	 * @param array<string,mixed> $expected   The shipped pattern's block.
	 * @param list<string>        $repeatable Block names that may repeat.
	 */
	private function matchesShape( array $candidate, array $expected, array $repeatable ): bool {
		if ( $this->localSignature( $candidate ) !== $this->localSignature( $expected ) ) {
			return false;
		}

		$actual = array_values( $candidate['innerBlocks'] ?? array() );
		$wanted = array_values( $expected['innerBlocks'] ?? array() );
		/** @var list<array<string,mixed>> $actual */
		/** @var list<array<string,mixed>> $wanted */

		$i            = 0;
		$j            = 0;
		$actual_count = count( $actual );
		$wanted_count = count( $wanted );

		while ( $j < $wanted_count ) {
			$model = $wanted[ $j ];

			// How many identically shaped children the pattern ships in a row.
			$run = 1;
			while ( $j + $run < $wanted_count && $this->matchesShape( $wanted[ $j + $run ], $model, $repeatable ) ) {
				++$run;
			}

			if ( in_array( (string) ( $model['blockName'] ?? '' ), $repeatable, true ) ) {
				while ( $i < $actual_count && $this->matchesShape( $actual[ $i ], $model, $repeatable ) ) {
					++$i;
				}
			} else {
				for ( $k = 0; $k < $run; $k++ ) {
					if ( $i >= $actual_count || ! $this->matchesShape( $actual[ $i ], $model, $repeatable ) ) {
						return false;
					}
					++$i;
				}
			}

			$j += $run;
		}

		return $i === $actual_count;
	}

	/**
	 * @param array<string,mixed> $pattern One pattern definition.
	 * @return array<string,mixed>|null
	 */
	private function expectedTree( array $pattern ): ?array {
		$slug = (string) $pattern['slug'];
		if ( ! array_key_exists( $slug, $this->pattern_trees ) ) {
			$blocks = $this->parse( (string) $pattern['markup'] ) ?? array();
			$tree   = null;
			foreach ( $blocks as $block ) {
				if ( $this->isPatternBlock( $block ) ) {
					$tree = $block;
					break;
				}
			}
			$this->pattern_trees[ $slug ] = $tree;
		}

		return $this->pattern_trees[ $slug ];
	}

	/**
	 * The closest pattern for an `unknown_pattern` message: the pattern whose
	 * expected signature shares the longest leading run with the candidate.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function nearestPattern( array $block ): string {
		$candidate = $this->signatureOf( $block );
		$best      = '';
		$best_len  = -1;

		foreach ( $this->vocabulary->all() as $pattern ) {
			$tree     = $this->expectedTree( $pattern );
			$expected = null === $tree ? '' : $this->signatureOf( $tree );
			$len      = 0;
			$max      = min( strlen( $candidate ), strlen( $expected ) );
			while ( $len < $max && $candidate[ $len ] === $expected[ $len ] ) {
				++$len;
			}
			if ( $len > $best_len ) {
				$best_len = $len;
				$best     = (string) $pattern['name'];
			}
		}

		return '' !== $best ? $best : 'unknown';
	}

	/**
	 * Compare the counted slots of a top-level block against its pattern's
	 * min..max constraints. Returns a WP_Error (slot_count) or null.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	private function checkSlots( string $slug, array $block ): ?WP_Error {
		$pattern = $this->patternBySlug( $slug );
		if ( null === $pattern ) {
			return null;
		}

		/** @var array<string, array{min:int,max:int}> $slots */
		$slots  = $pattern['constraints']['slots'] ?? array();
		$counts = $this->countSlots( $slug, $block );

		foreach ( $slots as $slot => $range ) {
			// A slot with no observed group at all still has to satisfy its
			// minimum, so an absent slot reads as a single count of zero.
			$observed = $counts[ $slot ] ?? array( 0 );
			foreach ( $observed as $actual ) {
				if ( $actual < $range['min'] || $actual > $range['max'] ) {
					return new WP_Error(
						'slot_count',
						$this->message(
							'slot_count',
							array(),
							array(
								'slot' => $slot,
								'min'  => $range['min'],
								'max'  => $range['max'],
							)
						),
						array(
							'status' => 400,
							'slot'   => $slot,
							'min'    => $range['min'],
							'max'    => $range['max'],
							'actual' => $actual,
						)
					);
				}
			}
		}

		return null;
	}

	/**
	 * Count the repeated-slot children of a top-level block. Each slot maps to
	 * a LIST of observed counts — `pricing-table`'s `list_items` is counted per
	 * COLUMN, so three columns of two features each is three refusals' worth of
	 * evidence, not one passing total of six.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @return array<string, list<int>>
	 */
	private function countSlots( string $slug, array $block ): array {
		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */

		switch ( $slug ) {
			case 'hero':
			case 'cta':
				$buttons = $this->firstByBlockName( $children, 'core/buttons' );
				$count   = 0;
				if ( null !== $buttons ) {
					$count = $this->countByBlockName( $buttons['innerBlocks'] ?? array(), 'core/button' );
				}

				return array( 'buttons' => array( $count ) );

			case 'text-section':
				return array( 'paragraphs' => array( $this->countByBlockName( $children, 'core/paragraph' ) ) );

			case 'feature-grid':
				return array( 'columns' => array( count( $this->columns( $children ) ) ) );

			case 'pricing-table':
				$columns = $this->columns( $children );
				$items   = array();
				foreach ( $columns as $column ) {
					$items[] = $this->listItemCount( $column['innerBlocks'] ?? array() );
				}

				return array(
					'columns'    => array( count( $columns ) ),
					'list_items' => array() === $items ? array( 0 ) : $items,
				);

			case 'faq':
				return array( 'details' => array( $this->countByBlockName( $children, 'core/details' ) ) );

			case 'testimonials':
				return array( 'quotes' => array( $this->countByBlockName( $children, 'core/quote' ) ) );
		}

		return array();
	}

	/**
	 * The `core/column` children of the first `core/columns` block.
	 *
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 * @return list<array<string,mixed>>
	 */
	private function columns( array $children ): array {
		$columns = $this->firstByBlockName( $children, 'core/columns' );
		if ( null === $columns ) {
			return array();
		}

		$inner = $columns['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $inner */
		$out = array();
		foreach ( $inner as $child ) {
			if ( 'core/column' === ( $child['blockName'] ?? '' ) ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 */
	private function countByBlockName( array $children, string $name ): int {
		$count = 0;
		foreach ( $children as $child ) {
			if ( ( $child['blockName'] ?? '' ) === $name ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 * @return array<string,mixed>|null The first matching block, else null.
	 */
	private function firstByBlockName( array $children, string $name ): ?array {
		foreach ( $children as $child ) {
			if ( ( $child['blockName'] ?? '' ) === $name ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Total list-item count across every `core/list` block in a subtree (items
	 * are `<li>` inner content, not child blocks).
	 *
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 */
	private function listItemCount( array $children ): int {
		$lists = $this->collectByBlockName( $children, 'core/list' );
		$count = 0;
		foreach ( $lists as $list ) {
			$count += substr_count( (string) ( $list['innerHTML'] ?? '' ), '<li' );
		}

		return $count;
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 * @return list<array<string,mixed>>
	 */
	private function collectByBlockName( array $children, string $name ): array {
		$out = array();
		foreach ( $children as $child ) {
			if ( ( $child['blockName'] ?? '' ) === $name ) {
				$out[] = $child;
			}
			$nested = $child['innerBlocks'] ?? array();
			/** @var list<array<string,mixed>> $nested */
			if ( array() !== $nested ) {
				$out = array_merge( $out, $this->collectByBlockName( $nested, $name ) );
			}
		}

		return $out;
	}

	/**
	 * The S11 page-shape rules over the whole, already-identified sequence.
	 *
	 * @param array<int,string> $identities parse offset => slug, in page order.
	 */
	private function checkPageShape( array $identities ): ?WP_Error {
		$slugs = array_values( $identities );
		$count = count( $slugs );

		if ( $count < Vocabulary::RULES_MIN_PATTERNS || $count > Vocabulary::RULES_MAX_PATTERNS ) {
			return new WP_Error(
				'page_shape',
				$this->message(
					'page_shape',
					array(),
					array(
						'rule'  => 'pattern_count',
						'count' => $count,
					)
				),
				array(
					'status' => 400,
					'rule'   => 'pattern_count',
					'count'  => $count,
				)
			);
		}

		// Hero first.
		if ( 'hero' !== ( $slugs[0] ?? '' ) ) {
			return new WP_Error(
				'page_shape',
				$this->message( 'page_shape', array(), array( 'rule' => 'hero_first' ) ),
				array(
					'status' => 400,
					'rule'   => 'hero_first',
				)
			);
		}

		// At most one CTA.
		$cta_count = count( array_filter( $slugs, static fn ( string $s ): bool => 'cta' === $s ) );
		if ( $cta_count > Vocabulary::RULES_MAX_CTA ) {
			return new WP_Error(
				'page_shape',
				$this->message( 'page_shape', array(), array( 'rule' => 'max_cta' ) ),
				array(
					'status' => 400,
					'rule'   => 'max_cta',
					'count'  => $cta_count,
				)
			);
		}

		// No pattern more than twice, except text-section.
		$freq = array();
		foreach ( $slugs as $slug ) {
			$freq[ $slug ] = ( $freq[ $slug ] ?? 0 ) + 1;
		}
		foreach ( $freq as $slug => $seen ) {
			if ( 'text-section' !== $slug && $seen > Vocabulary::RULES_MAX_REPEAT ) {
				return new WP_Error(
					'page_shape',
					$this->message(
						'page_shape',
						array(),
						array(
							'rule' => 'max_repeat',
							'slug' => $slug,
							'seen' => $seen,
						)
					),
					array(
						'status' => 400,
						'rule'   => 'max_repeat',
						'slug'   => $slug,
						'seen'   => $seen,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Step 5 — the mutation pass: strip decorative colour attrs and normalise
	 * `metadata.name` to `senroflux/<slug>` on each top-level pattern block.
	 *
	 * @param list<array<string,mixed>> $blocks     Parsed top-level blocks.
	 * @param array<int,string>         $identities parse offset => slug.
	 */
	private function mutate( array $blocks, array $identities ): string {
		$mutated = $blocks;
		foreach ( $mutated as $i => $block ) {
			if ( isset( $identities[ $i ] ) ) {
				$mutated[ $i ] = $this->mutateBlock( $block, $identities[ $i ] );
			}
		}

		return $this->serialize( $mutated );
	}

	/**
	 * Recursively strip decorative attrs and (for a top-level pattern block)
	 * set the canonical metadata.name.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 * @return array<string,mixed>
	 */
	private function mutateBlock( array $block, ?string $slug = null ): array {
		$attrs = $block['attrs'] ?? array();
		unset( $attrs['backgroundColor'], $attrs['textColor'], $attrs['gradient'] );

		if ( isset( $attrs['style']['color'] ) ) {
			unset( $attrs['style']['color'] );
			if ( empty( $attrs['style'] ) ) {
				unset( $attrs['style'] );
			}
		}

		if ( null !== $slug ) {
			$attrs['metadata'] = array( 'name' => 'senroflux/' . $slug );
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $j => $child ) {
			$children[ $j ] = $this->mutateBlock( $child );
		}
		$block['attrs']       = $attrs;
		$block['innerBlocks'] = $children;

		return $block;
	}

	/**
	 * One pattern definition by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function patternBySlug( string $slug ): ?array {
		foreach ( $this->vocabulary->all() as $pattern ) {
			if ( $slug === $pattern['slug'] ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * Human message for a refusal code (S15: harness/pack error codes via `__()`
	 * text domain `senroflux`; the code string itself stays machine-stable).
	 *
	 * @param string              $code Refusal code.
	 * @param array<string,mixed> $ctx  Context.
	 * @param array<string,mixed> $data Per-code data.
	 */
	private function message( string $code, array $ctx, array $data = array() ): string {
		$context = $ctx['post_type'] ?? 'page';

		return match ( $code ) {
			'invalid_markup'         => sprintf(
				/* translators: %s: post type. */
				__( 'The %1$s content is not well-formed block markup.', 'senroflux' ),
				$context
			),
			'unknown_block'          => sprintf(
				/* translators: %1$d: block index, %2$s: block name. */
				__( 'Block %1$d "%2$s" is not a page-vocabulary block.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? 'unknown'
			),
			'disallowed_markup'      => $this->disallowedMarkupMessage( $data ),
			'unresolved_placeholder' => sprintf(
				/* translators: %s: placeholder. */
				__( 'Unresolved placeholder "{{%s}}" must be filled before writing.', 'senroflux' ),
				$data['placeholder'] ?? ''
			),
			'unknown_pattern'        => sprintf(
				/* translators: %1$d: pattern index, %2$s: nearest pattern name. */
				__( 'Pattern %1$d does not match any page pattern (nearest: %2$s).', 'senroflux' ),
				$data['index'] ?? 0,
				$data['name'] ?? 'unknown'
			),
			'slot_count'             => sprintf(
				/* translators: %1$s: slot, %2$d: min, %3$d: max. */
				__( 'Slot "%1$s" must have %2$d to %3$d items.', 'senroflux' ),
				$data['slot'] ?? '',
				$data['min'] ?? 0,
				$data['max'] ?? 0
			),
			'page_shape'             => $this->pageShapeMessage( $data ),
			default                  => __( 'Invalid page content.', 'senroflux' ),
		};
	}

	/**
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private function disallowedMarkupMessage( array $data ): string {
		return match ( $data['reason'] ?? '' ) {
			'tag'   => sprintf(
				/* translators: %1$d: pattern index, %2$s: HTML tag. */
				__( 'Pattern %1$d contains the disallowed HTML tag "<%2$s>".', 'senroflux' ),
				$data['index'] ?? 0,
				$data['tag'] ?? ''
			),
			'attr'  => sprintf(
				/* translators: %1$d: pattern index, %2$s: attribute, %3$s: HTML tag. */
				__( 'Pattern %1$d contains the disallowed attribute "%2$s" on "<%3$s>".', 'senroflux' ),
				$data['index'] ?? 0,
				$data['attr'] ?? '',
				$data['tag'] ?? ''
			),
			'style' => sprintf(
				/* translators: %1$d: pattern index, %2$s: HTML tag. */
				__( 'Pattern %1$d has an unsafe style attribute on "<%2$s>".', 'senroflux' ),
				$data['index'] ?? 0,
				$data['tag'] ?? ''
			),
			'url'   => sprintf(
				/* translators: %1$d: pattern index, %2$s: attribute, %3$s: HTML tag. */
				__( 'Pattern %1$d has an unsafe URL in "%2$s" on "<%3$s>"; use http, https, mailto, tel or a relative link.', 'senroflux' ),
				$data['index'] ?? 0,
				$data['attr'] ?? '',
				$data['tag'] ?? ''
			),
			default => __( 'The page contains markup that is not allowed.', 'senroflux' ),
		};
	}

	/**
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private function pageShapeMessage( array $data ): string {
		return match ( $data['rule'] ?? '' ) {
			'pattern_count' => sprintf(
				/* translators: %d: pattern count. */
				__( 'A page needs 2 to 8 patterns; %d given.', 'senroflux' ),
				$data['count'] ?? 0
			),
			'hero_first'    => __( 'The first pattern on a page must be a hero.', 'senroflux' ),
			'max_cta'       => __( 'A page may contain at most one call-to-action.', 'senroflux' ),
			'max_repeat'    => sprintf(
				/* translators: %1$s: pattern, %2$d: occurrences. */
				__( 'No pattern may repeat more than twice; "%1$s" was used %2$d times.', 'senroflux' ),
				$data['slug'] ?? '',
				$data['seen'] ?? 0
			),
			default         => __( 'The page does not satisfy the page-shape rules.', 'senroflux' ),
		};
	}
}
