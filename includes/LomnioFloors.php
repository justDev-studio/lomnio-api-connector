<?php
/**
 * Global Lomnio floors facade.
 *
 * @package LomnioApiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LomnioFloors' ) ) {
	final class LomnioFloors {
		/**
		 * Get stored FloorResource objects.
		 */
		public static function get( array $filters = array() ): array {
			return self::repository()->get_floors( $filters );
		}

		/**
		 * Get stored FloorResource objects.
		 */
		public static function all( array $filters = array() ): array {
			return self::get( $filters );
		}

		/**
		 * Get a single floor by API ID.
		 */
		public static function find( $floor_id ): ?object {
			return self::repository()->get_floor_by_id( $floor_id );
		}

		/**
		 * Get a single floor by number.
		 */
		public static function find_by_number( $floor_number ): ?object {
			return self::repository()->get_floor_by_number( $floor_number );
		}

		/**
		 * Get floors for a project.
		 */
		public static function by_project( $project_id ): array {
			return self::repository()->get_floors_by_project_id( $project_id );
		}

		/**
		 * Get the floors repository for advanced usage.
		 */
		public static function repository(): \LomnioApiConnector\Database\FloorRepository {
			return new \LomnioApiConnector\Database\FloorRepository();
		}
	}
}
