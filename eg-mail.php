<?php
/**
 * Plugin Name:       EG Mail
 * Plugin URI:        https://example.com/eg-mail
 * Description:       Middleware d'interception transparente de wp_mail(), régulation SMTP multi-niveaux et gestion de lots d'e-mails.
 * Version:           1.0.3
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            Etienne Gagnon
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eg-mail
 * Domain Path:       /languages
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constantes globales du plugin.
define( 'EG_MAIL_VERSION', '1.0.3' );
define( 'EG_MAIL_PLUGIN_FILE', __FILE__ );
define( 'EG_MAIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EG_MAIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EG_MAIL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// 1. Chargement conditionnel de Composer (environnement de développement ou vendor local).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// 2. Autoloader SPL natif PSR-4 pour le namespace EGMail\ depuis includes/.
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix   = 'EGMail\\';
		$base_dir = plugin_dir_path( __FILE__ ) . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

// Hooks du cycle de vie du plugin.
register_activation_hook( __FILE__, [ 'EGMail\\Core\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'EGMail\\Core\\Activator', 'deactivate' ] );

// Initialisation globale du plugin.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( 'EGMail\\Core\\Plugin' ) ) {
			EGMail\Core\Plugin::instance()->init();
		}
	}
);
