<?php
/**
 * Test-only stand-ins for the Gutenberg / WP post surface the pages-pack tests
 * call (stage 8). TARGET REPO PATH: tests/stubs/blocks.php
 *
 * LIMITS (documented):
 *   - `parse_blocks` is a PURE-PHP parser good enough for the seven pattern
 *     markups and their children. It produces `[{blockName, attrs, innerHTML,
 *     innerBlocks}]`, where `innerHTML` preserves the block's FULL inner content
 *     (including nested block comments) and `innerBlocks` is the parsed
 *     children (for structural walking). It does NOT handle freeform text that
 *     should be attributed to a block — our patterns have none.
 *   - `serialize_blocks` re-emits `open + innerHTML + close`, so
 *     `serialize_blocks(parse_blocks($content))` round-trips `$content` exactly
 *     for the compact pattern markups (the same property the Validator's step-1
 *     normalised compare relies on). Attribute JSON is re-encoded compactly
 *     with `JSON_UNESCAPED_SLASHES`, so an inline space in the attrs JSON is a
 *     deliberate normalisation mismatch (used by the invalid_markup test).
 *   - The post shims are an in-memory store keyed by id; `wp_insert_post`
 *     assigns sequential ids; URLs are deterministic.
 *
 * Everything is `function_exists`-guarded so a real WordPress load order wins.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

// --- Block pattern registry shims -----------------------------------------

$GLOBALS['senroflux_test_patterns']           = array();
$GLOBALS['senroflux_test_pattern_categories'] = array();

if ( ! function_exists( 'register_block_pattern' ) ) {
	function register_block_pattern( string $name, array $args ): void {
		$GLOBALS['senroflux_test_patterns'][ $name ] = $args;
	}
}

if ( ! function_exists( 'register_block_pattern_category' ) ) {
	function register_block_pattern_category( string $name, array $args ): void {
		$GLOBALS['senroflux_test_pattern_categories'][ $name ] = $args;
	}
}

if ( ! function_exists( 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $name ): bool {
		return true; // The registration is recorded; category existence is not enforced.
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $name, array $args ): bool {
		$GLOBALS['senroflux_test_ability_categories'][ $name ] = $args;

		return true;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): ?object {
		$ability                                      = new \SenroFlux_Test_Fake_Ability(
			$name,
			$args['permission_callback'] ?? true,
			$args['execute_callback'] ?? array( 'ok' => true ),
			$args['description'] ?? '',
			$args['input_schema'] ?? null,
			$args['meta'] ?? array()
		);
		$GLOBALS['senroflux_test_abilities'][ $name ] = $ability;

		return $ability;
	}
}

// --- Gutenberg block parser / serializer shims ----------------------------

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		return _senroflux_parse_blocks_inner( $content );
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		$out = '';
		foreach ( $blocks as $block ) {
			$out .= _senroflux_serialize_block( $block );
		}

		return $out;
	}
}

/**
 * @return list<array<string,mixed>>
 */
function _senroflux_parse_blocks_inner( string $html ): array {
	$blocks = array();
	$pos    = 0;
	$len    = strlen( $html );

	while ( $pos < $len ) {
		$open = strpos( $html, '<!-- wp:', $pos );
		if ( false === $open ) {
			break; // Trailing text/freeform is not attributed in this shim.
		}

		$open_end = strpos( $html, '-->', $open );
		if ( false === $open_end ) {
			break;
		}

		$token = substr( $html, $open, ( $open_end + 3 ) - $open );
		$name  = _senroflux_block_name_from_open( $token );
		if ( null === $name ) {
			$pos = $open_end + 3;
			continue;
		}

		$attrs = _senroflux_attrs_from_open( $token );
		// Closers use the bare comment name (`<!-- /wp:group -->`), not the
		// inferred blockName (`core/group`).
		$comment_name = str_starts_with( $name, 'core/' ) ? substr( $name, strlen( 'core/' ) ) : $name;
		$close_tag    = '<!-- /wp:' . $comment_name . ' -->';
		$close        = strpos( $html, $close_tag, $open_end + 3 );

		if ( false === $close ) {
			$inner_full = substr( $html, $open_end + 3 );
			$close      = $len;
		} else {
			$inner_full = substr( $html, $open_end + 3, $close - ( $open_end + 3 ) );
		}

		$inner_blocks = _senroflux_parse_blocks_inner( $inner_full );

		$blocks[] = array(
			'blockName'   => $name,
			'attrs'       => $attrs,
			'innerHTML'   => $inner_full,
			'innerBlocks' => $inner_blocks,
		);

		$pos = $close + strlen( $close_tag );
	}

	return $blocks;
}

function _senroflux_block_name_from_open( string $token ): ?string {
	$rest  = substr( $token, strlen( '<!-- wp:' ) );
	$space = strpos( $rest, ' ' );
	$name  = false === $space ? $rest : substr( $rest, 0, $space );
	$name  = trim( $name );

	if ( '' === $name ) {
		return null;
	}

	// Real WP parse_blocks maps a bare `wp:group` to `core/group`; a namespaced
	// `wp:foo/bar` stays `foo/bar`. Mirror that.
	return str_contains( $name, '/' ) ? $name : 'core/' . $name;
}

function _senroflux_attrs_from_open( string $token ): array {
	$rest  = substr( $token, strlen( '<!-- wp:' ) );
	$space = strpos( $rest, ' ' );
	if ( false === $space ) {
		return array();
	}

	$attr_part   = trim( substr( $rest, $space ) );
	$open_brace  = strpos( $attr_part, '{' );
	$close_brace = strrpos( $attr_part, '}' );
	if ( false === $open_brace || false === $close_brace || $close_brace <= $open_brace ) {
		return array();
	}

	$json    = substr( $attr_part, $open_brace, $close_brace - $open_brace + 1 );
	$decoded = json_decode( $json, true );

	return is_array( $decoded ) ? $decoded : array();
}

function _senroflux_serialize_block( array $block ): string {
	$name = (string) ( $block['blockName'] ?? '' );
	// Real WP emits a core block as a bare `wp:group`, not `wp:core/group`.
	$comment_name = str_starts_with( $name, 'core/' ) ? substr( $name, strlen( 'core/' ) ) : $name;
	$open         = '<!-- wp:' . $comment_name;
	$attrs        = $block['attrs'] ?? array();

	if ( array() !== $attrs ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the test shim's wp_json_encode() drops the JSON_UNESCAPED_SLASHES flag the round-trip contract relies on.
		$open .= ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES );
	}
	$open .= ' -->';

	$inner = (string) ( $block['innerHTML'] ?? '' );
	$close = '<!-- /wp:' . $comment_name . ' -->';

	return $open . $inner . $close;
}

// --- Post shims (in-memory store) -----------------------------------------

$GLOBALS['senroflux_test_posts']          = array();
$GLOBALS['senroflux_test_inserted_posts'] = array();
$GLOBALS['senroflux_test_next_post_id']   = 100;

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, bool $wp_error = false ): int|WP_Error {
		unset( $wp_error );

		$GLOBALS['senroflux_test_inserted_posts'][] = $postarr;

		$id                                     = (int) ( $GLOBALS['senroflux_test_next_post_id'] ?? 100 );
		$GLOBALS['senroflux_test_next_post_id'] = $id + 1;

		$post                = new stdClass();
		$post->ID            = $id;
		$post->post_type     = $postarr['post_type'] ?? 'page';
		$post->post_title    = $postarr['post_title'] ?? '';
		$post->post_content  = $postarr['post_content'] ?? '';
		$post->post_status   = $postarr['post_status'] ?? 'draft';
		$post->post_name     = $postarr['post_name'] ?? '';
		$post->post_parent   = (int) ( $postarr['post_parent'] ?? 0 );
		$post->post_excerpt  = $postarr['post_excerpt'] ?? '';
		$post->post_date     = '';
		$post->post_modified = '';

		$GLOBALS['senroflux_test_posts'][ $id ] = $post;

		return $id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error {
		unset( $wp_error );

		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( isset( $GLOBALS['senroflux_test_posts'][ $id ] ) ) {
			$post = $GLOBALS['senroflux_test_posts'][ $id ];
			foreach ( $postarr as $key => $value ) {
				if ( 'ID' === $key ) {
					continue;
				}
				$prop = 'post_' . ltrim( $key, 'post_' );
				if ( property_exists( $post, $prop ) ) {
					$post->{$prop} = $value;
				}
			}
		}

		return $id;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( mixed $id ): ?stdClass {
		if ( is_object( $id ) ) {
			return $id;
		}

		return $GLOBALS['senroflux_test_posts'][ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $id ): string {
		$post = get_post( $id );

		return $post ? (string) ( $post->post_status ?? '' ) : '';
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $id ): string {
		$post = get_post( $id );

		return $post ? (string) ( $post->post_title ?? '' ) : '';
	}
}

if ( ! function_exists( 'get_preview_post_link' ) ) {
	function get_preview_post_link( int $id ): ?string {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}

		return 'https://example.test/?p=' . $id . '&preview=true';
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( int $id, string $context = 'display' ): string {
		$post = get_post( $id );
		if ( ! $post ) {
			return '';
		}

		$title = get_the_title( $id );
		$url   = 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';

		return 'raw' === $context ? $url : '<a href="' . $url . '">' . ( $title ? $title : 'Edit' ) . '</a>';
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( int $id ): mixed {
		return $GLOBALS['senroflux_test_users'][ $id ] ?? false;
	}
}
