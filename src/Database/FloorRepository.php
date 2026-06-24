<?php
/**
 * Floors database storage.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FloorRepository {
	private const SCHEMA_VERSION = '1.0.2';
	private const OPTION_SCHEMA  = 'lomnio_floors_schema_version';

	/**
	 * Ensure floors table exists.
	 */
	public function ensure_table(): void {
		if ( self::SCHEMA_VERSION === get_option( self::OPTION_SCHEMA ) && $this->table_exists() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL DEFAULT 0,
			floor_id varchar(64) NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			floor_number int NOT NULL,
			sort_order int NULL DEFAULT NULL,
			building_id varchar(64) NOT NULL DEFAULT '',
			building_name varchar(191) NOT NULL DEFAULT '',
			availability varchar(64) NOT NULL DEFAULT '',
			units_count int unsigned NOT NULL DEFAULT 0,
			available_units_count int unsigned NOT NULL DEFAULT 0,
			facade_map_id varchar(191) NOT NULL DEFAULT '',
			floor_plan_url varchar(255) NOT NULL DEFAULT '',
			payload_hash char(64) NOT NULL DEFAULT '',
			payload_json longtext NOT NULL,
			in_latest_list tinyint(1) NOT NULL DEFAULT 1,
			fetched_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY floor_id (floor_id),
			KEY floor_number (floor_number),
			KEY sort_order (sort_order),
			KEY building_id (building_id),
			KEY availability (availability),
			KEY facade_map_id (facade_map_id),
			KEY project_id (project_id),
			KEY in_latest_list (in_latest_list)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
	}

	/**
	 * Check whether the floors table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		return $table === $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		);
	}

	/**
	 * Store floors from the list endpoint.
	 *
	 * @return array|\WP_Error
	 */
	public function store_list_floors( array $floors ) {
		$this->ensure_table();

		global $wpdb;

		$table      = $this->table_name();
		$now        = current_time( 'mysql' );
		$project_id = $this->current_project_id();

		$wpdb->update( $table, array( 'in_latest_list' => 0 ), array( 'in_latest_list' => 1 ), array( '%d' ), array( '%d' ) );

		$stored_ids = array();

		foreach ( $floors as $floor ) {
			if ( ! is_array( $floor ) || empty( $floor['id'] ) ) {
				continue;
			}

			$payload_json = wp_json_encode( $floor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( ! is_string( $payload_json ) ) {
				return new \WP_Error(
					'lomnio_floors_json_encode_failed',
					__( 'Could not encode floor payload for storage.', 'lomnio-api-connector' )
				);
			}

			$columns      = $this->columns_from_floor( $floor );
			$floor_id     = $columns['floor_id'];
			$payload_hash = hash( 'sha256', $payload_json );

			$data = array_merge(
				$columns,
				array(
					'project_id'     => $project_id,
					'payload_hash'   => $payload_hash,
					'payload_json'   => $payload_json,
					'in_latest_list' => 1,
					'fetched_at'     => $now,
					'updated_at'     => $now,
				)
			);

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE floor_id = %s LIMIT 1",
					$floor_id
				)
			);

			if ( $existing_id > 0 ) {
				$result = $wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			} else {
				$result = $wpdb->insert( $table, $data );
			}

			if ( false === $result ) {
				return new \WP_Error(
					'lomnio_floors_database_error',
					__( 'Could not store floor payload in the database.', 'lomnio-api-connector' )
				);
			}

			$stored_ids[] = $floor_id;
		}

		return $stored_ids;
	}

	/**
	 * Get floors as FloorResource objects.
	 */
	public function get_floors( array $filters = array() ): array {
		$rows = $this->query_rows( $filters );

		return array_values(
			array_filter(
				array_map(
					array( $this, 'row_to_floor_object' ),
					$rows
				)
			)
		);
	}

	/**
	 * Get a single floor by ID.
	 */
	public function get_floor_by_id( $floor_id ): ?object {
		$floors = $this->get_floors( array( 'id' => (string) $floor_id ) );

		return $floors[0] ?? null;
	}

	/**
	 * Get a single floor by number.
	 */
	public function get_floor_by_number( $floor_number ): ?object {
		$floors = $this->get_floors( array( 'floor_number' => (string) $floor_number ) );

		return $floors[0] ?? null;
	}

	/**
	 * Get floors for a project.
	 */
	public function get_floors_by_project_id( $project_id ): array {
		return $this->get_floors( array( 'project_id' => (string) $project_id ) );
	}

	/**
	 * Get table name using the WordPress table prefix.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'lomnio_floors';
	}

	/**
	 * Build filterable columns from a floor resource.
	 */
	private function columns_from_floor( array $floor ): array {
		$building = isset( $floor['building'] ) && is_array( $floor['building'] ) ? $floor['building'] : array();

		return array(
			'floor_id'              => (string) $floor['id'],
			'name'                  => $this->string_value( $floor['name'] ?? '' ),
			'floor_number'          => $this->int_value( $floor['number'] ?? 0 ),
			'sort_order'            => $this->int_or_null( $floor['sort_order'] ?? null ),
			'building_id'           => $this->string_value( $building['id'] ?? '' ),
			'building_name'         => $this->string_value( $building['name'] ?? '' ),
			'availability'          => $this->string_value( $floor['availability'] ?? '' ),
			'units_count'           => max( 0, $this->int_value( $floor['units_count'] ?? 0 ) ),
			'available_units_count' => max( 0, $this->int_value( $floor['available_units_count'] ?? 0 ) ),
			'facade_map_id'         => $this->string_value( $floor['facade_map_id'] ?? '' ),
			'floor_plan_url'        => $this->string_value( $floor['floor_plan_url'] ?? '' ),
		);
	}

	/**
	 * Query rows by filter columns.
	 */
	private function query_rows( array $filters ): array {
		$this->ensure_table();

		global $wpdb;

		$where  = array( 'in_latest_list = 1' );
		$params = array();

		$map = array(
			'id'                    => 'floor_id',
			'project_id'            => 'project_id',
			'floor_id'              => 'floor_id',
			'name'                  => 'name',
			'number'                => 'floor_number',
			'floor_number'          => 'floor_number',
			'sort_order'            => 'sort_order',
			'building_id'           => 'building_id',
			'building_name'         => 'building_name',
			'availability'          => 'availability',
			'status'                => 'availability',
			'units_count'           => 'units_count',
			'available_units_count' => 'available_units_count',
			'facade_map_id'         => 'facade_map_id',
			'floor_plan_url'        => 'floor_plan_url',
		);

		foreach ( $map as $filter_key => $column ) {
			if ( ! array_key_exists( $filter_key, $filters ) || '' === $filters[ $filter_key ] || null === $filters[ $filter_key ] ) {
				continue;
			}

			if ( is_array( $filters[ $filter_key ] ) ) {
				$values = array_values( array_filter( $filters[ $filter_key ], static fn( $value ) => '' !== $value && null !== $value ) );

				if ( empty( $values ) ) {
					continue;
				}

				$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
				$where[]      = "{$column} IN ({$placeholders})";
				$params       = array_merge( $params, array_map( 'strval', $values ) );
			} else {
				$where[]  = "{$column} = %s";
				$params[] = (string) $filters[ $filter_key ];
			}
		}

		$sql = "SELECT * FROM {$this->table_name()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY sort_order IS NULL ASC, sort_order ASC, floor_number ASC, name ASC';

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Convert a stored row to a template object.
	 */
	private function row_to_floor_object( array $row ): ?object {
		$json = $row['payload_json'] ?? '';

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$payload = json_decode( $json );

		if ( ! $payload instanceof \stdClass ) {
			return null;
		}

		if ( isset( $payload->data ) && $payload->data instanceof \stdClass ) {
			return $payload->data;
		}

		return $payload;
	}

	/**
	 * Normalize integer values for database storage.
	 */
	private function int_or_null( $value ) {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Normalize required integer values for database storage.
	 */
	private function int_value( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Get current project ID for local table relations.
	 */
	private function current_project_id(): int {
		$project_id = ( new ProjectRepository() )->get_project_id();

		return null !== $project_id ? $project_id : 0;
	}

	/**
	 * Normalize string values for database storage.
	 */
	private function string_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}
}
