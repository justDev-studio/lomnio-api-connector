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
		$post_id = ( new PageSettingsPostTypes() )->first_post_id( 'floor' === $page ? PageSettingsPostTypes::FLOOR_POST_TYPE : PageSettingsPostTypes::UNIT_POST_TYPE );

		if ( $post_id <= 0 ) {
			return null;
		}

		$current_lang = apply_filters( 'wpml_current_language', null );
		$translated   = apply_filters( 'wpml_object_id', $post_id, get_post_type( $post_id ) ?: 'post', true, $current_lang );

		return $translated ? (int) $translated : $post_id;
	}

	public function fields( string $page, $loader = null ): array {
		$post_id = $this->settings_post_id( $page );
		$ttl     = max( 0, (int) $this->settings()['acf_cache_ttl'] );
		$lang    = defined( 'ICL_LANGUAGE_CODE' ) ? ICL_LANGUAGE_CODE : get_locale();
		$ver     = $post_id ? (string) get_post_modified_time( 'U', true, $post_id ) : '0';
		$data_ver = (string) get_option( \LomnioApiConnector\Database\DataRevision::OPTION_NAME, '0' );
		$key      = sprintf( 'lomnio_page_fields_%s_%s_%s_%s_%s', $page, $post_id ?: '0', $lang ?: 'na', $ver, $data_ver );

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

	/**
	 * Build SEO metadata for a dynamic unit route.
	 *
	 * @param object $unit Normalized Lomnio unit data.
	 */
	public function unit_seo( object $unit ): array {
		$site_name   = (string) get_bloginfo( 'name' );
		$code        = trim( (string) ( $unit->code ?? '' ) );
		$title       = '' !== $code ? $code . ' | ' . $site_name : $site_name;
		$language    = defined( 'ICL_LANGUAGE_CODE' ) ? strtolower( (string) ICL_LANGUAGE_CODE ) : strtolower( substr( get_locale(), 0, 2 ) );
		$type_labels = isset( $unit->type_labels ) ? (array) $unit->type_labels : array();
		$unit_type   = (string) ( $type_labels[ $language ] ?? ( $unit->type ?? __( 'Nehnuteľnosť', 'lomnio-api-connector' ) ) );
		$description = array(
			sprintf( __( '%1$s %2$s v projekte %3$s.', 'lomnio-api-connector' ), $unit_type, $code, $site_name ),
		);

		if ( isset( $unit->room_count ) ) {
			$description[] = sprintf( __( 'Počet izieb: %d.', 'lomnio-api-connector' ), (int) $unit->room_count );
		}

		if ( isset( $unit->areas->area ) && (float) $unit->areas->area > 0 ) {
			$description[] = sprintf(
				__( 'Celková plocha: %s m².', 'lomnio-api-connector' ),
				number_format_i18n( (float) $unit->areas->area, 2 )
			);
		}

		if ( isset( $unit->floor->number ) ) {
			$description[] = sprintf( __( 'Podlažie: %d.', 'lomnio-api-connector' ), (int) $unit->floor->number );
		}

		$phase = $this->phase();

		return $this->resolve_seo(
			'unit',
			array(
				'title'       => $title,
				'description' => implode( ' ', $description ),
				'canonical'   => $this->unit_link( $code, '' !== $phase ? $phase : null ),
			)
		);
	}

	/**
	 * Build SEO metadata for a dynamic floor route.
	 */
	public function floor_seo( int $floor ): array {
		$site_name = (string) get_bloginfo( 'name' );
		$phase     = $this->phase();

		return $this->resolve_seo(
			'floor',
			array(
				'title'       => sprintf( __( '%d. podlažie | %s', 'lomnio-api-connector' ), $floor, $site_name ),
				'description' => sprintf(
					__( 'Ponuka bytov na %1$d. podlaží projektu %2$s. Pozrite si aktuálne byty a apartmány na tomto podlaží.', 'lomnio-api-connector' ),
					$floor,
					$site_name
				),
				'canonical'   => $this->floor_link( '' !== $phase ? $phase : null, $floor ),
			)
		);
	}

	/**
	 * Apply explicitly configured Yoast fields to generated route metadata.
	 */
	private function resolve_seo( string $page, array $fallback ): array {
		$post_id = $this->settings_post_id( $page );

		if ( ! $post_id || ! function_exists( 'YoastSEO' ) ) {
			return $fallback;
		}

		try {
			$yoast_meta = YoastSEO()->meta->for_post( $post_id );
		} catch ( \Throwable $exception ) {
			return $fallback;
		}

		if ( ! $yoast_meta ) {
			return $fallback;
		}

		return array(
			'title'       => $this->has_post_meta_value( $post_id, '_yoast_wpseo_title' )
				? $this->first_value( array( $yoast_meta->title ?? '', $fallback['title'] ) )
				: $fallback['title'],
			'description' => $this->has_post_meta_value( $post_id, '_yoast_wpseo_metadesc' )
				? $this->first_value( array( $yoast_meta->meta_description ?? '', $fallback['description'] ) )
				: $fallback['description'],
			'canonical'   => $fallback['canonical'],
		);
	}

	private function has_post_meta_value( int $post_id, string $key ): bool {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		$value = get_post_meta( $post_id, $key, true );

		return is_scalar( $value ) && '' !== trim( (string) $value );
	}

	private function first_value( array $values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return '';
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
