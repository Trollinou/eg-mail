<?php
/**
 * Contrôleur REST API pour les actions asynchrones de l'administration EG Mail.
 */

declare(strict_types=1);

namespace EGMail\API;

use EGMail\Core\Backup_Integration;
use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Worker;
use EGMail\Repositories\Queue_Repository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Admin_Rest_Controller {

	public const REST_NAMESPACE = 'eg-mail/v1';

	/**
	 * Constructeur avec injection des dépendances.
	 *
	 * @param Queue_Repository   $repository Dépôt de données.
	 * @param Attachment_Spooler $spooler    Gestionnaire de pièces jointes.
	 */
	public function __construct(
		private Queue_Repository $repository,
		private Attachment_Spooler $spooler
	) {}

	/**
	 * Enregistre les routes REST du plugin sur rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/batches/(?P<batch_id>[a-zA-Z0-9_-]+)/pause',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_pause_batch' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/batches/(?P<batch_id>[a-zA-Z0-9_-]+)/resume',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_resume_batch' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/batches/(?P<batch_id>[a-zA-Z0-9_-]+)/cancel',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_cancel_batch' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/batches/(?P<batch_id>[a-zA-Z0-9_-]+)/force-day',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_force_day' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/batches/(?P<batch_id>[a-zA-Z0-9_-]+)/preview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_preview_batch' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/queue/purge-pending',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_purge_pending' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pra/reconcile',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_reconcile_pra' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * Vérifie les autorisations de l'administrateur.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Met en pause les e-mails en attente d'un lot.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Requête REST.
	 */
	public function handle_pause_batch( WP_REST_Request $request ): WP_REST_Response {
		$batch_id = sanitize_key( (string) $request->get_param( 'batch_id' ) );
		$paused   = $this->repository->pause_batch( $batch_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'paused'  => $paused,
				'message' => sprintf(
					/* translators: %d: nombre de messages mis en pause */
					__( '%d message(s) mis en pause.', 'eg-mail' ),
					$paused
				),
			]
		);
	}

	/**
	 * Reprend un lot mis en pause.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Requête REST.
	 */
	public function handle_resume_batch( WP_REST_Request $request ): WP_REST_Response {
		$batch_id = sanitize_key( (string) $request->get_param( 'batch_id' ) );
		$resumed  = $this->repository->resume_batch( $batch_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'resumed' => $resumed,
				'message' => sprintf(
					/* translators: %d: nombre de messages réactivés */
					__( '%d message(s) réactivés pour envoi.', 'eg-mail' ),
					$resumed
				),
			]
		);
	}

	/**
	 * Annule et purge les e-mails non envoyés d'un lot.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Requête REST.
	 */
	public function handle_cancel_batch( WP_REST_Request $request ): WP_REST_Response {
		$batch_id = sanitize_key( (string) $request->get_param( 'batch_id' ) );

		// 1. Coupe-circuit d'urgence : enregistre le batch_id pour stopper net le Worker s'il est actif.
		Worker::abort_batch( $batch_id );

		// 2. Suppression SQL des messages pending et paused.
		$deleted = $this->repository->cancel_batch( $batch_id );

		// 3. Garbage Collector sur les pièces jointes orphelines.
		$active_paths = $this->repository->get_active_attachment_paths();
		$this->spooler->clean_orphaned_attachments( $active_paths );

		return new WP_REST_Response(
			[
				'success' => true,
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: nombre de messages annulés */
					__( '%d message(s) annulés et purgés de la file.', 'eg-mail' ),
					$deleted
				),
			]
		);
	}

	/**
	 * Force l'envoi diurne d'un lot nocturne.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Requête REST.
	 */
	public function handle_force_day( WP_REST_Request $request ): WP_REST_Response {
		$batch_id = sanitize_key( (string) $request->get_param( 'batch_id' ) );
		$updated  = $this->repository->force_batch_day( $batch_id );

		return new WP_REST_Response(
			[
				'success' => true,
				'updated' => $updated,
				'message' => sprintf(
					/* translators: %d: nombre de messages avancés en diurne */
					__( '%d message(s) avancés pour envoi diurne immédiat.', 'eg-mail' ),
					$updated
				),
			]
		);
	}

	/**
	 * Retourne le contenu et les pièces jointes d'un message échantillon du lot.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Requête REST.
	 */
	public function handle_preview_batch( WP_REST_Request $request ): WP_REST_Response {
		$batch_id = sanitize_key( (string) $request->get_param( 'batch_id' ) );
		$sample   = $this->repository->get_first_by_batch_id( $batch_id );

		if ( null === $sample ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Lot introuvable.', 'eg-mail' ),
				],
				404
			);
		}

		$attachments = [];
		foreach ( $sample->attachments as $path ) {
			$attachments[] = [
				'filename' => basename( $path ),
				'exists'   => file_exists( $path ),
				'size'     => file_exists( $path ) ? (int) filesize( $path ) : 0,
			];
		}

		return new WP_REST_Response(
			[
				'success'     => true,
				'batch_id'    => $sample->batch_id,
				'subject'     => $sample->subject,
				'message'     => $sample->message,
				'priority'    => $sample->priority->value,
				'created_at'  => $sample->created_at,
				'attachments' => $attachments,
			]
		);
	}

	/**
	 * Purge tous les messages en attente de toute la file d'attente.
	 */
	public function handle_purge_pending(): WP_REST_Response {
		$purged = $this->repository->purge_all_pending();

		$active_paths = $this->repository->get_active_attachment_paths();
		$this->spooler->clean_orphaned_attachments( $active_paths );

		return new WP_REST_Response(
			[
				'success' => true,
				'purged'  => $purged,
				'message' => sprintf(
					/* translators: %d: nombre total de messages purgés */
					__( 'Toute la file d\'attente a été vidée (%d messages purgés).', 'eg-mail' ),
					$purged
				),
			]
		);
	}

	/**
	 * Déclenche la réconciliation PRA depuis le snapshot disque.
	 */
	public function handle_reconcile_pra(): WP_REST_Response {
		$restored = Backup_Integration::reconcile_missing_batches( $this->repository );

		return new WP_REST_Response(
			[
				'success'  => true,
				'restored' => $restored,
				'message'  => sprintf(
					/* translators: %d: nombre de messages réimportés en pause */
					__( '%d message(s) réimportés depuis le snapshot de secours (en statut "En pause").', 'eg-mail' ),
					$restored
				),
			]
		);
	}
}
