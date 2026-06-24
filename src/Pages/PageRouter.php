<?php
/**
 * Frontend routes for Lomnio display pages.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageRouter {
	private PageSettings $settings;

	public function __construct( ?PageSettings $settings = null ) {
		$this->settings = $settings ?: new PageSettings();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register_routes' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'template_include', array( $this, 'template_include' ) );
	}

	/**
	 * Register floor and unit rewrite rules.
	 */
	public function register_routes(): void {
		$settings = $this->settings->all();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$floor = $settings['floor_slug'];
		$unit  = $settings['unit_slug'];
		$phase = $settings['phase_query_var'];

		if ( ! empty( $settings['phase_routes_enabled'] ) ) {
			add_rewrite_rule( $floor . '/([^/]+)/([^/]+)/?$', 'index.php?' . $floor . '=$matches[2]&' . $phase . '=$matches[1]', 'top' );
			add_rewrite_rule( $unit . '/([^/]+)/([\w\.\-]+)/?$', 'index.php?' . $unit . '=$matches[2]&' . $phase . '=$matches[1]', 'top' );
		}

		add_rewrite_rule( $floor . '/([^/]+)/?$', 'index.php?' . $floor . '=$matches[1]', 'top' );
		add_rewrite_rule( $unit . '/([\w\.\-]+)/?$', 'index.php?' . $unit . '=$matches[1]', 'top' );
	}

	/**
	 * Register route query vars.
	 */
	public function query_vars( array $query_vars ): array {
		$settings = $this->settings->all();

		foreach ( array( $settings['floor_slug'], $settings['unit_slug'], $settings['phase_query_var'] ) as $query_var ) {
			if ( '' !== $query_var && ! in_array( $query_var, $query_vars, true ) ) {
				$query_vars[] = $query_var;
			}
		}

		return $query_vars;
	}

	/**
	 * Route requests to theme templates.
	 */
	public function template_include( string $template ): string {
		$settings = $this->settings->all();

		if ( empty( $settings['enabled'] ) ) {
			return $template;
		}

		$floor_value = get_query_var( $settings['floor_slug'], null );
		$unit_value  = get_query_var( $settings['unit_slug'], null );
		$phase_value = trim( (string) get_query_var( $settings['phase_query_var'], '' ) );

		if ( null !== $floor_value && '' !== $floor_value ) {
			return $this->locate_template( $settings['floor_template'], $template, 'floor' );
		}

		if ( null !== $unit_value && '' !== $unit_value ) {
			$template_name = '' !== $phase_value ? $settings['unit_phase_template'] : $settings['unit_template'];
			return $this->locate_template( $template_name, $template, 'unit' );
		}

		return $template;
	}

	/**
	 * Find a theme template with filter support.
	 */
	private function locate_template( string $template_name, string $fallback, string $context ): string {
		$template = locate_template( array( $template_name ), false, false );

		if ( ! $template ) {
			$template = $fallback;
		}

		return (string) apply_filters( 'lomnio_api_connector_' . $context . '_template', $template, $template_name );
	}
}
