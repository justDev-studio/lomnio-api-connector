<?php
/**
 * Display page settings storage.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageSettings {
	public const OPTION_NAME = 'lomnio_api_connector_page_settings';

	/**
	 * Get settings with defaults.
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $this->defaults(), $this->sanitize( $stored ) );
	}

	/**
	 * Save sanitized settings.
	 */
	public function update( array $settings ): void {
		$settings = array_merge(
			array(
				'enabled'              => false,
				'phase_routes_enabled' => false,
			),
			$settings
		);

		update_option( self::OPTION_NAME, array_merge( $this->defaults(), $this->sanitize( $settings ) ), false );
		flush_rewrite_rules( false );
	}

	/**
	 * Get one setting.
	 */
	public function get( string $key ) {
		$settings = $this->all();

		return $settings[ $key ] ?? null;
	}

	/**
	 * Get default settings.
	 */
	public function defaults(): array {
		return array(
			'enabled'                 => true,
			'phase_routes_enabled'    => true,
			'floor_slug'              => $this->constant_or_default( 'FLOOR', 'floor' ),
			'unit_slug'               => $this->constant_or_default( 'APPARTMENT', 'apartment' ),
			'phase_query_var'         => 'phase',
			'floor_template'          => 'floor.php',
			'unit_template'           => 'appartment.php',
			'unit_phase_template'     => 'appartment_phase.php',
			'floor_component'         => 'Floor',
			'unit_component'          => 'Appartment',
			'not_found_component'     => '404',
			'flats_home_page_id'      => 0,
			'acf_cache_ttl'           => 300,
		);
	}

	/**
	 * Sanitize settings.
	 */
	private function sanitize( array $settings ): array {
		$sanitized = array();

		foreach ( array( 'enabled', 'phase_routes_enabled' ) as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$sanitized[ $key ] = ! empty( $settings[ $key ] );
			}
		}

		foreach ( array( 'floor_slug', 'unit_slug', 'phase_query_var' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_title( (string) $settings[ $key ] );
			}
		}

		foreach ( array( 'floor_template', 'unit_template', 'unit_phase_template' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$sanitized[ $key ] = $this->sanitize_template( (string) $settings[ $key ] );
			}
		}

		foreach ( array( 'floor_component', 'unit_component', 'not_found_component' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $settings[ $key ] );
			}
		}

		foreach ( array( 'flats_home_page_id', 'acf_cache_ttl' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$sanitized[ $key ] = max( 0, (int) $settings[ $key ] );
			}
		}

		if ( empty( $sanitized['floor_slug'] ?? '' ) ) {
			$sanitized['floor_slug'] = $this->defaults()['floor_slug'];
		}

		if ( empty( $sanitized['unit_slug'] ?? '' ) ) {
			$sanitized['unit_slug'] = $this->defaults()['unit_slug'];
		}

		if ( empty( $sanitized['phase_query_var'] ?? '' ) ) {
			$sanitized['phase_query_var'] = 'phase';
		}

		return $sanitized;
	}

	/**
	 * Sanitize theme template path relative to the active theme.
	 */
	private function sanitize_template( string $template ): string {
		$template = trim( str_replace( '\\', '/', $template ) );
		$template = ltrim( $template, '/' );
		$template = preg_replace( '#\.+/#', '', $template );

		return sanitize_text_field( $template ?: 'index.php' );
	}

	/**
	 * Use an existing constant when available for backwards compatibility.
	 */
	private function constant_or_default( string $constant, string $default ): string {
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return sanitize_title( (string) constant( $constant ) );
		}

		return $default;
	}
}
