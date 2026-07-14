<?php
/**
 * External webhook receiver.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Webhook;

use LomnioApiConnector\Database\UnitRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncWebhook {
	private const REST_NAMESPACE = 'lomnio/v1';
	private const REST_ROUTE     = '/webhook';
	private const DEBUG_OPTION   = 'lomnio_api_connector_webhook_debug';
	private const MAX_BODY_BYTES = 1048576;

	/**
	 * Units database storage.
	 *
	 * @var UnitRepository
	 */
	private UnitRepository $unit_repository;

	public function __construct( ?UnitRepository $unit_repository = null ) {
		$this->unit_repository = $unit_repository ?? new UnitRepository();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
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
				'permission_callback' => '__return_true',
			)
		);
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

		$event = isset( $payload['event'] ) ? strtolower( trim( (string) $payload['event'] ) ) : '';

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
			return new \WP_Error(
				'lomnio_webhook_missing_unit_id',
				__( 'Unit webhook payload is missing unit_id.', 'lomnio-api-connector' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $payload['deleted'] ) || 'unit.deleted' === $event ) {
			$result = $this->unit_repository->mark_webhook_unit_deleted( $unit_id );
			$status = 'deleted';
		} else {
			$unit = isset( $payload['unit'] ) && is_array( $payload['unit'] ) ? $payload['unit'] : array();

			if ( empty( $unit['id'] ) || (string) $unit['id'] !== $unit_id ) {
				return new \WP_Error(
					'lomnio_webhook_invalid_unit',
					__( 'Webhook unit data is missing or does not match unit_id.', 'lomnio-api-connector' ),
					array( 'status' => 400 )
				);
			}

			$project    = isset( $payload['project'] ) && is_array( $payload['project'] ) ? $payload['project'] : array();
			$project_id = isset( $project['id'] ) ? (int) $project['id'] : 0;
			$changed    = isset( $payload['changed_fields'] ) && is_array( $payload['changed_fields'] ) ? $payload['changed_fields'] : array();
			$result     = $this->unit_repository->update_webhook_unit_fields( $unit, $changed, $project_id );
			$status     = 'updated';
		}

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
				'event'   => $event,
				'unit_id' => $unit_id,
			),
			200
		);
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
