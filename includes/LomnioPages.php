<?php
/**
 * Global Lomnio page facade.
 *
 * @package LomnioApiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LomnioPages' ) ) {
	final class LomnioPages {
		public static function settings(): array {
			return self::context()->settings();
		}

		public static function component( string $page ): string {
			return self::context()->component( $page );
		}

		public static function not_found_component(): string {
			return self::context()->not_found_component();
		}

		public static function floor_number(): int {
			return self::context()->floor_number();
		}

		public static function unit_id(): string {
			return self::context()->unit_id();
		}

		public static function phase(): string {
			return self::context()->phase();
		}

		public static function floor_base( ?string $phase = null ): string {
			return self::context()->floor_base( $phase );
		}

		public static function floor_link( ?string $phase, int $floor ): string {
			return self::context()->floor_link( $phase, $floor );
		}

		public static function unit_link( string $unit_id, ?string $phase = null ): string {
			return self::context()->unit_link( $unit_id, $phase );
		}

		public static function settings_id( string $page ): ?int {
			return self::context()->settings_post_id( $page );
		}

		public static function fields( string $page, $loader = null ): array {
			return self::context()->fields( $page, $loader );
		}

		public static function home_page_id(): ?int {
			return self::context()->home_page_id();
		}

		public static function seo( string $title, string $canonical, string $description = '' ): array {
			return self::context()->seo( $title, $canonical, $description );
		}

		public static function context(): \LomnioApiConnector\Pages\PageContext {
			return new \LomnioApiConnector\Pages\PageContext();
		}
	}
}
