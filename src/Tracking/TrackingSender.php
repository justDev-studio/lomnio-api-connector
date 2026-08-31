<?php
/**
 * Lomnio tracking batch sender.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Tracking;

use LomnioApiConnector\Security\SecretStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackingSender {
	private const ENDPOINT_KEY    = 'tracking';
	private const SETTINGS_OPTION = 'lomnio_api_connector_endpoint_settings';
	private const META_OPTION     = 'lomnio_api_connector_endpoint_meta';
	private const API_URL         = 'https://app.lomnio.com/api/v1/tracking/events/batch';

	private SecretStorage $secret_storage;

	public function __construct( ?SecretStorage $secret_storage = null ) {
		$this->secret_storage = $secret_storage ?: new SecretStorage();
	}

	/**
	 * Send one validated batch to Lomnio.
	 *
	 * Lomnio filters bots and derives the device type from the User-Agent of
	 * the request it receives. Without the visitor's own UA every event
	 * arrives as this server's UA — all traffic counts as desktop and no
	 * crawler is ever filtered — so the proxy must pass it through.
	 *
	 * @param string $user_agent The originating browser's User-Agent, or ''.
	 * @return array|\WP_Error
	 */
	public function send( array $events, string $user_agent = '' ) {
		$settings = $this->settings();

		if ( empty( $settings['active'] ) ) {
			return new \WP_Error(
				'lomnio_tracking_inactive',
				__( 'Lomnio tracking is inactive.', 'lomnio-api-connector' ),
				array( 'status' => 403 )
			);
		}

		$environment = $this->current_environment();

		if ( ! $this->environment_can_send( $environment ) ) {
			$this->store_meta( null, sprintf( 'Skipped in WP_ENV=%s.', $environment ) );

			return array(
				'status_code' => 200,
				'body'        => array(
					'status'      => 'skipped',
					'environment' => $environment,
				),
			);
		}

		$headers = $this->secret_storage->get_authorization_headers();

		if ( is_wp_error( $headers ) ) {
			$this->store_meta( false, $headers->get_error_message() );
			return $headers;
		}

		if ( empty( $headers ) ) {
			$error = new \WP_Error(
				'lomnio_tracking_missing_api_token',
				__( 'Missing Lomnio API token.', 'lomnio-api-connector' ),
				array( 'status' => 503 )
			);
			$this->store_meta( false, $error->get_error_message() );
			return $error;
		}

		$body = wp_json_encode( array( 'events' => $events ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $body ) ) {
			return new \WP_Error(
				'lomnio_tracking_json_encode_failed',
				__( 'Could not encode tracking events.', 'lomnio-api-connector' ),
				array( 'status' => 500 )
			);
		}

		$forwarded_headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		if ( '' !== $user_agent ) {
			$forwarded_headers['User-Agent'] = $user_agent;
		}

		$response = wp_safe_remote_post(
			self::API_URL,
			array(
				'timeout' => 10,
				'headers' => array_merge( $headers, $forwarded_headers ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->store_meta( false, $response->get_error_message() );
			return new \WP_Error(
				'lomnio_tracking_transport_error',
				__( 'Could not reach Lomnio tracking API.', 'lomnio-api-connector' ),
				array( 'status' => 502 )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );
		$success     = $status_code >= 200 && $status_code < 300;

		$created    = is_array( $decoded ) && isset( $decoded['created'] ) ? (int) $decoded['created'] : null;
		$duplicates = is_array( $decoded ) && isset( $decoded['duplicates'] ) ? (int) $decoded['duplicates'] : null;
		$message    = sprintf(
			/* translators: 1: HTTP status, 2: event count. */
			__( 'HTTP %1$d. Events submitted: %2$d.', 'lomnio-api-connector' ),
			$status_code,
			count( $events )
		);

		if ( null !== $created && null !== $duplicates ) {
			$message .= sprintf(
				/* translators: 1: created events, 2: duplicate events. */
				__( ' Created: %1$d. Duplicates: %2$d.', 'lomnio-api-connector' ),
				$created,
				$duplicates
			);
		}

		$this->store_meta( $success, $message );

		return array(
			'status_code' => $status_code,
			'body'        => is_array( $decoded ) ? $decoded : array(
				'status' => $success ? 'ok' : 'error',
			),
		);
	}

	public function settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$tracking = is_array( $settings ) && isset( $settings[ self::ENDPOINT_KEY ] ) && is_array( $settings[ self::ENDPOINT_KEY ] )
			? $settings[ self::ENDPOINT_KEY ]
			: array();
		$allowed  = isset( $tracking['allowed_envs'] ) && is_array( $tracking['allowed_envs'] )
			? array_map( 'sanitize_key', $tracking['allowed_envs'] )
			: array( 'production' );

		return array(
			'active'       => array_key_exists( 'active', $tracking ) ? (bool) $tracking['active'] : true,
			'allowed_envs' => array_values( array_unique( array_filter( $allowed ) ) ),
		);
	}

	public function environment_can_send( string $environment ): bool {
		$allowed = $this->settings()['allowed_envs'];

		return in_array( 'all', $allowed, true ) || in_array( sanitize_key( $environment ), $allowed, true );
	}

	public function current_environment(): string {
		if ( defined( 'WP_ENV' ) && is_string( WP_ENV ) && '' !== WP_ENV ) {
			return sanitize_key( WP_ENV );
		}

		if ( function_exists( 'wp_get_environment_type' ) ) {
			return sanitize_key( wp_get_environment_type() );
		}

		return 'production';
	}

	private function store_meta( $success, string $message ): void {
		$meta = get_option( self::META_OPTION, array() );
		$meta = is_array( $meta ) ? $meta : array();

		$meta[ self::ENDPOINT_KEY ] = array(
			'status'     => null === $success ? 'Skipped' : '',
			'success'    => null === $success ? null : (bool) $success,
			'message'    => $message,
			'fetched_at' => current_time( 'mysql' ),
		);

		update_option( self::META_OPTION, $meta, false );
	}
}
