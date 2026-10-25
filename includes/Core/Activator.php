<?php
declare(strict_types=1);

namespace EGMail\Core;

/**
 * Gestionnaire d'activation et de désactivation du plugin.
 */
final class Activator {

	/**
	 * Déclenché lors de l'activation du plugin.
	 *
	 * @param bool $network_wide Indique si l'activation est multisite globale.
	 */
	public static function activate( bool $network_wide = false ): void {
		// Sera complété en Phase 1 (dbDelta table SQL, spool et crons).
	}

	/**
	 * Déclenché lors de la désactivation du plugin.
	 *
	 * @param bool $network_wide Indique si la désactivation est multisite globale.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		// Sera complété en Phase 1 (nettoyage des crons planifiés).
	}
}
