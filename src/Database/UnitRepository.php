<?php
/**
 * Units database storage.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UnitRepository {
	private const SCHEMA_VERSION = '1.0.0';
	private const OPTION_SCHEMA  = 'lomnio_units_schema_version';

	/**
	 * Ensure units table exists.
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
			unit_id varchar(64) NOT NULL,
			code varchar(191) NOT NULL DEFAULT '',
			status_code varchar(64) NOT NULL DEFAULT '',
			status_label varchar(191) NOT NULL DEFAULT '',
			type varchar(64) NOT NULL DEFAULT '',
			layout_type varchar(64) NOT NULL DEFAULT '',
			room_count decimal(8,2) NULL DEFAULT NULL,
			building_id varchar(64) NOT NULL DEFAULT '',
			building_name varchar(191) NOT NULL DEFAULT '',
			floor_id varchar(64) NOT NULL DEFAULT '',
			floor_name varchar(191) NOT NULL DEFAULT '',
			floor_number int NULL DEFAULT NULL,
			phase_id varchar(64) NOT NULL DEFAULT '',
			phase_name varchar(191) NOT NULL DEFAULT '',
			area decimal(14,2) NULL DEFAULT NULL,
			area_floor decimal(14,2) NULL DEFAULT NULL,
			area_gross decimal(14,2) NULL DEFAULT NULL,
			area_building decimal(14,2) NULL DEFAULT NULL,
			area_land decimal(14,2) NULL DEFAULT NULL,
			price_without_vat decimal(14,2) NULL DEFAULT NULL,
			price_with_vat decimal(14,2) NULL DEFAULT NULL,
			discounted_price_without_vat decimal(14,2) NULL DEFAULT NULL,
			discounted_price_with_vat decimal(14,2) NULL DEFAULT NULL,
			price_per_sqm decimal(14,2) NULL DEFAULT NULL,
			vat_rate decimal(8,2) NULL DEFAULT NULL,
			list_hash char(64) NOT NULL DEFAULT '',
			detail_hash char(64) NOT NULL DEFAULT '',
			detail_source_hash char(64) NOT NULL DEFAULT '',
			list_payload_json longtext NOT NULL,
			detail_payload_json longtext NULL,
			in_latest_list tinyint(1) NOT NULL DEFAULT 1,
			list_fetched_at datetime NOT NULL,
			detail_fetched_at datetime NULL DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unit_id (unit_id),
			KEY code (code),
			KEY status_code (status_code),
			KEY floor_number (floor_number),
			KEY building_id (building_id),
			KEY type (type),
			KEY in_latest_list (in_latest_list),
			KEY detail_source_hash (detail_source_hash)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
	}

	/**
	 * Store units from the list endpoint.
	 *
	 * @return array|\WP_Error
	 */
	public function store_list_units( array $units ) {
		$this->ensure_table();

		global $wpdb;

		$table = $this->table_name();
		$now   = current_time( 'mysql' );

		$wpdb->update( $table, array( 'in_latest_list' => 0 ), array( 'in_latest_list' => 1 ), array( '%d' ), array( '%d' ) );

		$stored_ids = array();

		foreach ( $units as $unit ) {
			if ( ! is_array( $unit ) || empty( $unit['id'] ) ) {
				continue;
			}

			$payload_json = wp_json_encode( $unit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( ! is_string( $payload_json ) ) {
				return new \WP_Error(
					'lomnio_units_json_encode_failed',
					__( 'Could not encode unit list payload for storage.', 'lomnio-api-connector' )
				);
			}

			$columns   = $this->columns_from_unit( $unit );
			$unit_id   = $columns['unit_id'];
			$list_hash = hash( 'sha256', $payload_json );

			$data = array_merge(
				$columns,
				array(
					'list_hash'           => $list_hash,
					'detail_hash'         => $list_hash,
					'detail_source_hash'  => $list_hash,
					'list_payload_json'   => $payload_json,
					'detail_payload_json' => $payload_json,
					'in_latest_list'      => 1,
					'list_fetched_at'     => $now,
					'detail_fetched_at'   => $now,
					'updated_at'          => $now,
				)
			);

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE unit_id = %s LIMIT 1",
					$unit_id
				)
			);

			if ( $existing_id > 0 ) {
				$result = $wpdb->update( $table, $data, array( 'id' => $existing_id ) );
			} else {
				$result = $wpdb->insert( $table, $data );
			}

			if ( false === $result ) {
				return new \WP_Error(
					'lomnio_units_database_error',
					__( 'Could not store unit list payload in the database.', 'lomnio-api-connector' )
				);
			}

			$stored_ids[] = $unit_id;
		}

		return $stored_ids;
	}

	/**
	 * Get units as detailed UnitResource objects.
	 */
	public function get_units( array $filters = array() ): array {
		$rows = $this->query_rows( $filters );

		return array_values(
			array_filter(
				array_map(
					array( $this, 'row_to_unit_object' ),
					$rows
				)
			)
		);
	}

	/**
	 * Alias for templates that explicitly need detail list naming.
	 */
	public function get_detail_list( array $filters = array() ): array {
		return $this->get_units( $filters );
	}

	/**
	 * Get a single unit by ID.
	 */
	public function get_unit_by_id( $unit_id ): ?object {
		$units = $this->get_units( array( 'id' => (string) $unit_id ) );

		return $units[0] ?? null;
	}

	/**
	 * Get a single unit by code.
	 */
	public function get_unit_by_code( string $code ): ?object {
		$units = $this->get_units( array( 'code' => $code ) );

		return $units[0] ?? null;
	}

	/**
	 * Get table name using the WordPress table prefix.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'lomnio_units';
	}

	/**
	 * Build filterable columns from a unit resource.
	 */
	private function columns_from_unit( array $unit ): array {
		$status   = isset( $unit['status'] ) && is_array( $unit['status'] ) ? $unit['status'] : array();
		$building = isset( $unit['building'] ) && is_array( $unit['building'] ) ? $unit['building'] : array();
		$floor    = isset( $unit['floor'] ) && is_array( $unit['floor'] ) ? $unit['floor'] : array();
		$phase    = isset( $unit['phase'] ) && is_array( $unit['phase'] ) ? $unit['phase'] : array();
		$areas    = isset( $unit['areas'] ) && is_array( $unit['areas'] ) ? $unit['areas'] : array();
		$pricing  = isset( $unit['pricing'] ) && is_array( $unit['pricing'] ) ? $unit['pricing'] : array();

		return array(
			'unit_id'                      => (string) $unit['id'],
			'code'                         => isset( $unit['code'] ) ? (string) $unit['code'] : '',
			'status_code'                  => isset( $status['code'] ) ? (string) $status['code'] : '',
			'status_label'                 => isset( $status['label'] ) ? (string) $status['label'] : '',
			'type'                         => isset( $unit['type'] ) ? (string) $unit['type'] : '',
			'layout_type'                  => isset( $unit['layout_type'] ) ? (string) $unit['layout_type'] : '',
			'room_count'                   => $this->decimal_or_null( $unit['room_count'] ?? null ),
			'building_id'                  => isset( $building['id'] ) ? (string) $building['id'] : '',
			'building_name'                => isset( $building['name'] ) ? (string) $building['name'] : '',
			'floor_id'                     => isset( $floor['id'] ) ? (string) $floor['id'] : '',
			'floor_name'                   => isset( $floor['name'] ) ? (string) $floor['name'] : '',
			'floor_number'                 => isset( $floor['number'] ) && is_numeric( $floor['number'] ) ? (int) $floor['number'] : null,
			'phase_id'                     => isset( $phase['id'] ) ? (string) $phase['id'] : '',
			'phase_name'                   => isset( $phase['name'] ) ? (string) $phase['name'] : '',
			'area'                         => $this->decimal_or_null( $areas['area'] ?? null ),
			'area_floor'                   => $this->decimal_or_null( $areas['area_floor'] ?? null ),
			'area_gross'                   => $this->decimal_or_null( $areas['area_gross'] ?? null ),
			'area_building'                => $this->decimal_or_null( $areas['area_building'] ?? null ),
			'area_land'                    => $this->decimal_or_null( $areas['area_land'] ?? null ),
			'price_without_vat'            => $this->decimal_or_null( $pricing['price_without_vat'] ?? null ),
			'price_with_vat'               => $this->decimal_or_null( $pricing['price_with_vat'] ?? null ),
			'discounted_price_without_vat' => $this->decimal_or_null( $pricing['discounted_price_without_vat'] ?? null ),
			'discounted_price_with_vat'    => $this->decimal_or_null( $pricing['discounted_price_with_vat'] ?? null ),
			'price_per_sqm'                => $this->decimal_or_null( $pricing['price_per_sqm'] ?? null ),
			'vat_rate'                     => $this->decimal_or_null( $pricing['vat_rate'] ?? null ),
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
			'id'           => 'unit_id',
			'unit_id'      => 'unit_id',
			'code'         => 'code',
			'status'       => 'status_code',
			'status_code'  => 'status_code',
			'type'         => 'type',
			'layout_type'  => 'layout_type',
			'floor'        => 'floor_number',
			'floor_number' => 'floor_number',
			'floor_id'     => 'floor_id',
			'building_id'  => 'building_id',
			'phase_id'     => 'phase_id',
			'room_count'   => 'room_count',
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

		$sql = "SELECT * FROM {$this->table_name()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY floor_number ASC, code ASC';

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Convert a stored row to a template object.
	 */
	private function row_to_unit_object( array $row ): ?object {
		$json = ! empty( $row['detail_payload_json'] ) ? $row['detail_payload_json'] : $row['list_payload_json'];

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
	 * Normalize decimal values for database storage.
	 */
	private function decimal_or_null( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
