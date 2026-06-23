<?php
/**
 * Endpoint sync settings page.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Admin;

use LomnioApiConnector\Sync\ProjectSync;
use LomnioApiConnector\Sync\UnitsSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EndpointsPage {
	private const PAGE_SLUG   = 'lomnio-api-endpoints';
	private const ACTION_SAVE = 'lomnio_api_connector_save_endpoints';
	private const ACTION_SYNC = 'lomnio_api_connector_sync_endpoint';
	private const OPTION_NAME = 'lomnio_api_connector_endpoint_settings';
	private const OPTION_META = 'lomnio_api_connector_endpoint_meta';

	/**
	 * Project sync runner.
	 *
	 * @var ProjectSync|null
	 */
	private ?ProjectSync $project_sync;

	/**
	 * Units sync runner.
	 *
	 * @var UnitsSync|null
	 */
	private ?UnitsSync $units_sync;

	public function __construct( ?ProjectSync $project_sync = null, ?UnitsSync $units_sync = null ) {
		$this->project_sync = $project_sync;
		$this->units_sync   = $units_sync;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC, array( $this, 'handle_sync' ) );
	}

	/**
	 * Register admin page.
	 */
	public function register_page(): void {
		add_menu_page(
			__( 'Lomnio API', 'lomnio-api-connector' ),
			__( 'Lomnio API', 'lomnio-api-connector' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-rest-api',
			58
		);
	}

	/**
	 * Save endpoint settings.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'lomnio-api-connector' ) );
		}

		check_admin_referer( self::ACTION_SAVE );

		$submitted = isset( $_POST['lomnio_endpoints'] ) && is_array( $_POST['lomnio_endpoints'] )
			? wp_unslash( $_POST['lomnio_endpoints'] )
			: array();

		$settings  = array();
		$schedules = $this->schedules();

		foreach ( $this->endpoints() as $key => $endpoint ) {
			$endpoint_settings = isset( $submitted[ $key ] ) && is_array( $submitted[ $key ] ) ? $submitted[ $key ] : array();
			$schedule          = isset( $endpoint_settings['schedule'] ) ? sanitize_key( (string) $endpoint_settings['schedule'] ) : $endpoint['default_schedule'];

			if ( ! isset( $schedules[ $schedule ] ) ) {
				$schedule = $endpoint['default_schedule'];
			}

			$settings[ $key ] = array(
				'active'   => ! empty( $endpoint_settings['active'] ),
				'schedule' => $schedule,
			);
		}

		update_option( self::OPTION_NAME, $settings, false );
		do_action( 'lomnio_api_connector_endpoint_settings_saved' );

		wp_safe_redirect( add_query_arg( 'lomnio_endpoints_status', 'saved', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle manual sync requests.
	 */
	public function handle_sync(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'lomnio-api-connector' ) );
		}

		check_admin_referer( self::ACTION_SYNC );

		$target    = isset( $_POST['lomnio_sync_target'] ) ? sanitize_key( (string) wp_unslash( $_POST['lomnio_sync_target'] ) ) : '';
		$endpoints = $this->endpoints();

		if ( 'all' === $target ) {
			$targets = array_keys( $endpoints );
		} elseif ( isset( $endpoints[ $target ] ) ) {
			$targets = array( $target );
		} else {
			wp_safe_redirect( add_query_arg( 'lomnio_endpoints_status', 'invalid_sync', $this->page_url() ) );
			exit;
		}

		$has_error = false;

		foreach ( $targets as $endpoint_key ) {
			if ( 'project' === $endpoint_key && null !== $this->project_sync ) {
				$result = $this->project_sync->run();

				if ( is_wp_error( $result ) ) {
					$has_error = true;
				}

				continue;
			}

			if ( 'units' === $endpoint_key && null !== $this->units_sync ) {
				$result = $this->units_sync->run();

				if ( is_wp_error( $result ) ) {
					$has_error = true;
				}

				continue;
			}

			$this->store_placeholder_sync_meta( array( $endpoint_key ) );
		}

		wp_safe_redirect( add_query_arg( 'lomnio_endpoints_status', $has_error ? 'sync_failed' : 'sync_completed', $this->page_url() ) );
		exit;
	}

	/**
	 * Render admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$settings = $this->settings();
		$meta     = $this->meta();
		$status   = isset( $_GET['lomnio_endpoints_status'] ) ? sanitize_key( wp_unslash( $_GET['lomnio_endpoints_status'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Lomnio API', 'lomnio-api-connector' ); ?></h1>

			<?php $this->render_notice( $status ); ?>

			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=lomnio-api-connector' ) ); ?>">
					<?php echo esc_html__( 'API token settings', 'lomnio-api-connector' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SYNC ); ?>">
				<?php wp_nonce_field( self::ACTION_SYNC ); ?>

				<p>
					<button class="button button-primary" name="lomnio_sync_target" value="all">
						<?php echo esc_html__( 'Sync All', 'lomnio-api-connector' ); ?>
					</button>
					<?php foreach ( $this->endpoints() as $key => $endpoint ) : ?>
						<button class="button" name="lomnio_sync_target" value="<?php echo esc_attr( $key ); ?>">
							<?php
							printf(
								esc_html__( 'Sync %s', 'lomnio-api-connector' ),
								esc_html( $endpoint['label'] )
							);
							?>
						</button>
					<?php endforeach; ?>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Endpoint', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Method', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Path', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Schedule', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Active', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Status', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Message', 'lomnio-api-connector' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Fetched At', 'lomnio-api-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->endpoints() as $key => $endpoint ) : ?>
							<?php
							$endpoint_settings = $settings[ $key ];
							$endpoint_meta     = $meta[ $key ];
							$field_name        = 'lomnio_endpoints[' . esc_attr( $key ) . ']';
							?>
							<tr>
								<td>
									<label>
										<input
											type="checkbox"
											name="<?php echo esc_attr( $field_name ); ?>[active]"
											value="1"
											<?php checked( ! empty( $endpoint_settings['active'] ) ); ?>
										>
										<?php echo esc_html__( 'Active', 'lomnio-api-connector' ); ?>
									</label>
								</td>
								<td>
									<strong><?php echo esc_html( $endpoint['label'] ); ?></strong>
									<p class="description"><?php echo esc_html( $endpoint['description'] ); ?></p>
								</td>
								<td><code><?php echo esc_html( $endpoint['method'] ); ?></code></td>
								<td><code><?php echo esc_html( $endpoint['path'] ); ?></code></td>
								<td>
									<select name="<?php echo esc_attr( $field_name ); ?>[schedule]">
										<?php foreach ( $this->schedules() as $schedule_key => $schedule ) : ?>
											<option value="<?php echo esc_attr( $schedule_key ); ?>" <?php selected( $endpoint_settings['schedule'], $schedule_key ); ?>>
												<?php echo esc_html( $schedule['label'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								
								<td><?php echo esc_html( $this->status_label( $endpoint_meta ) ); ?></td>
								<td><?php echo esc_html( $endpoint_meta['message'] ); ?></td>
								<td><?php echo esc_html( $endpoint_meta['fetched_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save endpoint settings', 'lomnio-api-connector' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render status notices.
	 */
	private function render_notice( string $status ): void {
		if ( 'saved' === $status ) {
			$this->notice( __( 'Endpoint settings saved.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'sync_completed' === $status ) {
			$this->notice( __( 'Manual sync completed.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'sync_failed' === $status ) {
			$this->notice( __( 'Manual sync finished with errors. See endpoint status below.', 'lomnio-api-connector' ), 'error' );
			return;
		}

		if ( 'invalid_sync' === $status ) {
			$this->notice( __( 'Invalid sync target.', 'lomnio-api-connector' ), 'error' );
		}
	}

	/**
	 * Print admin notice.
	 */
	private function notice( string $message, string $type ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Get endpoint settings with defaults.
	 */
	public function settings(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array();

		foreach ( $this->endpoints() as $key => $endpoint ) {
			$item = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();

			$settings[ $key ] = array(
				'active'   => array_key_exists( 'active', $item ) ? (bool) $item['active'] : true,
				'schedule' => ! empty( $item['schedule'] ) ? sanitize_key( (string) $item['schedule'] ) : $endpoint['default_schedule'],
			);

			if ( ! isset( $this->schedules()[ $settings[ $key ]['schedule'] ] ) ) {
				$settings[ $key ]['schedule'] = $endpoint['default_schedule'];
			}
		}

		return $settings;
	}

	/**
	 * Get endpoint sync metadata with defaults.
	 */
	public function meta(): array {
		$stored = get_option( self::OPTION_META, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$meta = array();

		foreach ( $this->endpoints() as $key => $endpoint ) {
			$item = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();

			$meta[ $key ] = array(
				'status'     => ! empty( $item['status'] ) ? (string) $item['status'] : '',
				'success'    => array_key_exists( 'success', $item ) && null !== $item['success'] ? (bool) $item['success'] : null,
				'message'    => ! empty( $item['message'] ) ? (string) $item['message'] : '—',
				'fetched_at' => ! empty( $item['fetched_at'] ) ? (string) $item['fetched_at'] : '—',
			);
		}

		return $meta;
	}

	/**
	 * Store placeholder metadata until real endpoint runners are implemented.
	 */
	private function store_placeholder_sync_meta( array $targets ): void {
		$meta = get_option( self::OPTION_META, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$fetched_at = current_time( 'mysql' );

		foreach ( $targets as $target ) {
			$meta[ $target ] = array(
				'status'     => 'Requested',
				'success'    => null,
				'message'    => __( 'Manual run requested. Sync runner is not implemented yet.', 'lomnio-api-connector' ),
				'fetched_at' => $fetched_at,
			);
		}

		update_option( self::OPTION_META, $meta, false );
	}

	/**
	 * Get readable sync status label.
	 */
	private function status_label( array $meta ): string {
		if ( ! empty( $meta['status'] ) ) {
			return (string) $meta['status'];
		}

		if ( null === $meta['success'] ) {
			return '—';
		}

		return $meta['success'] ? 'OK' : 'Fail';
	}

	/**
	 * Endpoint definitions selected from .codex/api-1.json.
	 */
	private function endpoints(): array {
		return array(
			'project' => array(
				'label'            => 'Project',
				'description'      => __( 'Get project details.', 'lomnio-api-connector' ),
				'method'           => 'GET',
				'path'             => '/v1/project',
				'default_schedule' => 'daily',
			),
			'units'   => array(
				'label'            => 'Units',
				'description'      => __( 'List units.', 'lomnio-api-connector' ),
				'method'           => 'GET',
				'path'             => '/v1/units',
				'default_schedule' => 'every_ten_minutes',
			),
		);
	}

	/**
	 * Logical schedule choices for future cron implementation.
	 */
	private function schedules(): array {
		return array(
			'every_five_minutes'   => array(
				'label'    => __( 'Every 5 minutes', 'lomnio-api-connector' ),
				'interval' => 5 * MINUTE_IN_SECONDS,
			),
			'every_ten_minutes'    => array(
				'label'    => __( 'Every 10 minutes', 'lomnio-api-connector' ),
				'interval' => 10 * MINUTE_IN_SECONDS,
			),
			'every_thirty_minutes' => array(
				'label'    => __( 'Every 30 minutes', 'lomnio-api-connector' ),
				'interval' => 30 * MINUTE_IN_SECONDS,
			),
			'hourly'               => array(
				'label'    => __( 'Hourly', 'lomnio-api-connector' ),
				'interval' => HOUR_IN_SECONDS,
			),
			'twicedaily'           => array(
				'label'    => __( 'Twice daily', 'lomnio-api-connector' ),
				'interval' => 12 * HOUR_IN_SECONDS,
			),
			'daily'                => array(
				'label'    => __( 'Daily', 'lomnio-api-connector' ),
				'interval' => DAY_IN_SECONDS,
			),
		);
	}

	/**
	 * Admin page URL.
	 */
	private function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Required capability.
	 */
	private function capability(): string {
		return (string) apply_filters( 'lomnio_api_connector_manage_capability', 'manage_options' );
	}
}
