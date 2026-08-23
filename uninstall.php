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

/** @var wpdb $wpdb */
$wpdb = $GLOBALS['wpdb'];

$delete_data = get_option( 'senroflux_uninstall_delete_data' );

if ( true !== $delete_data && '1' !== $delete_data ) {
	// Keep tables + options; the site did not opt into deletion.
	return;
}

$table_steps = $wpdb->prefix . 'senroflux_steps';
$table_runs  = $wpdb->prefix . 'senroflux_runs';

$wpdb->query( "DROP TABLE IF EXISTS {$table_steps}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
$wpdb->query( "DROP TABLE IF EXISTS {$table_runs}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.

delete_option( 'senroflux_uninstall_delete_data' );
