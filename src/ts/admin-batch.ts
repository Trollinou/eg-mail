/**
 * Logique client TypeScript pour l'administration des campagnes et de la file d'attente EG Mail.
 */

document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.eg_mail_admin_data;
	if ( ! config ) {
		return;
	}

	const { api_url, nonce, night_start, now_local, i18n } = config;

	/**
	 * Envoie une requête POST vers l'API REST du plugin.
	 */
	const postAction = async ( endpoint: string, body?: Record<string, unknown> ): Promise<void> => {
		try {
			const init: RequestInit = {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
			};

			if ( body ) {
				init.body = JSON.stringify( body );
			}

			const response = await fetch( `${ api_url }${ endpoint }`, init );

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

	// 8. Modale de reprogrammation de campagne.
	const rescheduleModal = document.getElementById( 'eg-mail-reschedule-modal' );
	const rescheduleBatchIdInput = document.getElementById( 'eg-mail-reschedule-batch-id' ) as HTMLInputElement | null;
	const rescheduleSubjectSpan = document.getElementById( 'eg-mail-reschedule-subject' );
	const reschedulePrioritySelect = document.getElementById( 'eg-mail-reschedule-priority' ) as HTMLSelectElement | null;
	const rescheduleDatetimeInput = document.getElementById( 'eg-mail-reschedule-datetime' ) as HTMLInputElement | null;
	const rescheduleNightHint = document.getElementById( 'eg-mail-reschedule-night-hint' );
	const rescheduleSubmitBtn = document.getElementById( 'eg-mail-reschedule-submit' ) as HTMLButtonElement | null;

	const closeModals = (): void => {
		if ( modal ) {
			modal.style.display = 'none';
		}
		if ( iframe ) {
			iframe.srcdoc = '';
		}
		if ( rescheduleModal ) {
			rescheduleModal.style.display = 'none';
		}
	};

	const formatLocalDatetime = ( d: Date ): string => {
		const pad = ( n: number ): string => String( n ).padStart( 2, '0' );
		return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
	};

	const getNextNightLocal = ( baseDate?: Date ): string => {
		const ref = baseDate ? new Date( baseDate.getTime() ) : new Date();
		const [ nhStr = '22', nmStr = '00' ] = ( night_start || '22:00' ).split( ':' );
		const nh = parseInt( nhStr, 10 );
		const nm = parseInt( nmStr, 10 );

		const target = new Date( ref.getTime() );
		target.setHours( nh, nm, 0, 0 );

		const now = new Date();
		if ( target.getTime() < now.getTime() ) {
			target.setDate( target.getDate() + 1 );
		}

		return formatLocalDatetime( target );
	};

	const updateNightHint = ( priorityVal: string ): void => {
		if ( rescheduleNightHint ) {
			rescheduleNightHint.style.display = '20' === priorityVal ? 'block' : 'none';
		}
	};

	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-btn-reschedule' );
		if ( ! target || ! rescheduleModal ) {
			return;
		}

		const batchId = target.getAttribute( 'data-batch-id' ) ?? '';
		const subject = target.getAttribute( 'data-subject' ) ?? '';
		const priority = target.getAttribute( 'data-priority' ) ?? '10';
		const scheduledAt = target.getAttribute( 'data-scheduled-at' ) ?? '';

		if ( rescheduleBatchIdInput ) {
			rescheduleBatchIdInput.value = batchId;
		}
		if ( rescheduleSubjectSpan ) {
			rescheduleSubjectSpan.textContent = subject;
		}
		if ( reschedulePrioritySelect ) {
			reschedulePrioritySelect.value = priority;
		}
		if ( rescheduleDatetimeInput ) {
			const normalized = scheduledAt.replace( ' ', 'T' ).slice( 0, 16 );
			rescheduleDatetimeInput.value = normalized || now_local || formatLocalDatetime( new Date() );
		}

		updateNightHint( priority );
		rescheduleModal.style.display = 'flex';
	} );

	if ( reschedulePrioritySelect && rescheduleDatetimeInput ) {
		reschedulePrioritySelect.addEventListener( 'change', () => {
			const newPriority = reschedulePrioritySelect.value;
			updateNightHint( newPriority );

			const currentVal = rescheduleDatetimeInput.value;
			const parsedCurrent = currentVal ? new Date( currentVal ) : new Date();
			const baseDate = isNaN( parsedCurrent.getTime() ) ? new Date() : parsedCurrent;

			if ( '20' === newPriority ) {
				rescheduleDatetimeInput.value = getNextNightLocal( baseDate );
			} else {
				const now = new Date();
				// Si la date sélectionnée est aujourd'hui ou passée, on propose "Maintenant", sinon on garde le jour futur choisi avec l'heure courante.
				if ( baseDate.toDateString() === now.toDateString() || baseDate.getTime() < now.getTime() ) {
					rescheduleDatetimeInput.value = formatLocalDatetime( now );
				}
			}
		} );
	}

	// Boutons de raccourcis rapides dans la modale de reprogrammation.
	document.addEventListener( 'click', ( evt ) => {
		const target = ( evt.target as HTMLElement ).closest( '.eg-mail-shortcut-btn' );
		if ( ! target || ! rescheduleDatetimeInput ) {
			return;
		}

		const shortcut = target.getAttribute( 'data-shortcut' );
		if ( 'now' === shortcut ) {
			rescheduleDatetimeInput.value = formatLocalDatetime( new Date() );
			if ( reschedulePrioritySelect && '20' === reschedulePrioritySelect.value ) {
				reschedulePrioritySelect.value = '10';
				updateNightHint( '10' );
			}
		} else if ( 'tonight' === shortcut ) {
			rescheduleDatetimeInput.value = getNextNightLocal();
		} else if ( 'plus24h' === shortcut ) {
			const currentVal = rescheduleDatetimeInput.value;
			const base = currentVal ? new Date( currentVal ) : new Date();
			const validBase = isNaN( base.getTime() ) ? new Date() : base;
			validBase.setDate( validBase.getDate() + 1 );
			rescheduleDatetimeInput.value = formatLocalDatetime( validBase );
		}
	} );

	// Validation de la reprogrammation.
	if ( rescheduleSubmitBtn ) {
		rescheduleSubmitBtn.addEventListener( 'click', () => {
			const batchId = rescheduleBatchIdInput?.value ?? '';
			const priority = parseInt( reschedulePrioritySelect?.value ?? '10', 10 );
			const scheduledAt = rescheduleDatetimeInput?.value ?? '';

			if ( ! batchId || ! scheduledAt ) {
				return;
			}

			rescheduleSubmitBtn.setAttribute( 'disabled', 'disabled' );
			rescheduleSubmitBtn.textContent = i18n.loading;

			void postAction( `/batches/${ batchId }/reschedule`, {
				priority,
				scheduled_at: scheduledAt,
			} );
		} );
	}

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

	// Fermeture des modales.
	document.addEventListener( 'click', ( evt ) => {
		const target = evt.target as HTMLElement;
		if (
			target.classList.contains( 'eg-mail-modal__close' ) ||
			target.classList.contains( 'eg-mail-modal__close-btn' ) ||
			target.classList.contains( 'eg-mail-modal__backdrop' )
		) {
			closeModals();
		}
	} );

	document.addEventListener( 'keydown', ( evt ) => {
		if ( 'Escape' === evt.key ) {
			closeModals();
		}
	} );
} );
