<?php
/**
 * Lomnio API Connector
 *
 * @package LomnioApiConnector
 *
 * @wordpress-plugin
 * Plugin Name:       Lomnio API Connector
 * Plugin URI:        https://justdev.org
 * Description:       Connects WordPress with the Lomnio API.
 * Version:           0.1.0
 * Author:            justDev
 * Author URI:        https://justdev.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lomnio-api-connector
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOMNIO_API_CONNECTOR_VERSION', '0.1.0' );
define( 'LOMNIO_API_CONNECTOR_PLUGIN_FILE', __FILE__ );
define( 'LOMNIO_API_CONNECTOR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LOMNIO_API_CONNECTOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$lomnio_api_connector_autoload = LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'vendor/autoload.php';

if ( file_exists( $lomnio_api_connector_autoload ) ) {
	require_once $lomnio_api_connector_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'LomnioApiConnector\\';
			$base_dir = LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'src/';
			$length   = strlen( $prefix );

			if ( strncmp( $prefix, $class, $length ) !== 0 ) {
				return;
			}

			$relative_class = substr( $class, $length );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require $file;
			}
		}
	);
}

$lomnio_api_connector_action_scheduler = LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

if ( file_exists( $lomnio_api_connector_action_scheduler ) ) {
	require_once $lomnio_api_connector_action_scheduler;
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( \LomnioApiConnector\Plugin::class ) ) {
			\LomnioApiConnector\Plugin::instance()->boot();
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \LomnioApiConnector\Plugin::class ) ) {
			\LomnioApiConnector\Plugin::instance()->activate();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \LomnioApiConnector\Plugin::class ) ) {
			\LomnioApiConnector\Plugin::instance()->deactivate();
		}
	}
);
