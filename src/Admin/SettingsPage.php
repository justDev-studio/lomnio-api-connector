<?php
/**
 * Hidden admin settings page.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Admin;

use LomnioApiConnector\Security\SecretStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {
	private const PAGE_SLUG    = 'lomnio-api-connector';
	private const PARENT_SLUG  = 'options-general.php';
	private const ACTION_SAVE  = 'lomnio_api_connector_save';
	private const ACTION_CLEAR = 'lomnio_api_connector_clear';
	private const ACTION_SAVE_WEBHOOK_SECRET  = 'lomnio_api_connector_save_webhook_secret';
	private const ACTION_CLEAR_WEBHOOK_SECRET = 'lomnio_api_connector_clear_webhook_secret';
	private const ACTION_SAVE_TRACKING_TOKEN  = 'lomnio_api_connector_save_tracking_token';
	private const ACTION_CLEAR_TRACKING_TOKEN = 'lomnio_api_connector_clear_tracking_token';

	/**
	 * Encrypted secret storage.
	 *
	 * @var SecretStorage
	 */
	private SecretStorage $secret_storage;

	public function __construct( SecretStorage $secret_storage ) {
		$this->secret_storage = $secret_storage;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_head', array( $this, 'hide_menu_item' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR, array( $this, 'handle_clear' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_WEBHOOK_SECRET, array( $this, 'handle_save_webhook_secret' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_WEBHOOK_SECRET, array( $this, 'handle_clear_webhook_secret' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_TRACKING_TOKEN, array( $this, 'handle_save_tracking_token' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_TRACKING_TOKEN, array( $this, 'handle_clear_tracking_token' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LOMNIO_API_CONNECTOR_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register a hidden admin page.
	 */
	public function register_page(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Lomnio API Connector', 'lomnio-api-connector' ),
			__( 'Lomnio API Connector', 'lomnio-api-connector' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Hide the settings page from the Settings menu after WP access checks are complete.
	 */
	public function hide_menu_item(): void {
		remove_submenu_page( self::PARENT_SLUG, self::PAGE_SLUG );
	}

	/**
	 * Add a settings link to the plugin row for admins only.
	 */
	public function plugin_action_links( array $links ): array {
		if ( ! current_user_can( $this->capability() ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->page_url() ),
			esc_html__( 'Settings', 'lomnio-api-connector' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Save encrypted API token.
	 */
	public function handle_save(): void {
		$this->authorize_request( self::ACTION_SAVE );

		$token  = isset( $_POST['lomnio_api_token'] ) ? (string) wp_unslash( $_POST['lomnio_api_token'] ) : '';
		$result = $this->secret_storage->set_api_token( $token );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_status( 'error', $result->get_error_code() );
		}

		do_action( 'lomnio_api_connector_api_token_saved' );

		$this->redirect_with_status( 'saved' );
	}

	/**
	 * Remove encrypted API token.
	 */
	public function handle_clear(): void {
		$this->authorize_request( self::ACTION_CLEAR );
		$this->secret_storage->clear_api_token();
		$this->redirect_with_status( 'cleared' );
	}

	public function handle_save_webhook_secret(): void {
		$this->authorize_request( self::ACTION_SAVE_WEBHOOK_SECRET );

		$secret = isset( $_POST['lomnio_webhook_secret'] ) ? (string) wp_unslash( $_POST['lomnio_webhook_secret'] ) : '';
		$result = $this->secret_storage->set_webhook_secret( $secret );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_status( 'error', $result->get_error_code() );
		}

		$this->redirect_with_status( 'webhook_secret_saved' );
	}

	public function handle_clear_webhook_secret(): void {
		$this->authorize_request( self::ACTION_CLEAR_WEBHOOK_SECRET );
		$this->secret_storage->clear_webhook_secret();
		$this->redirect_with_status( 'webhook_secret_cleared' );
	}

	public function handle_save_tracking_token(): void {
		$this->authorize_request( self::ACTION_SAVE_TRACKING_TOKEN );

		$token  = isset( $_POST['lomnio_tracking_token'] ) ? (string) wp_unslash( $_POST['lomnio_tracking_token'] ) : '';
		$result = $this->secret_storage->set_tracking_token( $token );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_status( 'error', $result->get_error_code() );
		}

		$this->redirect_with_status( 'tracking_token_saved' );
	}

	public function handle_clear_tracking_token(): void {
		$this->authorize_request( self::ACTION_CLEAR_TRACKING_TOKEN );
		$this->secret_storage->clear_tracking_token();
		$this->redirect_with_status( 'tracking_token_cleared' );
	}

	/**
	 * Render settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lomnio-api-connector' ) );
		}

		$meta            = $this->secret_storage->get_api_token_meta();
		$has_token       = $this->secret_storage->has_api_token();
		$last_four       = isset( $meta['last_four'] ) ? (string) $meta['last_four'] : '';
		$updated_at      = isset( $meta['updated_at'] ) ? (int) $meta['updated_at'] : 0;
		$updated_at_text = $updated_at > 0 ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated_at ) : '';
		$webhook_meta       = $this->secret_storage->get_webhook_secret_meta();
		$has_webhook_secret = $this->secret_storage->has_webhook_secret();
		$webhook_last_four  = isset( $webhook_meta['last_four'] ) ? (string) $webhook_meta['last_four'] : '';
		$tracking_meta      = $this->secret_storage->get_tracking_token_meta();
		$has_tracking_token = $this->secret_storage->has_tracking_token();
		$tracking_last_four = isset( $tracking_meta['last_four'] ) ? (string) $tracking_meta['last_four'] : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Lomnio API Connector', 'lomnio-api-connector' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2><?php echo esc_html__( 'API Authorization', 'lomnio-api-connector' ); ?></h2>
			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Status', 'lomnio-api-connector' ); ?></th>
						<td>
							<?php if ( $has_token ) : ?>
								<?php echo esc_html__( 'Configured', 'lomnio-api-connector' ); ?>
								<?php if ( '' !== $last_four ) : ?>
									<?php
									printf(
										esc_html__( '(ending in %s)', 'lomnio-api-connector' ),
										'<code>' . esc_html( $last_four ) . '</code>'
									);
									?>
								<?php endif; ?>
							<?php else : ?>
								<?php echo esc_html__( 'Not configured', 'lomnio-api-connector' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $updated_at_text ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Last updated', 'lomnio-api-connector' ); ?></th>
							<td><?php echo esc_html( $updated_at_text ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 720px; margin-top: 24px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="lomnio_api_token"><?php echo esc_html__( 'API token', 'lomnio-api-connector' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="lomnio_api_token"
									name="lomnio_api_token"
									class="regular-text"
									autocomplete="off"
									placeholder="<?php echo esc_attr__( 'Authorization: Bearer YOUR_API_TOKEN', 'lomnio-api-connector' ); ?>"
								>
								<p class="description">
									<?php echo esc_html__( 'Paste the token or the full Authorization header. The token is encrypted before it is stored and is never displayed back.', 'lomnio-api-connector' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save API token', 'lomnio-api-connector' ) ); ?>
			</form>

			<?php if ( $has_token ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 720px; margin-top: 16px;">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR ); ?>">
					<?php wp_nonce_field( self::ACTION_CLEAR ); ?>
					<?php submit_button( __( 'Clear API token', 'lomnio-api-connector' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<hr style="margin: 32px 0; max-width: 720px;">
			<h2><?php echo esc_html__( 'Website Tracking token', 'lomnio-api-connector' ); ?></h2>
			<p>
				<?php echo esc_html( $has_tracking_token ? __( 'Configured', 'lomnio-api-connector' ) : __( 'Not configured', 'lomnio-api-connector' ) ); ?>
				<?php if ( '' !== $tracking_last_four ) : ?>
					<code>…<?php echo esc_html( $tracking_last_four ); ?></code>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 720px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_TRACKING_TOKEN ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_TRACKING_TOKEN ); ?>
				<label for="lomnio_tracking_token"><strong><?php echo esc_html__( 'Tracking token', 'lomnio-api-connector' ); ?></strong></label><br>
				<input
					type="password"
					id="lomnio_tracking_token"
					name="lomnio_tracking_token"
					class="regular-text"
					autocomplete="off"
					placeholder="<?php echo esc_attr__( 'Website Tracking token', 'lomnio-api-connector' ); ?>"
				>
				<p class="description">
					<?php echo esc_html__( 'Create a project token of type Website Tracking in Lomnio and paste it here. Inventory and Leads API tokens must not be used for tracking.', 'lomnio-api-connector' ); ?>
				</p>
				<?php submit_button( __( 'Save tracking token', 'lomnio-api-connector' ) ); ?>
			</form>
			<?php if ( $has_tracking_token ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR_TRACKING_TOKEN ); ?>">
					<?php wp_nonce_field( self::ACTION_CLEAR_TRACKING_TOKEN ); ?>
					<?php submit_button( __( 'Clear tracking token', 'lomnio-api-connector' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<hr style="margin: 32px 0; max-width: 720px;">
			<h2><?php echo esc_html__( 'Webhook signing secret', 'lomnio-api-connector' ); ?></h2>
			<p>
				<?php echo esc_html( $has_webhook_secret ? __( 'Configured', 'lomnio-api-connector' ) : __( 'Not configured', 'lomnio-api-connector' ) ); ?>
				<?php if ( '' !== $webhook_last_four ) : ?>
					<code>…<?php echo esc_html( $webhook_last_four ); ?></code>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 720px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_WEBHOOK_SECRET ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_WEBHOOK_SECRET ); ?>
				<label for="lomnio_webhook_secret"><strong><?php echo esc_html__( 'Project signing secret', 'lomnio-api-connector' ); ?></strong></label><br>
				<input type="password" id="lomnio_webhook_secret" name="lomnio_webhook_secret" class="regular-text" autocomplete="off">
				<p class="description"><?php echo esc_html__( 'Enter the signing secret configured for this project in Lomnio. It is encrypted and never displayed back.', 'lomnio-api-connector' ); ?></p>
				<?php submit_button( __( 'Save webhook secret', 'lomnio-api-connector' ) ); ?>
			</form>
			<?php if ( $has_webhook_secret ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR_WEBHOOK_SECRET ); ?>">
					<?php wp_nonce_field( self::ACTION_CLEAR_WEBHOOK_SECRET ); ?>
					<?php submit_button( __( 'Clear webhook secret', 'lomnio-api-connector' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Check capability and nonce for write requests.
	 */
	private function authorize_request( string $action ): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'lomnio-api-connector' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Render status notices.
	 */
	private function render_notice(): void {
		$status = isset( $_GET['lomnio_status'] ) ? sanitize_key( wp_unslash( $_GET['lomnio_status'] ) ) : '';
		$code   = isset( $_GET['lomnio_code'] ) ? sanitize_key( wp_unslash( $_GET['lomnio_code'] ) ) : '';

		if ( 'saved' === $status ) {
			$this->notice( __( 'API token saved securely.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'cleared' === $status ) {
			$this->notice( __( 'API token cleared.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'webhook_secret_saved' === $status ) {
			$this->notice( __( 'Webhook signing secret saved securely.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'webhook_secret_cleared' === $status ) {
			$this->notice( __( 'Webhook signing secret cleared.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'tracking_token_saved' === $status ) {
			$this->notice( __( 'Website Tracking token saved securely.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'tracking_token_cleared' === $status ) {
			$this->notice( __( 'Website Tracking token cleared.', 'lomnio-api-connector' ), 'success' );
			return;
		}

		if ( 'error' === $status ) {
			$message = $this->error_message( $code );
			$this->notice( $message, 'error' );
		}
	}

	/**
	 * Print admin notice.
	 */
	private function notice( string $message, string $type ): void {
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Get safe error message by code.
	 */
	private function error_message( string $code ): string {
		$messages = array(
			'lomnio_api_connector_empty_token'          => __( 'Enter a valid API token.', 'lomnio-api-connector' ),
			'lomnio_api_connector_missing_openssl'      => __( 'OpenSSL is required to store API tokens securely.', 'lomnio-api-connector' ),
			'lomnio_api_connector_random_bytes_failed'  => __( 'Could not generate encryption data.', 'lomnio-api-connector' ),
			'lomnio_api_connector_encrypt_failed'       => __( 'Could not encrypt the API token.', 'lomnio-api-connector' ),
			'lomnio_api_connector_invalid_token_payload' => __( 'Stored API token payload is invalid.', 'lomnio-api-connector' ),
			'lomnio_api_connector_decrypt_failed'       => __( 'Could not decrypt the stored API token.', 'lomnio-api-connector' ),
			'lomnio_webhook_empty_secret'               => __( 'Enter a webhook signing secret.', 'lomnio-api-connector' ),
			'lomnio_tracking_empty_token'               => __( 'Enter a valid Website Tracking token.', 'lomnio-api-connector' ),
			'lomnio_tracking_invalid_token_payload'     => __( 'Stored Website Tracking token payload is invalid.', 'lomnio-api-connector' ),
		);

		return $messages[ $code ] ?? __( 'Could not save the API token.', 'lomnio-api-connector' );
	}

	/**
	 * Redirect back to the hidden settings page.
	 */
	private function redirect_with_status( string $status, string $code = '' ): void {
		$url = add_query_arg(
			array_filter(
				array(
					'lomnio_status' => $status,
					'lomnio_code'   => $code,
				)
			),
			$this->page_url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Hidden settings page URL.
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
