<?php
/**
 * Encrypted secret storage.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecretStorage {
	private const OPTION_API_TOKEN      = 'lomnio_api_connector_api_token';
	private const OPTION_API_TOKEN_META = 'lomnio_api_connector_api_token_meta';
	private const CIPHER                = 'aes-256-gcm';

	/**
	 * Store API token encrypted in the database.
	 *
	 * @return true|\WP_Error
	 */
	public function set_api_token( string $token ) {
		$token = $this->normalize_api_token( $token );

		if ( '' === $token ) {
			return new \WP_Error(
				'lomnio_api_connector_empty_token',
				__( 'Enter a valid API token.', 'lomnio-api-connector' )
			);
		}

		$encrypted = $this->encrypt( $token );

		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		update_option( self::OPTION_API_TOKEN, wp_json_encode( $encrypted ), false );
		update_option(
			self::OPTION_API_TOKEN_META,
			array(
				'last_four'  => strlen( $token ) >= 8 ? substr( $token, -4 ) : '',
				'updated_at' => time(),
			),
			false
		);

		return true;
	}

	/**
	 * Remove stored API token.
	 */
	public function clear_api_token(): void {
		delete_option( self::OPTION_API_TOKEN );
		delete_option( self::OPTION_API_TOKEN_META );
	}

	/**
	 * Check whether a token exists.
	 */
	public function has_api_token(): bool {
		return '' !== get_option( self::OPTION_API_TOKEN, '' );
	}

	/**
	 * Get decrypted API token.
	 *
	 * @return string|\WP_Error Empty string when the token is not configured.
	 */
	public function get_api_token() {
		$payload = get_option( self::OPTION_API_TOKEN, '' );

		if ( '' === $payload ) {
			return '';
		}

		$payload = json_decode( (string) $payload, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'lomnio_api_connector_invalid_token_payload',
				__( 'Stored API token payload is invalid.', 'lomnio-api-connector' )
			);
		}

		return $this->decrypt( $payload );
	}

	/**
	 * Get Authorization header value for API requests.
	 *
	 * @return string|\WP_Error Empty string when the token is not configured.
	 */
	public function get_authorization_header() {
		$token = $this->get_api_token();

		if ( is_wp_error( $token ) || '' === $token ) {
			return $token;
		}

		return 'Bearer ' . $token;
	}

	/**
	 * Get complete HTTP headers array for wp_remote_* requests.
	 *
	 * @return array|\WP_Error Empty array when the token is not configured.
	 */
	public function get_authorization_headers() {
		$authorization = $this->get_authorization_header();

		if ( is_wp_error( $authorization ) ) {
			return $authorization;
		}

		if ( '' === $authorization ) {
			return array();
		}

		return array(
			'Authorization' => $authorization,
		);
	}

	/**
	 * Get non-sensitive token metadata for the settings screen.
	 */
	public function get_api_token_meta(): array {
		$meta = get_option( self::OPTION_API_TOKEN_META, array() );

		if ( ! is_array( $meta ) ) {
			return array();
		}

		return $meta;
	}

	/**
	 * Normalize raw input from token-only or Authorization header formats.
	 */
	private function normalize_api_token( string $token ): string {
		$token = trim( $token );

		if ( preg_match( '/^authorization\s*:\s*bearer\s+(.+)$/i', $token, $matches ) ) {
			$token = $matches[1];
		} elseif ( preg_match( '/^bearer\s+(.+)$/i', $token, $matches ) ) {
			$token = $matches[1];
		}

		$token = preg_replace( '/[\x00-\x1F\x7F]+/', '', trim( $token ) );

		if ( ! is_string( $token ) || preg_match( '/\s/', $token ) ) {
			return '';
		}

		return $token;
	}

	/**
	 * Encrypt plaintext using the WordPress salts as key material.
	 *
	 * @return array|\WP_Error
	 */
	private function encrypt( string $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error(
				'lomnio_api_connector_missing_openssl',
				__( 'OpenSSL is required to store API tokens securely.', 'lomnio-api-connector' )
			);
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			return new \WP_Error(
				'lomnio_api_connector_random_bytes_failed',
				__( 'Could not generate encryption data.', 'lomnio-api-connector' )
			);
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext || '' === $tag ) {
			return new \WP_Error(
				'lomnio_api_connector_encrypt_failed',
				__( 'Could not encrypt the API token.', 'lomnio-api-connector' )
			);
		}

		return array(
			'version'    => 1,
			'cipher'     => self::CIPHER,
			'iv'         => base64_encode( $iv ),
			'tag'        => base64_encode( $tag ),
			'ciphertext' => base64_encode( $ciphertext ),
		);
	}

	/**
	 * Decrypt stored payload.
	 *
	 * @return string|\WP_Error
	 */
	private function decrypt( array $payload ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new \WP_Error(
				'lomnio_api_connector_missing_openssl',
				__( 'OpenSSL is required to store API tokens securely.', 'lomnio-api-connector' )
			);
		}

		if (
			empty( $payload['cipher'] ) ||
			self::CIPHER !== $payload['cipher'] ||
			empty( $payload['iv'] ) ||
			empty( $payload['tag'] ) ||
			empty( $payload['ciphertext'] )
		) {
			return new \WP_Error(
				'lomnio_api_connector_invalid_token_payload',
				__( 'Stored API token payload is invalid.', 'lomnio-api-connector' )
			);
		}

		$iv         = base64_decode( (string) $payload['iv'], true );
		$tag        = base64_decode( (string) $payload['tag'], true );
		$ciphertext = base64_decode( (string) $payload['ciphertext'], true );

		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return new \WP_Error(
				'lomnio_api_connector_invalid_token_payload',
				__( 'Stored API token payload is invalid.', 'lomnio-api-connector' )
			);
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->encryption_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			return new \WP_Error(
				'lomnio_api_connector_decrypt_failed',
				__( 'Could not decrypt the stored API token.', 'lomnio-api-connector' )
			);
		}

		return $plaintext;
	}

	/**
	 * Derive a stable 256-bit key from WordPress salts.
	 */
	private function encryption_key(): string {
		$key_material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ) . wp_salt( 'nonce' );

		return hash_hmac( 'sha256', 'lomnio-api-connector-api-token', $key_material, true );
	}
}
