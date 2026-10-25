<?php
/**
 * Énumération des niveaux de priorité d'un e-mail.
 */

declare(strict_types=1);

namespace EGMail\Enums;

enum Queue_Priority: int {
	case BYPASS   = 0;
	case CRITICAL = 1;
	case NORMAL   = 10;
	case LOW      = 20;

	/**
	 * Retourne le libellé en français pour l'administration.
	 */
	public function label(): string {
		return match ( $this ) {
			self::BYPASS   => __( 'Immédiat (Bypass)', 'eg-mail' ),
			self::CRITICAL => __( 'Critique (Coupe-file)', 'eg-mail' ),
			self::NORMAL   => __( 'Normale', 'eg-mail' ),
			self::LOW      => __( 'Basse / Nuit', 'eg-mail' ),
		};
	}

	/**
	 * Indique si la priorité requiert un routage nocturne.
	 */
	public function is_nocturnal(): bool {
		return $this->value >= self::LOW->value;
	}

	/**
	 * Indique si le message contourne totalement la file d'attente.
	 */
	public function is_bypass(): bool {
		return $this === self::BYPASS;
	}
}
