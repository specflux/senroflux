<?php
/**
 * Multisite refusal (0.3 S2).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux;

/**
 * SenroFlux does not support multisite. Activation is refused whether it is
 * per site or network-wide, and a copy that is somehow active on a multisite
 * install (a site converted after activation) boots nothing.
 */
final class MultisiteGuard {

	/**
	 * Whether this install must be refused.
	 *
	 * @return bool
	 */
	public static function refused(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * The one sentence shown to the person who tried.
	 *
	 * @return string
	 */
	public static function message(): string {
		return __( 'SenroFlux does not support WordPress multisite. It was not activated.', 'senroflux' );
	}

	/**
	 * Activation hook body: deactivates the plugin (defensive — WordPress has
	 * not yet recorded it as active at this point) and dies before
	 * activate_plugin() can report success, for both per-site and network
	 * activation.
	 *
	 * @param string $plugin_file The main plugin file (__FILE__ of the caller).
	 * @return void
	 */
	public static function refuse_activation( string $plugin_file ): void {
		if ( ! self::refused() ) {
			return;
		}

		deactivate_plugins( plugin_basename( $plugin_file ) );

		wp_die(
			esc_html( self::message() ),
			esc_html__( 'Plugin activation refused', 'senroflux' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Runtime guard for a copy that is somehow active on a multisite install
	 * (for example a site converted to multisite after activation): deactivates
	 * it and shows the admin notice, so that no part of the plugin runs.
	 *
	 * @param string $plugin_file The main plugin file (__FILE__ of the caller).
	 * @return void
	 */
	public static function refuse_runtime( string $plugin_file ): void {
		if ( ! self::refused() ) {
			return;
		}

		deactivate_plugins( plugin_basename( $plugin_file ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Runtime notice for an already-active copy on a multisite install.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'SenroFlux is inactive: WordPress multisite is not supported. Deactivate it on this network.', 'senroflux' )
		);
	}
}
