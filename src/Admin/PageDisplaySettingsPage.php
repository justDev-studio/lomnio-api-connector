<?php
/**
 * Frontend display page settings.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Admin;

use LomnioApiConnector\Pages\PageSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageDisplaySettingsPage {
	private const PAGE_SLUG   = 'lomnio-api-pages';
	private const PARENT_SLUG = 'lomnio-api-endpoints';
	private const ACTION_SAVE = 'lomnio_api_connector_save_pages';

	private PageSettings $settings;

	public function __construct( ?PageSettings $settings = null ) {
		$this->settings = $settings ?: new PageSettings();
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Lomnio Pages', 'lomnio-api-connector' ),
			__( 'Pages', 'lomnio-api-connector' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'lomnio-api-connector' ) );
		}

		check_admin_referer( self::ACTION_SAVE );

		$submitted = isset( $_POST['lomnio_pages'] ) && is_array( $_POST['lomnio_pages'] )
			? wp_unslash( $_POST['lomnio_pages'] )
			: array();

		$this->settings->update( $submitted );

		wp_safe_redirect( add_query_arg( 'lomnio_pages_status', 'saved', $this->page_url() ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lomnio-api-connector' ) );
		}

		$settings = $this->settings->all();
		$status   = isset( $_GET['lomnio_pages_status'] ) ? sanitize_key( wp_unslash( $_GET['lomnio_pages_status'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Lomnio Pages', 'lomnio-api-connector' ); ?></h1>

			<?php if ( 'saved' === $status ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Page display settings saved.', 'lomnio-api-connector' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<h2><?php echo esc_html__( 'Routing', 'lomnio-api-connector' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable Lomnio pages', 'lomnio-api-connector' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="lomnio_pages[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
									<?php echo esc_html__( 'Register frontend routes and route them to theme templates.', 'lomnio-api-connector' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Phase routes', 'lomnio-api-connector' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="lomnio_pages[phase_routes_enabled]" value="1" <?php checked( ! empty( $settings['phase_routes_enabled'] ) ); ?>>
									<?php echo esc_html__( 'Enable /floor/{phase}/{floor} and /apartment/{phase}/{unit}.', 'lomnio-api-connector' ); ?>
								</label>
							</td>
						</tr>
						<?php $this->text_row( 'floor_slug', __( 'Floor slug', 'lomnio-api-connector' ), $settings['floor_slug'] ); ?>
						<?php $this->text_row( 'unit_slug', __( 'Unit slug', 'lomnio-api-connector' ), $settings['unit_slug'] ); ?>
						<?php $this->text_row( 'phase_query_var', __( 'Phase query var', 'lomnio-api-connector' ), $settings['phase_query_var'] ); ?>
					</tbody>
				</table>

				<h2><?php echo esc_html__( 'Templates and Inertia', 'lomnio-api-connector' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<?php $this->text_row( 'floor_template', __( 'Floor template', 'lomnio-api-connector' ), $settings['floor_template'] ); ?>
						<?php $this->text_row( 'unit_template', __( 'Unit template', 'lomnio-api-connector' ), $settings['unit_template'] ); ?>
						<?php $this->text_row( 'unit_phase_template', __( 'Unit phase template', 'lomnio-api-connector' ), $settings['unit_phase_template'] ); ?>
						<?php $this->text_row( 'floor_component', __( 'Floor Inertia component', 'lomnio-api-connector' ), $settings['floor_component'] ); ?>
						<?php $this->text_row( 'unit_component', __( 'Unit Inertia component', 'lomnio-api-connector' ), $settings['unit_component'] ); ?>
						<?php $this->text_row( 'not_found_component', __( '404 Inertia component', 'lomnio-api-connector' ), $settings['not_found_component'] ); ?>
					</tbody>
				</table>

				<h2><?php echo esc_html__( 'Content Settings', 'lomnio-api-connector' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<?php $this->page_row( 'flats_home_page_id', __( 'Flats home page', 'lomnio-api-connector' ), (int) $settings['flats_home_page_id'] ); ?>
						<?php $this->post_row( 'floor_settings_post_id', __( 'Floor settings post', 'lomnio-api-connector' ), (int) $settings['floor_settings_post_id'] ); ?>
						<?php $this->post_row( 'unit_settings_post_id', __( 'Unit settings post', 'lomnio-api-connector' ), (int) $settings['unit_settings_post_id'] ); ?>
						<?php $this->number_row( 'acf_cache_ttl', __( 'ACF cache TTL', 'lomnio-api-connector' ), (int) $settings['acf_cache_ttl'] ); ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Unit dark header', 'lomnio-api-connector' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="lomnio_pages[force_dark_unit_header]" value="1" <?php checked( ! empty( $settings['force_dark_unit_header'] ) ); ?>>
									<?php echo esc_html__( 'Force dark_header for unit detail pages.', 'lomnio-api-connector' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save page settings', 'lomnio-api-connector' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function text_row( string $key, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="lomnio_pages_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="lomnio_pages_<?php echo esc_attr( $key ); ?>" class="regular-text" type="text" name="lomnio_pages[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>">
			</td>
		</tr>
		<?php
	}

	private function number_row( string $key, string $label, int $value ): void {
		?>
		<tr>
			<th scope="row"><label for="lomnio_pages_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="lomnio_pages_<?php echo esc_attr( $key ); ?>" class="small-text" type="number" min="0" step="1" name="lomnio_pages[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>">
			</td>
		</tr>
		<?php
	}

	private function page_row( string $key, string $label, int $value ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'lomnio_pages[' . $key . ']',
						'selected'          => $value,
						'show_option_none'  => __( 'Select page', 'lomnio-api-connector' ),
						'option_none_value' => '0',
					)
				);
				?>
			</td>
		</tr>
		<?php
	}

	private function post_row( string $key, string $label, int $value ): void {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<tr>
			<th scope="row"><label for="lomnio_pages_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="lomnio_pages_<?php echo esc_attr( $key ); ?>" name="lomnio_pages[<?php echo esc_attr( $key ); ?>]">
					<option value="0"><?php echo esc_html__( 'Auto / legacy CPT fallback', 'lomnio-api-connector' ); ?></option>
					<?php foreach ( $posts as $post ) : ?>
						<option value="<?php echo esc_attr( (string) $post->ID ); ?>" <?php selected( $value, (int) $post->ID ); ?>>
							<?php echo esc_html( get_the_title( $post ) . ' (' . get_post_type( $post ) . ' #' . $post->ID . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	private function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	private function capability(): string {
		return (string) apply_filters( 'lomnio_api_connector_manage_capability', 'manage_options' );
	}
}
