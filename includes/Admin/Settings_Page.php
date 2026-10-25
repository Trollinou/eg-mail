<?php
/**
 * Rendu et traitement du formulaire de la page Réglages & Quotas.
 */

declare(strict_types=1);

namespace EGMail\Admin;

use EGMail\Core\Backup_Integration;
use EGMail\Core\Settings;
use EGMail\Repositories\Queue_Repository;
use EGMail\Utils\Request;

class Settings_Page {

	/**
	 * Constructeur avec injection des dépendances.
	 *
	 * @param Settings         $settings   Gestionnaire de configuration.
	 * @param Queue_Repository $repository Dépôt de données.
	 */
	public function __construct(
		private Settings $settings,
		private Queue_Repository $repository
	) {}

	/**
	 * Rendu de la page et traitement de l'enregistrement si soumis.
	 */
	public function render(): void {
		$message = $this->handle_save();

		$current = $this->settings->get_all();

		// Bannière de réconciliation PRA si des données sont en secours dans le snapshot.
		$this->render_pra_banner();

		if ( null !== $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'eg_mail_save_settings', 'eg_mail_nonce' ); ?>

			<h2><?php esc_html_e( '1. Régulation du Flux SMTP (Rate Limiting)', 'eg-mail' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configurez les plafonds d\'envoi par minute et le plafond horaire glissant imposé par votre hébergeur.', 'eg-mail' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="quota_minute_day"><?php esc_html_e( 'Quota par minute (Journée)', 'eg-mail' ); ?></label></th>
					<td>
						<input name="quota_minute_day" type="number" id="quota_minute_day" value="<?php echo esc_attr( (string) $current['quota_minute_day'] ); ?>" class="small-text" min="1" max="100" />
						<p class="description"><?php esc_html_e( 'Nombre maximum d\'e-mails envoyés par cycle du Worker (toutes les minutes) en journée (défaut : 10).', 'eg-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="quota_minute_night"><?php esc_html_e( 'Quota par minute (Nuit)', 'eg-mail' ); ?></label></th>
					<td>
						<input name="quota_minute_night" type="number" id="quota_minute_night" value="<?php echo esc_attr( (string) $current['quota_minute_night'] ); ?>" class="small-text" min="1" max="200" />
						<p class="description"><?php esc_html_e( 'Nombre d\'e-mails autorisés par minute durant la plage horaire nocturne (défaut : 25).', 'eg-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="quota_hour"><?php esc_html_e( 'Quota horaire glissant (Optionnel)', 'eg-mail' ); ?></label></th>
					<td>
						<input name="quota_hour" type="number" id="quota_hour" value="<?php echo esc_attr( (string) $current['quota_hour'] ); ?>" class="small-text" min="0" />
						<p class="description"><?php esc_html_e( 'Nombre maximum d\'e-mails sur 60 minutes glissantes. Laissez à 0 pour désactiver (défaut : 0).', 'eg-mail' ); ?></p>
					</td>
				</tr>
			</table>

			<hr />

			<h2><?php esc_html_e( '2. Plage Horaire Nocturne (Priorités basses & campagnes)', 'eg-mail' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Les envois de masse (newsletters, convocations de priorité 20) sont automatiquement différés dans cette fenêtre.', 'eg-mail' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="night_start"><?php esc_html_e( 'Heure de début (Nuit)', 'eg-mail' ); ?></label></th>
					<td>
						<input name="night_start" type="time" id="night_start" value="<?php echo esc_attr( (string) $current['night_start'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Heure à laquelle les envois nocturnes commencent (ex: 22:00).', 'eg-mail' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="night_end"><?php esc_html_e( 'Heure de fin (Nuit)', 'eg-mail' ); ?></label></th>
					<td>
						<input name="night_end" type="time" id="night_end" value="<?php echo esc_attr( (string) $current['night_end'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Heure à laquelle les envois nocturnes sont stoppés si le lot n\'est pas terminé (ex: 06:00).', 'eg-mail' ); ?></p>
					</td>
				</tr>
			</table>

			<hr />

			<h2><?php esc_html_e( '3. Configuration SMTP Personnalisée (Optionnel)', 'eg-mail' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Si activé, surcharge la configuration mail de WordPress pour forcer un serveur SMTP avec KeepAlive.', 'eg-mail' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activer le forçage SMTP', 'eg-mail' ); ?></th>
					<td>
						<label for="smtp_force">
							<input name="smtp_force" type="checkbox" id="smtp_force" value="1" <?php checked( (bool) $current['smtp_force'] ); ?> />
							<?php esc_html_e( 'Forcer l\'envoi via les paramètres SMTP ci-dessous', 'eg-mail' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_host"><?php esc_html_e( 'Hôte SMTP', 'eg-mail' ); ?></label></th>
					<td><input name="smtp_host" type="text" id="smtp_host" value="<?php echo esc_attr( (string) $current['smtp_host'] ); ?>" class="regular-text" placeholder="smtp.example.com" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_port"><?php esc_html_e( 'Port SMTP', 'eg-mail' ); ?></label></th>
					<td><input name="smtp_port" type="number" id="smtp_port" value="<?php echo esc_attr( (string) $current['smtp_port'] ); ?>" class="small-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_encryption"><?php esc_html_e( 'Chiffrement', 'eg-mail' ); ?></label></th>
					<td>
						<select name="smtp_encryption" id="smtp_encryption">
							<option value="tls" <?php selected( $current['smtp_encryption'], 'tls' ); ?>>TLS (STARTTLS - Port 587)</option>
							<option value="ssl" <?php selected( $current['smtp_encryption'], 'ssl' ); ?>>SSL (Port 465)</option>
							<option value="none" <?php selected( $current['smtp_encryption'], 'none' ); ?>><?php esc_html_e( 'Aucun', 'eg-mail' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authentification', 'eg-mail' ); ?></th>
					<td>
						<label for="smtp_auth">
							<input name="smtp_auth" type="checkbox" id="smtp_auth" value="1" <?php checked( (bool) $current['smtp_auth'] ); ?> />
							<?php esc_html_e( 'Le serveur SMTP requiert une authentification', 'eg-mail' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_username"><?php esc_html_e( 'Identifiant SMTP', 'eg-mail' ); ?></label></th>
					<td><input name="smtp_username" type="text" id="smtp_username" value="<?php echo esc_attr( (string) $current['smtp_username'] ); ?>" class="regular-text" autocomplete="off" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_password"><?php esc_html_e( 'Mot de passe SMTP', 'eg-mail' ); ?></label></th>
					<td><input name="smtp_password" type="password" id="smtp_password" value="<?php echo esc_attr( (string) $current['smtp_password'] ); ?>" class="regular-text" autocomplete="new-password" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_from_email"><?php esc_html_e( 'E-mail d\'expédition par défaut', 'eg-mail' ); ?></label></th>
					<td><input name="smtp_from_email" type="email" id="smtp_from_email" value="<?php echo esc_attr( (string) $current['smtp_from_email'] ); ?>" class="regular-text" placeholder="contact@example.com" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="smtp_from_name"><?php esc_html_e( 'Nom d\'expéditeur par défaut', 'eg-mail' ); ?></label></th>
					<td><input name="smtp_from_name" type="text" id="smtp_from_name" value="<?php echo esc_attr( (string) $current['smtp_from_name'] ); ?>" class="regular-text" /></td>
				</tr>
			</table>

			<hr />

			<h2><?php esc_html_e( '4. Rétention des Journaux', 'eg-mail' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="retention_days"><?php esc_html_e( 'Conservation des e-mails envoyés', 'eg-mail' ); ?></label></th>
					<td>
						<input name="retention_days" type="number" id="retention_days" value="<?php echo esc_attr( (string) $current['retention_days'] ); ?>" class="small-text" min="1" max="90" /> <?php esc_html_e( 'jours', 'eg-mail' ); ?>
						<p class="description"><?php esc_html_e( 'Les messages au statut "Envoyé" seront automatiquement purgés de la table SQL après ce délai (défaut : 15 jours).', 'eg-mail' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Enregistrer les réglages', 'eg-mail' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Traite la soumission du formulaire d'enregistrement.
	 */
	private function handle_save(): ?string {
		if ( 'POST' !== Request::method() ) {
			return null;
		}

		if ( ! Request::verify_post_nonce( 'eg_mail_nonce', 'eg_mail_save_settings' ) ) {
			return null;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$new_settings = [
			'smtp_force'         => Request::post_bool( 'smtp_force' ),
			'smtp_host'          => Request::post_string( 'smtp_host' ),
			'smtp_port'          => Request::post_int( 'smtp_port', 587 ),
			'smtp_encryption'    => Request::post_key( 'smtp_encryption', 'tls' ),
			'smtp_auth'          => Request::post_bool( 'smtp_auth' ),
			'smtp_username'      => Request::post_string( 'smtp_username' ),
			'smtp_password'      => Request::post_string( 'smtp_password' ),
			'smtp_from_email'    => Request::post_email( 'smtp_from_email' ),
			'smtp_from_name'     => Request::post_string( 'smtp_from_name' ),
			'quota_minute_day'   => max( 1, Request::post_int( 'quota_minute_day', 10 ) ),
			'quota_minute_night' => max( 1, Request::post_int( 'quota_minute_night', 25 ) ),
			'quota_hour'         => Request::post_int( 'quota_hour', 0 ),
			'night_start'        => Request::post_string( 'night_start', '22:00' ),
			'night_end'          => Request::post_string( 'night_end', '06:00' ),
			'retention_days'     => max( 1, Request::post_int( 'retention_days', 15 ) ),
		];

		$this->settings->update( $new_settings );

		return __( 'Réglages enregistrés avec succès.', 'eg-mail' );
	}

	/**
	 * Affiche la bannière de secours PRA si des données sont présentes dans le snapshot et manquantes en BDD.
	 */
	private function render_pra_banner(): void {
		$snapshot = Backup_Integration::read_snapshot();
		if ( null === $snapshot || empty( $snapshot['items'] ) ) {
			return;
		}

		$count         = count( $snapshot['items'] );
		$db_counts     = $this->repository->count_by_status();
		$pending_in_db = ( $db_counts['pending'] ?? 0 ) + ( $db_counts['paused'] ?? 0 );

		// Si les données du snapshot sont déjà toutes en base, pas besoin de réconciliation.
		if ( $count <= $pending_in_db ) {
			return;
		}

		echo '<div class="notice notice-warning" style="border-left-color: #d63638;">';
		echo '<p><strong>' . esc_html__( 'Continuité d\'activité (EG Backup / PRA) :', 'eg-mail' ) . '</strong> ';
		printf(
			/* translators: 1: nombre d'e-mails dans le snapshot, 2: nombre en base */
			esc_html__( 'Un snapshot miroir contient %1$d e-mail(s) sur disque contre %2$d en base SQL. Si vous venez d\'effectuer une restauration, vous pouvez réimporter ces messages en toute sécurité.', 'eg-mail' ),
			(int) $count,
			(int) $pending_in_db
		);
		echo ' <button type="button" class="button button-secondary eg-mail-btn-reconcile" style="margin-left: 10px;">' . esc_html__( 'Réimporter les e-mails de secours', 'eg-mail' ) . '</button>';
		echo '</p></div>';
	}
}
