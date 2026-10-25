<?php
declare(strict_types=1);

// Bootstrap PHPUnit pour EG Mail.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Constantes minimales WordPress si non définies.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'EG_MAIL_VERSION' ) ) {
	define( 'EG_MAIL_VERSION', '1.0.0' );
}

if ( ! defined( 'EG_MAIL_PLUGIN_DIR' ) ) {
	define( 'EG_MAIL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'EG_MAIL_PLUGIN_URL' ) ) {
	define( 'EG_MAIL_PLUGIN_URL', 'https://example.com/wp-content/plugins/eg-mail/' );
}
