<?php
/**
 * Lomnio image proxy with local caching and optional transforms.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImageProxy {
	private const QUERY_FLAG = 'lomnio_image_proxy';
	private const CACHE_DIR  = 'lomnio-api-connector/images';

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * Build a public proxy URL for a remote image.
	 */
	public function url( string $source_url, array $args = array() ): string {
		$source_url = trim( $source_url );

		if ( '' === $source_url || ! $this->is_allowed_source( $source_url ) ) {
			return $source_url;
		}

		$params = $this->normalize_params( $args );
		$query  = array(
			self::QUERY_FLAG => '1',
			'src'            => $source_url,
		);

		if ( null !== $params['w'] ) {
			$query['w'] = $params['w'];
		}

		if ( null !== $params['h'] ) {
			$query['h'] = $params['h'];
		}

		if ( '' !== $params['format'] ) {
			$query['format'] = $params['format'];
		}

		if ( null !== $params['q'] ) {
			$query['q'] = $params['q'];
		}

		return (string) add_query_arg( $query, home_url( '/' ) );
	}

	/**
	 * Default floor plan transform used by Lomnio objects.
	 */
	public function default_floor_plan_url( string $source_url ): string {
		return $this->url(
			$source_url,
			array(
				'w'      => 2048,
				'format' => 'webp',
				'q'      => 82,
			)
		);
	}

	/**
	 * Serve a proxied image when the special query flag is present.
	 */
	public function maybe_render(): void {
		if ( empty( $_GET[ self::QUERY_FLAG ] ) ) {
			return;
		}

		$source_url = isset( $_GET['src'] ) ? trim( (string) wp_unslash( $_GET['src'] ) ) : '';

		if ( '' === $source_url || ! $this->is_allowed_source( $source_url ) ) {
			$this->send_error( 400, 'Invalid image source.' );
		}

		$params = $this->normalize_params( $_GET );
		$asset  = $this->resolve_asset( $source_url, $params );

		if ( is_wp_error( $asset ) ) {
			$this->send_error( 500, $asset->get_error_message() );
		}

		$this->send_file( $asset['path'], $asset['mime'] );
	}

	/**
	 * Resolve a cached asset or generate it on demand.
	 *
	 * @return array|\WP_Error
	 */
	private function resolve_asset( string $source_url, array $params ) {
		$cache_root = $this->cache_root();

		if ( is_wp_error( $cache_root ) ) {
			return $cache_root;
		}

		$hash      = hash( 'sha256', wp_json_encode( array( $source_url, $params ) ) ?: $source_url );
		$cache_dir = trailingslashit( $cache_root ) . substr( $hash, 0, 2 );
		$base_path = $cache_dir . '/' . $hash;
		$meta_path = $base_path . '.json';
		$ttl       = (int) apply_filters( 'lomnio_api_connector_image_proxy_ttl', 7 * DAY_IN_SECONDS, $source_url, $params );

		$cached = $this->read_meta( $meta_path );

		if ( null !== $cached && file_exists( $cached['path'] ) && ( time() - filemtime( $cached['path'] ) ) < max( 0, $ttl ) ) {
			return $cached;
		}

		$generated = $this->generate_asset( $source_url, $params, $cache_dir, $base_path, $meta_path );

		if ( is_wp_error( $generated ) ) {
			if ( null !== $cached && file_exists( $cached['path'] ) ) {
				return $cached;
			}

			return $generated;
		}

		return $generated;
	}

	/**
	 * Download, transform, and cache the image.
	 *
	 * @return array|\WP_Error
	 */
	private function generate_asset( string $source_url, array $params, string $cache_dir, string $base_path, string $meta_path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! wp_mkdir_p( $cache_dir ) ) {
			return new \WP_Error( 'lomnio_image_proxy_cache_dir', 'Could not create the image cache directory.' );
		}

		$tmp_file = $this->download_source( $source_url );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$source_mime = (string) wp_get_image_mime( $tmp_file );
		$target      = $this->target_definition( $params['format'], $source_mime );

		if ( null === $target ) {
			@unlink( $tmp_file );
			return new \WP_Error( 'lomnio_image_proxy_format', 'Unsupported source image format.' );
		}

		$target_path = $base_path . '.' . $target['ext'];
		$result = $this->transform_file( $tmp_file, $target_path, $source_mime, $target['mime'], $params );

		if ( is_wp_error( $result ) ) {
			$fallback = $this->definition_from_mime( $source_mime );

			if ( null === $fallback ) {
				@unlink( $tmp_file );
				return $result;
			}

			$target_path = $base_path . '.' . $fallback['ext'];
			$copied      = $this->copy_original( $tmp_file, $target_path );

			if ( is_wp_error( $copied ) ) {
				@unlink( $tmp_file );
				return $result;
			}

			$target['mime'] = $fallback['mime'];
		}

		@unlink( $tmp_file );

		$this->cleanup_variants( $base_path, $target_path );

		$payload = array(
			'path' => $target_path,
			'mime' => $target['mime'],
		);

		file_put_contents( $meta_path, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) ?: '' );

		return $payload;
	}

	/**
	 * Download the remote source image to a temporary file.
	 *
	 * @return string|\WP_Error
	 */
	private function download_source( string $source_url ) {
		$tmp_file = wp_tempnam( basename( wp_parse_url( $source_url, PHP_URL_PATH ) ?: 'lomnio-image' ) );

		if ( ! is_string( $tmp_file ) || '' === $tmp_file ) {
			return new \WP_Error( 'lomnio_image_proxy_temp_file', 'Could not allocate a temporary file for the image proxy.' );
		}

		$response = wp_safe_remote_get(
			$source_url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'stream'      => true,
				'filename'    => $tmp_file,
				'user-agent'  => 'Lomnio API Connector/' . ( defined( 'LOMNIO_API_CONNECTOR_VERSION' ) ? LOMNIO_API_CONNECTOR_VERSION : 'dev' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_file );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			@unlink( $tmp_file );
			return new \WP_Error( 'lomnio_image_proxy_http', sprintf( 'Remote image request failed with HTTP %d.', $status_code ) );
		}

		return $tmp_file;
	}

	/**
	 * Transform the image or copy it when transforms are not possible.
	 *
	 * @return true|\WP_Error
	 */
	private function transform_file( string $tmp_file, string $target_path, string $source_mime, string $target_mime, array $params ) {
		$requires_resize  = null !== $params['w'] || null !== $params['h'];
		$requires_reencode = $source_mime !== $target_mime;
		$editor           = wp_get_image_editor( $tmp_file );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		if ( $requires_resize ) {
			$resize = $editor->resize( $params['w'], $params['h'], false );

			if ( is_wp_error( $resize ) ) {
				return $resize;
			}
		}

		if ( null !== $params['q'] && method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( $params['q'] );
		}

		if ( ! $requires_resize && ! $requires_reencode ) {
			return $this->copy_original( $tmp_file, $target_path );
		}

		$saved = $editor->save( $target_path, $target_mime );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return true;
	}

	/**
	 * Copy a file into the cache path.
	 *
	 * @return true|\WP_Error
	 */
	private function copy_original( string $from, string $to ) {
		if ( ! @copy( $from, $to ) ) {
			return new \WP_Error( 'lomnio_image_proxy_copy_failed', 'Could not cache the source image.' );
		}

		return true;
	}

	/**
	 * Serve a local file with cache headers.
	 */
	private function send_file( string $path, string $mime ): void {
		if ( ! file_exists( $path ) ) {
			$this->send_error( 404, 'Image cache file not found.' );
		}

		$modified_time = filemtime( $path ) ?: time();
		$max_age       = 7 * DAY_IN_SECONDS;

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Cache-Control: public, max-age=' . $max_age );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_time ) . ' GMT' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $path );
		exit;
	}

	/**
	 * Send a plain-text error response.
	 */
	private function send_error( int $status_code, string $message ): void {
		status_header( $status_code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $message;
		exit;
	}

	/**
	 * Normalize incoming transform parameters.
	 */
	private function normalize_params( array $args ): array {
		$width   = isset( $args['w'] ) ? absint( $args['w'] ) : 0;
		$height  = isset( $args['h'] ) ? absint( $args['h'] ) : 0;
		$quality = isset( $args['q'] ) ? absint( $args['q'] ) : 82;
		$format  = isset( $args['format'] ) ? sanitize_key( (string) $args['format'] ) : '';

		return array(
			'w'      => $width > 0 ? min( $width, 4096 ) : null,
			'h'      => $height > 0 ? min( $height, 4096 ) : null,
			'q'      => $quality > 0 ? min( max( $quality, 30 ), 95 ) : null,
			'format' => in_array( $format, array( 'webp', 'jpg', 'jpeg', 'png', 'original' ), true ) ? $format : '',
		);
	}

	/**
	 * Get the final output definition for a requested format.
	 */
	private function target_definition( string $requested_format, string $source_mime ): ?array {
		if ( 'webp' === $requested_format && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return array(
				'mime' => 'image/webp',
				'ext'  => 'webp',
			);
		}

		if ( 'png' === $requested_format ) {
			return array(
				'mime' => 'image/png',
				'ext'  => 'png',
			);
		}

		if ( in_array( $requested_format, array( 'jpg', 'jpeg' ), true ) ) {
			return array(
				'mime' => 'image/jpeg',
				'ext'  => 'jpg',
			);
		}

		return $this->definition_from_mime( $source_mime );
	}

	/**
	 * Map MIME types to file extensions.
	 */
	private function definition_from_mime( string $mime ): ?array {
		$map = array(
			'image/jpeg'    => array( 'mime' => 'image/jpeg', 'ext' => 'jpg' ),
			'image/png'     => array( 'mime' => 'image/png', 'ext' => 'png' ),
			'image/webp'    => array( 'mime' => 'image/webp', 'ext' => 'webp' ),
			'image/svg+xml' => array( 'mime' => 'image/svg+xml', 'ext' => 'svg' ),
			'image/gif'     => array( 'mime' => 'image/gif', 'ext' => 'gif' ),
		);

		return $map[ $mime ] ?? null;
	}

	/**
	 * Read cache metadata.
	 */
	private function read_meta( string $meta_path ): ?array {
		if ( ! file_exists( $meta_path ) ) {
			return null;
		}

		$payload = json_decode( (string) file_get_contents( $meta_path ), true );

		if ( ! is_array( $payload ) || empty( $payload['path'] ) || empty( $payload['mime'] ) ) {
			return null;
		}

		return array(
			'path' => (string) $payload['path'],
			'mime' => (string) $payload['mime'],
		);
	}

	/**
	 * Remove outdated extension variants for the same hash.
	 */
	private function cleanup_variants( string $base_path, string $keep_path ): void {
		foreach ( glob( $base_path . '.*' ) ?: array() as $candidate ) {
			if ( $candidate === $keep_path || '.json' === substr( $candidate, -5 ) ) {
				continue;
			}

			@unlink( $candidate );
		}
	}

	/**
	 * Get or create the image cache root.
	 *
	 * @return string|\WP_Error
	 */
	private function cache_root() {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) || ! is_dir( $uploads['basedir'] ) ) {
			return new \WP_Error( 'lomnio_image_proxy_upload_dir', 'The WordPress uploads directory is not available.' );
		}

		$root = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR;

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new \WP_Error( 'lomnio_image_proxy_upload_dir', 'Could not create the Lomnio image cache directory.' );
		}

		return $root;
	}

	/**
	 * Restrict image proxying to approved remote hosts.
	 */
	private function is_allowed_source( string $source_url ): bool {
		if ( ! wp_http_validate_url( $source_url ) ) {
			return false;
		}

		$scheme = (string) wp_parse_url( $source_url, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}

		$allowed_hosts = (array) apply_filters(
			'lomnio_api_connector_image_proxy_allowed_hosts',
			array(
				'app.lomnio.com',
			)
		);

		$allowed_hosts = array_map( 'strtolower', array_filter( $allowed_hosts, 'is_string' ) );

		return in_array( $host, $allowed_hosts, true );
	}
}
