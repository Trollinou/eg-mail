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

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Mémoire de simulation pour options et transients.
$GLOBALS['eg_test_options']    = [];
$GLOBALS['eg_test_transients'] = [];

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null ): string {
		$time = null !== $timestamp ? $timestamp : time();
		$tz   = null !== $timezone ? $timezone : wp_timezone();
		$dt   = new DateTimeImmutable( '@' . $time )->setTimezone( $tz );
		return $dt->format( $format );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( date_default_timezone_get() );
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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $key ) ?? $key );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return strip_tags( trim( $str ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		return filter_var( $email, FILTER_SANITIZE_EMAIL ) ?: '';
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

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: $url;
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( string $text, int $num_words = 55, string $more = '…' ): string {
		$words = explode( ' ', $text );
		if ( count( $words ) > $num_words ) {
			return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
		}
		return $text;
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

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1 ): true {
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $tag, callable $function_to_remove, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1 ): true {
		$GLOBALS['wp_actions_registered'][ $tag ][] = [
			'callback' => $function_to_add,
			'priority' => $priority,
		];
		return true;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $tag, mixed $function_to_check = false ): int|bool {
		if ( empty( $GLOBALS['wp_actions_registered'][ $tag ] ) ) {
			return false;
		}

		if ( false === $function_to_check ) {
			return true;
		}

		foreach ( $GLOBALS['wp_actions_registered'][ $tag ] as $entry ) {
			if ( $entry['callback'] === $function_to_check ) {
				return $entry['priority'];
			}
		}

		return false;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $tag, callable $function_to_remove, int $priority = 10 ): bool {
		if ( isset( $GLOBALS['wp_actions_registered'][ $tag ] ) ) {
			unset( $GLOBALS['wp_actions_registered'][ $tag ] );
		}
		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'UTF-8';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['eg_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['eg_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ): mixed {
		return $GLOBALS['eg_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['eg_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['eg_test_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ): string|false {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( mixed $to, string $subject, string $message, mixed $headers = '', mixed $attachments = [] ): bool {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [] ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null, string $icon_url = '', ?int $position = null ): string {
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, ?callable $callback = null, ?int $position = null ): string {
		return $parent_slug . '_page_' . $menu_slug;
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/**
		 * @param array<string, mixed> $params
		 */
		public function __construct(
			private string $method = 'GET',
			private string $route = '',
			private array $params = []
		) {}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct(
			private mixed $data = null,
			private int $status = 200
		) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	class WP_List_Table {
		/** @var array<int, mixed> */
		public array $items = [];
		/** @var array<string, mixed> */
		protected array $_args = [];
		/** @var ?array{0: array<string, string>, 1: array<int, string>, 2: array<string, array{0: string, 1: bool}>, 3?: string} */
		protected ?array $_column_headers = null;
		/** @var ?array<string, mixed> */
		protected ?array $_pagination_args = null;

		/**
		 * @param array<string, mixed> $args
		 */
		public function __construct( array $args = [] ) {
			$this->_args = $args;
		}

		public function get_pagenum(): int {
			return 1;
		}

		/**
		 * @param array<string, mixed> $args
		 */
		public function set_pagination_args( array $args ): void {
			$this->_pagination_args = $args;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_columns(): array {
			return [];
		}

		/**
		 * @return array<string, array{0: string, 1: bool}>
		 */
		public function get_sortable_columns(): array {
			return [];
		}

		/**
		 * @return array{0: array<string, string>, 1: array<int, string>, 2: array<string, array{0: string, 1: bool}>, 3: string}
		 */
		public function get_column_info(): array {
			/** @var array{0: array<string, string>, 1: array<int, string>, 2: array<string, array{0: string, 1: bool}>, 3: string} */
			return $this->_column_headers ?? [ [], [], [], '' ];
		}
	}
}
