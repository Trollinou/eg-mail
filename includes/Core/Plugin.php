<?php
/**
 * Orchestrateur central et conteneur de services du plugin EG Mail.
 */

declare(strict_types=1);

namespace EGMail\Core;

use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Interceptor;
use EGMail\Queue\Worker;
use EGMail\Repositories\Queue_Repository;

final class Plugin {

	private static ?self $instance = null;

	private Settings $settings;

	private Queue_Repository $repository;

	private Attachment_Spooler $spooler;

	private Interceptor $interceptor;

	private Smtp_Configurator $smtp_configurator;

	private Worker $worker;

	/**
	 * Constructeur privé (Singleton).
	 */
	private function __construct() {
		$this->settings          = new Settings();
		$this->repository        = new Queue_Repository();
		$this->spooler           = new Attachment_Spooler();
		$this->interceptor       = new Interceptor( $this->repository, $this->spooler );
		$this->smtp_configurator = new Smtp_Configurator( $this->settings );
		$this->worker            = new Worker( $this->repository, $this->spooler, $this->settings );
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

		// 3. Initialisation du configurateur SMTP et persistance KeepAlive.
		$this->smtp_configurator->init();

		// 4. Initialisation du Worker d'arrière-plan.
		$this->worker->init();

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
	 * Accès au gestionnaire de paramètres.
	 */
	public function get_settings(): Settings {
		return $this->settings;
	}

	/**
	 * Accès au configurateur SMTP.
	 */
	public function get_smtp_configurator(): Smtp_Configurator {
		return $this->smtp_configurator;
	}

	/**
	 * Accès au Worker.
	 */
	public function get_worker(): Worker {
		return $this->worker;
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
