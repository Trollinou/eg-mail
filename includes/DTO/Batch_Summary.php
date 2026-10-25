<?php
/**
 * DTO immuable représentant la synthèse agrégée d'un lot de messages.
 */

declare(strict_types=1);

namespace EGMail\DTO;

use EGMail\Enums\Queue_Priority;

readonly class Batch_Summary {

	/**
	 * Constructeur avec promotion de propriétés.
	 *
	 * @param string         $batch_id      Identifiant de lot.
	 * @param string         $subject       Sujet du message de la campagne.
	 * @param Queue_Priority $priority      Niveau de priorité assigné.
	 * @param string         $scheduled_at  Date de départ programmée.
	 * @param string         $created_at    Date de création du premier message.
	 * @param int            $total_count   Nombre total de destinataires dans le lot.
	 * @param int            $sent_count    Nombre d'e-mails envoyés avec succès.
	 * @param int            $pending_count Nombre d'e-mails en attente d'expédition.
	 * @param int            $paused_count  Nombre d'e-mails en pause.
	 * @param int            $failed_count  Nombre d'e-mails en échec définitif.
	 */
	public function __construct(
		public string $batch_id,
		public string $subject,
		public Queue_Priority $priority,
		public string $scheduled_at,
		public string $created_at,
		public int $total_count,
		public int $sent_count,
		public int $pending_count,
		public int $paused_count,
		public int $failed_count,
	) {}

	/**
	 * Calcule le pourcentage de progression (0 à 100).
	 */
	public function progress_percentage(): float {
		if ( $this->total_count <= 0 ) {
			return 100.0;
		}

		$processed = $this->sent_count + $this->failed_count;
		return round( ( $processed / $this->total_count ) * 100, 1 );
	}

	/**
	 * Indique si tous les messages du lot ont été traités.
	 */
	public function is_finished(): bool {
		return $this->pending_count === 0 && $this->paused_count === 0;
	}

	/**
	 * Indique si le lot a encore des messages en attente active.
	 */
	public function has_pending(): bool {
		return $this->pending_count > 0;
	}

	/**
	 * Hydrate le DTO depuis le résultat d'une requête SQL d'agrégation.
	 *
	 * @param object{
	 *     batch_id: string,
	 *     subject: string,
	 *     priority: int|string,
	 *     scheduled_at: string,
	 *     created_at: string,
	 *     total_count: int|string,
	 *     sent_count: int|string,
	 *     pending_count: int|string,
	 *     paused_count: int|string,
	 *     failed_count: int|string
	 * } $row Ligne SQL retournée par la requête GROUP BY batch_id.
	 */
	public static function from_db_row( object $row ): self {
		$priority_int = (int) $row->priority;
		$priority     = Queue_Priority::tryFrom( $priority_int ) ?? Queue_Priority::NORMAL;

		return new self(
			batch_id: $row->batch_id,
			subject: $row->subject,
			priority: $priority,
			scheduled_at: $row->scheduled_at,
			created_at: $row->created_at,
			total_count: (int) $row->total_count,
			sent_count: (int) $row->sent_count,
			pending_count: (int) $row->pending_count,
			paused_count: (int) $row->paused_count,
			failed_count: (int) $row->failed_count,
		);
	}
}
