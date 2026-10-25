<?php
/**
 * Contrôleur du menu d'administration, des sous-pages sous "Outils" et du chargement des assets.
 */

declare(strict_types=1);

namespace EGMail\Admin;

use EGMail\Core\Settings;
use EGMail\Repositories\Queue_Repository;
use EGMail\Utils\Request;

class Admin_Menu {

	/**
	 * Slugs des pages du menu.
	 */
	public const PAGE_BATCHES  = 'eg-mail-batches';
	public const PAGE_QUEUE    = 'eg-mail-queue';
	public const PAGE_SETTINGS = 'eg-mail-settings';

	/**
	 * Identifiants d'écrans retournés lors de l'enregistrement des menus.
	 *
	 * @var array<int, string>
	 */
	private array $hook_suffixes = [];

	/**
	 * Constructeur avec injection des dépendances.
	 *
	 * @param Queue_Repository $repository Dépôt de données.
	 * @param Settings         $settings   Gestionnaire de configuration.
	 */
	public function __construct(
		private Queue_Repository $repository,
		private Settings $settings
	) {}

	/**
	 * Initialise les hooks WordPress pour l'administration.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enregistre le menu de premier niveau et ses sous-pages dans l'administration WordPress.
	 */
	public function register_menu_pages(): void {
		// 1. Menu de premier niveau : EG Mail (icône dashicons-email-alt2).
		$main_hook = add_menu_page(
			__( 'EG Mail — Campagnes', 'eg-mail' ),
			__( 'EG Mail', 'eg-mail' ),
			'manage_options',
			self::PAGE_BATCHES,
			[ $this, 'render_batches_page' ],
			'dashicons-email-alt2',
			26
		);

		if ( is_string( $main_hook ) ) {
			$this->hook_suffixes[] = $main_hook;
		}

		// 2. Sous-menu 1 : Campagnes & Lots (renommage du premier sous-menu par défaut).
		$batches_hook = add_submenu_page(
			self::PAGE_BATCHES,
			__( 'EG Mail — Campagnes', 'eg-mail' ),
			__( 'Campagnes & Lots', 'eg-mail' ),
			'manage_options',
			self::PAGE_BATCHES,
			[ $this, 'render_batches_page' ]
		);

		if ( is_string( $batches_hook ) ) {
			$this->hook_suffixes[] = $batches_hook;
		}

		// 3. Sous-menu 2 : File d'attente FIFO.
		$queue_hook = add_submenu_page(
			self::PAGE_BATCHES,
			__( 'EG Mail — File d\'attente', 'eg-mail' ),
			__( 'File d\'attente', 'eg-mail' ),
			'manage_options',
			self::PAGE_QUEUE,
			[ $this, 'render_queue_page' ]
		);

		if ( is_string( $queue_hook ) ) {
			$this->hook_suffixes[] = $queue_hook;
		}

		// 4. Sous-menu 3 : Réglages & Quotas.
		$settings_hook = add_submenu_page(
			self::PAGE_BATCHES,
			__( 'EG Mail — Réglages', 'eg-mail' ),
			__( 'Réglages', 'eg-mail' ),
			'manage_options',
			self::PAGE_SETTINGS,
			[ $this, 'render_settings_page' ]
		);

		if ( is_string( $settings_hook ) ) {
			$this->hook_suffixes[] = $settings_hook;
		}
	}

	/**
	 * Enfile conditionnellement les styles et scripts uniquement sur les écrans du plugin.
	 *
	 * @param string $hook_suffix Identifiant de l'écran admin courant.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$allowed_pages = array_merge(
			$this->hook_suffixes,
			[
				'toplevel_page_' . self::PAGE_BATCHES,
				self::PAGE_BATCHES . '_page_' . self::PAGE_QUEUE,
				self::PAGE_BATCHES . '_page_' . self::PAGE_SETTINGS,
			]
		);

		if ( ! in_array( $hook_suffix, $allowed_pages, true ) ) {
			return;
		}

		// Enregistrement et chargement des feuilles de style SCSS compilées.
		wp_register_style(
			'eg-mail-admin-queue',
			EG_MAIL_PLUGIN_URL . 'assets/css/admin-queue.css',
			[],
			EG_MAIL_VERSION
		);
		wp_enqueue_style( 'eg-mail-admin-queue' );

		// Enregistrement et chargement du script TypeScript bundlé avec stratégie defer.
		wp_register_script(
			'eg-mail-admin-batch',
			EG_MAIL_PLUGIN_URL . 'assets/js/admin-batch.js',
			[],
			EG_MAIL_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);

		// Transmission du dictionnaire de configuration typé (snake_case sur window).
		wp_localize_script(
			'eg-mail-admin-batch',
			'eg_mail_admin_data',
			[
				'api_url' => rest_url( 'eg-mail/v1' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'confirm_cancel' => __( 'Confirmez-vous l\'annulation et la purge de ce lot ? Les e-mails restants ne seront pas envoyés.', 'eg-mail' ),
					'confirm_purge'  => __( 'Êtes-vous sûr de vouloir vider TOUTE la file d\'attente ? Cette action est irréversible.', 'eg-mail' ),
					'loading'        => __( 'Traitement en cours...', 'eg-mail' ),
					'error'          => __( 'Une erreur réseau est survenue.', 'eg-mail' ),
				],
			]
		);

		wp_enqueue_script( 'eg-mail-admin-batch' );
	}

	/**
	 * Rendu de la barre d'onglets de navigation.
	 *
	 * @param string $active_page Page actuellement affichée.
	 */
	public function render_nav_tabs( string $active_page ): void {
		$tabs = [
			self::PAGE_BATCHES  => __( 'Campagnes & Lots', 'eg-mail' ),
			self::PAGE_QUEUE    => __( 'File d\'attente détaillée', 'eg-mail' ),
			self::PAGE_SETTINGS => __( 'Réglages SMTP & Quotas', 'eg-mail' ),
		];

		echo '<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">';
		foreach ( $tabs as $page => $label ) {
			$active_class = ( $page === $active_page ) ? ' nav-tab-active' : '';
			$url          = admin_url( 'admin.php?page=' . $page );
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active_class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Rendu de la page Campagnes & Lots.
	 */
	public function render_batches_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'eg-mail' ) );
		}

		echo '<div class="wrap eg-mail-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'EG Mail — Suivi des Campagnes & Lots', 'eg-mail' ) . '</h1>';
		echo '<button type="button" class="button button-primary eg-mail-btn-process" style="margin-left: 10px;">' . esc_html__( 'Dépiler la file maintenant', 'eg-mail' ) . '</button>';
		echo '<hr class="wp-header-end">';

		$this->render_nav_tabs( self::PAGE_BATCHES );

		$table = new Batch_List_Table( $this->repository );
		$table->render();

		echo '</div>';
	}

	/**
	 * Rendu de la page détaillée File d'attente (FIFO).
	 */
	public function render_queue_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'eg-mail' ) );
		}

		echo '<div class="wrap eg-mail-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'EG Mail — Journal Détaillé de la File d\'Attente', 'eg-mail' ) . '</h1>';
		echo '<button type="button" class="button button-primary eg-mail-btn-process" style="margin-left: 10px;">' . esc_html__( 'Dépiler la file maintenant', 'eg-mail' ) . '</button>';
		echo '<button type="button" class="button button-secondary eg-mail-btn-purge" style="margin-left: 10px;">' . esc_html__( 'Vider toute la file', 'eg-mail' ) . '</button>';
		echo '<hr class="wp-header-end">';

		$this->render_nav_tabs( self::PAGE_QUEUE );

		$table = new Queue_List_Table( $this->repository );
		$table->prepare_items();

		$table->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_QUEUE ) . '" />';
		$status = Request::get_key( 'status' );
		if ( '' !== $status && 'all' !== $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '" />';
		}
		$table->search_box( __( 'Rechercher un destinataire', 'eg-mail' ), 'eg-mail-search' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Rendu de la page de Réglages & Quotas.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'eg-mail' ) );
		}

		echo '<div class="wrap eg-mail-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'EG Mail — Réglages & Régulation', 'eg-mail' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		$this->render_nav_tabs( self::PAGE_SETTINGS );

		$page = new Settings_Page( $this->settings, $this->repository );
		$page->render();

		echo '</div>';
	}
}
