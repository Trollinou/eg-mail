<?php
/**
 * Table d'administration WP_List_Table pour l'exploration détaillée de la file d'attente FIFO.
 */

declare(strict_types=1);

namespace EGMail\Admin;

use EGMail\DTO\Queue_Item;
use EGMail\Repositories\Queue_Repository;
use EGMail\Utils\Request;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Queue_List_Table extends WP_List_Table {

	/**
	 * Constructeur avec injection du repository.
	 *
	 * @param Queue_Repository $repository Dépôt de données.
	 */
	public function __construct( private Queue_Repository $repository ) {
		parent::__construct(
			[
				'singular' => 'email',
				'plural'   => 'emails',
				'ajax'     => false,
			]
		);
	}

	/**
	 * Déclare les colonnes du tableau.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return [
			'id'           => __( 'ID', 'eg-mail' ),
			'recipient'    => __( 'Destinataire', 'eg-mail' ),
			'subject'      => __( 'Sujet', 'eg-mail' ),
			'status'       => __( 'Statut', 'eg-mail' ),
			'priority'     => __( 'Priorité', 'eg-mail' ),
			'attempts'     => __( 'Essais', 'eg-mail' ),
			'scheduled_at' => __( 'Planifié pour', 'eg-mail' ),
			'sent_at'      => __( 'Envoyé le', 'eg-mail' ),
			'last_error'   => __( 'Dernière erreur', 'eg-mail' ),
		];
	}

	/**
	 * Génère les liens de filtrage par statut en haut de la table (Vues).
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts         = $this->repository->count_by_status();
		$current_status = Request::get_key( 'status', 'all' );

		$views  = [];
		$labels = [
			'all'        => __( 'Tous', 'eg-mail' ),
			'pending'    => __( 'En attente', 'eg-mail' ),
			'processing' => __( 'En cours', 'eg-mail' ),
			'paused'     => __( 'En pause', 'eg-mail' ),
			'sent'       => __( 'Envoyés', 'eg-mail' ),
			'failed'     => __( 'Échoués', 'eg-mail' ),
			'cancelled'  => __( 'Annulés', 'eg-mail' ),
		];

		$base_url = admin_url( 'tools.php?page=' . Admin_Menu::PAGE_QUEUE );

		foreach ( $labels as $status => $label ) {
			$cnt = $counts[ $status ] ?? 0;
			if ( 'all' !== $status && 0 === $cnt ) {
				continue;
			}

			$class = ( $current_status === $status ) ? 'current' : '';
			$url   = add_query_arg( 'status', $status, $base_url );

			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				$cnt
			);
		}

		return $views;
	}

	/**
	 * Prépare et pagine les éléments pour l'affichage.
	 */
	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$status = Request::get_key( 'status', 'all' );
		$search = Request::get_string( 's' );

		$filters = [
			'status' => $status,
			'search' => $search,
		];

		$results     = $this->repository->search_queue( $filters, $per_page, $offset );
		$this->items = $results['items'];

		$this->set_pagination_args(
			[
				'total_items' => $results['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $results['total'] / $per_page ),
			]
		);
	}

	/**
	 * Rendu par défaut pour les colonnes non spécifiques.
	 *
	 * @param Queue_Item $item        Ligne courante.
	 * @param string     $column_name Nom de colonne.
	 */
	public function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'id'           => (string) $item->id,
			'subject'      => esc_html( $item->subject ?: __( '(Sans sujet)', 'eg-mail' ) ),
			'attempts'     => esc_html( $item->attempts . ' / ' . $item->max_attempts ),
			'scheduled_at' => esc_html( $item->scheduled_at ),
			'sent_at'      => esc_html( $item->sent_at ?? '—' ),
			'last_error'   => ! empty( $item->last_error ) ? '<span style="color: #d63638;" title="' . esc_attr( $item->last_error ) . '">' . esc_html( wp_trim_words( $item->last_error, 8 ) ) . '</span>' : '—',
			default        => '',
		};
	}

	/**
	 * Rendu de la colonne Destinataire.
	 *
	 * @param Queue_Item $item Message courant.
	 */
	public function column_recipient( $item ): string {
		$output  = '<strong>' . esc_html( $item->recipient ) . '</strong>';
		$output .= '<div class="row-actions">';
		$output .= '<span style="color: #646970;">Batch: ' . esc_html( $item->batch_id ) . '</span>';
		$output .= '</div>';
		return $output;
	}

	/**
	 * Rendu de la colonne Statut avec badge coloré.
	 *
	 * @param Queue_Item $item Message courant.
	 */
	public function column_status( $item ): string {
		$class = 'eg-mail-badge eg-mail-badge--' . esc_attr( $item->status->value );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $item->status->label() ) . '</span>';
	}

	/**
	 * Rendu de la colonne Priorité.
	 *
	 * @param Queue_Item $item Message courant.
	 */
	public function column_priority( $item ): string {
		$class = 'eg-mail-badge eg-mail-badge--priority-' . esc_attr( (string) $item->priority->value );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $item->priority->label() ) . '</span>';
	}
}
