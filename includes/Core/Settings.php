<?php
/**
 * Gestionnaire des options, des quotas et de la configuration du plugin EG Mail.
 */

declare(strict_types=1);

namespace EGMail\Core;

final class Settings {

	public const OPTION_NAME = 'eg_mail_settings';

	/**
	 * Retourne les valeurs par défaut de configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'smtp_force'         => false,
			'smtp_host'          => '',
			'smtp_port'          => 587,
			'smtp_encryption'    => 'tls',
			'smtp_auth'          => true,
			'smtp_username'      => '',
			'smtp_password'      => '',
			'smtp_from_email'    => '',
			'smtp_from_name'     => '',
			'quota_minute_day'   => 10,
			'quota_minute_night' => 25,
			'quota_hour'         => 0, // 0 = désactivé.
			'night_start'        => '22:00',
			'night_end'          => '06:00',
			'retention_days'     => 15,
		];
	}

	/**
	 * Récupère l'ensemble des réglages fusionnés avec les valeurs par défaut.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		$saved = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return array_merge( self::get_defaults(), $saved );
	}

	/**
	 * Récupère la valeur d'un réglage spécifique.
	 *
	 * @param string $key            Clé du réglage.
	 * @param mixed  $fallback_value Valeur de repli si absente.
	 */
	public function get( string $key, mixed $fallback_value = null ): mixed {
		$settings = $this->get_all();
		return $settings[ $key ] ?? $fallback_value;
	}

	/**
	 * Met à jour les réglages du plugin avec autoload désactivé (WP 7.1).
	 *
	 * @param array<string, mixed> $new_settings Nouveaux paramètres.
	 * @return bool Vrai si la mise à jour a réussi.
	 */
	public function update( array $new_settings ): bool {
		$current = $this->get_all();
		$updated = array_merge( $current, $new_settings );

		return update_option( self::OPTION_NAME, $updated, false );
	}

	/**
	 * Détermine si un instant donné (ou maintenant) se situe dans la plage horaire nocturne.
	 *
	 * @param ?int $timestamp Timestamp Unix à évaluer (null pour maintenant).
	 */
	public function is_night_time( ?int $timestamp = null ): bool {
		$time        = null !== $timestamp ? $timestamp : time();
		$night_start = (string) $this->get( 'night_start', '22:00' );
		$night_end   = (string) $this->get( 'night_end', '06:00' );

		$current_hi = wp_date( 'H:i', $time );

		if ( $night_start > $night_end ) {
			// Plage chevauchant minuit (ex: 22h00 à 06h00).
			return ( $current_hi >= $night_start || $current_hi < $night_end );
		}

		// Plage dans la même journée (ex: 01h00 à 05h00).
		return ( $current_hi >= $night_start && $current_hi < $night_end );
	}

	/**
	 * Calcule le quota effectif d'envoi pour le cycle courant du Worker.
	 *
	 * Croise le quota par minute (jour ou nuit) et le reliquat horaire glissant.
	 *
	 * @param int  $sent_last_hour Nombre d'e-mails envoyés sur les 60 dernières minutes.
	 * @param ?int $timestamp      Timestamp pour évaluer le mode nuit.
	 * @return int Nombre d'e-mails autorisés sur ce cycle de 1 minute.
	 */
	public function calculate_effective_quota( int $sent_last_hour, ?int $timestamp = null ): int {
		$is_night     = $this->is_night_time( $timestamp );
		$minute_limit = $is_night ? (int) $this->get( 'quota_minute_night', 25 ) : (int) $this->get( 'quota_minute_day', 10 );
		$minute_limit = max( 1, $minute_limit );

		$hour_limit = (int) $this->get( 'quota_hour', 0 );
		if ( $hour_limit <= 0 ) {
			return $minute_limit;
		}

		$remaining_hour = max( 0, $hour_limit - $sent_last_hour );

		return min( $minute_limit, $remaining_hour );
	}

	/**
	 * Calcule le délai de report (en secondes) pour le backoff exponentiel en cas d'échec SMTP.
	 *
	 * @param int $attempt Numéro de la tentative actuelle (1, 2, 3...).
	 * @return int Délai en secondes.
	 */
	public static function calculate_backoff_delay( int $attempt ): int {
		return match ( $attempt ) {
			1       => 300,  // +5 minutes.
			2       => 1200, // +20 minutes.
			default => 3600, // +60 minutes.
		};
	}
}
