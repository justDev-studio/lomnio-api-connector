<?php
/**
 * Page content settings post types.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageSettingsPostTypes {
	public const FLOOR_POST_TYPE = 'floor_settings';
	public const UNIT_POST_TYPE  = 'single_settings';

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'limit_single_settings_post' ), 10, 2 );
	}

	/**
	 * Register page settings post types.
	 */
	public function register(): void {
		$this->register_post_type(
			self::UNIT_POST_TYPE,
			__( 'Unit Page', 'lomnio-api-connector' ),
			__( 'Unit Page', 'lomnio-api-connector' )
		);

		$this->register_post_type(
			self::FLOOR_POST_TYPE,
			__( 'Floor Page', 'lomnio-api-connector' ),
			__( 'Floor Page', 'lomnio-api-connector' )
		);
	}

	/**
	 * Keep one settings post per language and per post type.
	 */
	public function limit_single_settings_post( array $data, array $postarr ): array {
		$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';

		if ( ! in_array( $post_type, array( self::FLOOR_POST_TYPE, self::UNIT_POST_TYPE ), true ) ) {
			return $data;
		}

		if ( 'auto-draft' === ( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$current_id = ! empty( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

		if ( $this->has_existing_post_for_language( $post_type, $current_id ) ) {
			wp_die(
				esc_html__( 'Only one Lomnio settings page can be created per language.', 'lomnio-api-connector' ),
				esc_html__( 'Lomnio settings page limit', 'lomnio-api-connector' ),
				array( 'back_link' => true )
			);
		}

		return $data;
	}

	/**
	 * Get the first settings post ID for the current language.
	 */
	public function first_post_id( string $post_type ): int {
		if ( ! post_type_exists( $post_type ) ) {
			return 0;
		}

		$current_language = $this->current_language();
		$posts            = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'suppress_filters' => false,
			)
		);

		foreach ( $posts as $post_id ) {
			if ( $this->post_language( (int) $post_id, $post_type ) === $current_language ) {
				return (int) $post_id;
			}
		}

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	private function register_post_type( string $post_type, string $name, string $singular_name ): void {
		register_post_type(
			$post_type,
			array(
				'labels'              => array(
					'name'          => $name,
					'singular_name' => $singular_name,
					'add_new_item'  => sprintf(
						/* translators: %s: post type label. */
						__( 'Add %s', 'lomnio-api-connector' ),
						$singular_name
					),
					'edit_item'     => sprintf(
						/* translators: %s: post type label. */
						__( 'Edit %s', 'lomnio-api-connector' ),
						$singular_name
					),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'lomnio-api-endpoints',
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'rewrite'             => false,
			)
		);
	}

	private function has_existing_post_for_language( string $post_type, int $current_id ): bool {
		$current_language = $this->current_language();
		$posts            = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => $current_id > 0 ? array( $current_id ) : array(),
				'suppress_filters' => false,
			)
		);

		foreach ( $posts as $post_id ) {
			if ( $this->post_language( (int) $post_id, $post_type ) === $current_language ) {
				return true;
			}
		}

		return false;
	}

	private function current_language(): string {
		$language = apply_filters( 'wpml_current_language', null );

		return is_string( $language ) && '' !== $language ? $language : '';
	}

	private function post_language( int $post_id, string $post_type ): string {
		$language = apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => $post_id,
				'element_type' => 'post_' . $post_type,
			)
		);

		return is_string( $language ) ? $language : '';
	}
}
