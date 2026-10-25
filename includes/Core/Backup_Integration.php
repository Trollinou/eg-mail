<?php
/**
 * Intégration avec EG Backup & Restore et gestion de la résilience (PRA) via snapshot JSON.
 */

declare(strict_types=1);

namespace EGMail\Core;

use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use EGMail\Repositories\Queue_Repository;

final class Backup_Integration {

	/**
	 * Initialise les hooks d'intégration avec les sauvegardes WordPress.
	 */
	public static function init(): void {
		add_filter( 'eg_backup_registered_locations', [ self::class, 'register_spool_location' ] );
	}

	/**
	 * Déclare l'emplacement du spool auprès de EG Backup & Restore.
	 *
	 * @param array<string, mixed> $locations Emplacements déjà enregistrés.
	 * @return array<string, mixed>
	 */
	public static function register_spool_location( array $locations ): array {
		$upload_dir = wp_upload_dir();
		$spool_path = $upload_dir['basedir'] . '/eg-mail-spool';

		$locations['eg_mail_spool'] = [
			'id'          => 'eg_mail_spool',
			'label'       => __( 'File d\'attente E-mails & Pièces jointes en transit (Spool)', 'eg-mail' ),
			'plugin_name' => 'EG Mail',
			'path'        => $spool_path,
			'category'    => 'documents',
			'recommended' => true,
			'description' => __( 'Pièces jointes dédupliquées des e-mails en file d\'attente et snapshot JSON de secours des envois programmés.', 'eg-mail' ),
			'exclude'     => [
				'temp',
				'*.tmp',
				'*.lock',
			],
		];

		return $locations;
	}

	/**
	 * Retourne le chemin absolu du fichier de snapshot sur disque.
	 */
	public static function get_snapshot_path(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/eg-mail-spool/queue-snapshot.json';
	}

	/**
	 * Écrit de manière atomique l'état actuel de la file non terminée sur disque.
	 *
	 * @param array<int, Queue_Item> $active_items Messages actifs à sérialiser.
	 * @return bool Vrai si l'écriture et le renommage ont réussi.
	 */
	public static function write_snapshot( array $active_items ): bool {
		$snapshot_file = self::get_snapshot_path();
		$spool_dir     = dirname( $snapshot_file );

		if ( ! is_dir( $spool_dir ) ) {
			wp_mkdir_p( $spool_dir );
			Activator::ensure_spool_protection( $spool_dir );
		}

		$data = [];
		foreach ( $active_items as $item ) {
			$data[] = [
				'batch_id'     => $item->batch_id,
				'dedup_hash'   => $item->dedup_hash,
				'status'       => $item->status->value,
				'priority'     => $item->priority->value,
				'recipient'    => $item->recipient,
				'subject'      => $item->subject,
				'message'      => $item->message,
				'headers'      => $item->headers,
				'attachments'  => $item->attachments,
				'context_meta' => $item->context_meta->to_array(),
				'created_at'   => $item->created_at,
				'scheduled_at' => $item->scheduled_at,
			];
		}

		$payload = [
			'generated_at' => current_time( 'mysql' ),
			'total_items'  => count( $data ),
			'items'        => $data,
		];

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT );
		if ( ! is_string( $json ) ) {
			return false;
		}

		// Écriture atomique via fichier temporaire .tmp pour empêcher toute corruption pendant une sauvegarde.
		$tmp_file = $snapshot_file . '.' . uniqid( 'snap_', true ) . '.tmp';
		$written  = file_put_contents( $tmp_file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $written ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Renommage atomique local requis pour la résilience snapshot.
		return rename( $tmp_file, $snapshot_file );
	}

	/**
	 * Lit le contenu du snapshot JSON depuis le disque.
	 *
	 * @return ?array<string, mixed> Données du snapshot ou null si inexistant.
	 */
	public static function read_snapshot(): ?array {
		$snapshot_file = self::get_snapshot_path();
		if ( ! file_exists( $snapshot_file ) || ! is_readable( $snapshot_file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lecture de fichier local de snapshot sur disque.
		$content = file_get_contents( $snapshot_file );
		if ( false === $content ) {
			return null;
		}

		$data = json_decode( $content, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Réconcilie la base SQL après une restauration disque en réimportant les messages orphelins en statut 'paused'.
	 *
	 * @param Queue_Repository $repository Dépôt pour réinsertion.
	 * @return int Nombre de messages restaurés.
	 */
	public static function reconcile_missing_batches( Queue_Repository $repository ): int {
		$snapshot = self::read_snapshot();
		if ( null === $snapshot || empty( $snapshot['items'] ) || ! is_array( $snapshot['items'] ) ) {
			return 0;
		}

		$restored_count = 0;
		$now            = current_time( 'mysql' );

		foreach ( $snapshot['items'] as $item_data ) {
			if ( ! is_array( $item_data ) || empty( $item_data['batch_id'] ) || empty( $item_data['recipient'] ) ) {
				continue;
			}

			// Création d'un Queue_Item avec statut 'paused' par sécurité pour revue admin.
			$priority_int = isset( $item_data['priority'] ) && is_int( $item_data['priority'] ) ? $item_data['priority'] : 10;
			$priority     = Queue_Priority::tryFrom( $priority_int ) ?? Queue_Priority::NORMAL;

			$context_data = isset( $item_data['context_meta'] ) && is_array( $item_data['context_meta'] ) ? $item_data['context_meta'] : [];
			/** @var array<string, mixed> $context_data */
			$context_meta = Context_Meta::from_array( $context_data );

			$headers_raw     = isset( $item_data['headers'] ) && is_array( $item_data['headers'] ) ? $item_data['headers'] : [];
			$headers         = array_values( array_filter( $headers_raw, 'is_string' ) );
			$attachments_raw = isset( $item_data['attachments'] ) && is_array( $item_data['attachments'] ) ? $item_data['attachments'] : [];
			$attachments     = array_values( array_filter( $attachments_raw, 'is_string' ) );

			$item = new Queue_Item(
				id: null,
				batch_id: (string) $item_data['batch_id'],
				dedup_hash: (string) ( $item_data['dedup_hash'] ?? md5( (string) $item_data['recipient'] . '|' . (string) ( $item_data['subject'] ?? '' ) ) ),
				status: Queue_Status::PAUSED,
				priority: $priority,
				recipient: (string) $item_data['recipient'],
				subject: (string) ( $item_data['subject'] ?? '' ),
				message: (string) ( $item_data['message'] ?? '' ),
				headers: $headers,
				attachments: $attachments,
				context_meta: $context_meta,
				attempts: 0,
				max_attempts: 3,
				last_error: null,
				created_at: (string) ( $item_data['created_at'] ?? $now ),
				scheduled_at: (string) ( $item_data['scheduled_at'] ?? $now ),
				sent_at: null,
			);

			$insert_id = $repository->insert( $item );
			if ( $insert_id > 0 ) {
				++$restored_count;
			}
		}

		return $restored_count;
	}
}
