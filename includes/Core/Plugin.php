<?php
/**
 * Orchestrateur central et conteneur de services du plugin EG Mail.
 */

declare(strict_types=1);

namespace EGMail\Core;

use EGMail\Admin\Admin_Menu;
use EGMail\API\Admin_Rest_Controller;
use EGMail\Queue\Attachment_Spooler;
use EGMail\Queue\Cleaner;
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

	private Cleaner $cleaner;

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
		$this->cleaner           = new Cleaner( $this->repository, $this->spooler, $this->settings );
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

		// 5. Initialisation du nettoyeur quotidien de rétention.
		$this->cleaner->init();

		// 6. Initialisation différée de l'API REST sur rest_api_init.
		add_action(
			'rest_api_init',
			function (): void {
				$rest_controller = new Admin_Rest_Controller( $this->repository, $this->spooler, $this->worker );
				$rest_controller->register_routes();
			}
		);

		// 7. Initialisation Back-office isolée sous is_admin().
		if ( is_admin() ) {
			$admin_menu = new Admin_Menu( $this->repository, $this->settings );
			$admin_menu->init();
		}

		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Intervalle 1 minute indispensable pour le dépilement réactif.
		add_filter( 'cron_schedules', [ $this, 'register_cron_schedules' ] );

		// 8. Auto-planification de sécurité si les tâches récurrentes ne sont pas encore enregistrées.
		if ( ! wp_next_scheduled( 'eg_mail_cron_worker' ) ) {
			wp_schedule_event( time(), 'eg_mail_every_minute', 'eg_mail_cron_worker' );
		}
		if ( ! wp_next_scheduled( 'eg_mail_cron_cleaner' ) ) {
			wp_schedule_event( time(), 'daily', 'eg_mail_cron_cleaner' );
		}

		// 9. Filet de sécurité anti-blocage loopback HTTP (ex: LocalWP .local sur macOS).
		add_action( 'wp_loaded', [ $this, 'maybe_run_overdue_worker' ], 20 );
		add_action( 'shutdown', [ $this, 'maybe_run_overdue_worker' ], 20 );

		// 10. Support des commandes WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command(
				'eg-mail worker run',
				function (): void {
					$sent = $this->worker->run();
					\WP_CLI::success( sprintf( 'Worker exécuté avec succès : %d e-mail(s) envoyé(s).', $sent ) );
				}
			);
		}
	}

	/**
	 * Exécute directement le Worker si l'échéance WP-Cron est arrivée à terme mais que le loopback HTTP a échoué.
	 */
	public function maybe_run_overdue_worker(): void {
		if ( wp_doing_cron() ) {
			return;
		}

		$next_scheduled = wp_next_scheduled( 'eg_mail_cron_worker' );
		if ( false === $next_scheduled || $next_scheduled > time() ) {
			return;
		}

		// Replanifie l'échéance à +60s avant l'exécution pour maintenir la cadence horaire.
		wp_clear_scheduled_hook( 'eg_mail_cron_worker' );
		wp_schedule_event( time() + 60, 'eg_mail_every_minute', 'eg_mail_cron_worker' );

		$this->worker->process_queue();
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

	/**
	 * Accès au nettoyeur quotidien.
	 */
	public function get_cleaner(): Cleaner {
		return $this->cleaner;
	}
}
