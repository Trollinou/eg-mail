<?php
/**
 * DTO immuable représentant un message dans la file d'attente.
 */

declare(strict_types=1);

namespace EGMail\DTO;

use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;

readonly class Queue_Item {

	/**
	 * Constructeur avec promotion de propriétés.
	 *
	 * @param ?int                 $id           Identifiant unique SQL (null si pas encore inséré).
	 * @param string               $batch_id     Identifiant de lot/campagne.
	 * @param string               $dedup_hash   Empreinte anti-doublon.
	 * @param Queue_Status         $status       Statut actuel du message.
	 * @param Queue_Priority       $priority     Niveau de priorité.
	 * @param string               $recipient    Adresse e-mail unique du destinataire.
	 * @param string               $subject      Sujet du message.
	 * @param string               $message      Corps du message (texte ou HTML).
	 * @param array<int, string>   $headers      En-têtes bruts du message.
	 * @param array<int, string>   $attachments  Chemins des pièces jointes spooled.
	 * @param Context_Meta         $context_meta Métadonnées capturées de WordPress.
	 * @param int                  $attempts     Nombre de tentatives effectuées.
	 * @param int                  $max_attempts Nombre maximal d'essais autorisés.
	 * @param ?string              $last_error   Dernier message d'erreur SMTP retourné.
	 * @param string               $created_at   Date de création (format Y-m-d H:i:s).
	 * @param string               $scheduled_at Date d'éligibilité pour l'envoi.
	 * @param ?string              $sent_at      Date d'expédition effective.
	 */
	public function __construct(
		public ?int $id,
		public string $batch_id,
		public string $dedup_hash,
		public Queue_Status $status,
		public Queue_Priority $priority,
		public string $recipient,
		public string $subject,
		public string $message,
		public array $headers,
		public array $attachments,
		public Context_Meta $context_meta,
		public int $attempts = 0,
		public int $max_attempts = 3,
		public ?string $last_error = null,
		public string $created_at = '',
		public string $scheduled_at = '',
		public ?string $sent_at = null,
	) {}

	/**
	 * Hydrate le DTO depuis une ligne brute issue de la base de données.
	 *
	 * @param object{
	 *     id: int|string,
	 *     batch_id: string,
	 *     dedup_hash: string,
	 *     status: string,
	 *     priority: int|string,
	 *     recipient: string,
	 *     subject: string,
	 *     message: string,
	 *     headers?: ?string,
	 *     attachments?: ?string,
	 *     context_meta?: ?string,
	 *     attempts: int|string,
	 *     max_attempts: int|string,
	 *     last_error?: ?string,
	 *     created_at: string,
	 *     scheduled_at: string,
	 *     sent_at?: ?string
	 * } $row Ligne SQL retournée par $wpdb.
	 */
	public static function from_db_row( object $row ): self {
		$headers_raw = ! empty( $row->headers ) ? json_decode( $row->headers, true ) : [];
		$headers     = is_array( $headers_raw ) ? array_values( array_filter( $headers_raw, 'is_string' ) ) : [];

		$attachments_raw = ! empty( $row->attachments ) ? json_decode( $row->attachments, true ) : [];
		$attachments     = is_array( $attachments_raw ) ? array_values( array_filter( $attachments_raw, 'is_string' ) ) : [];

		$status = Queue_Status::tryFrom( $row->status ) ?? Queue_Status::PENDING;

		$priority_int = (int) $row->priority;
		$priority     = Queue_Priority::tryFrom( $priority_int ) ?? Queue_Priority::NORMAL;

		return new self(
			id: (int) $row->id,
			batch_id: $row->batch_id,
			dedup_hash: $row->dedup_hash,
			status: $status,
			priority: $priority,
			recipient: $row->recipient,
			subject: $row->subject,
			message: $row->message,
			headers: $headers,
			attachments: $attachments,
			context_meta: Context_Meta::from_json( $row->context_meta ?? null ),
			attempts: (int) $row->attempts,
			max_attempts: (int) $row->max_attempts,
			last_error: ! empty( $row->last_error ) ? $row->last_error : null,
			created_at: $row->created_at,
			scheduled_at: $row->scheduled_at,
			sent_at: ! empty( $row->sent_at ) ? $row->sent_at : null,
		);
	}

	/**
	 * Convertit le DTO en tableau pour insertion ou mise à jour $wpdb.
	 *
	 * @return array<string, mixed>
	 */
	public function to_db_array(): array {
		$headers_json     = ! empty( $this->headers ) ? wp_json_encode( $this->headers ) : null;
		$attachments_json = ! empty( $this->attachments ) ? wp_json_encode( $this->attachments ) : null;

		return [
			'batch_id'     => $this->batch_id,
			'dedup_hash'   => $this->dedup_hash,
			'status'       => $this->status->value,
			'priority'     => $this->priority->value,
			'recipient'    => $this->recipient,
			'subject'      => $this->subject,
			'message'      => $this->message,
			'headers'      => is_string( $headers_json ) ? $headers_json : null,
			'attachments'  => is_string( $attachments_json ) ? $attachments_json : null,
			'context_meta' => $this->context_meta->to_json(),
			'attempts'     => $this->attempts,
			'max_attempts' => $this->max_attempts,
			'last_error'   => $this->last_error,
			'created_at'   => $this->created_at,
			'scheduled_at' => $this->scheduled_at,
			'sent_at'      => $this->sent_at,
		];
	}
}
