<?php
declare(strict_types=1);

// Constantes globales du plugin pour PHPStan.
if ( ! defined( 'EG_MAIL_VERSION' ) ) {
	define( 'EG_MAIL_VERSION', '1.0.0' );
}

if ( ! defined( 'EG_MAIL_PLUGIN_DIR' ) ) {
	define( 'EG_MAIL_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'EG_MAIL_PLUGIN_URL' ) ) {
	define( 'EG_MAIL_PLUGIN_URL', 'https://example.com/wp-content/plugins/eg-mail/' );
}
