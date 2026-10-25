<?php
/**
 * Utilitaire typé pour la lecture sécurisée et centralisée des superglobales ($_GET, $_POST, $_SERVER).
 */

declare(strict_types=1);

namespace EGMail\Utils;

final class Request {

	/**
	 * Retourne la méthode HTTP de la requête courante (GET, POST, etc.).
	 */
	public static function method(): string {
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		return is_string( $method ) ? strtoupper( $method ) : 'GET';
	}

	/**
	 * Vérifie la présence et la validité d'un nonce dans la requête POST.
	 *
	 * @param string $key    Clé du champ nonce dans $_POST.
	 * @param string $action Nom de l'action WordPress associée.
	 */
	public static function verify_post_nonce( string $key, string $action ): bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		return (bool) wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Récupère une chaîne assainie depuis $_POST.
	 *
	 * @param string $key          Clé du paramètre.
	 * @param string $fallback_val Valeur par défaut.
	 */
	public static function post_string( string $key, string $fallback_val = '' ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback_val;
		}

		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Récupère une adresse e-mail assainie depuis $_POST.
	 *
	 * @param string $key          Clé du paramètre.
	 * @param string $fallback_val Valeur par défaut.
	 */
	public static function post_email( string $key, string $fallback_val = '' ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback_val;
		}

		return sanitize_email( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Récupère une clé (slug) assainie depuis $_POST.
	 *
	 * @param string $key          Clé du paramètre.
	 * @param string $fallback_val Valeur par défaut.
	 */
	public static function post_key( string $key, string $fallback_val = '' ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback_val;
		}

		return sanitize_key( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Récupère un entier positif depuis $_POST.
	 *
	 * @param string $key          Clé du paramètre.
	 * @param int    $fallback_val Valeur par défaut.
	 */
	public static function post_int( string $key, int $fallback_val = 0 ): int {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback_val;
		}

		return absint( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Détermine si une case à cocher est cochée dans $_POST.
	 *
	 * @param string $key Clé du paramètre.
	 */
	public static function post_bool( string $key ): bool {
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Récupère une chaîne assainie depuis $_GET.
	 *
	 * @param string $key          Clé du paramètre.
	 * @param string $fallback_val Valeur par défaut.
	 */
	public static function get_string( string $key, string $fallback_val = '' ): string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $fallback_val;
		}

		return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
	}

	/**
	 * Récupère une clé (slug) assainie depuis $_GET.
	 *
	 * @param string $key          Clé du paramètre.
	 * @param string $fallback_val Valeur par défaut.
	 */
	public static function get_key( string $key, string $fallback_val = '' ): string {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $fallback_val;
		}

		return sanitize_key( wp_unslash( (string) $_GET[ $key ] ) );
	}
}
