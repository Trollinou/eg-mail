<?php
/**
 * Tâche quotidienne de nettoyage et de purge des anciens e-mails envoyés.
 */

declare(strict_types=1);

namespace EGMail\Queue;

use EGMail\Core\Backup_Integration;
use EGMail\Core\Settings;
use EGMail\Repositories\Queue_Repository;

class Cleaner {

	/**
	 * Constructeur avec injection des dépendances requises.
	 *
	 * @param Queue_Repository   $repository Dépôt d'accès à la file d'attente.
	 * @param Attachment_Spooler $spooler    Gestionnaire de spool des pièces jointes.
	 * @param Settings           $settings   Gestionnaire de réglages.
	 */
	public function __construct(
		private Queue_Repository $repository,
		private Attachment_Spooler $spooler,
		private Settings $settings
	) {}

	/**
	 * Enregistre les hooks WP-Cron pour la purge quotidienne.
	 */
	public function init(): void {
		add_action( 'eg_mail_cron_cleaner', [ $this, 'purge_scheduled' ] );
	}

	/**
	 * Déclencheur WP-Cron sans valeur de retour pour satisfaire le typage strict des actions WordPress.
	 */
	public function purge_scheduled(): void {
		$this->clean();
	}

	/**
	 * Exécute le cycle complet de nettoyage :
	 * 1. Purge des e-mails envoyés plus anciens que la période de rétention.
	 * 2. Nettoyage physique des pièces jointes orphelines dans le spool.
	 * 3. Reconstruction du snapshot miroir PRA.
	 *
	 * @return int Nombre de messages purgés de la table SQL.
	 */
	public function clean(): int {
		$raw_retention = $this->settings->get( 'retention_days' );
		$days          = is_numeric( $raw_retention ) ? (int) $raw_retention : 15;

		if ( $days < 1 ) {
			$days = 15;
		}

		// 1. Purge des messages envoyés au-delà de la rétention.
		$purged_count = $this->repository->purge_sent( $days );

		// 2. Nettoyage des pièces jointes devenues orphelines.
		$active_paths = $this->repository->get_active_attachment_paths();
		$this->spooler->clean_orphaned_attachments( $active_paths );

		// 3. Mise à jour de sécurité du snapshot miroir PRA sur disque.
		$remaining_items = $this->repository->fetch_eligible_batch( 100, true );
		Backup_Integration::write_snapshot( $remaining_items );

		return $purged_count;
	}
}
