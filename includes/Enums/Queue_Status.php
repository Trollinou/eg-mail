<?php
/**
 * Énumération des statuts d'un e-mail dans la file d'attente.
 */

declare(strict_types=1);

namespace EGMail\Enums;

enum Queue_Status: string {
	case PENDING    = 'pending';
	case PROCESSING = 'processing';
	case PAUSED     = 'paused';
	case SENT       = 'sent';
	case FAILED     = 'failed';
	case CANCELLED  = 'cancelled';

	/**
	 * Retourne le libellé en français pour l'administration.
	 */
	public function label(): string {
		return match ( $this ) {
			self::PENDING    => __( 'En attente', 'eg-mail' ),
			self::PROCESSING => __( 'En cours d\'envoi', 'eg-mail' ),
			self::PAUSED     => __( 'En pause', 'eg-mail' ),
			self::SENT       => __( 'Envoyé', 'eg-mail' ),
			self::FAILED     => __( 'Échoué', 'eg-mail' ),
			self::CANCELLED  => __( 'Annulé', 'eg-mail' ),
		};
	}

	/**
	 * Indique si le statut est terminal (aucun traitement ultérieur).
	 */
	public function is_terminal(): bool {
		return match ( $this ) {
			self::SENT, self::FAILED, self::CANCELLED => true,
			default                                  => false,
		};
	}

	/**
	 * Indique si le message peut être annulé ou mis en pause.
	 */
	public function can_be_cancelled(): bool {
		return match ( $this ) {
			self::PENDING, self::PAUSED => true,
			default                     => false,
		};
	}
}
