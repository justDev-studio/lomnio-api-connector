<?php
/**
 * Project endpoint synchronization.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Sync;

use LomnioApiConnector\Database\ProjectRepository;
use LomnioApiConnector\Security\SecretStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProjectSync {
	private const ENDPOINT_KEY    = 'project';
	private const ACTION_HOOK     = 'lomnio_api_connector_sync_project';
	private const ACTION_GROUP    = 'lomnio-api-connector';
	private const SETTINGS_OPTION = 'lomnio_api_connector_endpoint_settings';
	private const META_OPTION     = 'lomnio_api_connector_endpoint_meta';
	private const SCHEDULE_OPTION = 'lomnio_api_connector_project_schedule_signature';
	private const API_BASE_URL    = 'https://app.lomnio.com/api';
	private const API_PATH        = '/v1/project';

	/**
	 * Encrypted secret storage.
	 *
	 * @var SecretStorage
	 */
	private SecretStorage $secret_storage;

	/**
	 * Project repository.
	 *
	 * @var ProjectRepository
	 */
	private ProjectRepository $repository;

	public function __construct( SecretStorage $secret_storage, ProjectRepository $repository ) {
		$this->secret_storage = $secret_storage;
		$this->repository     = $repository;
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( self::ACTION_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
		add_action( 'action_scheduler_init', array( $this, 'ensure_scheduled' ) );
		add_action( 'lomnio_api_connector_endpoint_settings_saved', array( $this, 'ensure_scheduled' ) );
		add_action( 'lomnio_api_connector_api_token_saved', array( $this, 'run_after_api_token_saved' ) );
	}

	/**
	 * Ensure required database schema exists.
	 */
	public function activate(): void {
		$this->repository->ensure_table();
	}

	/**
	 * Run plugin deactivation tasks.
	 */
	public function deactivate(): void {
		if ( $this->action_scheduler_available() ) {
			as_unschedule_all_actions( self::ACTION_HOOK, array(), self::ACTION_GROUP );
		}

		delete_option( self::SCHEDULE_OPTION );
	}

	/**
	 * Run project synchronization.
	 *
	 * @return true|\WP_Error
	 */
	public function run() {
		$headers = $this->secret_storage->get_authorization_headers();

		if ( is_wp_error( $headers ) ) {
			$this->store_meta( false, $headers->get_error_message() );
			return $headers;
		}

		if ( empty( $headers ) ) {
			$error = new \WP_Error(
				'lomnio_project_missing_api_token',
				__( 'Missing Lomnio API token.', 'lomnio-api-connector' )
			);
			$this->store_meta( false, $error->get_error_message() );
			return $error;
		}

		$response = wp_remote_get(
			self::API_BASE_URL . self::API_PATH,
			array(
				'timeout' => 30,
				'headers' => array_merge(
					$headers,
					array(
						'Accept' => 'application/json',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->store_meta( false, $response->get_error_message() );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Lomnio project request failed with HTTP %d.', 'lomnio-api-connector' ),
				$status_code
			);

			$this->store_meta( false, $message );

			return new \WP_Error( 'lomnio_project_http_error', $message );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			$error = new \WP_Error(
				'lomnio_project_invalid_json',
				__( 'Lomnio project response is not valid JSON.', 'lomnio-api-connector' )
			);
			$this->store_meta( false, $error->get_error_message() );
			return $error;
		}

		$stored = $this->repository->store_api_response( $decoded );

		if ( is_wp_error( $stored ) ) {
			$this->store_meta( false, $stored->get_error_message() );
			return $stored;
		}

		$this->store_meta( true, __( 'Project synchronized successfully.', 'lomnio-api-connector' ) );

		return true;
	}

	/**
	 * Queue an immediate project sync after a token is saved.
	 */
	public function run_after_api_token_saved(): void {
		$this->ensure_scheduled();

		$settings = $this->project_settings();

		if ( empty( $settings['active'] ) ) {
			$this->store_pending_meta( __( 'API token saved. Project sync is inactive.', 'lomnio-api-connector' ), 'Inactive' );
			return;
		}

		$this->enqueue_immediate();
	}

	/**
	 * Enqueue immediate project sync through Action Scheduler.
	 */
	public function enqueue_immediate(): void {
		if ( $this->action_scheduler_async_available() ) {
			$action_id = as_enqueue_async_action( self::ACTION_HOOK, array(), self::ACTION_GROUP, false );

			if ( $action_id ) {
				$this->store_pending_meta( __( 'Project sync queued after API token save.', 'lomnio-api-connector' ), 'Queued' );
				return;
			}
		}

		$this->run();
	}

	/**
	 * Keep the Action Scheduler recurring task in sync with endpoint settings.
	 */
	public function ensure_scheduled(): void {
		if ( ! $this->action_scheduler_available() ) {
			$this->store_meta( false, __( 'Action Scheduler is not available.', 'lomnio-api-connector' ) );
			return;
		}

		$settings = $this->project_settings();
		$args     = array();
		$signature = ! empty( $settings['active'] )
			? ( 'active:' . (string) $settings['schedule'] )
			: 'inactive';

		if (
			$signature === get_option( self::SCHEDULE_OPTION, '' ) &&
			( 'inactive' === $signature || as_next_scheduled_action( self::ACTION_HOOK, $args, self::ACTION_GROUP ) )
		) {
			return;
		}

		as_unschedule_all_actions( self::ACTION_HOOK, $args, self::ACTION_GROUP );

		if ( empty( $settings['active'] ) ) {
			update_option( self::SCHEDULE_OPTION, $signature, false );
			return;
		}

		$interval = $this->schedule_interval( (string) $settings['schedule'] );

		if ( $interval <= 0 ) {
			return;
		}

		as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_HOOK, $args, self::ACTION_GROUP );
		update_option( self::SCHEDULE_OPTION, $signature, false );
	}

	/**
	 * Store sync metadata for the admin page.
	 */
	private function store_meta( bool $success, string $message ): void {
		$meta = get_option( self::META_OPTION, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$meta[ self::ENDPOINT_KEY ] = array(
			'success'    => $success,
			'message'    => $message,
			'fetched_at' => current_time( 'mysql' ),
		);

		update_option( self::META_OPTION, $meta, false );
	}

	/**
	 * Store non-final sync metadata for the admin page.
	 */
	private function store_pending_meta( string $message, string $status ): void {
		$meta = get_option( self::META_OPTION, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$meta[ self::ENDPOINT_KEY ] = array(
			'status'     => $status,
			'success'    => null,
			'message'    => $message,
			'fetched_at' => current_time( 'mysql' ),
		);

		update_option( self::META_OPTION, $meta, false );
	}

	/**
	 * Get stored project endpoint settings.
	 */
	private function project_settings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$project = isset( $settings[ self::ENDPOINT_KEY ] ) && is_array( $settings[ self::ENDPOINT_KEY ] )
			? $settings[ self::ENDPOINT_KEY ]
			: array();

		return array(
			'active'   => array_key_exists( 'active', $project ) ? (bool) $project['active'] : true,
			'schedule' => ! empty( $project['schedule'] ) ? sanitize_key( (string) $project['schedule'] ) : 'daily',
		);
	}

	/**
	 * Convert schedule key to interval in seconds.
	 */
	private function schedule_interval( string $schedule ): int {
		$intervals = array(
			'every_five_minutes'   => 5 * MINUTE_IN_SECONDS,
			'every_ten_minutes'    => 10 * MINUTE_IN_SECONDS,
			'every_thirty_minutes' => 30 * MINUTE_IN_SECONDS,
			'hourly'               => HOUR_IN_SECONDS,
			'twicedaily'           => 12 * HOUR_IN_SECONDS,
			'daily'                => DAY_IN_SECONDS,
		);

		return $intervals[ $schedule ] ?? DAY_IN_SECONDS;
	}

	/**
	 * Check Action Scheduler functions.
	 */
	private function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Check Action Scheduler async enqueue function.
	 */
	private function action_scheduler_async_available(): bool {
		return $this->action_scheduler_available() && function_exists( 'as_enqueue_async_action' );
	}
}
