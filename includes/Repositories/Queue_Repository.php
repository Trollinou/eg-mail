<?php
/**
 * Dépôt de données pour la gestion de la table SQL de la file d'attente.
 */

declare(strict_types=1);

namespace EGMail\Repositories;

use EGMail\DTO\Batch_Summary;
use EGMail\DTO\Queue_Item;
use EGMail\Enums\Queue_Priority;
use EGMail\Enums\Queue_Status;
use wpdb;

class Queue_Repository {

	private const CACHE_GROUP = 'eg_mail_cache';

	private wpdb $db;

	private string $table_name;

	/**
	 * Constructeur avec injection optionnelle de $wpdb.
	 *
	 * @param ?wpdb $db Instance de la base de données WordPress.
	 */
	public function __construct( ?wpdb $db = null ) {
		if ( null !== $db ) {
			$this->db = $db;
		} else {
			global $wpdb;
			/** @var wpdb $wpdb */
			$this->db = $wpdb;
		}

		$this->table_name = $this->db->prefix . 'eg_mail_queue';
	}

	/**
	 * Insère un nouvel e-mail dans la file d'attente.
	 *
	 * @param Queue_Item $item Message à enregistrer.
	 * @return int Identifiant inséré (ID SQL), ou 0 en cas d'échec.
	 */
	public function insert( Queue_Item $item ): int {
		$data = $item->to_db_array();

		$format = [
			'%s', // batch_id
			'%s', // dedup_hash
			'%s', // status
			'%d', // priority
			'%s', // recipient
			'%s', // subject
			'%s', // message
			'%s', // headers
			'%s', // attachments
			'%s', // context_meta
			'%d', // attempts
			'%d', // max_attempts
			'%s', // last_error
			'%s', // created_at
			'%s', // scheduled_at
			'%s', // sent_at
		];

		$inserted = $this->db->insert( $this->table_name, $data, $format );
		if ( false === $inserted ) {
			return 0;
		}

		$insert_id = (int) $this->db->insert_id;
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return $insert_id;
	}

	/**
	 * Récupère un message par son identifiant unique.
	 *
	 * @param int $id Identifiant unique.
	 * @return ?Queue_Item Le DTO hydraté ou null si introuvable.
	 */
	public function get_by_id( int $id ): ?Queue_Item {
		$cache_key = 'item_' . $id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( $cached instanceof Queue_Item ) {
			return $cached;
		}

		/** @var literal-string $sql */
		$sql   = "SELECT * FROM {$this->table_name} WHERE id = %d LIMIT 1";
		$query = $this->db->prepare( $sql, $id );

		/** @var ?object $row */
		$row = $this->db->get_row( $query );
		if ( ! is_object( $row ) ) {
			return null;
		}

		/** @var object{id: int|string, batch_id: string, dedup_hash: string, status: string, priority: int|string, recipient: string, subject: string, message: string, headers?: ?string, attachments?: ?string, context_meta?: ?string, attempts: int|string, max_attempts: int|string, last_error?: ?string, created_at: string, scheduled_at: string, sent_at?: ?string} $row */
		$item = Queue_Item::from_db_row( $row );
		wp_cache_set( $cache_key, $item, self::CACHE_GROUP, 60 );

		return $item;
	}

	/**
	 * Récupère un premier exemplaire représentatif d'un lot pour la prévisualisation.
	 *
	 * @param string $batch_id Identifiant de lot.
	 * @return ?Queue_Item
	 */
	public function get_first_by_batch_id( string $batch_id ): ?Queue_Item {
		/** @var literal-string $sql */
		$sql   = "SELECT * FROM {$this->table_name} WHERE batch_id = %s LIMIT 1";
		$query = $this->db->prepare( $sql, $batch_id );

		/** @var ?object $row */
		$row = $this->db->get_row( $query );
		if ( ! is_object( $row ) ) {
			return null;
		}

		/** @var object{id: int|string, batch_id: string, dedup_hash: string, status: string, priority: int|string, recipient: string, subject: string, message: string, headers?: ?string, attachments?: ?string, context_meta?: ?string, attempts: int|string, max_attempts: int|string, last_error?: ?string, created_at: string, scheduled_at: string, sent_at?: ?string} $row */
		return Queue_Item::from_db_row( $row );
	}

	/**
	 * Récupère le lot d'e-mails éligibles pour le Worker.
	 *
	 * @param int  $limit           Nombre maximal d'e-mails à dépiler.
	 * @param bool $allow_nocturnal Autoriser les messages à priorité nocturne (>= 20).
	 * @return array<int, Queue_Item> Liste des messages ordonnés par priorité et FIFO.
	 */
	public function fetch_eligible_batch( int $limit, bool $allow_nocturnal = true ): array {
		if ( $limit <= 0 ) {
			return [];
		}

		$now = current_time( 'mysql' );

		if ( $allow_nocturnal ) {
			/** @var literal-string $sql */
			$sql   = "SELECT * FROM {$this->table_name}
				WHERE status = 'pending' AND scheduled_at <= %s
				ORDER BY priority ASC, scheduled_at ASC, id ASC
				LIMIT %d";
			$query = $this->db->prepare( $sql, $now, $limit );
		} else {
			$low_priority = Queue_Priority::LOW->value;
			/** @var literal-string $sql */
			$sql   = "SELECT * FROM {$this->table_name}
				WHERE status = 'pending' AND scheduled_at <= %s AND priority < %d
				ORDER BY priority ASC, scheduled_at ASC, id ASC
				LIMIT %d";
			$query = $this->db->prepare( $sql, $now, $low_priority, $limit );
		}

		/** @var array<int, object> $rows */
		$rows = $this->db->get_results( $query );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$items = [];
		foreach ( $rows as $row ) {
			/** @var object{id: int|string, batch_id: string, dedup_hash: string, status: string, priority: int|string, recipient: string, subject: string, message: string, headers?: ?string, attachments?: ?string, context_meta?: ?string, attempts: int|string, max_attempts: int|string, last_error?: ?string, created_at: string, scheduled_at: string, sent_at?: ?string} $row */
			$items[] = Queue_Item::from_db_row( $row );
		}

		return $items;
	}

	/**
	 * Compte le nombre d'e-mails envoyés lors des 60 dernières minutes glissantes.
	 */
	public function count_sent_in_last_hour(): int {
		$query = "SELECT COUNT(*) FROM {$this->table_name}
			WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $this->db->get_var( $query );
	}

	/**
	 * Met à jour le statut et les métadonnées d'un message.
	 *
	 * @param int                  $id    Identifiant du message.
	 * @param Queue_Status         $status Nouveau statut.
	 * @param array<string, mixed> $extra Champs complémentaires (attempts, last_error, scheduled_at, sent_at).
	 * @return bool Vrai en cas de succès.
	 */
	public function update_status( int $id, Queue_Status $status, array $extra = [] ): bool {
		$data   = [ 'status' => $status->value ];
		$format = [ '%s' ];

		if ( isset( $extra['attempts'] ) && is_int( $extra['attempts'] ) ) {
			$data['attempts'] = $extra['attempts'];
			$format[]         = '%d';
		}

		if ( array_key_exists( 'last_error', $extra ) ) {
			$data['last_error'] = is_string( $extra['last_error'] ) ? $extra['last_error'] : null;
			$format[]           = '%s';
		}

		if ( isset( $extra['scheduled_at'] ) && is_string( $extra['scheduled_at'] ) ) {
			$data['scheduled_at'] = $extra['scheduled_at'];
			$format[]             = '%s';
		}

		if ( array_key_exists( 'sent_at', $extra ) ) {
			$data['sent_at'] = is_string( $extra['sent_at'] ) ? $extra['sent_at'] : null;
			$format[]        = '%s';
		}

		$updated = $this->db->update(
			$this->table_name,
			$data,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		wp_cache_delete( 'item_' . $id, self::CACHE_GROUP );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return false !== $updated;
	}

	/**
	 * Met en pause tous les e-mails en attente d'un lot donné.
	 *
	 * @param string $batch_id Identifiant de lot.
	 * @return int Nombre de lignes passées en pause.
	 */
	public function pause_batch( string $batch_id ): int {
		/** @var literal-string $sql */
		$sql   = "UPDATE {$this->table_name} SET status = 'paused' WHERE batch_id = %s AND status = 'pending'";
		$query = $this->db->prepare( $sql, $batch_id );

		if ( ! is_string( $query ) ) {
			return 0;
		}

		$result = $this->db->query( $query );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Reprend l'envoi d'un lot mis en pause.
	 *
	 * @param string $batch_id Identifiant de lot.
	 * @return int Nombre de lignes réactivées.
	 */
	public function resume_batch( string $batch_id ): int {
		/** @var literal-string $sql */
		$sql   = "UPDATE {$this->table_name} SET status = 'pending' WHERE batch_id = %s AND status = 'paused'";
		$query = $this->db->prepare( $sql, $batch_id );

		if ( ! is_string( $query ) ) {
			return 0;
		}

		$result = $this->db->query( $query );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Supprime définitivement les e-mails non expédiés d'un lot (pending ou paused).
	 *
	 * @param string $batch_id Identifiant de lot.
	 * @return int Nombre de messages supprimés.
	 */
	public function cancel_batch( string $batch_id ): int {
		/** @var literal-string $sql */
		$sql   = "DELETE FROM {$this->table_name} WHERE batch_id = %s AND status IN ('pending', 'paused')";
		$query = $this->db->prepare( $sql, $batch_id );

		if ( ! is_string( $query ) ) {
			return 0;
		}

		$result = $this->db->query( $query );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Force l'envoi diurne d'un lot nocturne en abaissant sa priorité à 10 et en réinitialisant scheduled_at à maintenant.
	 *
	 * @param string $batch_id Identifiant de lot.
	 * @return int Nombre de messages mis à jour.
	 */
	public function force_batch_day( string $batch_id ): int {
		$now          = current_time( 'mysql' );
		$low_priority = Queue_Priority::LOW->value;

		/** @var literal-string $sql */
		$sql   = "UPDATE {$this->table_name}
			SET priority = 10, scheduled_at = %s
			WHERE batch_id = %s AND priority >= %d AND status IN ('pending', 'paused')";
		$query = $this->db->prepare( $sql, $now, $batch_id, $low_priority );

		if ( ! is_string( $query ) ) {
			return 0;
		}

		$result = $this->db->query( $query );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Purge l'intégralité des messages en attente ou en pause de toute la file d'attente.
	 *
	 * @return int Nombre de messages purgés.
	 */
	public function purge_all_pending(): int {
		$query = "DELETE FROM {$this->table_name} WHERE status IN ('pending', 'paused')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = $this->db->query( $query );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Récupère la liste synthétique des campagnes/lots pour le tableau de bord back-office.
	 *
	 * @param int $limit  Nombre de lots à afficher.
	 * @param int $offset Décalage de pagination.
	 * @return array<int, Batch_Summary>
	 */
	public function get_batch_summaries( int $limit = 50, int $offset = 0 ): array {
		/** @var literal-string $sql */
		$sql   = "SELECT
				batch_id,
				MAX(subject) AS subject,
				MIN(priority) AS priority,
				MIN(scheduled_at) AS scheduled_at,
				MIN(created_at) AS created_at,
				COUNT(*) AS total_count,
				SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
				SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
				SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused_count,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
			FROM {$this->table_name}
			GROUP BY batch_id
			ORDER BY MAX(created_at) DESC
			LIMIT %d OFFSET %d";
		$query = $this->db->prepare( $sql, $limit, $offset );

		/** @var array<int, object> $rows */
		$rows = $this->db->get_results( $query );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$summaries = [];
		foreach ( $rows as $row ) {
			/** @var object{batch_id: string, subject: string, priority: int|string, scheduled_at: string, created_at: string, total_count: int|string, sent_count: int|string, pending_count: int|string, paused_count: int|string, failed_count: int|string} $row */
			$summaries[] = Batch_Summary::from_db_row( $row );
		}

		return $summaries;
	}

	/**
	 * Extrait la liste complète de tous les chemins de pièces jointes encore requis par des e-mails actifs.
	 *
	 * @return array<int, string> Liste dédupliquée des chemins absolus de fichiers.
	 */
	public function get_active_attachment_paths(): array {
		$query = "SELECT attachments FROM {$this->table_name}
			WHERE status IN ('pending', 'processing', 'paused') AND attachments IS NOT NULL"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<int, string> $results */
		$results = $this->db->get_col( $query );
		if ( ! is_array( $results ) ) {
			return [];
		}

		$paths = [];
		foreach ( $results as $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $path ) {
					if ( is_string( $path ) ) {
						$paths[] = $path;
					}
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Récupère les compteurs d'e-mails par statut pour les vues WP_List_Table.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		$query = "SELECT status, COUNT(*) AS cnt FROM {$this->table_name} GROUP BY status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<int, object{status: string, cnt: int|string}> $results */
		$results = $this->db->get_results( $query );
		$counts  = [
			'all'        => 0,
			'pending'    => 0,
			'processing' => 0,
			'paused'     => 0,
			'sent'       => 0,
			'failed'     => 0,
			'cancelled'  => 0,
		];

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$cnt                    = (int) $row->cnt;
				$counts[ $row->status ] = $cnt;
				$counts['all']         += $cnt;
			}
		}

		return $counts;
	}

	/**
	 * Recherche et pagine les e-mails de la file pour WP_List_Table.
	 *
	 * @param array<string, string> $filters Filtres (status, search).
	 * @param int                   $limit   Nombre d'éléments par page.
	 * @param int                   $offset  Décalage.
	 * @return array{items: array<int, Queue_Item>, total: int}
	 */
	public function search_queue( array $filters, int $limit = 20, int $offset = 0 ): array {
		$where_clauses = [ '1=1' ];
		$params        = [];

		if ( ! empty( $filters['status'] ) && 'all' !== $filters['status'] ) {
			$where_clauses[] = 'status = %s';
			$params[]        = $filters['status'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$where_clauses[] = '(recipient LIKE %s OR subject LIKE %s)';
			$like            = '%' . $this->db->esc_like( $filters['search'] ) . '%';
			$params[]        = $like;
			$params[]        = $like;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// 1. Calcul du total.
		/** @var literal-string $count_sql */
		$count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$count_query = $this->db->prepare( $count_sql, ...$params );
		} else {
			$count_query = $count_sql;
		}
		$total = (int) $this->db->get_var( $count_query );

		// 2. Récupération des résultats paginés avec tri sécurisé.
		$allowed_orders = [ 'id', 'recipient', 'scheduled_at', 'status', 'priority', 'created_at' ];
		$orderby        = isset( $filters['orderby'] ) && in_array( $filters['orderby'], $allowed_orders, true ) ? $filters['orderby'] : 'id';
		$order          = isset( $filters['order'] ) && 'asc' === strtolower( $filters['order'] ) ? 'ASC' : 'DESC';

		/** @var literal-string $select_sql */
		$select_sql = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, [ $limit, $offset ] );
		$query      = $this->db->prepare( $select_sql, ...$all_params );

		/** @var array<int, object> $rows */
		$rows  = $this->db->get_results( $query );
		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				/** @var object{id: int|string, batch_id: string, dedup_hash: string, status: string, priority: int|string, recipient: string, subject: string, message: string, headers?: ?string, attachments?: ?string, context_meta?: ?string, attempts: int|string, max_attempts: int|string, last_error?: ?string, created_at: string, scheduled_at: string, sent_at?: ?string} $row */
				$items[] = Queue_Item::from_db_row( $row );
			}
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Purge les e-mails envoyés avec succès dont la date d'envoi dépasse la période de rétention.
	 *
	 * @param int $retention_days Nombre de jours de rétention.
	 * @return int Nombre d'e-mails supprimés.
	 */
	public function purge_sent( int $retention_days ): int {
		if ( $retention_days < 1 ) {
			$retention_days = 15;
		}

		/** @var literal-string $sql */
		$sql   = "DELETE FROM {$this->table_name}
			WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
		$query = $this->db->prepare( $sql, $retention_days );

		if ( ! is_string( $query ) ) {
			return 0;
		}

		$result = $this->db->query( $query );
		wp_cache_delete( 'batch_summaries', self::CACHE_GROUP );

		return is_int( $result ) ? $result : 0;
	}
}
