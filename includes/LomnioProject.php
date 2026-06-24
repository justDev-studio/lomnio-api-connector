<?php
/**
 * Global Lomnio project facade.
 *
 * @package LomnioApiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LomnioProject' ) ) {
	final class LomnioProject {
		/**
		 * Get the latest stored ProjectResource as an object.
		 */
		public static function get(): object {
			return self::repository()->get_project_object();
		}

		/**
		 * Get the latest stored ProjectResource as an array.
		 */
		public static function to_array(): ?array {
			return self::repository()->get_api_response();
		}

		/**
		 * Get the latest stored project ID.
		 */
		public static function id(): ?int {
			return self::repository()->get_project_id();
		}

		/**
		 * Get the project repository for advanced usage.
		 */
		public static function repository(): \LomnioApiConnector\Database\ProjectRepository {
			return new \LomnioApiConnector\Database\ProjectRepository();
		}
	}
}
