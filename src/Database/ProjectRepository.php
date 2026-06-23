<?php
/**
 * Project database storage.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProjectRepository {
	private const SCHEMA_VERSION = '1.0.0';
	private const OPTION_SCHEMA  = 'lomnio_project_schema_version';

	/**
	 * Ensure project table exists.
	 */
	public function ensure_table(): void {
		if ( self::SCHEMA_VERSION === get_option( self::OPTION_SCHEMA ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			slug varchar(191) NOT NULL DEFAULT '',
			response_json longtext NOT NULL,
			fetched_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_id (project_id),
			KEY slug (slug)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
	}

	/**
	 * Store ProjectResource from an API response.
	 *
	 * @return true|\WP_Error
	 */
	public function store_api_response( array $response ) {
		$project = $response['data'] ?? null;

		if ( ! is_array( $project ) || empty( $project['id'] ) ) {
			return new \WP_Error(
				'lomnio_project_invalid_response',
				__( 'Project API response does not match the expected shape.', 'lomnio-api-connector' )
			);
		}

		$this->ensure_table();

		global $wpdb;

		$now          = current_time( 'mysql' );
		$table        = $this->table_name();
		$project_id   = (int) $project['id'];
		$slug         = isset( $project['slug'] ) ? sanitize_title( (string) $project['slug'] ) : '';
		$response_json = wp_json_encode( $project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $response_json ) ) {
			return new \WP_Error(
				'lomnio_project_json_encode_failed',
				__( 'Could not encode project response for storage.', 'lomnio-api-connector' )
			);
		}

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE project_id = %d LIMIT 1",
				$project_id
			)
		);

		if ( $existing_id > 0 ) {
			$result = $wpdb->update(
				$table,
				array(
					'slug'          => $slug,
					'response_json' => $response_json,
					'fetched_at'    => $now,
					'updated_at'    => $now,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$result = $wpdb->insert(
				$table,
				array(
					'project_id'    => $project_id,
					'slug'          => $slug,
					'response_json' => $response_json,
					'fetched_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}

		if ( false === $result ) {
			return new \WP_Error(
				'lomnio_project_database_error',
				__( 'Could not store project response in the database.', 'lomnio-api-connector' )
			);
		}

		return true;
	}

	/**
	 * Get the latest stored ProjectResource.
	 *
	 * @return array|null
	 */
	public function get_api_response(): ?array {
		$this->ensure_table();

		global $wpdb;

		$json = $wpdb->get_var( "SELECT response_json FROM {$this->table_name()} ORDER BY fetched_at DESC LIMIT 1" );

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$response = json_decode( $json, true );

		if ( ! is_array( $response ) ) {
			return null;
		}

		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}

		return $response;
	}

	/**
	 * Get the latest stored ProjectResource as an object for theme consumers.
	 */
	public function get_project_object(): object {
		$project = $this->get_api_response();

		if ( ! is_array( $project ) ) {
			return (object) array();
		}

		return json_decode( wp_json_encode( $project ) ?: '{}' ) ?: (object) array();
	}

	/**
	 * Get table name using the WordPress table prefix.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'lomnio_project';
	}
}
