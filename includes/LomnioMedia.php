<?php
/**
 * Global Lomnio media facade.
 *
 * @package LomnioApiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LomnioMedia' ) ) {
	final class LomnioMedia {
		public static function url( string $source_url, array $args = array() ): string {
			return self::proxy()->url( $source_url, $args );
		}

		public static function floor_plan( string $source_url ): string {
			return self::proxy()->default_floor_plan_url( $source_url );
		}

		public static function proxy(): \LomnioApiConnector\Media\ImageProxy {
			return new \LomnioApiConnector\Media\ImageProxy();
		}
	}
}
