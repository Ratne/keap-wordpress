<?php
/**
 * Disinstallazione del plugin: rimuove opzioni e tabella di log.
 *
 * @package KeapConnect
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Rimuove le opzioni.
delete_option( 'kc_settings' );
delete_option( 'kc_field_map' );
delete_option( 'kc_keap_model' );
delete_option( 'kc_db_version' );

// Rimuove eventuali transient.
delete_transient( 'kc_oauth_state' );
delete_transient( 'kc_notify_rate' );
delete_transient( 'kc_auth_fail_logged' );
delete_transient( 'kc_oauth_alert_sent' );

// Elimina la tabella di log.
$table = $wpdb->prefix . 'kc_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Rimuove gli eventi cron pianificati.
wp_clear_scheduled_hook( 'kc_refresh_oauth' );
