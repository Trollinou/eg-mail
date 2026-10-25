<?php
/**
 * Orchestrateur central et conteneur de services du plugin EG Mail.
 */

declare(strict_types=1);

namespace EGMail\Core;

use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Interceptor;
use EGMail\Repositories\Queue_Repository;

final class Plugin {

	private static ?self $instance = null;

	private Queue_Repository $repository;

	private Attachment_Spooler $spooler;

	private Interceptor $interceptor;

	/**
	 * Constructeur privé (Singleton).
	 */
	private function __construct() {
		$this->repository  = new Queue_Repository();
		$this->spooler     = new Attachment_Spooler();
		$this->interceptor = new Interceptor( $this->repository, $this->spooler );
	}

	/**
	 * Accès à l'instance unique du plugin.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialise les composants et écouteurs d'événements du plugin.
	 */
	public function init(): void {
		// 1. Initialisation de l'intégration avec EG Backup & Restore.
		Backup_Integration::init();

		// 2. Initialisation de l'intercepteur transparent wp_mail().
		$this->interceptor->init();

		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Intervalle 1 minute indispensable pour le dépilement réactif.
		add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] );
	}

	/**
	 * Ajoute un intervalle d'une minute aux plannings WP-Cron.
	 *
	 * @param array<string, array<string, mixed>> $schedules Plannings existants.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['eg_mail_every_minute'] ) ) {
			// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Intervalle 1 minute indispensable pour le worker d'e-mails.
			$schedules['eg_mail_every_minute'] = [
				'interval' => 60,
				'display'  => __( 'Toutes les minutes (EG Mail Worker)', 'eg-mail' ),
			];
		}
		return $schedules;
	}

	/**
	 * Accès au dépôt de la file d'attente.
	 */
	public function get_repository(): Queue_Repository {
		return $this->repository;
	}

	/**
	 * Accès au spooler de pièces jointes.
	 */
	public function get_spooler(): Attachment_Spooler {
		return $this->spooler;
	}

	/**
	 * Accès à l'intercepteur.
	 */
	public function get_interceptor(): Interceptor {
		return $this->interceptor;
	}
}
