<?php
/**
 * Frontend display page helpers.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageContext {
	private PageSettings $settings;

	public function __construct( ?PageSettings $settings = null ) {
		$this->settings = $settings ?: new PageSettings();
	}

	public function settings(): array {
		return $this->settings->all();
	}

	public function component( string $page ): string {
		$settings = $this->settings();
		$key      = $page . '_component';

		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}

	public function not_found_component(): string {
		return (string) $this->settings()['not_found_component'];
	}

	public function floor_number(): int {
		return (int) get_query_var( (string) $this->settings()['floor_slug'] );
	}

	public function unit_id(): string {
		return (string) get_query_var( (string) $this->settings()['unit_slug'] );
	}

	public function phase(): string {
		return preg_replace( '/\s+/', '-', urldecode( trim( (string) get_query_var( (string) $this->settings()['phase_query_var'], '' ) ) ) );
	}

	public function floor_base( ?string $phase = null ): string {
		$settings = $this->settings();
		$url      = trailingslashit( rtrim( (string) get_bloginfo( 'url' ), '/' ) . '/' . trim( (string) $settings['floor_slug'], '/' ) );

		if ( null !== $phase && '' !== $phase ) {
			$url = trailingslashit( $url . trim( $phase, '/' ) );
		}

		return (string) apply_filters( 'wpml_permalink', $url );
	}

	public function floor_link( ?string $phase, int $floor ): string {
		return trailingslashit( $this->floor_base( $phase ) . rawurlencode( (string) $floor ) );
	}

	public function unit_link( string $unit_id, ?string $phase = null ): string {
		$settings = $this->settings();
		$url      = trailingslashit( rtrim( (string) get_bloginfo( 'url' ), '/' ) . '/' . trim( (string) $settings['unit_slug'], '/' ) );

		if ( null !== $phase && '' !== $phase ) {
			$url = trailingslashit( $url . trim( $phase, '/' ) );
		}

		$url .= rawurlencode( $unit_id );

		return (string) apply_filters( 'wpml_permalink', $url );
	}

	public function settings_post_id( string $page ): ?int {
		$settings = $this->settings();
		$key      = 'floor' === $page ? 'floor_settings_post_id' : 'unit_settings_post_id';
		$post_id  = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;

		if ( $post_id <= 0 ) {
			$post_id = $this->legacy_settings_post_id( 'floor' === $page ? 'floor_settings' : 'single_settings' );
		}

		if ( $post_id <= 0 ) {
			return null;
		}

		$current_lang = apply_filters( 'wpml_current_language', null );
		$translated   = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ) ?: 'post', true, $current_lang );

		return $translated ? (int) $translated : $post_id;
	}

	public function fields( string $page, $loader = null, ?bool $force_dark_header = null ): array {
		$post_id = $this->settings_post_id( $page );
		$ttl     = max( 0, (int) $this->settings()['acf_cache_ttl'] );
		$lang    = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : get_locale();
		$ver     = $post_id ? (string) get_post_modified_time( 'U', true, $post_id ) : '0';
		$key     = sprintf( 'lomnio_page_fields_%s_%s_%s_%s', $page, $post_id ?: '0', $lang ?: 'na', $ver );

		$fields = $this->cache(
			$key,
			static function () use ( $loader, $post_id ): array {
				if ( is_callable( $loader ) ) {
					$value = call_user_func( $loader, $post_id );
					return is_array( $value ) ? $value : array();
				}

				if ( function_exists( 'get_fields' ) ) {
					$value = $post_id ? get_fields( $post_id ) : get_fields();
					return is_array( $value ) ? $value : array();
				}

				return array();
			},
			$ttl
		);

		if ( null === $force_dark_header ) {
			$force_dark_header = 'unit' === $page && ! empty( $this->settings()['force_dark_unit_header'] );
		}

		if ( $force_dark_header ) {
			$fields['dark_header'] = true;
		}

		return $fields;
	}

	public function home_page_id(): ?int {
		$page_id = (int) $this->settings()['flats_home_page_id'];

		if ( $page_id <= 0 && defined( 'PAGES' ) && is_array( PAGES ) && ! empty( PAGES['FLATS_HOME'] ) ) {
			$page_id = (int) PAGES['FLATS_HOME'];
		}

		if ( $page_id <= 0 ) {
			return null;
		}

		$translated = apply_filters( 'wpml_object_id', $page_id, 'page', true );

		return $translated ? (int) $translated : $page_id;
	}

	public function seo( string $title, string $canonical, string $description = '' ): array {
		return array(
			'title'       => $title,
			'description' => $description,
			'canonical'   => $canonical,
		);
	}

	private function legacy_settings_post_id( string $post_type ): int {
		if ( ! post_type_exists( $post_type ) ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	private function cache( string $key, callable $callback, int $ttl ): array {
		if ( defined( 'WP_ENV' ) && 'development' === WP_ENV ) {
			return $callback();
		}

		if ( $ttl <= 0 ) {
			return $callback();
		}

		$group = 'lomnio_pages';
		$value = wp_cache_get( $key, $group );

		if ( false !== $value ) {
			return is_array( $value ) ? $value : array();
		}

		$transient_key = $group . '_' . $key;
		$value         = get_transient( $transient_key );

		if ( false !== $value ) {
			wp_cache_set( $key, $value, $group, $ttl );
			return is_array( $value ) ? $value : array();
		}

		$value = $callback();
		wp_cache_set( $key, $value, $group, $ttl );
		set_transient( $transient_key, $value, $ttl );

		return is_array( $value ) ? $value : array();
	}
}
