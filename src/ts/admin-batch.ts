/**
 * Logique client TypeScript pour l'administration des campagnes et de la file d'attente EG Mail.
 */

document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.eg_mail_admin_data;
	if ( ! config ) {
		return;
	}

	const { api_url, nonce, i18n } = config;

	/**
	 * Envoie une requête POST vers l'API REST du plugin.
	 */
	const postAction = async ( endpoint: string ): Promise<void> => {
		try {
			const response = await fetch( `${ api_url }${ endpoint }`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
			} );

			const data = ( await response.json() ) as { success?: boolean; message?: string };
			if ( data.success ) {
				window.location.reload();
			} else {
				alert( data.message ?? i18n.error );
			}
		} catch ( err ) {
			console.error( err );
			alert( i18n.error );
		}
	};

	// 1. Bouton "Mettre en pause" d'un lot.
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-pause' );
		if ( ! target ) {
			return;
		}

		const batchId = target.getAttribute( 'data-batch-id' );
		if ( batchId ) {
			target.setAttribute( 'disabled', 'disabled' );
			void postAction( `/batches/${ batchId }/pause` );
		}
	} );

	// 2. Bouton "Reprendre" d'un lot.
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-resume' );
		if ( ! target ) {
			return;
		}

		const batchId = target.getAttribute( 'data-batch-id' );
		if ( batchId ) {
			target.setAttribute( 'disabled', 'disabled' );
			void postAction( `/batches/${ batchId }/resume` );
		}
	} );

	// 3. Bouton "Annuler et Purger" d'un lot (avec confirmation).
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-cancel' );
		if ( ! target ) {
			return;
		}

		if ( ! confirm( i18n.confirm_cancel ) ) {
			return;
		}

		const batchId = target.getAttribute( 'data-batch-id' );
		if ( batchId ) {
			target.setAttribute( 'disabled', 'disabled' );
			void postAction( `/batches/${ batchId }/cancel` );
		}
	} );

	// 4. Bouton "Forcer le départ diurne".
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-force-day' );
		if ( ! target ) {
			return;
		}

		const batchId = target.getAttribute( 'data-batch-id' );
		if ( batchId ) {
			target.setAttribute( 'disabled', 'disabled' );
			void postAction( `/batches/${ batchId }/force-day` );
		}
	} );

	// 5. Bouton "Vider toute la file d'attente".
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-purge' );
		if ( ! target ) {
			return;
		}

		if ( ! confirm( i18n.confirm_purge ) ) {
			return;
		}

		target.setAttribute( 'disabled', 'disabled' );
		void postAction( '/queue/purge-pending' );
	} );

	// 6. Bouton "Réimporter les e-mails de secours (PRA)".
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-reconcile' );
		if ( ! target ) {
			return;
		}

		target.setAttribute( 'disabled', 'disabled' );
		void postAction( '/pra/reconcile' );
	} );

	// 6b. Bouton "Dépiler la file maintenant" (Exécution manuelle du Worker).
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-process' );
		if ( ! target ) {
			return;
		}

		target.setAttribute( 'disabled', 'disabled' );
		target.textContent = i18n.loading;
		void postAction( '/queue/process' );
	} );

	// 7. Modale de prévisualisation du message.
	const modal = document.getElementById( 'eg-mail-preview-modal' );
	const subjectSpan = document.getElementById( 'eg-mail-preview-subject' );
	const dateSpan = document.getElementById( 'eg-mail-preview-date' );
	const iframe = document.getElementById( 'eg-mail-preview-iframe' ) as HTMLIFrameElement | null;
	const attachContainer = document.getElementById( 'eg-mail-preview-attachments-container' );
	const attachList = document.getElementById( 'eg-mail-preview-attachments' );

	const closeModal = (): void => {
		if ( modal ) {
			modal.style.display = 'none';
		}
		if ( iframe ) {
			iframe.srcdoc = '';
		}
	};

	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-preview' );
		if ( ! target ) {
			return;
		}

		const batchId = target.getAttribute( 'data-batch-id' );
		if ( ! batchId || ! modal ) {
			return;
		}

		void ( async () => {
			try {
				const res = await fetch( `${ api_url }/batches/${ batchId }/preview`, {
					headers: { 'X-WP-Nonce': nonce },
				} );
				const json = ( await res.json() ) as {
					success: boolean;
					subject?: string;
					message?: string;
					created_at?: string;
					attachments?: { filename: string; size: number }[];
				};

				if ( json.success ) {
					if ( subjectSpan ) {
						subjectSpan.textContent = json.subject ?? '';
					}
					if ( dateSpan ) {
						dateSpan.textContent = json.created_at ?? '';
					}
					if ( iframe && json.message ) {
						iframe.srcdoc = json.message;
					}

					if ( attachContainer && attachList ) {
						if ( json.attachments && json.attachments.length > 0 ) {
							attachList.innerHTML = json.attachments
								.map( ( a ) => `<li>📎 ${ a.filename } (${ Math.round( a.size / 1024 ) } Ko)</li>` )
								.join( '' );
							attachContainer.style.display = 'block';
						} else {
							attachContainer.style.display = 'none';
						}
					}

					modal.style.display = 'flex';
				}
			} catch ( err ) {
				console.error( err );
			}
		} )();
	} );

	// Fermeture de la modale.
	document.addEventListener( 'click', ( evt ) => {
		const target = evt.target as HTMLElement;
		if (
			target.classList.contains( 'eg-mail-modal__close' ) ||
			target.classList.contains( 'eg-mail-modal__close-btn' ) ||
			target.classList.contains( 'eg-mail-modal__backdrop' )
		) {
			closeModal();
		}
	} );

	document.addEventListener( 'keydown', ( evt ) => {
		if ( 'Escape' === evt.key && modal && 'none' !== modal.style.display ) {
			closeModal();
		}
	} );
} );
