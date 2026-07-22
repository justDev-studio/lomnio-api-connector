<?php
/**
 * External webhook receiver.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Webhook;

use LomnioApiConnector\Database\DataRevision;
use LomnioApiConnector\Database\UnitRepository;
use LomnioApiConnector\Security\SecretStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncWebhook {
	private const REST_NAMESPACE  = 'lomnio/v1';
	private const REST_ROUTE      = '/webhook';
	private const DEBUG_OPTION    = 'lomnio_api_connector_webhook_debug';
	private const MAX_BODY_BYTES  = 1048576;
	private const DELIVERY_PREFIX = 'lomnio_webhook_delivery_';
	private const DELIVERY_TTL    = 604800;
	private const CLEANUP_HOOK     = 'lomnio_api_connector_cleanup_webhook_delivery';

	/**
	 * Units database storage.
	 *
	 * @var UnitRepository
	 */
	private UnitRepository $unit_repository;

	/**
	 * Encrypted API token storage.
	 *
	 * @var SecretStorage
	 */
	private SecretStorage $secret_storage;

	public function __construct( ?UnitRepository $unit_repository = null, ?SecretStorage $secret_storage = null ) {
		$this->unit_repository = $unit_repository ?? new UnitRepository();
		$this->secret_storage  = $secret_storage ?? new SecretStorage();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_delivery' ) );
	}

	/**
	 * Register the public WordPress REST API route.
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Verify the Lomnio HMAC signature over the raw request body.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		$secret = $this->secret_storage->get_webhook_secret();

		if ( is_wp_error( $secret ) ) {
			$secret->add_data( array( 'status' => 500 ) );
			return $secret;
		}

		if ( '' === $secret ) {
			return new \WP_Error(
				'lomnio_webhook_secret_not_configured',
				__( 'Lomnio webhook signing secret is not configured.', 'lomnio-api-connector' ),
				array( 'status' => 503 )
			);
		}

		$provided = trim( (string) $request->get_header( 'X-Lomnio-Signature' ) );
		$expected = 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $secret );

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new \WP_Error(
				'lomnio_webhook_invalid_signature',
				__( 'Invalid Lomnio webhook signature.', 'lomnio-api-connector' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Capture an incoming webhook request for temporary debugging.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$this->store_debug_request( $request );
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'lomnio_webhook_invalid_json',
				__( 'Webhook body must contain a JSON object.', 'lomnio-api-connector' ),
				array( 'status' => 400 )
			);
		}

		$event        = isset( $payload['event'] ) ? strtolower( trim( (string) $payload['event'] ) ) : '';
		$header_event = strtolower( trim( (string) $request->get_header( 'X-Lomnio-Event' ) ) );
		$delivery     = trim( (string) $request->get_header( 'X-Lomnio-Delivery' ) );

		if ( '' === $event || '' === $header_event || ! hash_equals( $event, $header_event ) ) {
			return new \WP_Error(
				'lomnio_webhook_event_mismatch',
				__( 'Webhook event header does not match the payload.', 'lomnio-api-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $delivery ) {
			return new \WP_Error(
				'lomnio_webhook_missing_delivery',
				__( 'Webhook delivery ID is missing.', 'lomnio-api-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->claim_delivery( $delivery ) ) {
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'status'   => 'duplicate',
					'event'    => $event,
					'delivery' => $delivery,
				),
				200
			);
		}

		if ( 'webhook.test' === $event ) {
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'status'   => 'tested',
					'event'    => $event,
					'delivery' => $delivery,
				),
				200
			);
		}

		if ( 0 !== strpos( $event, 'unit.' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'status'  => 'ignored',
					'event'   => $event,
				),
				200
			);
		}

		$unit_id = isset( $payload['unit_id'] ) ? (string) $payload['unit_id'] : '';

		if ( '' === $unit_id ) {
			$this->release_delivery( $delivery );
			return new \WP_Error(
				'lomnio_webhook_missing_unit_id',
				__( 'Unit webhook payload is missing unit_id.', 'lomnio-api-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $payload['deleted'] ) || ( array_key_exists( 'visible', $payload ) && ! $payload['visible'] ) || 'unit.deleted' === $event ) {
			$result = $this->unit_repository->delete_webhook_unit( $unit_id );
			$status = 'deleted';
		} else {
			$unit = isset( $payload['unit'] ) && is_array( $payload['unit'] ) ? $payload['unit'] : array();

			if ( empty( $unit['id'] ) || (string) $unit['id'] !== $unit_id ) {
				$this->release_delivery( $delivery );
				return new \WP_Error(
					'lomnio_webhook_invalid_unit',
					__( 'Webhook unit data is missing or does not match unit_id.', 'lomnio-api-connector' ),
					array( 'status' => 400 )
				);
			}

			$project    = isset( $payload['project'] ) && is_array( $payload['project'] ) ? $payload['project'] : array();
			$project_id = isset( $project['id'] ) ? (int) $project['id'] : 0;
			$result     = $this->unit_repository->store_webhook_unit( $unit, $project_id );
			$status     = 'updated';
		}

		if ( is_wp_error( $result ) ) {
			$this->release_delivery( $delivery );
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		DataRevision::bump();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
				'event'   => $event,
				'unit_id'  => $unit_id,
				'delivery' => $delivery,
			),
			200
		);
	}

	/**
	 * Atomically claim a delivery ID for at-least-once delivery handling.
	 */
	private function claim_delivery( string $delivery ): bool {
		$key     = self::DELIVERY_PREFIX . hash( 'sha256', $delivery );
		$claimed = add_option( $key, time(), '', false );

		if ( $claimed ) {
			wp_schedule_single_event( time() + self::DELIVERY_TTL, self::CLEANUP_HOOK, array( $key ) );
		}

		return $claimed;
	}

	/**
	 * Release a delivery claim after validation or processing failure.
	 */
	private function release_delivery( string $delivery ): void {
		delete_option( self::DELIVERY_PREFIX . hash( 'sha256', $delivery ) );
	}

	/**
	 * Remove an expired delivery claim.
	 */
	public function cleanup_delivery( string $key ): void {
		if ( 0 === strpos( $key, self::DELIVERY_PREFIX ) ) {
			delete_option( $key );
		}
	}

	/**
	 * Get the full webhook URL for this WordPress installation.
	 */
	public function url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Get the most recently received webhook request for temporary debugging.
	 */
	public function debug_request(): array {
		$debug = get_option( self::DEBUG_OPTION, array() );

		return is_array( $debug ) ? $debug : array();
	}

	/**
	 * Delete the saved webhook request.
	 */
	public function clear_debug_request(): void {
		delete_option( self::DEBUG_OPTION );
	}

	/**
	 * Save all available representations of the last webhook request.
	 */
	private function store_debug_request( \WP_REST_Request $request ): void {
		$body      = (string) $request->get_body();
		$truncated = strlen( $body ) > self::MAX_BODY_BYTES;

		if ( $truncated ) {
			$body = substr( $body, 0, self::MAX_BODY_BYTES );
		}

		update_option(
			self::DEBUG_OPTION,
			array(
				'received_at' => current_time( 'mysql' ),
				'method'      => $request->get_method(),
				'route'       => $request->get_route(),
				'headers'     => $request->get_headers(),
				'query'       => $request->get_query_params(),
				'body_params' => $request->get_body_params(),
				'json_params' => $request->get_json_params(),
				'file_params' => $request->get_file_params(),
				'all_params'  => $request->get_params(),
				'raw_body'    => $body,
				'truncated'   => $truncated,
			),
			false
		);
	}
}
