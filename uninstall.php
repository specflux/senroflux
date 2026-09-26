<?php
/**
 * SenroFlux uninstall: drop runs/steps ONLY when the site opted in.
 *
 * Runs are conversational history someone may want to keep; the Agent Safety
 * audit chain remains the authoritative record of what executed regardless.
 * Deleting data on uninstall therefore requires the explicit
 * senroflux_uninstall_delete_data option.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

// Abort if WordPress didn't call us (direct access or wrong context).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || empty( $GLOBALS['wpdb'] ) ) {
	exit;
}

global $wpdb;

$senroflux_delete_data = get_option( 'senroflux_uninstall_delete_data' );

if ( true !== $senroflux_delete_data && '1' !== $senroflux_delete_data ) {
	// Keep tables + options; the site did not opt into deletion.
	return;
}

foreach ( array( 'senroflux_steps', 'senroflux_runs' ) as $senroflux_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- opt-in uninstall dropping a table this plugin owns; nothing to cache.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $senroflux_table ) );
}

delete_option( 'senroflux_uninstall_delete_data' );
