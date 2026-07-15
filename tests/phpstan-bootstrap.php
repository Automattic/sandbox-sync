<?php
/** Static-analysis-only runtime declarations absent from WordPress stubs. */

defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'DB_NAME' ) || define( 'DB_NAME', 'wordpress' );
defined( 'DB_USER' ) || define( 'DB_USER', 'wordpress' );
defined( 'DB_PASSWORD' ) || define( 'DB_PASSWORD', 'test-only' );
defined( 'DB_HOST' ) || define( 'DB_HOST', 'localhost' );

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function add_command( ...$args ) {}
		public static function confirm( ...$args ) {}
		public static function error( ...$args ) {}
		public static function log( ...$args ) {}
		public static function line( ...$args ) {}
		public static function success( ...$args ) {}
	}
}
