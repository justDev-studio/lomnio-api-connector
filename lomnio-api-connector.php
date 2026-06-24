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
 * Version:           0.1.1
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

define( 'LOMNIO_API_CONNECTOR_VERSION', '0.1.1' );
define( 'LOMNIO_API_CONNECTOR_PLUGIN_FILE', __FILE__ );
define( 'LOMNIO_API_CONNECTOR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LOMNIO_API_CONNECTOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$lomnio_api_connector_autoload_candidates = array(
	LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'vendor/autoload.php',
	dirname( LOMNIO_API_CONNECTOR_PLUGIN_PATH, 3 ) . '/vendor/autoload.php',
);

$lomnio_api_connector_autoload_loaded = false;

foreach ( $lomnio_api_connector_autoload_candidates as $lomnio_api_connector_autoload ) {
	if ( file_exists( $lomnio_api_connector_autoload ) ) {
		require_once $lomnio_api_connector_autoload;
		$lomnio_api_connector_autoload_loaded = true;
		break;
	}
}

if ( ! $lomnio_api_connector_autoload_loaded ) {
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

$lomnio_api_connector_facades = array(
	'LomnioProject' => LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'includes/LomnioProject.php',
	'LomnioUnits'   => LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'includes/LomnioUnits.php',
	'LomnioFloors'  => LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'includes/LomnioFloors.php',
	'LomnioLeads'   => LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'includes/LomnioLeads.php',
);

foreach ( $lomnio_api_connector_facades as $lomnio_api_connector_facade_class => $lomnio_api_connector_facade_file ) {
	if ( ! class_exists( $lomnio_api_connector_facade_class ) && file_exists( $lomnio_api_connector_facade_file ) ) {
		require_once $lomnio_api_connector_facade_file;
	}
}

$lomnio_api_connector_action_scheduler_candidates = array(
	LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php',
	dirname( LOMNIO_API_CONNECTOR_PLUGIN_PATH, 3 ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php',
);

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	foreach ( array_unique( $lomnio_api_connector_action_scheduler_candidates ) as $lomnio_api_connector_action_scheduler ) {
		if ( file_exists( $lomnio_api_connector_action_scheduler ) ) {
			require_once $lomnio_api_connector_action_scheduler;
			break;
		}
	}
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
