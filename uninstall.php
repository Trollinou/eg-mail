<?php
/**
 * Fichier de désinstallation du plugin EG Mail.
 *
 * Déclenché lors de la suppression définitive du plugin depuis l'administration.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Annulation des tâches récurrentes planifiées WP-Cron.
wp_clear_scheduled_hook( 'eg_mail_cron_worker' );
wp_clear_scheduled_hook( 'eg_mail_cron_cleaner' );

// 2. Nettoyage des options et transients WordPress.
delete_option( 'eg_mail_settings' );
delete_option( 'eg_mail_version' );
delete_transient( 'eg_mail_worker_lock' );
delete_transient( 'eg_mail_aborted_batches' );

// 3. Suppression conditionnelle de la table SQL personnalisée.
global $wpdb;
$table_name = $wpdb->prefix . 'eg_mail_queue';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// 4. Nettoyage du dossier de spool dans uploads.
$upload_dir = wp_upload_dir();
$spool_dir  = $upload_dir['basedir'] . '/eg-mail-spool';

if ( is_dir( $spool_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;

	if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
		$wp_filesystem->delete( $spool_dir, true );
	}
}
