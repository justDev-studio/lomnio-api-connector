<?php
/**
 * Frontend tracking SDK loader.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Tracking;

use LomnioApiConnector\Database\UnitRepository;
use LomnioApiConnector\Pages\PageSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackingFrontend {
	private TrackingSender $sender;
	private PageSettings $page_settings;

	public function __construct( ?TrackingSender $sender = null, ?PageSettings $page_settings = null ) {
		$this->sender        = $sender ?: new TrackingSender();
		$this->page_settings = $page_settings ?: new PageSettings();
	}

	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		$endpoint = $this->sender->settings();

		if (
			empty( $endpoint['active'] ) ||
			! $this->sender->environment_can_send( $this->sender->current_environment() )
		) {
			return;
		}

		$handle       = 'lomnio-tracking';
		$src          = LOMNIO_API_CONNECTOR_PLUGIN_URL . 'assets/js/lomnio-tracking.js';
		$path         = LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'assets/js/lomnio-tracking.js';
		$version      = file_exists( $path ) ? (string) filemtime( $path ) : LOMNIO_API_CONNECTOR_VERSION;
		$consent_mode = (string) $this->page_settings->get( 'tracking_consent_mode' );

		wp_enqueue_script( $handle, $src, array(), $version, true );
		wp_add_inline_script(
			$handle,
			'window.LomnioTrackingConfig = ' . wp_json_encode(
				array(
					'endpoint'      => rest_url( 'lomnio/v1/tracking/events' ),
					'consentMode'   => $consent_mode,
					'consentGroups' => array( 'C0002', 'C0007' ),
					'unitId'        => $this->current_unit_id(),
					'flushMs'       => 5000,
					'batchSize'     => 10,
					'maxBatch'      => 50,
				),
				JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);

		if ( 'required' === $consent_mode ) {
			$bridge_src     = LOMNIO_API_CONNECTOR_PLUGIN_URL . 'assets/js/lomnio-consent-onetrust.js';
			$bridge_path    = LOMNIO_API_CONNECTOR_PLUGIN_PATH . 'assets/js/lomnio-consent-onetrust.js';
			$bridge_version = file_exists( $bridge_path ) ? (string) filemtime( $bridge_path ) : LOMNIO_API_CONNECTOR_VERSION;

			wp_enqueue_script( 'lomnio-consent-onetrust', $bridge_src, array( $handle ), $bridge_version, true );
		}
	}

	private function current_unit_id(): ?int {
		$settings = $this->page_settings->all();
		$code     = (string) get_query_var( (string) $settings['unit_slug'], '' );

		if ( '' === $code ) {
			return null;
		}

		$unit = ( new UnitRepository() )->get_unit_by_code( $code );

		return $unit && isset( $unit->id ) ? (int) $unit->id : null;
	}
}
