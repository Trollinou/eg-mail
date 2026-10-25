<?php
/**
 * Gestionnaire d'activation, de migration et de désactivation du plugin.
 */

declare(strict_types=1);

namespace EGMail\Core;

final class Activator {

	/**
	 * Déclenché lors de l'activation du plugin.
	 *
	 * @param bool $network_wide Indique si l'activation est multisite globale.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			/** @var array<int, int|string> $site_ids */
			$site_ids = get_sites( [ 'fields' => 'ids' ] );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_for_current_blog();
				restore_current_blog();
			}
			return;
		}

		self::install_for_current_blog();
	}

	/**
	 * Déclenché lors de la désactivation du plugin.
	 *
	 * @param bool $network_wide Indique si la désactivation est multisite globale.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			/** @var array<int, int|string> $site_ids */
			$site_ids = get_sites( [ 'fields' => 'ids' ] );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_for_current_blog();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_for_current_blog();
	}

	/**
	 * Désactive les tâches crons et verrous pour le blog courant.
	 */
	public static function deactivate_for_current_blog(): void {
		wp_clear_scheduled_hook( 'eg_mail_cron_worker' );
		wp_clear_scheduled_hook( 'eg_mail_cron_cleaner' );
		delete_transient( 'eg_mail_worker_lock' );
	}

	/**
	 * Exécute l'installation des tables, du dossier de spool et des crons pour le blog courant.
	 */
	public static function install_for_current_blog(): void {
		self::create_tables();
		self::init_spool_directory();
		self::schedule_crons();
	}

	/**
	 * Crée ou met à jour la table SQL de la file d'attente via dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'eg_mail_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch_id char(32) NOT NULL,
			dedup_hash char(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			priority tinyint(3) unsigned NOT NULL DEFAULT 10,
			recipient varchar(255) NOT NULL,
			subject text NOT NULL,
			message longtext NOT NULL,
			headers longtext NULL,
			attachments longtext NULL,
			context_meta longtext NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
			last_error text NULL,
			created_at datetime NOT NULL,
			scheduled_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			KEY idx_worker_fetch (status, scheduled_at, priority, id),
			KEY idx_hourly_quota (status, sent_at),
			KEY idx_batch (batch_id, status),
			KEY idx_dedup (dedup_hash, status, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Initialise le dossier de spool sécurisé et les protections d'accès.
	 */
	public static function init_spool_directory(): void {
		$upload_dir = wp_upload_dir();
		$spool_dir  = $upload_dir['basedir'] . '/eg-mail-spool';

		if ( ! is_dir( $spool_dir ) ) {
			wp_mkdir_p( $spool_dir );
		}

		self::ensure_spool_protection( $spool_dir );
	}

	/**
	 * Garantit l'existence des fichiers de verrouillage dans le spool (.htaccess et index.php).
	 *
	 * @param string $spool_dir Chemin absolu vers le dossier de spool.
	 */
	public static function ensure_spool_protection( string $spool_dir ): void {
		$htaccess_file = $spool_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content = "# Bloquer l'accès direct aux pièces jointes temporaires\n"
				. "<IfModule mod_authz_core.c>\n"
				. "    Require all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "    Deny from all\n"
				. "</IfModule>\n";
			file_put_contents( $htaccess_file, $htaccess_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index_file = $spool_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Planifie les tâches récurrentes WP-Cron du plugin.
	 */
	public static function schedule_crons(): void {
		// Enregistrer l'intervalle personnalisé immédiatement pour la transaction d'activation.
		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Intervalle 1 minute indispensable pour le worker.
			static function ( array $schedules ): array {
				if ( ! isset( $schedules['eg_mail_every_minute'] ) ) {
					// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Intervalle 1 minute indispensable pour le worker.
					$schedules['eg_mail_every_minute'] = [
						'interval' => 60,
						'display'  => __( 'Toutes les minutes (EG Mail Worker)', 'eg-mail' ),
					];
				}
				return $schedules;
			}
		);

		if ( ! wp_next_scheduled( 'eg_mail_cron_worker' ) ) {
			wp_schedule_event( time(), 'eg_mail_every_minute', 'eg_mail_cron_worker' );
		}

		if ( ! wp_next_scheduled( 'eg_mail_cron_cleaner' ) ) {
			wp_schedule_event( time(), 'daily', 'eg_mail_cron_cleaner' );
		}
	}
}
