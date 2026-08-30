<?php
/**
 * Test-only stand-ins for the Gutenberg / WP post surface the pages-pack tests
 * call (stage 8). TARGET REPO PATH: tests/stubs/blocks.php
 *
 * NOT a hand-rolled parser: `parse_blocks()` / `serialize_blocks()` are
 * WordPress core's own implementations, vendored in `wp-block-parser.php` and
 * re-declared here byte-for-byte from `wp-includes/blocks.php`. The pages
 * pack's write contract is
 * `serialize_blocks( parse_blocks( $content ) ) === $content` plus a walk over
 * `innerBlocks` / `innerHTML`; a simplified stub would make those assertions
 * true by construction, so only the real parser is used.
 *
 * LIMITS (documented):
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

// --- Gutenberg block parser / serializer (REAL WordPress core) -------------

// The vendored core parser + the three serializer helpers it needs. Nothing
// here is a simplification: `parse_blocks()` is `WP_Block_Parser::parse()` and
// `serialize_block()` walks `innerContent`'s null markers exactly as core does,
// so the Validator's round-trip contract is exercised for real.
if ( ! class_exists( 'WP_Block_Parser', false ) ) {
	require_once __DIR__ . '/wp-block-parser.php';
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		$parser = new WP_Block_Parser();

		return $parser->parse( $content );
	}
}

if ( ! function_exists( 'strip_core_block_namespace' ) ) {
	function strip_core_block_namespace( $block_name = null ) {
		if ( is_string( $block_name ) && str_starts_with( $block_name, 'core/' ) ) {
			return substr( $block_name, 5 );
		}

		return $block_name;
	}
}

if ( ! function_exists( 'serialize_block_attributes' ) ) {
	function serialize_block_attributes( array $block_attributes ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- core uses wp_json_encode() with these exact flags; the bootstrap shim drops them.
		$encoded_attributes = json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return strtr(
			(string) $encoded_attributes,
			array(
				'\\\\' => '\\u005c',
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			)
		);
	}
}

if ( ! function_exists( 'get_comment_delimited_block_content' ) ) {
	function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
		if ( is_null( $block_name ) ) {
			return $block_content;
		}

		$serialized_block_name = strip_core_block_namespace( $block_name );
		$serialized_attributes = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';

		if ( empty( $block_content ) ) {
			return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
		}

		return sprintf(
			'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
			$serialized_block_name,
			$serialized_attributes,
			$block_content,
			$serialized_block_name
		);
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( array $block ): string {
		$block_content = '';

		$index = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
		}

		if ( ! is_array( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}

		return get_comment_delimited_block_content(
			$block['blockName'],
			$block['attrs'],
			$block_content
		);
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}
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
				// The keys ARE already the `post_*` column names; the previous
				// `ltrim( $key, 'post_' )` stripped a CHARACTER SET, not a
				// prefix, so `post_status` became `post_atus` and no write ever
				// landed. Tests that assert a refusal persisted nothing were
				// therefore true by construction.
				if ( property_exists( $post, (string) $key ) ) {
					$post->{$key} = $value;
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
