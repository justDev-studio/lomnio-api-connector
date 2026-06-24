<?php
/**
 * Global Lomnio units facade.
 *
 * @package LomnioApiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LomnioUnits' ) ) {
	final class LomnioUnits {
		/**
		 * Get stored UnitDetailResource objects.
		 */
		public static function get( array $filters = array() ): array {
			return self::repository()->get_units( $filters );
		}

		/**
		 * Get stored UnitDetailResource objects.
		 */
		public static function all( array $filters = array() ): array {
			return self::get( $filters );
		}

		/**
		 * Get a single unit by API ID.
		 */
		public static function find( $unit_id ): ?object {
			return self::repository()->get_unit_by_id( $unit_id );
		}

		/**
		 * Get a single unit by code.
		 */
		public static function find_by_code( string $code ): ?object {
			return self::repository()->get_unit_by_code( $code );
		}

		/**
		 * Get units for a floor.
		 */
		public static function by_floor( $floor_id ): array {
			return self::repository()->get_units_by_floor_id( $floor_id );
		}

		/**
		 * Get the units repository for advanced usage.
		 */
		public static function repository(): \LomnioApiConnector\Database\UnitRepository {
			return new \LomnioApiConnector\Database\UnitRepository();
		}
	}
}
