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

// Rimuove le opzioni note.
delete_option( 'kc_settings' );
delete_option( 'kc_field_map' );
delete_option( 'kc_keap_model' );
delete_option( 'kc_source' );
delete_option( 'kc_db_version' );
delete_option( 'kc_secrets_migrated' );

// Rimuove i transient noti (a nome fisso).
delete_transient( 'kc_oauth_state' );
delete_transient( 'kc_notify_rate' );
delete_transient( 'kc_auth_fail_logged' );
delete_transient( 'kc_oauth_alert_sent' );
delete_transient( 'kc_show_bearer_initial' );
delete_transient( 'kc_show_cron_initial' );

// Rimuove i transient per-utente (il nome contiene l'ID utente: sweep via SQL).
$transient_like = array(
	'_transient_kc_show_bearer_%',
	'_transient_timeout_kc_show_bearer_%',
	'_transient_kc_show_cron_%',
	'_transient_timeout_kc_show_cron_%',
	'_transient_kc_enc_snippet_%',
	'_transient_timeout_kc_enc_snippet_%',
);
foreach ( $transient_like as $pattern ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
}

// Sweep di sicurezza generico: opzioni e transient con prefisso kc_.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kc\\_%'" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_kc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_kc\\_%'" );

// Rimuove gli artefatti del Plugin Update Checker (slug = nome cartella del plugin).
$kc_slug = basename( dirname( __FILE__ ) );
delete_option( 'external_updates-' . $kc_slug );
delete_site_option( 'external_updates-' . $kc_slug );
delete_site_transient( 'puc_manual_check_errors-' . $kc_slug );
wp_clear_scheduled_hook( 'puc_cron_check_updates-' . $kc_slug );

// Elimina la tabella di log.
$table = $wpdb->prefix . 'kc_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Rimuove gli eventi cron pianificati.
wp_clear_scheduled_hook( 'kc_refresh_oauth' );
