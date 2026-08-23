<?php
/**
 * Plugin Name:       SenroFlux
 * Description:       Resumable, browser-driven multi-step agent runs inside the logged-in WordPress session — Abilities as tools, Agent Safety as a hard-required checkpoint.
 * Version:           0.1.0
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

use Specflux\SenroFlux\Plugin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

// Composer autoloader for this plugin's own classes (dev install). A broken
// or missing autoloader must fail SAFE below, not fatal here.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/src/api.php';

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

		Plugin::instance()->boot();
	},
	5
);
