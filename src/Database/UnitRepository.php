<?php
/**
 * Units database storage.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Database;

use LomnioApiConnector\Pages\PageContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UnitRepository {
	private const SCHEMA_VERSION = '1.0.2';
	private const OPTION_SCHEMA  = 'lomnio_units_schema_version';

	/**
	 * Ensure units table exists.
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
			unit_id varchar(64) NOT NULL,
			code varchar(191) NOT NULL DEFAULT '',
			status_code varchar(64) NOT NULL DEFAULT '',
			status_label varchar(191) NOT NULL DEFAULT '',
			status_color varchar(64) NOT NULL DEFAULT '',
			status_is_system tinyint(1) NOT NULL DEFAULT 0,
			type varchar(64) NOT NULL DEFAULT '',
			layout_type varchar(64) NOT NULL DEFAULT '',
			room_count decimal(8,2) NULL DEFAULT NULL,
			orientation varchar(64) NOT NULL DEFAULT '',
			building_id varchar(64) NOT NULL DEFAULT '',
			building_name varchar(191) NOT NULL DEFAULT '',
			floor_id varchar(64) NOT NULL DEFAULT '',
			floor_name varchar(191) NOT NULL DEFAULT '',
			floor_number varchar(64) NOT NULL DEFAULT '',
			phase_id varchar(64) NOT NULL DEFAULT '',
			phase_name varchar(191) NOT NULL DEFAULT '',
			area decimal(14,2) NULL DEFAULT NULL,
			area_floor decimal(14,2) NULL DEFAULT NULL,
			area_gross decimal(14,2) NULL DEFAULT NULL,
			area_building decimal(14,2) NULL DEFAULT NULL,
			area_land decimal(14,2) NULL DEFAULT NULL,
			price_without_vat decimal(14,2) NULL DEFAULT NULL,
			price_with_vat decimal(14,2) NULL DEFAULT NULL,
			discount_without_vat decimal(14,2) NULL DEFAULT NULL,
			discount_with_vat decimal(14,2) NULL DEFAULT NULL,
			discounted_price_without_vat decimal(14,2) NULL DEFAULT NULL,
			discounted_price_with_vat decimal(14,2) NULL DEFAULT NULL,
			price_per_sqm decimal(14,2) NULL DEFAULT NULL,
			vat_rate decimal(8,2) NULL DEFAULT NULL,
			pricing_hidden tinyint(1) NULL DEFAULT NULL,
			pricing_display_text varchar(255) NOT NULL DEFAULT '',
			floor_plan_url varchar(255) NOT NULL DEFAULT '',
			bundle_id varchar(64) NOT NULL DEFAULT '',
			bundle_name varchar(191) NOT NULL DEFAULT '',
			payload_hash char(64) NOT NULL DEFAULT '',
			payload_json longtext NOT NULL,
			in_latest_list tinyint(1) NOT NULL DEFAULT 1,
			fetched_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unit_id (unit_id),
			KEY code (code),
			KEY status_code (status_code),
			KEY orientation (orientation),
			KEY floor_number (floor_number),
			KEY building_id (building_id),
			KEY floor_id (floor_id),
			KEY phase_id (phase_id),
			KEY type (type),
			KEY layout_type (layout_type),
			KEY bundle_id (bundle_id),
			KEY project_id (project_id),
			KEY in_latest_list (in_latest_list)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
	}

	/**
	 * Check whether the units table exists.
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
	 * Store units from the list endpoint.
	 *
	 * @return array|\WP_Error
	 */
	public function store_list_units( array $units ) {
		$this->ensure_table();

		global $wpdb;

		$table      = $this->table_name();
		$now        = current_time( 'mysql' );
		$project_id = $this->current_project_id();

		$wpdb->update( $table, array( 'in_latest_list' => 0 ), array( 'in_latest_list' => 1 ), array( '%d' ), array( '%d' ) );

		$stored_ids = array();

		foreach ( $units as $unit ) {
			if ( ! is_array( $unit ) || empty( $unit['id'] ) ) {
				continue;
			}

			$stored = $this->upsert_unit( $unit, $project_id, $now );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			$stored_ids[] = $stored;
		}

		return $stored_ids;
	}

	/**
	 * Store one unit received through a webhook without changing other rows.
	 *
	 * @return string|\WP_Error Stored unit ID.
	 */
	public function store_webhook_unit( array $unit, int $project_id = 0 ) {
		if ( empty( $unit['id'] ) ) {
			return new \WP_Error(
				'lomnio_webhook_unit_missing_id',
				__( 'Webhook unit payload is missing an ID.', 'lomnio-api-connector' )
			);
		}

		$this->ensure_table();

		return $this->upsert_unit(
			$unit,
			$project_id > 0 ? $project_id : $this->current_project_id(),
			current_time( 'mysql' )
		);
	}

	/**
	 * Apply only fields declared by a unit webhook.
	 *
	 * A complete row is inserted when the unit does not exist locally.
	 *
	 * @return string|\WP_Error Stored unit ID.
	 */
	public function update_webhook_unit_fields( array $unit, array $changed_fields, int $project_id = 0 ) {
		if ( empty( $unit['id'] ) ) {
			return new \WP_Error(
				'lomnio_webhook_unit_missing_id',
				__( 'Webhook unit payload is missing an ID.', 'lomnio-api-connector' )
			);
		}

		$this->ensure_table();

		global $wpdb;

		$unit_id = (string) $unit['id'];
		$table   = $this->table_name();
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, payload_json FROM {$table} WHERE unit_id = %s LIMIT 1",
				$unit_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $changed_fields ) ) {
			return $this->store_webhook_unit( $unit, $project_id );
		}

		$stored_unit = json_decode( (string) $row['payload_json'], true );

		if ( ! is_array( $stored_unit ) ) {
			return $this->store_webhook_unit( $unit, $project_id );
		}

		$changed_groups = array();

		foreach ( $changed_fields as $changed_field ) {
			$field = trim( (string) $changed_field );

			if ( '' === $field ) {
				continue;
			}

			$group = explode( '.', $field, 2 )[0];

			if ( array_key_exists( $group, $unit ) ) {
				$stored_unit[ $group ] = $unit[ $group ];
				$changed_groups[]      = $group;
			}
		}

		if ( empty( $changed_groups ) ) {
			return $unit_id;
		}

		$payload_json = wp_json_encode( $stored_unit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $payload_json ) ) {
			return new \WP_Error(
				'lomnio_units_json_encode_failed',
				__( 'Could not encode unit payload for storage.', 'lomnio-api-connector' )
			);
		}

		$all_columns = $this->columns_from_unit( $stored_unit );
		$column_map  = $this->webhook_field_column_map();
		$data        = array(
			'payload_hash'   => hash( 'sha256', $payload_json ),
			'payload_json'   => $payload_json,
			'in_latest_list' => 1,
			'fetched_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		foreach ( array_unique( $changed_groups ) as $group ) {
			foreach ( $column_map[ $group ] ?? array() as $column ) {
				$data[ $column ] = $all_columns[ $column ];
			}
		}

		if ( false === $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) ) ) {
			return new \WP_Error(
				'lomnio_units_database_error',
				__( 'Could not update unit webhook fields in the database.', 'lomnio-api-connector' )
			);
		}

		return $unit_id;
	}

	/**
	 * Exclude a deleted webhook unit from frontend queries.
	 *
	 * @return true|\WP_Error
	 */
	public function mark_webhook_unit_deleted( $unit_id ) {
		$this->ensure_table();

		global $wpdb;

		$result = $wpdb->update(
			$this->table_name(),
			array(
				'in_latest_list' => 0,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'unit_id' => (string) $unit_id ),
			array( '%d', '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			return new \WP_Error(
				'lomnio_webhook_unit_delete_failed',
				__( 'Could not mark the webhook unit as deleted.', 'lomnio-api-connector' )
			);
		}

		return true;
	}

	/**
	 * Remove a unit deleted or unpublished in Lomnio.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_webhook_unit( $unit_id ) {
		$this->ensure_table();

		global $wpdb;

		$result = $wpdb->delete( $this->table_name(), array( 'unit_id' => (string) $unit_id ), array( '%s' ) );

		if ( false === $result ) {
			return new \WP_Error(
				'lomnio_webhook_unit_delete_failed',
				__( 'Could not remove the webhook unit.', 'lomnio-api-connector' )
			);
		}

		return true;
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
	 * Get units for a floor.
	 */
	public function get_units_by_floor_id( $floor_id ): array {
		return $this->get_units( array( 'floor_id' => (string) $floor_id ) );
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
		$bundle   = isset( $unit['bundle'] ) && is_array( $unit['bundle'] ) ? $unit['bundle'] : array();

		return array(
			'unit_id'                      => (string) $unit['id'],
			'code'                         => isset( $unit['code'] ) ? (string) $unit['code'] : '',
			'status_code'                  => isset( $status['code'] ) ? (string) $status['code'] : '',
			'status_label'                 => isset( $status['label'] ) ? (string) $status['label'] : '',
			'status_color'                 => isset( $status['color'] ) ? (string) $status['color'] : '',
			'status_is_system'             => ! empty( $status['is_system'] ) ? 1 : 0,
			'type'                         => isset( $unit['type'] ) ? (string) $unit['type'] : '',
			'layout_type'                  => isset( $unit['layout_type'] ) ? (string) $unit['layout_type'] : '',
			'room_count'                   => $this->decimal_or_null( $unit['room_count'] ?? null ),
			'orientation'                  => isset( $unit['orientation'] ) ? (string) $unit['orientation'] : '',
			'building_id'                  => isset( $building['id'] ) ? (string) $building['id'] : '',
			'building_name'                => isset( $building['name'] ) ? (string) $building['name'] : '',
			'floor_id'                     => isset( $floor['id'] ) ? (string) $floor['id'] : '',
			'floor_name'                   => isset( $floor['name'] ) ? (string) $floor['name'] : '',
			'floor_number'                 => isset( $floor['number'] ) ? (string) $floor['number'] : '',
			'phase_id'                     => isset( $phase['id'] ) ? (string) $phase['id'] : '',
			'phase_name'                   => isset( $phase['name'] ) ? (string) $phase['name'] : '',
			'area'                         => $this->decimal_or_null( $areas['area'] ?? null ),
			'area_floor'                   => $this->decimal_or_null( $areas['area_floor'] ?? null ),
			'area_gross'                   => $this->decimal_or_null( $areas['area_gross'] ?? null ),
			'area_building'                => $this->decimal_or_null( $areas['area_building'] ?? null ),
			'area_land'                    => $this->decimal_or_null( $areas['area_land'] ?? null ),
			'price_without_vat'            => $this->decimal_or_null( $pricing['price_without_vat'] ?? null ),
			'price_with_vat'               => $this->decimal_or_null( $pricing['price_with_vat'] ?? null ),
			'discount_without_vat'         => $this->decimal_or_null( $pricing['discount_without_vat'] ?? null ),
			'discount_with_vat'            => $this->decimal_or_null( $pricing['discount_with_vat'] ?? null ),
			'discounted_price_without_vat' => $this->decimal_or_null( $pricing['discounted_price_without_vat'] ?? null ),
			'discounted_price_with_vat'    => $this->decimal_or_null( $pricing['discounted_price_with_vat'] ?? null ),
			'price_per_sqm'                => $this->decimal_or_null( $pricing['price_per_sqm'] ?? null ),
			'vat_rate'                     => $this->decimal_or_null( $pricing['vat_rate'] ?? null ),
			'pricing_hidden'               => array_key_exists( 'hidden', $pricing ) ? (int) (bool) $pricing['hidden'] : null,
			'pricing_display_text'         => $this->display_text_value( $pricing['display_text'] ?? '' ),
			'floor_plan_url'               => isset( $unit['floor_plan_url'] ) ? (string) $unit['floor_plan_url'] : '',
			'bundle_id'                    => isset( $bundle['bundle_id'] ) ? (string) $bundle['bundle_id'] : '',
			'bundle_name'                  => isset( $bundle['bundle_name'] ) ? (string) $bundle['bundle_name'] : '',
		);
	}

	/**
	 * Insert or update one complete unit payload.
	 *
	 * @return string|\WP_Error Stored unit ID.
	 */
	private function upsert_unit( array $unit, int $project_id, string $now ) {
		global $wpdb;

		$payload_json = wp_json_encode( $unit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $payload_json ) ) {
			return new \WP_Error(
				'lomnio_units_json_encode_failed',
				__( 'Could not encode unit payload for storage.', 'lomnio-api-connector' )
			);
		}

		$columns = $this->columns_from_unit( $unit );
		$unit_id = $columns['unit_id'];
		$data    = array_merge(
			$columns,
			array(
				'project_id'     => $project_id,
				'payload_hash'   => hash( 'sha256', $payload_json ),
				'payload_json'   => $payload_json,
				'in_latest_list' => 1,
				'fetched_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$table   = $this->table_name();

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE unit_id = %s LIMIT 1",
				$unit_id
			)
		);

		$result = $existing_id > 0
			? $wpdb->update( $table, $data, array( 'id' => $existing_id ) )
			: $wpdb->insert( $table, $data );

		if ( false === $result ) {
			return new \WP_Error(
				'lomnio_units_database_error',
				__( 'Could not store unit payload in the database.', 'lomnio-api-connector' )
			);
		}

		return $unit_id;
	}

	/**
	 * Map API resource fields to denormalized database columns.
	 */
	private function webhook_field_column_map(): array {
		return array(
			'code'           => array( 'code' ),
			'status'         => array( 'status_code', 'status_label', 'status_color', 'status_is_system' ),
			'type'           => array( 'type' ),
			'layout_type'    => array( 'layout_type' ),
			'room_count'     => array( 'room_count' ),
			'orientation'    => array( 'orientation' ),
			'building'       => array( 'building_id', 'building_name' ),
			'floor'          => array( 'floor_id', 'floor_name', 'floor_number' ),
			'phase'          => array( 'phase_id', 'phase_name' ),
			'areas'          => array( 'area', 'area_floor', 'area_gross', 'area_building', 'area_land' ),
			'pricing'        => array( 'price_without_vat', 'price_with_vat', 'discount_without_vat', 'discount_with_vat', 'discounted_price_without_vat', 'discounted_price_with_vat', 'price_per_sqm', 'vat_rate', 'pricing_hidden', 'pricing_display_text' ),
			'floor_plan_url' => array( 'floor_plan_url' ),
			'bundle'         => array( 'bundle_id', 'bundle_name' ),
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
			'id'                           => 'unit_id',
			'project_id'                   => 'project_id',
			'unit_id'                      => 'unit_id',
			'code'                         => 'code',
			'status'                       => 'status_code',
			'status_code'                  => 'status_code',
			'status_label'                 => 'status_label',
			'status_color'                 => 'status_color',
			'status_is_system'             => 'status_is_system',
			'type'                         => 'type',
			'layout_type'                  => 'layout_type',
			'orientation'                  => 'orientation',
			'floor'                        => 'floor_number',
			'floor_number'                 => 'floor_number',
			'floor_id'                     => 'floor_id',
			'floor_name'                   => 'floor_name',
			'building_id'                  => 'building_id',
			'building_name'                => 'building_name',
			'phase_id'                     => 'phase_id',
			'phase_name'                   => 'phase_name',
			'room_count'                   => 'room_count',
			'area'                         => 'area',
			'area_floor'                   => 'area_floor',
			'area_gross'                   => 'area_gross',
			'area_building'                => 'area_building',
			'area_land'                    => 'area_land',
			'price_without_vat'            => 'price_without_vat',
			'price_with_vat'               => 'price_with_vat',
			'discount_without_vat'         => 'discount_without_vat',
			'discount_with_vat'            => 'discount_with_vat',
			'discounted_price_without_vat' => 'discounted_price_without_vat',
			'discounted_price_with_vat'    => 'discounted_price_with_vat',
			'price_per_sqm'                => 'price_per_sqm',
			'vat_rate'                     => 'vat_rate',
			'pricing_hidden'               => 'pricing_hidden',
			'pricing_display_text'         => 'pricing_display_text',
			'floor_plan_url'               => 'floor_plan_url',
			'bundle_id'                    => 'bundle_id',
			'bundle_name'                  => 'bundle_name',
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

		$sql = "SELECT * FROM {$this->table_name()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY floor_number + 0 ASC, code ASC';

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Convert a stored row to a template object.
	 */
	private function row_to_unit_object( array $row ): ?object {
		static $page_context = null;

		$json = ! empty( $row['payload_json'] ) ? $row['payload_json'] : '';

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$payload = json_decode( $json );

		if ( ! $payload instanceof \stdClass ) {
			return null;
		}

		if ( isset( $payload->data ) && $payload->data instanceof \stdClass ) {
			$payload = $payload->data;
		}

		$payload->url = null;

		if ( isset( $payload->code ) && is_scalar( $payload->code ) && '' !== (string) $payload->code ) {
			if ( ! $page_context instanceof PageContext ) {
				$page_context = new PageContext();
			}

			$payload->url = $page_context->unit_link( (string) $payload->code, null );
		}

		return $payload;
	}

	/**
	 * Normalize decimal values for database storage.
	 */
	private function decimal_or_null( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Get current project ID for local table relations.
	 */
	private function current_project_id(): int {
		$project_id = ( new ProjectRepository() )->get_project_id();

		return null !== $project_id ? $project_id : 0;
	}

	/**
	 * Normalize pricing display text for filterable storage.
	 */
	private function display_text_value( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $json ) ? $json : '';
		}

		return '';
	}
}
