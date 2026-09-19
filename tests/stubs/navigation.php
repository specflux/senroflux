<?php
/**
 * Test-only stand-ins for the block-theme / classic-menu surface the site
 * pack's Navigation registrar calls (stage 7, S7).
 *
 * Everything is `function_exists`-guarded so a real WordPress load order
 * wins. Requires `stubs/blocks.php` to already be loaded (reuses its
 * `parse_blocks()`/post store).
 *
 * TARGET REPO PATH: tests/stubs/navigation.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

$GLOBALS['senroflux_test_is_block_theme']          = true;
$GLOBALS['senroflux_test_header_template_content'] = null;
$GLOBALS['senroflux_test_nav_fallback']            = null;
$GLOBALS['senroflux_test_registered_nav_menus']    = array();
$GLOBALS['senroflux_test_nav_menu_locations']      = array();
$GLOBALS['senroflux_test_nav_menu_items']          = array();
$GLOBALS['senroflux_test_next_menu_item_id']       = 500;

if ( ! function_exists( 'wp_is_block_theme' ) ) {
	function wp_is_block_theme(): bool {
		return (bool) ( $GLOBALS['senroflux_test_is_block_theme'] ?? true );
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string {
		return 'senroflux-test-theme';
	}
}

if ( ! function_exists( 'get_block_template' ) ) {
	/**
	 * Fixture-backed header template part: only `<stylesheet>//header`,
	 * `wp_template_part` is answered — every other id is null, matching a
	 * theme with no OTHER template parts registered.
	 */
	function get_block_template( string $id, string $template_type = 'wp_template' ): ?object {
		if ( 'wp_template_part' !== $template_type || get_stylesheet() . '//header' !== $id ) {
			return null;
		}
		$content = $GLOBALS['senroflux_test_header_template_content'] ?? null;
		if ( null === $content ) {
			return null;
		}

		$template          = new stdClass();
		$template->content = $content;

		return $template;
	}
}

if ( ! class_exists( 'WP_Navigation_Fallback' ) ) {
	/**
	 * Test stand-in for core's fallback resolver: answers whatever fixture
	 * `$GLOBALS['senroflux_test_nav_fallback']` names (or null), never
	 * creates one — the real class WOULD create one, but this class only
	 * needs to prove SenroFlux never assumes a ref and instead calls through
	 * to this resolver.
	 */
	class WP_Navigation_Fallback {
		public static function get_fallback(): ?object {
			$id = $GLOBALS['senroflux_test_nav_fallback'] ?? null;
			if ( null === $id ) {
				return null;
			}

			return get_post( (int) $id );
		}
	}
}

if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	function get_registered_nav_menus(): array {
		return (array) ( $GLOBALS['senroflux_test_registered_nav_menus'] ?? array() );
	}
}

if ( ! function_exists( 'get_nav_menu_locations' ) ) {
	function get_nav_menu_locations(): array {
		return (array) ( $GLOBALS['senroflux_test_nav_menu_locations'] ?? array() );
	}
}

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	function wp_get_nav_menu_items( int $menu ): array {
		$items = $GLOBALS['senroflux_test_nav_menu_items'][ $menu ] ?? array();

		// Sort by menu_order, mirroring core's own ordering guarantee.
		usort(
			$items,
			static fn ( $a, $b ): int => ( (int) ( $a->menu_order ?? 0 ) ) <=> ( (int) ( $b->menu_order ?? 0 ) )
		);

		return array_values( $items );
	}
}

if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
	function wp_update_nav_menu_item( int $menu_id, int $menu_item_db_id = 0, array $menu_item_data = array() ): int {
		$id = 0 !== $menu_item_db_id ? $menu_item_db_id : (int) ( $GLOBALS['senroflux_test_next_menu_item_id'] ?? 500 );
		$GLOBALS['senroflux_test_next_menu_item_id'] = $id + 1;

		$item             = new stdClass();
		$item->ID         = $id;
		$item->title      = (string) ( $menu_item_data['menu-item-title'] ?? '' );
		$item->url        = (string) ( $menu_item_data['menu-item-url'] ?? '' );
		$item->object     = (string) ( $menu_item_data['menu-item-object'] ?? '' );
		$item->object_id  = (int) ( $menu_item_data['menu-item-object-id'] ?? 0 );
		$item->menu_order = count( $GLOBALS['senroflux_test_nav_menu_items'][ $menu_id ] ?? array() ) + 1;

		$GLOBALS['senroflux_test_nav_menu_items'][ $menu_id ][ $id ] = $item;

		return $id;
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force_delete = false ): bool {
		unset( $force_delete );
		unset( $GLOBALS['senroflux_test_posts'][ $post_id ] );

		foreach ( array_keys( (array) ( $GLOBALS['senroflux_test_nav_menu_items'] ?? array() ) ) as $menu_id ) {
			unset( $GLOBALS['senroflux_test_nav_menu_items'][ $menu_id ][ $post_id ] );
		}

		return true;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $id ): string {
		return 'https://example.test/?page_id=' . $id;
	}
}
