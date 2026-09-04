<?php
/**
 * Verify SDK/OneTrust enqueue order, consent mode, and environment guards.
 *
 * @package LomnioApiConnector
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'LOMNIO_API_CONNECTOR_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
	define( 'LOMNIO_API_CONNECTOR_PLUGIN_URL', 'https://example.test/plugins/lomnio/' );
	define( 'LOMNIO_API_CONNECTOR_VERSION', '0.1.3' );
	define( 'WP_ENV', 'production' );
}

namespace LomnioApiConnector\Security {
	final class SecretStorage {}
}

namespace {
	$GLOBALS['test_options'] = array();
	$GLOBALS['test_scripts'] = array();
	$GLOBALS['test_inline'] = array();

	function get_option( string $name, $default = false ) {
		return $GLOBALS['test_options'][ $name ] ?? $default;
	}

	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
	}

	function get_query_var( string $name, $default = '' ) {
		return $default;
	}

	function rest_url( string $path ): string {
		return 'https://example.test/wp-json/' . $path;
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function wp_enqueue_script( $handle, $src, $deps, $version, $footer ): void {
		$GLOBALS['test_scripts'][ $handle ] = compact( 'src', 'deps', 'version', 'footer' );
	}

	function wp_add_inline_script( $handle, $script, $position ): void {
		$GLOBALS['test_inline'][ $handle ] = compact( 'script', 'position' );
	}

	function expect_same( string $label, $expected, $actual ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $label . ': ' . var_export( $actual, true ) );
		}
	}

	require_once dirname( __DIR__ ) . '/src/Pages/PageSettings.php';
	require_once dirname( __DIR__ ) . '/src/Tracking/TrackingSender.php';
	require_once dirname( __DIR__ ) . '/src/Tracking/TrackingFrontend.php';

	$frontend = new \LomnioApiConnector\Tracking\TrackingFrontend();
	$frontend->enqueue();
	expect_same( 'Required mode loads SDK before bridge', array( 'lomnio-tracking', 'lomnio-consent-onetrust' ), array_keys( $GLOBALS['test_scripts'] ) );
	$bridge = $GLOBALS['test_scripts']['lomnio-consent-onetrust'];
	expect_same( 'Bridge dependency', array( 'lomnio-tracking' ), $bridge['deps'] );
	expect_same( 'Bridge URL', LOMNIO_API_CONNECTOR_PLUGIN_URL . 'assets/js/lomnio-consent-onetrust.js', $bridge['src'] );
	expect_same( 'Bridge cache version', (string) filemtime( LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'assets/js/lomnio-consent-onetrust.js' ), $bridge['version'] );
	expect_same( 'Bridge in footer', true, $bridge['footer'] );
	expect_same( 'Config before SDK', 'before', $GLOBALS['test_inline']['lomnio-tracking']['position'] );
	$config_json = substr( $GLOBALS['test_inline']['lomnio-tracking']['script'], strlen( 'window.LomnioTrackingConfig = ' ), -1 );
	$config = json_decode( $config_json, true, 512, JSON_THROW_ON_ERROR );
	expect_same( 'Required mode config', 'required', $config['consentMode'] );
	expect_same( 'Default OneTrust groups', array( 'C0002', 'C0007' ), $config['consentGroups'] );

	$GLOBALS['test_scripts'] = array();
	$GLOBALS['test_options']['lomnio_api_connector_page_settings'] = array( 'tracking_consent_mode' => 'always' );
	$frontend->enqueue();
	expect_same( 'Always mode does not load bridge', array( 'lomnio-tracking' ), array_keys( $GLOBALS['test_scripts'] ) );

	foreach ( array(
		array( 'active' => false, 'allowed_envs' => array( 'production' ) ),
		array( 'active' => true, 'allowed_envs' => array( 'development' ) ),
	) as $settings ) {
		$GLOBALS['test_scripts'] = array();
		$GLOBALS['test_inline'] = array();
		$GLOBALS['test_options']['lomnio_api_connector_endpoint_settings'] = array( 'tracking' => $settings );
		$frontend->enqueue();
		expect_same( 'Disabled/disallowed tracking loads no scripts', array(), $GLOBALS['test_scripts'] );
		expect_same( 'Disabled/disallowed tracking emits no config', array(), $GLOBALS['test_inline'] );
	}

	echo "PASS: Tracking frontend respects consent mode, script order, and environment guards.\n";
}
