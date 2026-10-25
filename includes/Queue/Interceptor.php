<?php
/**
 * Intercepteur transparent du flux natif wp_mail().
 */

declare(strict_types=1);

namespace EGMail\Queue;

use EGMail\Core\Backup_Integration;
use EGMail\DTO\Context_Meta;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use EGMail\Repositories\Queue_Repository;

final class Interceptor {

	/**
	 * Drapeau statique activé par le Worker pour autoriser l'envoi direct sans intercepter.
	 */
	public static bool $is_worker_sending = false;

	/**
	 * Constructeur avec injection des dépendances.
	 *
	 * @param Queue_Repository   $repository Dépôt pour la persistance SQL.
	 * @param Attachment_Spooler $spooler    Gestionnaire de pièces jointes.
	 */
	public function __construct(
		private Queue_Repository $repository,
		private Attachment_Spooler $spooler
	) {}

	/**
	 * Enregistre le hook WordPress sur pre_wp_mail.
	 */
	public function init(): void {
		add_filter( 'pre_wp_mail', [ $this, 'intercept' ], 10, 2 );
	}

	/**
	 * Intercepte l'appel à wp_mail() pour différer l'envoi en file d'attente.
	 *
	 * @param ?bool                $short_circuit Valeur de retour court-circuitée (null par défaut).
	 * @param array<string, mixed> $atts          Attributs passés à wp_mail().
	 * @return ?bool True pour court-circuiter wp_mail(), null pour laisser passer en direct.
	 */
	public function intercept( ?bool $short_circuit, array $atts ): ?bool {
		// 1. Si l'envoi est déclenché par le Worker lui-même, on laisse filer vers PHPMailer.
		if ( self::$is_worker_sending ) {
			return $short_circuit;
		}

		$headers_raw = $atts['headers'] ?? [];
		$parsed_meta = $this->parse_custom_headers( $headers_raw );

		// 2. Si le mode Bypass immédiat est demandé (X-Queue-Priority: 0 ou X-Queue-Bypass: true).
		if ( $parsed_meta['is_bypass'] || Queue_Priority::BYPASS === $parsed_meta['priority'] ) {
			return $short_circuit;
		}

		$to_raw     = $atts['to'] ?? '';
		$recipients = $this->extract_recipients( $to_raw );
		if ( empty( $recipients ) ) {
			return false;
		}

		$subject    = isset( $atts['subject'] ) && is_string( $atts['subject'] ) ? $atts['subject'] : '';
		$message    = isset( $atts['message'] ) && is_string( $atts['message'] ) ? $atts['message'] : '';
		$clean_head = $parsed_meta['clean_headers'];
		$priority   = $parsed_meta['priority'];
		$batch_id   = ! empty( $parsed_meta['batch_id'] ) ? $parsed_meta['batch_id'] : $this->generate_batch_id( $subject );

		// Si ce lot avait été précédemment annulé, on lève le marqueur d'annulation pour ce nouvel envoi.
		Worker::clear_aborted_batch( $batch_id );

		// 3. Spool sécurisé des pièces jointes avec déduplication par hash.
		$attachments_raw = isset( $atts['attachments'] ) && is_array( $atts['attachments'] ) ? $atts['attachments'] : ( is_string( $atts['attachments'] ?? null ) ? [ $atts['attachments'] ] : [] );
		$spooled_files   = $this->spooler->spool_attachments( $attachments_raw );

		// 4. Capture du contexte dynamique de WordPress (filtres dynamiques au moment de l'appel).
		$context_meta = $this->capture_context_meta();

		// 5. Calcul de la date d'éligibilité (scheduled_at) selon la priorité et la fenêtre nocturne.
		$scheduled_at = $this->calculate_scheduled_at( $priority );
		$now          = current_time( 'mysql' );

		// 6. Éclatement des destinataires : insertion d'un enregistrement par destinataire.
		$inserted_items = [];
		foreach ( $recipients as $recipient ) {
			$dedup_hash = md5( $recipient . '|' . $subject . '|' . $message );

			$item = new Queue_Item(
				id: null,
				batch_id: $batch_id,
				dedup_hash: $dedup_hash,
				status: Queue_Status::PENDING,
				priority: $priority,
				recipient: $recipient,
				subject: $subject,
				message: $message,
				headers: $clean_head,
				attachments: $spooled_files,
				context_meta: $context_meta,
				attempts: 0,
				max_attempts: 3,
				last_error: null,
				created_at: $now,
				scheduled_at: $scheduled_at,
				sent_at: null,
			);

			$insert_id = $this->repository->insert( $item );
			if ( $insert_id > 0 ) {
				$inserted_items[] = $item;
			}
		}

		// 7. Mise à jour de sécurité du snapshot miroir sur disque pour la résilience.
		if ( ! empty( $inserted_items ) ) {
			Backup_Integration::write_snapshot( $inserted_items );
		}

		// 8. Retourne true pour court-circuiter l'envoi immédiat de WordPress.
		return true;
	}

	/**
	 * Extrait et normalise la liste des destinataires.
	 *
	 * @param mixed $to_raw Chaîne ou tableau d'adresses.
	 * @return array<int, string>
	 */
	public function extract_recipients( mixed $to_raw ): array {
		if ( is_string( $to_raw ) ) {
			$list = explode( ',', $to_raw );
		} elseif ( is_array( $to_raw ) ) {
			$list = [];
			foreach ( $to_raw as $entry ) {
				if ( is_string( $entry ) ) {
					$split = explode( ',', $entry );
					foreach ( $split as $email ) {
						$list[] = $email;
					}
				}
			}
		} else {
			return [];
		}

		$valid = [];
		foreach ( $list as $email ) {
			$trimmed = trim( $email );
			if ( is_email( $trimmed ) ) {
				$valid[] = $trimmed;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Analyse les en-têtes personnalisés X-Queue-* et retourne les en-têtes nettoyés.
	 *
	 * @param mixed $headers_raw En-têtes bruts (chaîne ou tableau).
	 * @return array{priority: Queue_Priority, batch_id: ?string, is_bypass: bool, clean_headers: array<int, string>}
	 */
	public function parse_custom_headers( mixed $headers_raw ): array {
		$headers = [];
		if ( is_string( $headers_raw ) ) {
			$lines = explode( "\n", str_replace( "\r\n", "\n", $headers_raw ) );
			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( '' !== $trimmed ) {
					$headers[] = $trimmed;
				}
			}
		} elseif ( is_array( $headers_raw ) ) {
			foreach ( $headers_raw as $header ) {
				if ( is_string( $header ) ) {
					$headers[] = trim( $header );
				}
			}
		}

		$priority      = Queue_Priority::NORMAL;
		$batch_id      = null;
		$is_bypass     = false;
		$clean_headers = [];

		foreach ( $headers as $header ) {
			if ( preg_match( '/^X-Queue-Priority\s*:\s*(\d+)/i', $header, $matches ) ) {
				$p_val    = (int) $matches[1];
				$priority = Queue_Priority::tryFrom( $p_val ) ?? Queue_Priority::NORMAL;
				continue;
			}

			if ( preg_match( '/^X-Queue-Batch\s*:\s*(.+)$/i', $header, $matches ) ) {
				$batch_id = sanitize_key( trim( $matches[1] ) );
				continue;
			}

			if ( preg_match( '/^X-Queue-Bypass\s*:\s*(true|1)/i', $header ) ) {
				$is_bypass = true;
				continue;
			}

			$clean_headers[] = $header;
		}

		return [
			'priority'      => $priority,
			'batch_id'      => $batch_id,
			'is_bypass'     => $is_bypass,
			'clean_headers' => $clean_headers,
		];
	}

	/**
	 * Génère un identifiant de lot unique basé sur le sujet et la minute courante.
	 *
	 * @param string $subject Sujet du message.
	 */
	public function generate_batch_id( string $subject ): string {
		return md5( $subject . '|' . floor( time() / 60 ) );
	}

	/**
	 * Capture les métadonnées de contexte WordPress par écoute des filtres standards.
	 */
	public function capture_context_meta(): Context_Meta {
		$content_type = apply_filters( 'wp_mail_content_type', '' );
		$charset      = apply_filters( 'wp_mail_charset', get_bloginfo( 'charset' ) );
		$from_email   = apply_filters( 'wp_mail_from', '' );
		$from_name    = apply_filters( 'wp_mail_from_name', '' );

		return new Context_Meta(
			content_type: is_string( $content_type ) && '' !== $content_type ? $content_type : null,
			charset: is_string( $charset ) && '' !== $charset ? $charset : null,
			from_email: is_string( $from_email ) && '' !== $from_email ? $from_email : null,
			from_name: is_string( $from_name ) && '' !== $from_name ? $from_name : null,
		);
	}

	/**
	 * Calcule la date d'éligibilité d'expédition (scheduled_at) selon la priorité et la fenêtre nocturne.
	 *
	 * @param Queue_Priority $priority Niveau de priorité.
	 * @return string Date au format MySQL Y-m-d H:i:s.
	 */
	public function calculate_scheduled_at( Queue_Priority $priority ): string {
		$now_timestamp = time();
		$now_mysql     = current_time( 'mysql' );

		if ( ! $priority->is_nocturnal() ) {
			return $now_mysql;
		}

		// Récupération des réglages de la plage nocturne (défaut : 22h00 - 06h00).
		$settings    = get_option( 'eg_mail_settings', [] );
		$night_start = is_array( $settings ) && isset( $settings['night_start'] ) && is_string( $settings['night_start'] ) ? $settings['night_start'] : '22:00';
		$night_end   = is_array( $settings ) && isset( $settings['night_end'] ) && is_string( $settings['night_end'] ) ? $settings['night_end'] : '06:00';

		$current_hour_minute = wp_date( 'H:i', $now_timestamp );

		// Vérifie si l'heure actuelle est dans la fenêtre nocturne.
		$is_in_night_window = false;
		if ( $night_start > $night_end ) {
			// Plage chevauchant minuit (ex: 22h00 à 06h00).
			$is_in_night_window = ( $current_hour_minute >= $night_start || $current_hour_minute < $night_end );
		} else {
			// Plage sur la même journée (ex: 01h00 à 05h00).
			$is_in_night_window = ( $current_hour_minute >= $night_start && $current_hour_minute < $night_end );
		}

		if ( $is_in_night_window ) {
			return $now_mysql;
		}

		// Si hors de la plage nocturne en journée (ex: 14h30), programmer pour aujourd'hui à l'heure de début nocturne.
		$today_date = wp_date( 'Y-m-d', $now_timestamp );
		$target     = $today_date . ' ' . $night_start . ':00';

		// Si l'heure cible est déjà passée aujourd'hui (cas rare de plage diurne terminée), reporter à demain.
		if ( strtotime( $target ) < $now_timestamp ) {
			$target = wp_date( 'Y-m-d', strtotime( '+1 day', $now_timestamp ) ) . ' ' . $night_start . ':00';
		}

		return $target;
	}
}
