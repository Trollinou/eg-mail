<?php
/**
 * Configurateur SMTP et gestionnaire de persistance KeepAlive pour PHPMailer.
 */

declare(strict_types=1);

namespace EGMail\Core;

use EGMail\Queue\Interceptor;
use PHPMailer\PHPMailer\PHPMailer;

class Smtp_Configurator {

	/**
	 * Constructeur avec injection des paramètres.
	 *
	 * @param Settings $settings Gestionnaire de configuration.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Initialise le hook sur phpmailer_init et les filtres d'expéditeur par défaut.
	 */
	public function init(): void {
		add_action( 'phpmailer_init', [ $this, 'configure_phpmailer' ] );
		add_filter( 'wp_mail_from', [ $this, 'filter_default_from_email' ], 5 );
		add_filter( 'wp_mail_from_name', [ $this, 'filter_default_from_name' ], 5 );
	}

	/**
	 * Applique l'adresse d'expédition par défaut configurée dans EG Mail si l'adresse est celle par défaut de WordPress.
	 *
	 * @param string $from_email Adresse courante.
	 */
	public function filter_default_from_email( string $from_email ): string {
		$configured = (string) $this->settings->get( 'smtp_from_email', '' );
		if ( '' !== $configured && is_email( $configured ) ) {
			if ( '' === $from_email || 0 === stripos( $from_email, 'wordpress@' ) || ! is_email( $from_email ) ) {
				return $configured;
			}
		}

		return $from_email;
	}

	/**
	 * Applique le nom d'expéditeur par défaut configuré dans EG Mail si le nom est celui par défaut de WordPress.
	 *
	 * @param string $from_name Nom courant.
	 */
	public function filter_default_from_name( string $from_name ): string {
		$configured = (string) $this->settings->get( 'smtp_from_name', '' );
		if ( '' !== $configured ) {
			if ( '' === $from_name || 'WordPress' === $from_name ) {
				return $configured;
			}
		}

		return $from_name;
	}

	/**
	 * Configure l'instance courante de PHPMailer.
	 *
	 * @param PHPMailer $phpmailer Instance PHPMailer préparée par WordPress.
	 */
	public function configure_phpmailer( PHPMailer $phpmailer ): void {
		$smtp_force = (bool) $this->settings->get( 'smtp_force', false );

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Propriétés natives de la bibliothèque tierce PHPMailer en PascalCase.
		if ( $smtp_force ) {
			$phpmailer->isSMTP();

			$host = (string) $this->settings->get( 'smtp_host', '' );
			if ( '' !== $host ) {
				$phpmailer->Host = $host;
			}

			$port = (int) $this->settings->get( 'smtp_port', 587 );
			if ( $port > 0 ) {
				$phpmailer->Port = $port;
			}

			$encryption            = (string) $this->settings->get( 'smtp_encryption', 'tls' );
			$phpmailer->SMTPSecure = match ( $encryption ) {
				'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
				'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
				default => '',
			};

			$auth                = (bool) $this->settings->get( 'smtp_auth', true );
			$phpmailer->SMTPAuth = $auth;
			if ( $auth ) {
				$phpmailer->Username = (string) $this->settings->get( 'smtp_username', '' );
				$phpmailer->Password = (string) $this->settings->get( 'smtp_password', '' );
			}

			$from_email = (string) $this->settings->get( 'smtp_from_email', '' );
			if ( '' !== $from_email && is_email( $from_email ) ) {
				$phpmailer->From = $from_email;
			}

			$from_name = (string) $this->settings->get( 'smtp_from_name', '' );
			if ( '' !== $from_name ) {
				$phpmailer->FromName = $from_name;
			}
		}

		// Si l'envoi est orchestré par le Worker, maintenir la connexion socket ouverte pour le lot.
		if ( Interceptor::$is_worker_sending ) {
			$phpmailer->SMTPKeepAlive = true;
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
