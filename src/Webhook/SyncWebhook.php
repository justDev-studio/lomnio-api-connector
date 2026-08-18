<?php
/**
 * External webhook receiver.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Webhook;

use LomnioApiConnector\Database\DataRevision;
use LomnioApiConnector\Database\FloorRepository;
use LomnioApiConnector\Database\ProjectRepository;
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
	 * Floors database storage.
	 *
	 * @var FloorRepository
	 */
	private FloorRepository $floor_repository;

	/**
	 * Project database storage.
	 *
	 * @var ProjectRepository
	 */
	private ProjectRepository $project_repository;

	/**
	 * Encrypted API token storage.
	 *
	 * @var SecretStorage
	 */
	private SecretStorage $secret_storage;

	public function __construct(
		?UnitRepository $unit_repository = null,
		?SecretStorage $secret_storage = null,
		?FloorRepository $floor_repository = null,
		?ProjectRepository $project_repository = null
	) {
		$this->unit_repository    = $unit_repository ?? new UnitRepository();
		$this->secret_storage     = $secret_storage ?? new SecretStorage();
		$this->floor_repository   = $floor_repository ?? new FloorRepository();
		$this->project_repository = $project_repository ?? new ProjectRepository();
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

		$resource_type = strstr( $event, '.', true );

		if ( ! in_array( $resource_type, array( 'unit', 'floor', 'project' ), true ) ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'status'  => 'ignored',
					'event'   => $event,
				),
				200
			);
		}

		$processed = $this->process_resource( $resource_type, $payload, $event );

		if ( is_wp_error( $processed ) ) {
			$this->release_delivery( $delivery );

			if ( ! is_array( $processed->get_error_data() ) || ! isset( $processed->get_error_data()['status'] ) ) {
				$processed->add_data( array( 'status' => 500 ) );
			}

			return $processed;
		}

		DataRevision::bump();

		$id_key = $resource_type . '_id';

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'status'        => $processed['status'],
				'event'         => $event,
				'resource_type' => $resource_type,
				$id_key         => $processed['id'],
				'delivery'      => $delivery,
			),
			200
		);
	}

	/**
	 * Store or delete one webhook resource.
	 *
	 * @return array|\WP_Error
	 */
	private function process_resource( string $resource_type, array $payload, string $event ) {
		if ( 'unit' === $resource_type ) {
			return $this->process_unit( $payload, $event );
		}

		if ( 'floor' === $resource_type ) {
			return $this->process_floor( $payload, $event );
		}

		return $this->process_project( $payload, $event );
	}

	/**
	 * Store or delete one unit snapshot.
	 *
	 * @return array|\WP_Error
	 */
	private function process_unit( array $payload, string $event ) {
		$unit_id = isset( $payload['unit_id'] ) ? (string) $payload['unit_id'] : '';

		if ( '' === $unit_id ) {
			return $this->missing_id_error( 'unit' );
		}

		if ( $this->should_delete( $payload, $event, 'unit' ) ) {
			$result = $this->unit_repository->delete_webhook_unit( $unit_id );
			return is_wp_error( $result ) ? $result : array( 'id' => $unit_id, 'status' => 'deleted' );
		}

		$unit = isset( $payload['unit'] ) && is_array( $payload['unit'] ) ? $payload['unit'] : array();

		if ( empty( $unit['id'] ) || (string) $unit['id'] !== $unit_id ) {
			return $this->invalid_resource_error( 'unit' );
		}

		$project    = isset( $payload['project'] ) && is_array( $payload['project'] ) ? $payload['project'] : array();
		$project_id = isset( $project['id'] ) ? (int) $project['id'] : 0;
		$result     = $this->unit_repository->store_webhook_unit( $unit, $project_id );

		return is_wp_error( $result ) ? $result : array( 'id' => $unit_id, 'status' => 'updated' );
	}

	/**
	 * Store or delete one floor snapshot.
	 *
	 * @return array|\WP_Error
	 */
	private function process_floor( array $payload, string $event ) {
		$floor    = isset( $payload['floor'] ) && is_array( $payload['floor'] ) ? $payload['floor'] : array();
		$floor_id = isset( $payload['floor_id'] ) ? (string) $payload['floor_id'] : ( isset( $floor['id'] ) ? (string) $floor['id'] : '' );

		if ( '' === $floor_id ) {
			return $this->missing_id_error( 'floor' );
		}

		if ( $this->should_delete( $payload, $event, 'floor' ) ) {
			$result = $this->floor_repository->delete_webhook_floor( $floor_id );
			return is_wp_error( $result ) ? $result : array( 'id' => $floor_id, 'status' => 'deleted' );
		}

		if ( empty( $floor['id'] ) || (string) $floor['id'] !== $floor_id ) {
			return $this->invalid_resource_error( 'floor' );
		}

		$project    = isset( $payload['project'] ) && is_array( $payload['project'] ) ? $payload['project'] : array();
		$project_id = isset( $project['id'] ) ? (int) $project['id'] : 0;
		$result     = $this->floor_repository->store_webhook_floor( $floor, $project_id );

		return is_wp_error( $result ) ? $result : array( 'id' => $floor_id, 'status' => 'updated' );
	}

	/**
	 * Store or delete one project snapshot.
	 *
	 * @return array|\WP_Error
	 */
	private function process_project( array $payload, string $event ) {
		$project    = isset( $payload['project'] ) && is_array( $payload['project'] ) ? $payload['project'] : array();
		$project_id = isset( $payload['project_id'] ) ? (string) $payload['project_id'] : ( isset( $project['id'] ) ? (string) $project['id'] : '' );

		if ( '' === $project_id ) {
			return $this->missing_id_error( 'project' );
		}

		if ( $this->should_delete( $payload, $event, 'project' ) ) {
			$result = $this->project_repository->delete_webhook_project( $project_id );
			return is_wp_error( $result ) ? $result : array( 'id' => $project_id, 'status' => 'deleted' );
		}

		if ( empty( $project['id'] ) || (string) $project['id'] !== $project_id ) {
			return $this->invalid_resource_error( 'project' );
		}

		$result = $this->project_repository->store_webhook_project( $project );

		return is_wp_error( $result ) ? $result : array( 'id' => $project_id, 'status' => 'updated' );
	}

	/**
	 * Check whether a webhook resource must be removed.
	 */
	private function should_delete( array $payload, string $event, string $resource_type ): bool {
		return ! empty( $payload['deleted'] )
			|| ( array_key_exists( 'visible', $payload ) && ! $payload['visible'] )
			|| $resource_type . '.deleted' === $event;
	}

	/**
	 * Build a missing resource ID validation error.
	 */
	private function missing_id_error( string $resource_type ): \WP_Error {
		return new \WP_Error(
			'lomnio_webhook_missing_' . $resource_type . '_id',
			sprintf(
				/* translators: %s: webhook resource type. */
				__( 'Webhook payload is missing %s_id.', 'lomnio-api-connector' ),
				$resource_type
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Build an invalid resource snapshot validation error.
	 */
	private function invalid_resource_error( string $resource_type ): \WP_Error {
		return new \WP_Error(
			'lomnio_webhook_invalid_' . $resource_type,
			sprintf(
				/* translators: %s: webhook resource type. */
				__( 'Webhook %s data is missing or does not match its ID.', 'lomnio-api-connector' ),
				$resource_type
			),
			array( 'status' => 400 )
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
