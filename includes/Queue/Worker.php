<?php
/**
 * Worker d'arrière-plan exécuté chaque minute pour dépiler et expédier les e-mails régulés.
 */

declare(strict_types=1);

namespace EGMail\Queue;

use EGMail\Core\Backup_Integration;
use EGMail\Core\Settings;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Status;
use EGMail\Repositories\Queue_Repository;
use WP_Error;

class Worker {

	public const MUTEX_TRANSIENT = 'eg_mail_worker_lock';

	public const ABORTED_BATCHES_TRANSIENT = 'eg_mail_aborted_batches';

	/**
	 * Constructeur avec injection des dépendances.
	 *
	 * @param Queue_Repository   $repository Dépôt pour les opérations SQL.
	 * @param Attachment_Spooler $spooler    Spooler de pièces jointes.
	 * @param Settings           $settings   Gestionnaire de configuration.
	 */
	public function __construct(
		private Queue_Repository $repository,
		private Attachment_Spooler $spooler,
		private Settings $settings
	) {}

	/**
	 * Initialise l'écouteur sur l'événement WP-Cron récurrent.
	 */
	public function init(): void {
		add_action( 'eg_mail_cron_worker', [ $this, 'process_queue' ] );
	}

	/**
	 * Point d'entrée de l'action WP-Cron (signature void exigée par WordPress).
	 */
	public function process_queue(): void {
		$this->run();
	}

	/**
	 * Déclenche un cycle de dépilement et d'envoi.
	 *
	 * @return int Nombre d'e-mails envoyés avec succès durant ce cycle.
	 */
	public function run(): int {
		// 1. Verrouillage Mutex anti-concurrence (empêche deux Crons simultanés).
		if ( ! $this->acquire_lock() ) {
			return 0;
		}

		$sent_count = 0;

		try {
			// 2. Calcul du quota effectif (croisement du quota minute et du reliquat horaire).
			$sent_last_hour  = $this->repository->count_sent_in_last_hour();
			$effective_limit = $this->settings->calculate_effective_quota( $sent_last_hour );

			if ( $effective_limit <= 0 ) {
				return 0;
			}

			// 3. Récupération des messages éligibles.
			$is_night        = $this->settings->is_night_time();
			$allow_nocturnal = $is_night; // Bloque les messages >= 20 si le jour s'est levé.
			$items           = $this->repository->fetch_eligible_batch( $effective_limit, $allow_nocturnal );

			if ( empty( $items ) ) {
				return 0;
			}

			// 4. Boucle d'envoi des messages sélectionnés.
			$sent_count = $this->dispatch_items( $items );

			// 5. Nettoyage des pièces jointes orphelines du spool (Garbage Collector).
			$active_paths = $this->repository->get_active_attachment_paths();
			$this->spooler->clean_orphaned_attachments( $active_paths );

			// 6. Mise à jour de sécurité du snapshot miroir sur disque.
			$remaining_items = $this->repository->fetch_eligible_batch( 100, true );
			Backup_Integration::write_snapshot( $remaining_items );

		} finally {
			$this->release_lock();
		}

		return $sent_count;
	}

	/**
	 * Itère sur le lot d'e-mails et gère le coupe-circuit temps réel intra-boucle.
	 *
	 * @param array<int, Queue_Item> $items Lot d'e-mails à envoyer.
	 * @return int Nombre d'e-mails expédiés avec succès.
	 */
	private function dispatch_items( array $items ): int {
		$sent_count                     = 0;
		Interceptor::$is_worker_sending = true;

		try {
			foreach ( $items as $item ) {
				if ( null === $item->id ) {
					continue;
				}

				// Vérification coupe-circuit temps réel : si le lot a été révoqué depuis l'admin.
				if ( $this->is_batch_aborted( $item->batch_id ) ) {
					continue;
				}

				// Vérification de sécurité en base : si le statut n'est plus pending (mis en pause entre-temps).
				$current = $this->repository->get_by_id( $item->id );
				if ( null === $current || Queue_Status::PENDING !== $current->status ) {
					continue;
				}

				// Passage en statut processing.
				$this->repository->update_status( $item->id, Queue_Status::PROCESSING );

				// Envoi unitaire avec capture d'erreur fine.
				$success = $this->send_single_item( $item );
				if ( $success ) {
					++$sent_count;
				}
			}
		} finally {
			Interceptor::$is_worker_sending = false;
		}

		return $sent_count;
	}

	/**
	 * Expédie un message unitaire et applique le backoff exponentiel en cas d'erreur.
	 *
	 * @param Queue_Item $item Message à envoyer.
	 * @return bool Vrai si envoyé avec succès.
	 */
	private function send_single_item( Queue_Item $item ): bool {
		if ( null === $item->id ) {
			return false;
		}

		// Application temporaire des filtres de contexte capturés à l'origine.
		$remove_filters = $this->apply_context_filters( $item );

		$captured_error = null;
		$error_listener = static function ( WP_Error $error ) use ( &$captured_error ): void {
			$captured_error = $error;
		};
		add_action( 'wp_mail_failed', $error_listener );

		// Envoi effectif via wp_mail (non intercepté grâce à $is_worker_sending = true).
		$sent = wp_mail(
			$item->recipient,
			$item->subject,
			$item->message,
			$item->headers,
			$item->attachments
		);

		remove_action( 'wp_mail_failed', $error_listener );
		$remove_filters();

		if ( true === $sent ) {
			$this->repository->update_status(
				$item->id,
				Queue_Status::SENT,
				[ 'sent_at' => current_time( 'mysql' ) ]
			);
			return true;
		}

		// Gestion de l'échec et backoff exponentiel.
		$error_message = $captured_error instanceof WP_Error
			? $captured_error->get_error_message()
			: __( 'Erreur SMTP indéterminée lors de l\'envoi.', 'eg-mail' );

		$attempts = $item->attempts + 1;
		if ( $attempts >= $item->max_attempts ) {
			$this->repository->update_status(
				$item->id,
				Queue_Status::FAILED,
				[
					'attempts'   => $attempts,
					'last_error' => $error_message,
				]
			);
		} else {
			$delay_seconds    = Settings::calculate_backoff_delay( $attempts );
			$new_scheduled_at = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );

			$this->repository->update_status(
				$item->id,
				Queue_Status::PENDING,
				[
					'attempts'     => $attempts,
					'last_error'   => $error_message,
					'scheduled_at' => $new_scheduled_at,
				]
			);
		}

		return false;
	}

	/**
	 * Applique les filtres de contexte WordPress pour l'e-mail et retourne un callback de nettoyage.
	 *
	 * @param Queue_Item $item Message contenant le contexte.
	 * @return callable(): void Callback pour retirer les filtres après l'envoi.
	 */
	private function apply_context_filters( Queue_Item $item ): callable {
		$callbacks = [];

		if ( null !== $item->context_meta->content_type ) {
			$ct_cb                             = static fn(): string => $item->context_meta->content_type ?? 'text/plain';
			add_filter( 'wp_mail_content_type', $ct_cb );
			$callbacks['wp_mail_content_type'] = $ct_cb;
		}

		if ( null !== $item->context_meta->charset ) {
			$cs_cb                        = static fn(): string => $item->context_meta->charset ?? 'UTF-8';
			add_filter( 'wp_mail_charset', $cs_cb );
			$callbacks['wp_mail_charset'] = $cs_cb;
		}

		if ( null !== $item->context_meta->from_email ) {
			$fe_cb                     = static fn(): string => $item->context_meta->from_email ?? '';
			add_filter( 'wp_mail_from', $fe_cb );
			$callbacks['wp_mail_from'] = $fe_cb;
		}

		if ( null !== $item->context_meta->from_name ) {
			$fn_cb                          = static fn(): string => $item->context_meta->from_name ?? '';
			add_filter( 'wp_mail_from_name', $fn_cb );
			$callbacks['wp_mail_from_name'] = $fn_cb;
		}

		return static function () use ( $callbacks ): void {
			foreach ( $callbacks as $hook => $callback ) {
				remove_filter( $hook, $callback );
			}
		};
	}

	/**
	 * Vérifie si un lot a été annulé en urgence dans le transient de révocation rapide.
	 *
	 * @param string $batch_id Identifiant de lot.
	 */
	public function is_batch_aborted( string $batch_id ): bool {
		$aborted = get_transient( self::ABORTED_BATCHES_TRANSIENT );
		if ( ! is_array( $aborted ) ) {
			return false;
		}

		return in_array( $batch_id, $aborted, true );
	}

	/**
	 * Enregistre un lot dans la liste d'arrêt d'urgence.
	 *
	 * @param string $batch_id Identifiant de lot.
	 */
	public static function abort_batch( string $batch_id ): void {
		$aborted = get_transient( self::ABORTED_BATCHES_TRANSIENT );
		if ( ! is_array( $aborted ) ) {
			$aborted = [];
		}

		if ( ! in_array( $batch_id, $aborted, true ) ) {
			$aborted[] = $batch_id;
			set_transient( self::ABORTED_BATCHES_TRANSIENT, $aborted, 3600 );
		}
	}

	/**
	 * Tente de poser le verrou Mutex d'exécution du Worker.
	 */
	public function acquire_lock(): bool {
		if ( false !== get_transient( self::MUTEX_TRANSIENT ) ) {
			return false;
		}

		return set_transient( self::MUTEX_TRANSIENT, time(), 55 );
	}

	/**
	 * Libère le verrou Mutex.
	 */
	public function release_lock(): void {
		delete_transient( self::MUTEX_TRANSIENT );
	}
}
