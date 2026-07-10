<?php
/**
 * External webhook that queues all Lomnio synchronizations.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncWebhook {
	private const REST_NAMESPACE = 'lomnio/v1';
	private const REST_ROUTE     = '/webhook';
	private const ACTION_GROUP   = 'lomnio-api-connector';

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
	 * Queue every data synchronization and return immediately.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new \WP_Error(
				'lomnio_webhook_scheduler_unavailable',
				__( 'Action Scheduler is unavailable.', 'lomnio-api-connector' ),
				array( 'status' => 503 )
			);
		}

		$hooks = array(
			'project' => 'lomnio_api_connector_sync_project',
			'units'   => 'lomnio_api_connector_sync_units',
			'floors'  => 'lomnio_api_connector_sync_floors',
		);
		$queued = array();

		foreach ( $hooks as $endpoint => $hook ) {
			$action_id = as_enqueue_async_action( $hook, array(), self::ACTION_GROUP, false );

			if ( 0 === (int) $action_id ) {
				return new \WP_Error(
					'lomnio_webhook_queue_failed',
					__( 'Could not queue all Lomnio synchronizations.', 'lomnio-api-connector' ),
					array( 'status' => 500 )
				);
			}

			$queued[ $endpoint ] = (int) $action_id;
		}

		$response = new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => 'queued',
				'actions' => $queued,
			),
			202
		);

		return $response;
	}

	/**
	 * Get the full webhook URL for this WordPress installation.
	 */
	public function url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}
}
