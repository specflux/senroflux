<?php
/**
 * Test-only shims for the RunsScreen admin wiring (stage 9).
 *
 * TARGET REPO PATH: tests/stubs/admin.php
 *
 * Loaded from tests/bootstrap.php AFTER the existing shims (function_exists-
 * guarded) so a real WP load order always wins. These cover the functions the
 * rewritten RunsScreen calls when rendering forms/handlers under bare PHPUnit.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! function_exists( 'wp_unslash' ) ) {
	/** Identity shim (WP's slash-removal is a no-op on already-unslashed input). */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/** Identity shim (RunsScreen uses esc_attr__ for JS/attribute strings). */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** Strip-shim: keeps text fields printable. */
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- test shim; wp_strip_all_tags may not exist in tests.
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** Strip-shim that preserves newlines. */
	function sanitize_textarea_field( string $text ): string {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- test shim; wp_strip_all_tags may not exist in tests.
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Nonce-field shim: prints the two hidden inputs WP forms carry.
	 *
	 * @param string $action Action.
	 * @param string $name   Field name.
	 * @return string
	 */
	function wp_nonce_field( string $action, string $name = '_wpnonce' ): string {
		return '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( 'test-' . md5( $action ) ) . '">';
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/** Nonce-creation shim (deterministic). */
	function wp_create_nonce( string $action ): string {
		return 'test-' . md5( $action );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Remove ONE callback, like WordPress does.
	 *
	 * This used to delegate to `remove_all_filters()`, which (a) returns void,
	 * so the declared `bool` return raised a TypeError the moment any code
	 * actually removed a filter, and (b) wiped EVERY callback on the hook —
	 * including a test's own spy. Both made the scoped-allowance seams
	 * (`senroflux_can_tick`) untestable.
	 *
	 * @param string $hook     Filter name.
	 * @param mixed  $callback The exact callback to remove.
	 * @param int    $priority The priority it was added at.
	 */
	function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
		$bucket = $GLOBALS['senroflux_test_filters'][ $hook ][ $priority ] ?? null;
		if ( ! is_array( $bucket ) ) {
			return false;
		}

		$removed = false;
		foreach ( $bucket as $index => $entry ) {
			if ( ( $entry[0] ?? null ) === $callback ) {
				unset( $GLOBALS['senroflux_test_filters'][ $hook ][ $priority ][ $index ] );
				$removed = true;
			}
		}

		if ( array() === $GLOBALS['senroflux_test_filters'][ $hook ][ $priority ] ) {
			unset( $GLOBALS['senroflux_test_filters'][ $hook ][ $priority ] );
		}

		return $removed;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/** No-op shim. */
	function wp_enqueue_style( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/** No-op shim. */
	function wp_enqueue_script( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/** No-op shim. */
	function wp_localize_script( string $handle, string $object_name, array $data ): bool {
		unset( $handle, $object_name, $data );

		return true;
	}
}
