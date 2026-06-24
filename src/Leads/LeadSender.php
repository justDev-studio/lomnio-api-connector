<?php
/**
 * Lomnio lead sender.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Leads;

use LomnioApiConnector\Security\SecretStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LeadSender {
	private const ENDPOINT_KEY    = 'leads';
	private const SETTINGS_OPTION = 'lomnio_api_connector_endpoint_settings';
	private const META_OPTION     = 'lomnio_api_connector_endpoint_meta';
	private const API_BASE_URL    = 'https://app.lomnio.com/api';
	private const API_PATH        = '/v1/leads';
	private const NOTE_USER_ID    = 0;
	private const NOTE_USER_NAME  = 'Lomnio';
	private const LOG_FILENAME    = 'lomnio_leads_log.txt';

	/**
	 * Encrypted secret storage.
	 *
	 * @var SecretStorage
	 */
	private SecretStorage $secret_storage;

	public function __construct( ?SecretStorage $secret_storage = null ) {
		$this->secret_storage = $secret_storage ?: new SecretStorage();
	}

	/**
	 * Send a lead to Lomnio.
	 *
	 * @return array|\WP_Error
	 */
	public function send( array $fields, array $context = array() ) {
		if ( empty( $this->settings()['active'] ) ) {
			return array(
				'sent'    => false,
				'skipped' => true,
				'reason'  => 'inactive',
			);
		}

		$fields = $this->sanitize_payload( $fields );

		if ( empty( $fields ) ) {
			$error = new \WP_Error(
				'lomnio_leads_empty_payload',
				__( 'Lomnio lead payload is empty.', 'lomnio-api-connector' )
			);
			$this->report_result( false, $error->get_error_message(), $fields, $context );
			return $error;
		}

		$environment = $this->current_environment();

		if ( ! $this->environment_can_send( $environment ) ) {
			$message = sprintf(
				/* translators: %s: current WP_ENV. */
				__( 'Lomnio lead was not sent because WP_ENV is %s. Payload was recorded locally.', 'lomnio-api-connector' ),
				$environment
			);

			$this->report_result( null, $message, $fields, $context );

			return array(
				'sent'        => false,
				'skipped'     => true,
				'environment' => $environment,
				'reason'      => 'environment_not_allowed',
			);
		}

		$headers = $this->secret_storage->get_authorization_headers();

		if ( is_wp_error( $headers ) ) {
			$this->report_result( false, $headers->get_error_message(), $fields, $context );
			return $headers;
		}

		if ( empty( $headers ) ) {
			$error = new \WP_Error(
				'lomnio_leads_missing_api_token',
				__( 'Missing Lomnio API token.', 'lomnio-api-connector' )
			);
			$this->report_result( false, $error->get_error_message(), $fields, $context );
			return $error;
		}

		$body = wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $body ) ) {
			$error = new \WP_Error(
				'lomnio_leads_json_encode_failed',
				__( 'Could not encode Lomnio lead payload.', 'lomnio-api-connector' )
			);
			$this->report_result( false, $error->get_error_message(), $fields, $context );
			return $error;
		}

		$response = wp_safe_remote_post(
			self::API_BASE_URL . self::API_PATH,
			array(
				'timeout' => 10,
				'headers' => array_merge(
					$headers,
					array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					)
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->report_result( false, 'ERROR: ' . $response->get_error_message(), $fields, $context );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$message     = (string) wp_remote_retrieve_response_message( $response );
		$response_body = (string) wp_remote_retrieve_body( $response );
		$success     = $status_code >= 200 && $status_code < 300;

		$note = sprintf(
			"Lomnio Lead Response:\nBody: %s\nCode: %s\nMessage: %s",
			wp_strip_all_tags( $response_body ),
			$status_code,
			$message
		);

		$this->report_result( $success, $note, $fields, $context );

		if ( ! $success ) {
			return new \WP_Error(
				'lomnio_leads_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Lomnio lead request failed with HTTP %d.', 'lomnio-api-connector' ),
					$status_code
				)
			);
		}

		return array(
			'sent'          => true,
			'status_code'   => $status_code,
			'response_body' => $response_body,
		);
	}

	/**
	 * Get leads endpoint settings.
	 */
	public function settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$leads = isset( $settings[ self::ENDPOINT_KEY ] ) && is_array( $settings[ self::ENDPOINT_KEY ] )
			? $settings[ self::ENDPOINT_KEY ]
			: array();

		$allowed_envs = isset( $leads['allowed_envs'] ) && is_array( $leads['allowed_envs'] )
			? array_map( 'sanitize_key', $leads['allowed_envs'] )
			: array( 'production' );

		return array(
			'active'       => array_key_exists( 'active', $leads ) ? (bool) $leads['active'] : true,
			'allowed_envs' => array_values( array_unique( array_filter( $allowed_envs ) ) ),
		);
	}

	/**
	 * Check whether a current environment can send real API requests.
	 */
	public function environment_can_send( string $environment ): bool {
		$allowed_envs = $this->settings()['allowed_envs'];

		return in_array( 'all', $allowed_envs, true ) || in_array( sanitize_key( $environment ), $allowed_envs, true );
	}

	/**
	 * Get current WordPress environment.
	 */
	public function current_environment(): string {
		if ( defined( 'WP_ENV' ) && is_string( WP_ENV ) && '' !== WP_ENV ) {
			return sanitize_key( WP_ENV );
		}

		if ( function_exists( 'wp_get_environment_type' ) ) {
			return sanitize_key( wp_get_environment_type() );
		}

		return 'production';
	}

	/**
	 * Sanitize payload recursively without changing field names.
	 */
	private function sanitize_payload( array $fields ): array {
		$sanitized = array();

		foreach ( $fields as $key => $value ) {
			if ( '' === (string) $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ (string) $key ] = $this->sanitize_payload( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$sanitized[ (string) $key ] = $value;
				continue;
			}

			$sanitized[ (string) $key ] = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
		}

		return $sanitized;
	}

	/**
	 * Add GF note when possible, otherwise write to upload log.
	 */
	private function report_result( $success, string $message, array $payload, array $context ): void {
		$this->store_meta( $success, $message );

		$entry_id = isset( $context['entry_id'] ) ? (int) $context['entry_id'] : 0;

		if ( $entry_id > 0 && class_exists( 'GFAPI' ) ) {
			\GFAPI::add_note( $entry_id, self::NOTE_USER_ID, self::NOTE_USER_NAME, $message . "\n\nPayload:\n" . print_r( $payload, true ) );
			return;
		}

		$this->write_log( $message, $payload, $context );
	}

	/**
	 * Store lead usage metadata for the admin endpoint list.
	 */
	private function store_meta( $success, string $message ): void {
		$meta = get_option( self::META_OPTION, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$meta[ self::ENDPOINT_KEY ] = array(
			'status'     => null === $success ? 'Skipped' : '',
			'success'    => null === $success ? null : (bool) $success,
			'message'    => $message,
			'fetched_at' => current_time( 'mysql' ),
		);

		update_option( self::META_OPTION, $meta, false );
	}

	/**
	 * Write lead usage to an upload log file.
	 */
	private function write_log( string $message, array $payload, array $context ): void {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}

		$upload = wp_upload_dir();

		if ( empty( $upload['basedir'] ) ) {
			return;
		}

		$content = sprintf(
			"[%s] %s\nContext: %s\nPayload: %s\n\n",
			current_time( 'mysql' ),
			$message,
			print_r( $context, true ),
			print_r( $payload, true )
		);

		file_put_contents( trailingslashit( $upload['basedir'] ) . self::LOG_FILENAME, $content, FILE_APPEND );
	}
}
