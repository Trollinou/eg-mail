<?php
/**
 * Rendu du tableau synthétique des campagnes et lots d'e-mails.
 */

declare(strict_types=1);

namespace EGMail\Admin;

use EGMail\DTO\Batch_Summary;
use EGMail\Enums\Queue_Priority;
use EGMail\Repositories\Queue_Repository;

class Batch_List_Table {

	/**
	 * Constructeur avec injection du repository.
	 *
	 * @param Queue_Repository $repository Dépôt pour les requêtes SQL.
	 */
	public function __construct( private Queue_Repository $repository ) {}

	/**
	 * Affiche le tableau HTML complet avec les barres de progression et boutons d'action.
	 */
	public function render(): void {
		$batches = $this->repository->get_batch_summaries( 50, 0 );

		if ( empty( $batches ) ) {
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'Aucune campagne ou envoi groupé enregistré pour le moment.', 'eg-mail' )
				. '</p></div>';
			return;
		}

		echo '<div class="eg-mail-batch-container">';
		echo '<table class="wp-list-table widefat fixed striped eg-mail-batch-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th scope="col" class="manage-column column-subject" style="width: 30%;">' . esc_html__( 'Campagne / Sujet', 'eg-mail' ) . '</th>';
		echo '<th scope="col" class="manage-column column-priority" style="width: 15%;">' . esc_html__( 'Priorité & Programmation', 'eg-mail' ) . '</th>';
		echo '<th scope="col" class="manage-column column-progress" style="width: 30%;">' . esc_html__( 'Progression de l\'envoi', 'eg-mail' ) . '</th>';
		echo '<th scope="col" class="manage-column column-actions" style="width: 25%;">' . esc_html__( 'Actions immédiates', 'eg-mail' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $batches as $batch ) {
			$this->render_row( $batch );
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';

		// Modales HTML (cachées par défaut).
		$this->render_preview_modal();
		$this->render_reschedule_modal();
	}

	/**
	 * Rendu d'une ligne du tableau de campagne.
	 *
	 * @param Batch_Summary $batch Synthèse du lot.
	 */
	private function render_row( Batch_Summary $batch ): void {
		$pct = $batch->progress_percentage();

		echo '<tr data-batch-id="' . esc_attr( $batch->batch_id ) . '">';

		// 1. Sujet et identifiant de lot.
		echo '<td class="column-subject">';
		echo '<strong>' . esc_html( $batch->subject ?: __( '(Sans sujet)', 'eg-mail' ) ) . '</strong>';
		echo '<div class="row-actions">';
		echo '<span class="batch-id-text" style="color: #646970;">ID: ' . esc_html( $batch->batch_id ) . '</span> | ';
		echo '<button type="button" class="button-link eg-mail-btn-preview" data-batch-id="' . esc_attr( $batch->batch_id ) . '">'
			. esc_html__( 'Prévisualiser le message', 'eg-mail' )
			. '</button>';
		echo '</div>';
		echo '</td>';

		// 2. Priorité et programmation.
		echo '<td class="column-priority">';
		echo '<span class="eg-mail-badge eg-mail-badge--priority-' . esc_attr( (string) $batch->priority->value ) . '">';
		echo esc_html( $batch->priority->label() );
		echo '</span>';
		echo '<div style="font-size: 11px; color: #646970; margin-top: 4px;">';
		if ( ! $batch->is_finished() ) {
			echo esc_html__( 'Départ prévu : ', 'eg-mail' ) . esc_html( $batch->scheduled_at );
		} else {
			echo esc_html__( 'Créé le : ', 'eg-mail' ) . esc_html( $batch->created_at );
		}
		echo '</div>';
		echo '</td>';

		// 3. Barre de progression et compteurs.
		echo '<td class="column-progress">';
		echo '<div class="eg-mail-progress-bar">';
		echo '<div class="eg-mail-progress-fill" style="width: ' . esc_attr( (string) $pct ) . '%;"></div>';
		echo '</div>';
		echo '<div class="eg-mail-progress-counts" style="font-size: 12px; margin-top: 4px;">';
		echo '<span style="color: #007017;"><strong>' . esc_html( (string) $batch->sent_count ) . '</strong> ' . esc_html__( 'envoyés', 'eg-mail' ) . '</span> / ';
		echo '<span style="color: #b32d2e;"><strong>' . esc_html( (string) $batch->pending_count ) . '</strong> ' . esc_html__( 'en attente', 'eg-mail' ) . '</span>';
		if ( $batch->paused_count > 0 ) {
			echo ' / <span style="color: #dba617;"><strong>' . esc_html( (string) $batch->paused_count ) . '</strong> ' . esc_html__( 'en pause', 'eg-mail' ) . '</span>';
		}
		if ( $batch->failed_count > 0 ) {
			echo ' / <span style="color: #d63638;"><strong>' . esc_html( (string) $batch->failed_count ) . '</strong> ' . esc_html__( 'échecs', 'eg-mail' ) . '</span>';
		}
		echo ' (' . esc_html__( 'Total : ', 'eg-mail' ) . esc_html( (string) $batch->total_count ) . ' — ' . esc_html( (string) $pct ) . '%)';
		echo '</div>';
		echo '</td>';

		// 4. Boutons d'action.
		echo '<td class="column-actions">';
		if ( ! $batch->is_finished() ) {
			if ( $batch->pending_count > 0 ) {
				echo '<button type="button" class="button button-small eg-mail-btn-pause" data-batch-id="' . esc_attr( $batch->batch_id ) . '" style="margin-right: 4px;">'
					. esc_html__( 'Mettre en pause', 'eg-mail' )
					. '</button>';
			} elseif ( $batch->paused_count > 0 ) {
				echo '<button type="button" class="button button-small button-primary eg-mail-btn-resume" data-batch-id="' . esc_attr( $batch->batch_id ) . '" style="margin-right: 4px;">'
					. esc_html__( 'Reprendre', 'eg-mail' )
					. '</button>';
			}

			echo '<button type="button" class="button button-small eg-mail-btn-reschedule" data-batch-id="' . esc_attr( $batch->batch_id ) . '" data-subject="' . esc_attr( $batch->subject ?: __( '(Sans sujet)', 'eg-mail' ) ) . '" data-priority="' . esc_attr( (string) $batch->priority->value ) . '" data-scheduled-at="' . esc_attr( $batch->scheduled_at ) . '" style="margin-right: 4px;">'
				. esc_html__( 'Reprogrammer', 'eg-mail' )
				. '</button>';

			echo '<button type="button" class="button button-small button-link-delete eg-mail-btn-cancel" data-batch-id="' . esc_attr( $batch->batch_id ) . '">'
				. esc_html__( 'Annuler & Purger', 'eg-mail' )
				. '</button>';
		} else {
			echo '<span class="eg-mail-badge eg-mail-badge--finished">' . esc_html__( 'Terminé', 'eg-mail' ) . '</span>';
		}
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Structure HTML de la modale de prévisualisation d'e-mail.
	 */
	private function render_preview_modal(): void {
		echo '<div id="eg-mail-preview-modal" class="eg-mail-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="eg-mail-modal-title">';
		echo '<div class="eg-mail-modal__backdrop"></div>';
		echo '<div class="eg-mail-modal__dialog">';
		echo '<div class="eg-mail-modal__header">';
		echo '<h2 id="eg-mail-modal-title" class="eg-mail-modal__title">' . esc_html__( 'Aperçu du Message', 'eg-mail' ) . '</h2>';
		echo '<button type="button" class="eg-mail-modal__close" aria-label="' . esc_attr__( 'Fermer', 'eg-mail' ) . '">&times;</button>';
		echo '</div>';
		echo '<div class="eg-mail-modal__body">';
		echo '<div class="eg-mail-modal__field"><strong>' . esc_html__( 'Sujet : ', 'eg-mail' ) . '</strong><span id="eg-mail-preview-subject"></span></div>';
		echo '<div class="eg-mail-modal__field"><strong>' . esc_html__( 'Date de création : ', 'eg-mail' ) . '</strong><span id="eg-mail-preview-date"></span></div>';
		echo '<div class="eg-mail-modal__field" id="eg-mail-preview-attachments-container" style="display: none;"><strong>' . esc_html__( 'Pièces jointes : ', 'eg-mail' ) . '</strong><ul id="eg-mail-preview-attachments"></ul></div>';
		echo '<hr style="margin: 15px 0;">';
		echo '<div class="eg-mail-modal__content-wrapper">';
		echo '<iframe id="eg-mail-preview-iframe" class="eg-mail-modal__iframe" sandbox="allow-same-origin" title="' . esc_attr__( 'Corps du message', 'eg-mail' ) . '"></iframe>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eg-mail-modal__footer">';
		echo '<button type="button" class="button button-secondary eg-mail-modal__close-btn">' . esc_html__( 'Fermer', 'eg-mail' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Structure HTML de la modale de reprogrammation d'une campagne.
	 */
	private function render_reschedule_modal(): void {
		echo '<div id="eg-mail-reschedule-modal" class="eg-mail-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="eg-mail-reschedule-title">';
		echo '<div class="eg-mail-modal__backdrop"></div>';
		echo '<div class="eg-mail-modal__dialog eg-mail-modal__dialog--small">';
		echo '<div class="eg-mail-modal__header">';
		echo '<h2 id="eg-mail-reschedule-title" class="eg-mail-modal__title">' . esc_html__( 'Reprogrammer la Campagne', 'eg-mail' ) . '</h2>';
		echo '<button type="button" class="eg-mail-modal__close" aria-label="' . esc_attr__( 'Fermer', 'eg-mail' ) . '">&times;</button>';
		echo '</div>';
		echo '<div class="eg-mail-modal__body">';
		echo '<input type="hidden" id="eg-mail-reschedule-batch-id" value="">';

		echo '<div class="eg-mail-modal__field">';
		echo '<strong>' . esc_html__( 'Campagne : ', 'eg-mail' ) . '</strong><span id="eg-mail-reschedule-subject"></span>';
		echo '</div>';

		echo '<div class="eg-mail-modal__form-group">';
		echo '<label for="eg-mail-reschedule-priority"><strong>' . esc_html__( 'Priorité d\'envoi', 'eg-mail' ) . '</strong></label>';
		echo '<select id="eg-mail-reschedule-priority" class="regular-text">';
		echo '<option value="' . esc_attr( (string) Queue_Priority::CRITICAL->value ) . '">' . esc_html( Queue_Priority::CRITICAL->label() ) . '</option>';
		echo '<option value="' . esc_attr( (string) Queue_Priority::NORMAL->value ) . '">' . esc_html( Queue_Priority::NORMAL->label() ) . '</option>';
		echo '<option value="' . esc_attr( (string) Queue_Priority::LOW->value ) . '">' . esc_html( Queue_Priority::LOW->label() ) . '</option>';
		echo '</select>';
		echo '<p id="eg-mail-reschedule-night-hint" class="description eg-mail-modal__hint" style="display: none;">';
		echo esc_html__( 'Info : La priorité "Basse / Nuit" restreint l\'expédition aux seules fenêtres nocturnes à partir de la date choisie. Pour un envoi en journée à une heure précise, sélectionnez la priorité "Normale".', 'eg-mail' );
		echo '</p>';
		echo '</div>';

		echo '<div class="eg-mail-modal__form-group">';
		echo '<label for="eg-mail-reschedule-datetime"><strong>' . esc_html__( 'Date et heure de départ prévues', 'eg-mail' ) . '</strong></label>';
		echo '<input type="datetime-local" id="eg-mail-reschedule-datetime" class="regular-text" required>';
		echo '<div class="eg-mail-modal__shortcuts">';
		echo '<button type="button" class="button button-small eg-mail-shortcut-btn" data-shortcut="now">' . esc_html__( 'Maintenant', 'eg-mail' ) . '</button>';
		echo '<button type="button" class="button button-small eg-mail-shortcut-btn" data-shortcut="tonight">' . esc_html__( 'Ce soir (Plage nuit)', 'eg-mail' ) . '</button>';
		echo '<button type="button" class="button button-small eg-mail-shortcut-btn" data-shortcut="plus24h">' . esc_html__( '+24h (Décaler d\'un jour)', 'eg-mail' ) . '</button>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Si la campagne était en pause, elle repassera automatiquement au statut "En attente" pour partir à l\'échéance choisie.', 'eg-mail' ) . '</p>';
		echo '</div>';

		echo '</div>';
		echo '<div class="eg-mail-modal__footer">';
		echo '<button type="button" class="button button-secondary eg-mail-modal__close-btn" style="margin-right: 8px;">' . esc_html__( 'Annuler', 'eg-mail' ) . '</button>';
		echo '<button type="button" id="eg-mail-reschedule-submit" class="button button-primary">' . esc_html__( 'Enregistrer la programmation', 'eg-mail' ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}
}
