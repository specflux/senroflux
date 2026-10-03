<?php
/**
 * Test-only stand-ins for the media/attachment/term WordPress surface the
 * `Packs\Content\Media` tests call (stage 6). TARGET REPO PATH:
 * tests/stubs/media.php
 *
 * Attachments live in the SAME `senroflux_test_posts` in-memory store
 * `blocks.php` already provides — an attachment is just a post whose
 * `post_type` is `attachment` — so `get_post()`/`get_post_status()` etc. keep
 * working unmodified.
 *
 * Everything is `function_exists`-guarded so a real WordPress load order
 * wins.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

$GLOBALS['senroflux_test_postmeta']   = array();
$GLOBALS['senroflux_test_terms']      = array();
$GLOBALS['senroflux_test_post_terms'] = array();

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		$GLOBALS['senroflux_test_postmeta'][ $post_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		unset( $single );

		return $GLOBALS['senroflux_test_postmeta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
		$GLOBALS['senroflux_test_postmeta'][ $post_id ]['_thumbnail_id'] = $thumbnail_id;

		return true;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $attachment_id ): string|false {
		$post = get_post( $attachment_id );

		return $post ? 'https://example.test/wp-content/uploads/' . $attachment_id . '.jpg' : false;
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename ): array {
		unset( $filename );

		return array(
			'ext'  => 'jpg',
			'type' => 'image/jpeg',
		);
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( int $attachment_id, string $file ): array {
		unset( $attachment_id, $file );

		return array();
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	function wp_update_attachment_metadata( int $attachment_id, array $data ): bool {
		unset( $attachment_id, $data );

		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$base = sys_get_temp_dir() . '/senroflux-test-uploads';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture dir, not a WP runtime path.
		}

		return array(
			'basedir' => $base,
			'baseurl' => 'https://example.test/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
	function wp_insert_attachment( array $postarr, string $file = '' ): int|WP_Error {
		unset( $file );
		$postarr['post_type'] = 'attachment';

		return wp_insert_post( $postarr );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * A minimal `get_posts()`: filters the in-memory post store by
	 * `post_type` (`'any'` matches every type), an optional `post_mime_type`
	 * PREFIX, an optional `s` substring match against the title, and an
	 * optional exact `meta_key`/`meta_value` pair (the `read-media`
	 * "which posts use this as a featured image" lookup, defect fix).
	 * `posts_per_page` -1 means no limit.
	 */
	function get_posts( array $args = array() ): array {
		$post_type  = $args['post_type'] ?? 'post';
		$mime       = $args['post_mime_type'] ?? '';
		$search     = isset( $args['s'] ) ? strtolower( (string) $args['s'] ) : '';
		$limit      = (int) ( $args['posts_per_page'] ?? -1 );
		$meta_key   = isset( $args['meta_key'] ) ? (string) $args['meta_key'] : '';
		$meta_value = array_key_exists( 'meta_value', $args ) ? (string) $args['meta_value'] : null;

		$matches = array();
		foreach ( $GLOBALS['senroflux_test_posts'] ?? array() as $post ) {
			if ( 'any' !== $post_type && ( $post->post_type ?? '' ) !== $post_type ) {
				continue;
			}
			if ( '' !== $mime && ! str_starts_with( (string) ( $post->post_mime_type ?? '' ), $mime ) ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( (string) ( $post->post_title ?? '' ) ), $search ) ) {
				continue;
			}
			if ( '' !== $meta_key ) {
				$actual = $GLOBALS['senroflux_test_postmeta'][ $post->ID ][ $meta_key ] ?? null;
				if ( null === $meta_value ) {
					if ( null === $actual ) {
						continue;
					}
				} elseif ( (string) $actual !== $meta_value ) {
					continue;
				}
			}
			$matches[] = $post;
			if ( $limit > 0 && count( $matches ) >= $limit ) {
				break;
			}
		}

		return $matches;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * 0.3 quality fix 3: the on-disk path `generateAltText()`'s caller falls
	 * back to when no intermediate size is registered. Scripted per
	 * attachment via `$GLOBALS['senroflux_test_attached_files'][$id]`;
	 * absent means "no file on disk" (the missing-file refusal path).
	 */
	function get_attached_file( int $attachment_id ): string|false {
		return $GLOBALS['senroflux_test_attached_files'][ $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'image_get_intermediate_size' ) ) {
	/**
	 * 0.3 quality fix 3: scripted per attachment+size via
	 * `$GLOBALS['senroflux_test_intermediate_sizes'][$id][$size]` (a
	 * `basedir`-relative `path`, mirroring the real function's shape).
	 */
	function image_get_intermediate_size( int $attachment_id, string $size = 'thumbnail' ): array|false {
		return $GLOBALS['senroflux_test_intermediate_sizes'][ $attachment_id ][ $size ] ?? false;
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * `read-media`'s dimensions source (defect fix): scripted per attachment
	 * via `$GLOBALS['senroflux_test_attachment_metadata'][$id]`, empty array
	 * (no width/height) otherwise — mirrors a real attachment with no
	 * generated image sizes rather than guessing.
	 */
	function wp_get_attachment_metadata( int $attachment_id ): array {
		return $GLOBALS['senroflux_test_attachment_metadata'][ $attachment_id ] ?? array();
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		unset( $special_chars, $extra_special_chars );

		return substr( str_repeat( 'abcdef0123456789', 4 ), 0, $length );
	}
}

if ( ! function_exists( 'wp_upload_bits' ) ) {
	/**
	 * Writes `$bits` into the (test) uploads dir, mirroring the real
	 * function's return shape (defect A: AiClientMediaGateway persists
	 * generated images via this call).
	 */
	function wp_upload_bits( string $name, ?string $deprecated, string $bits, ?string $time = null ): array {
		unset( $deprecated, $time );

		$dir  = wp_upload_dir();
		$path = $dir['basedir'] . '/' . $name;
		file_put_contents( $path, $bits ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, not a WP runtime path.

		return array(
			'file'  => $path,
			'url'   => $dir['baseurl'] . '/' . $name,
			'type'  => '',
			'error' => false,
		);
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): void {
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture, not a WP runtime path.
		}
	}
}

if ( ! function_exists( 'download_url' ) ) {
	/**
	 * Test double: writes the scripted response body
	 * (`$GLOBALS['senroflux_test_download_url_response']`) to a temp file and
	 * returns its path, or a WP_Error if the script sets one.
	 */
	function download_url( string $url, int $timeout = 300, bool $signature_verification = false ): string|WP_Error {
		unset( $timeout, $signature_verification );

		$GLOBALS['senroflux_test_download_url_calls'][] = $url;

		$response = $GLOBALS['senroflux_test_download_url_response'] ?? null;
		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$tmp = tempnam( sys_get_temp_dir(), 'senroflux-download-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- test fixture, not a WP runtime path.
		file_put_contents( $tmp, (string) $response ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, not a WP runtime path.

		return $tmp;
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	/**
	 * False by default, which forces the assign/manage-terms fallback caps in
	 * Media; a test may script `$GLOBALS['senroflux_test_taxonomies'][$name]`.
	 */
	function get_taxonomy( string $taxonomy ): object|false {
		return $GLOBALS['senroflux_test_taxonomies'][ $taxonomy ] ?? false;
	}
}

if ( ! function_exists( 'is_object_in_taxonomy' ) ) {
	/** Only `post` carries categories and tags unless a test scripts otherwise. */
	function is_object_in_taxonomy( string $object_type, string $taxonomy ): bool {
		$map = $GLOBALS['senroflux_test_object_taxonomies'] ?? array( 'post' => array( 'category', 'post_tag' ) );

		return in_array( $taxonomy, $map[ $object_type ] ?? array(), true );
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( string $term, string $taxonomy = '' ): array|null {
		$key = $taxonomy . ':' . strtolower( $term );

		return isset( $GLOBALS['senroflux_test_terms'][ $key ] )
			? array(
				'term_id'          => $GLOBALS['senroflux_test_terms'][ $key ],
				'term_taxonomy_id' => $GLOBALS['senroflux_test_terms'][ $key ],
			)
			: null;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( string $term, string $taxonomy, array $args = array() ): array|WP_Error {
		unset( $args );
		$key = $taxonomy . ':' . strtolower( $term );
		if ( isset( $GLOBALS['senroflux_test_terms'][ $key ] ) ) {
			return new WP_Error( 'term_exists', 'Term already exists.' );
		}

		$id                                      = (int) ( $GLOBALS['senroflux_test_next_term_id'] ?? 1 );
		$GLOBALS['senroflux_test_next_term_id']  = $id + 1;
		$GLOBALS['senroflux_test_terms'][ $key ] = $id;

		return array(
			'term_id'          => $id,
			'term_taxonomy_id' => $id,
		);
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Finds a term by id in the fixture map; the taxonomy is the part of
	 * the `<taxonomy>:<lowercased name>` key before the colon.
	 */
	function get_term( int $term_id, string $taxonomy = '' ): object|null {
		unset( $taxonomy );

		foreach ( $GLOBALS['senroflux_test_terms'] ?? array() as $key => $id ) {
			if ( (int) $id !== $term_id ) {
				continue;
			}

			list( $term_taxonomy, $name ) = explode( ':', (string) $key, 2 );

			return (object) array(
				'term_id'  => $term_id,
				'name'     => ucwords( $name ),
				'taxonomy' => $term_taxonomy,
			);
		}

		return null;
	}
}

if ( ! function_exists( 'get_edit_term_link' ) ) {
	function get_edit_term_link( int $term_id, string $taxonomy = '', string $object_type = '' ): ?string {
		unset( $object_type );

		return 'https://example.test/wp-admin/term.php?taxonomy=' . $taxonomy . '&tag_ID=' . $term_id;
	}
}

if ( ! function_exists( 'wp_set_post_terms' ) ) {
	function wp_set_post_terms( int $post_id, array $terms, string $taxonomy, bool $append = false ): array|WP_Error {
		if ( ! $append ) {
			$GLOBALS['senroflux_test_post_terms'][ $post_id ][ $taxonomy ] = array();
		}
		foreach ( $terms as $term_id ) {
			$GLOBALS['senroflux_test_post_terms'][ $post_id ][ $taxonomy ][] = (int) $term_id;
		}

		return $GLOBALS['senroflux_test_post_terms'][ $post_id ][ $taxonomy ];
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * Honours `taxonomy`, `search` (substring of the name), `orderby=count`
	 * (most used first), `number`. Rows are scripted in
	 * `$GLOBALS['senroflux_test_term_rows'][$taxonomy]` as name => count;
	 * `product_cat` reads the commerce stub's `senroflux_test_product_cats`.
	 *
	 * @param array<string,mixed> $args
	 * @return list<object>
	 */
	function get_terms( array $args = array() ): array {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		$terms    = array();
		if ( 'product_cat' === $taxonomy ) {
			foreach ( $GLOBALS['senroflux_test_product_cats'] ?? array() as $id => $row ) {
				$terms[] = (object) array(
					'term_id' => (int) $id,
					'name'    => (string) $row['name'],
					'slug'    => (string) $row['slug'],
					'count'   => (int) ( $row['count'] ?? 0 ),
				);
			}

			return $terms;
		}

		foreach ( $GLOBALS['senroflux_test_term_rows'][ $taxonomy ] ?? array() as $name => $count ) {
			if ( isset( $args['search'] ) && false === stripos( (string) $name, (string) $args['search'] ) ) {
				continue;
			}
			$terms[] = (object) array(
				'term_id' => count( $terms ) + 1,
				'name'    => (string) $name,
				'count'   => (int) $count,
			);
		}
		if ( 'count' === ( $args['orderby'] ?? '' ) ) {
			usort( $terms, static fn ( $a, $b ) => $b->count <=> $a->count );
		}

		return isset( $args['number'] ) ? array_slice( $terms, 0, (int) $args['number'] ) : $terms;
	}
}
