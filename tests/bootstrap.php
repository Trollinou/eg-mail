<?php
/**
 * Bootstrap PHPUnit pour EG Mail.
 */

declare(strict_types=1);

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

// Stubs WordPress légers pour l'exécution unitaire isolée.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$tmp_dir = sys_get_temp_dir() . '/eg-mail-test-uploads';
		return [
			'basedir' => $tmp_dir,
			'baseurl' => 'http://example.com/wp-content/uploads',
		];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^a-zA-Z0-9._-]/', '', $name ) ?? $name;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( string $file ): bool {
		return file_exists( $file ) ? unlink( $file ) : false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string|int {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( string|int $key, string $group = '' ): mixed {
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( string|int $key, mixed $data, string $group = '', int $expire = 0 ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( string|int $key, string $group = '' ): bool {
		return true;
	}
}
