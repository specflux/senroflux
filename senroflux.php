<?php
/**
 * Plugin Name:       SenroFlux
 * Description:       Resumable, browser-driven multi-step agent runs inside the logged-in WordPress session — Abilities as tools, Agent Safety as a hard-required checkpoint.
 * Version:           0.2.0-dev
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       senroflux
 *
 * Harness for agent RUNS: one goal pursued by one user through many model
 * turns and tool calls, pausing for human approval when Agent Safety demands
 * it. SenroFlux owns runs/steps state and nothing else — the chat UI belongs
 * to consumers (first consumer: marketing-analytics-chat), enforcement belongs
 * to Agent Safety.
 *
 * HARD DEPENDENCY: Agent Safety must be active. Without its gate every run
 * would execute irreversible tools ungoverned, so the plugin refuses to start
 * runs (senroflux_ungoverned) and shows an admin notice instead. Fail closed.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux;

use Specflux\SenroFlux\MultisiteGuard;
use Specflux\SenroFlux\Plugin;

// Bail on direct access (must precede any other runtime code so static
// analysers and the plugin-check detector both recognize it).
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SENROFLUX_URL' ) ) {
	define( 'SENROFLUX_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'SENROFLUX_PATH' ) ) {
	// 0.3 S10/S17: the React Runs screen's build assets are read from disk
	// (its `index.asset.php` dependency/version manifest) before being
	// enqueued by URL via `SENROFLUX_URL` above.
	define( 'SENROFLUX_PATH', plugin_dir_path( __FILE__ ) );
}


// Registered UNCONDITIONALLY (not inside any class guard): WordPress calls
// activation hooks by re-including this file and checking what got registered
// THAT load, so the callbacks below must exist even when the autoloader is
// broken — they guard internally instead (fail safe, not silent).
register_activation_hook( __FILE__, __NAMESPACE__ . '\senroflux_activate' );

/**
 * Create the runs/steps tables.
 *
 * @return void
 */
function senroflux_activate(): void {
	// Multisite is refused (0.3 S2). Checked inline as well as through the
	// guard class so a broken autoloader cannot let activation through.
	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		if ( class_exists( MultisiteGuard::class ) ) {
			MultisiteGuard::refuse_activation( __FILE__ );
		}
		wp_die( esc_html__( 'SenroFlux does not support WordPress multisite. It was not activated.', 'senroflux' ) );
	}

	if ( ! class_exists( Schema::class ) || ! function_exists( 'dbDelta' ) ) {
		return;
	}

	global $wpdb;
	if ( isset( $wpdb ) ) {
		Schema::maybe_upgrade( $wpdb );
	}

	// 0.3 S10: no redirect, no site-wide notice — one one-time notice on the
	// Plugins screen only, rendered by RunsScreen::maybeRenderActivationNotice()
	// and cleared the first time it shows.
	if ( function_exists( 'set_transient' ) ) {
		set_transient( \Specflux\SenroFlux\Admin\RunsScreen::ACTIVATION_NOTICE_TRANSIENT, 1, WEEK_IN_SECONDS );
	}
}

// Runtime autoloader for THIS PLUGIN'S OWN CLASSES ONLY (S2): deliberately a
// minimal spl_autoloader rather than Composer's vendor/autoload.php, because
// a dev install would otherwise shadow WordPress core's bundled AI Client SDK
// with the composer copy — a cross-version mix that fatals inside core's own
// glue (observed live: PromptBuilder vs core event-dispatcher mismatch).
// Dev tooling (phpcs/phpstan/phpunit) keeps using vendor/autoload.php.
if ( ! class_exists( Plugin::class ) ) {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( ! str_starts_with( $class_name, 'Specflux\\SenroFlux\\' ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( 'Specflux\\SenroFlux\\' ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

require_once __DIR__ . '/src/api.php';

/**
 * Register the packs and their Agent Safety governance BEFORE Agent Safety's
 * own wiring: it reads `agent_safety_governed_namespaces` and
 * `agent_safety_verb_map` inside its plugins_loaded priority-0 bootstrap, so a
 * pack wired at our boot priority below would never be governed at all.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( Plugin::class ) && ! MultisiteGuard::refused() ) {
			Plugin::instance()->govern();
		}
	},
	-1
);

/**
 * Boot the plugin on plugins_loaded, AFTER Agent Safety's own wiring
 * (priority 0) so its classes exist when we look for them.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Fail safe: without the autoloader there is no Plugin class and no
		// safe way to run anything — say so in wp-admin instead of fataling.
		if ( ! class_exists( Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'SenroFlux is inactive: its autoloader is missing (run `composer install` inside the plugin directory).', 'senroflux' )
					);
				}
			);

			return;
		}

		if ( MultisiteGuard::refused() ) {
			MultisiteGuard::refuse_runtime( __FILE__ );

			return;
		}

		Plugin::instance()->boot();
	},
	5
);
