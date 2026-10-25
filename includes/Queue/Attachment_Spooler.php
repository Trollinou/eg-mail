<?php
/**
 * Spooler de pièces jointes avec déduplication par empreinte MD5 et Garbage Collector.
 */

declare(strict_types=1);

namespace EGMail\Queue;

use EGMail\Core\Activator;

final class Attachment_Spooler {

	/**
	 * Retourne le chemin absolu du dossier de spool.
	 */
	public function get_spool_dir(): string {
		$upload_dir = wp_upload_dir();
		$spool_dir  = $upload_dir['basedir'] . '/eg-mail-spool';

		if ( ! is_dir( $spool_dir ) ) {
			wp_mkdir_p( $spool_dir );
			Activator::ensure_spool_protection( $spool_dir );
		}

		return $spool_dir;
	}

	/**
	 * Copie un fichier vers le spool avec déduplication par hash MD5.
	 *
	 * @param string $source_path Chemin absolu du fichier source.
	 * @return ?string Chemin du fichier dans le spool, ou null en cas d'erreur.
	 */
	public function spool_file( string $source_path ): ?string {
		if ( ! is_readable( $source_path ) || ! is_file( $source_path ) ) {
			return null;
		}

		$hash = md5_file( $source_path );
		if ( false === $hash ) {
			return null;
		}

		$spool_dir  = $this->get_spool_dir();
		$clean_name = sanitize_file_name( basename( $source_path ) );
		$dest_name  = $hash . '_' . $clean_name;
		$dest_path  = $spool_dir . '/' . $dest_name;

		// Si le fichier existe déjà dans le spool, on réutilise l'existant (déduplication).
		if ( file_exists( $dest_path ) ) {
			return $dest_path;
		}

		$copied = copy( $source_path, $dest_path );
		if ( ! $copied ) {
			return null;
		}

		return $dest_path;
	}

	/**
	 * Traite une liste de pièces jointes et retourne les chemins spooled dédupliqués.
	 *
	 * @param array<int, mixed> $attachments Liste des pièces jointes brutes (chemins).
	 * @return array<int, string> Liste des chemins absolus dans le spool.
	 */
	public function spool_attachments( array $attachments ): array {
		$spooled = [];

		foreach ( $attachments as $attachment ) {
			if ( ! is_string( $attachment ) ) {
				continue;
			}

			$path = $this->spool_file( $attachment );
			if ( null !== $path ) {
				$spooled[] = $path;
			}
		}

		return array_values( array_unique( $spooled ) );
	}

	/**
	 * Supprime du spool les fichiers orphelins qui ne sont plus référencés par aucun e-mail actif.
	 *
	 * @param array<int, string> $active_files Chemins absolus des fichiers actuellement actifs en base.
	 * @return int Nombre de fichiers orphelins supprimés.
	 */
	public function clean_orphaned_attachments( array $active_files ): int {
		$spool_dir = $this->get_spool_dir();
		if ( ! is_dir( $spool_dir ) ) {
			return 0;
		}

		$active_map = array_flip( $active_files );
		$deleted    = 0;
		$entries    = scandir( $spool_dir );

		if ( false === $entries ) {
			return 0;
		}

		// Noms de fichiers protégés à ne jamais supprimer.
		$protected_names = [
			'.',
			'..',
			'.htaccess',
			'index.php',
			'queue-snapshot.json',
		];

		foreach ( $entries as $entry ) {
			if ( in_array( $entry, $protected_names, true ) ) {
				continue;
			}

			// Protection des fichiers temporaires ou verrous en cours d'écriture.
			if ( str_ends_with( $entry, '.tmp' ) || str_ends_with( $entry, '.lock' ) ) {
				continue;
			}

			$full_path = $spool_dir . '/' . $entry;
			if ( ! is_file( $full_path ) ) {
				continue;
			}

			if ( ! isset( $active_map[ $full_path ] ) ) {
				if ( wp_delete_file( $full_path ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Vérifie et restaure les protections d'accès au spool si nécessaire.
	 */
	public function ensure_spool_security(): void {
		$spool_dir = $this->get_spool_dir();
		Activator::ensure_spool_protection( $spool_dir );
	}
}
