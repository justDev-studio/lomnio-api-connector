<?php
/**
 * Verify the tracking proxy filters bot artefacts and forwards browser identity.
 *
 * @package LomnioApiConnector
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WP_ENV', 'production' );
}

namespace LomnioApiConnector\Security {
	final class SecretStorage {
		public function get_authorization_headers(): array {
			return array( 'Authorization' => 'Bearer test-token' );
		}
	}
}

namespace {
	final class WP_Error {
		private string $code;
		private string $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}

	final class WP_REST_Request {
		private array $payload;
		private array $headers;

		public function __construct( array $payload, array $headers = array() ) {
			$this->payload = $payload;
			$this->headers = array();

			foreach ( $headers as $name => $value ) {
				$this->headers[ strtolower( str_replace( '-', '_', (string) $name ) ) ] = (string) $value;
			}
		}

		public function get_json_params(): array {
			return $this->payload;
		}

		public function get_header( string $name ): string {
			$key = strtolower( str_replace( '-', '_', $name ) );

			return $this->headers[ $key ] ?? '';
		}
	}

	final class WP_REST_Response {
		private $data;
		private int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}

	$GLOBALS['tracking_remote_requests'] = array();
	$GLOBALS['tracking_transients']      = array();
	$GLOBALS['tracking_meta']            = array();

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
	}

	function sanitize_text_field( string $value ): string {
		$value = strip_tags( $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value ) ?? '';

		return trim( $value );
	}

	function get_transient( string $key ) {
		return $GLOBALS['tracking_transients'][ $key ] ?? false;
	}

	function set_transient( string $key, $value, int $expiration ): bool {
		$GLOBALS['tracking_transients'][ $key ] = $value;

		return true;
	}

	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}

	function wp_unslash( string $value ): string {
		return stripslashes( $value );
	}

	function get_option( string $name, $default = false ) {
		if ( 'lomnio_api_connector_endpoint_settings' === $name ) {
			return array(
				'tracking' => array(
					'active'       => true,
					'allowed_envs' => array( 'production' ),
				),
			);
		}

		if ( 'lomnio_api_connector_endpoint_meta' === $name ) {
			return $GLOBALS['tracking_meta'];
		}

		return $default;
	}

	function update_option( string $name, $value, $autoload = null ): bool {
		if ( 'lomnio_api_connector_endpoint_meta' === $name ) {
			$GLOBALS['tracking_meta'] = $value;
		}

		return true;
	}

	function current_time( string $type ): string {
		return '2026-08-31 12:00:00';
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function wp_safe_remote_post( string $url, array $arguments ): array {
		$GLOBALS['tracking_remote_requests'][] = array(
			'url'       => $url,
			'arguments' => $arguments,
		);

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"created":1,"duplicates":0}',
		);
	}

	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) $response['response']['code'];
	}

	function wp_remote_retrieve_body( array $response ): string {
		return (string) $response['body'];
	}

	function assert_same_value( string $label, $expected, $actual ): void {
		if ( $expected !== $actual ) {
			fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
			exit( 1 );
		}
	}

	function tracking_event( int $timestamp, string $visitor = 'visitor-1' ): array {
		return array(
			'visitor_token' => $visitor,
			'session_id'    => 'session-1',
			'event_type'    => 'page_view',
			'url'           => 'https://example.test/apartments',
			'ts'            => $timestamp,
		);
	}

	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

	require_once dirname( __DIR__ ) . '/src/Tracking/TrackingSender.php';
	require_once dirname( __DIR__ ) . '/src/Tracking/TrackingController.php';

	$controller         = new \LomnioApiConnector\Tracking\TrackingController();
	$midnight           = DAY_IN_SECONDS * 1000 * 20000;
	$browser_user_agent = str_repeat( 'A', 510 );
	$response           = $controller->handle(
		new WP_REST_Request(
			array(
				'events' => array(
					tracking_event( $midnight, 'bot-at-midnight' ),
					tracking_event( $midnight + 1234, 'real-browser' ),
				),
			),
			array( 'User-Agent' => $browser_user_agent )
		)
	);

	assert_same_value( 'mixed batch response status', 200, $response->get_status() );
	assert_same_value( 'one mixed batch request forwarded', 1, count( $GLOBALS['tracking_remote_requests'] ) );

	$remote_request   = $GLOBALS['tracking_remote_requests'][0]['arguments'];
	$forwarded_body   = json_decode( $remote_request['body'], true );
	$forwarded_events = $forwarded_body['events'] ?? array();

	assert_same_value( 'midnight artefact removed from mixed batch', 1, count( $forwarded_events ) );
	assert_same_value( 'real event remains in mixed batch', 'real-browser', $forwarded_events[0]['visitor_token'] ?? null );
	assert_same_value( 'browser User-Agent forwarded and capped', str_repeat( 'A', 500 ), $remote_request['headers']['User-Agent'] ?? null );

	$response = $controller->handle(
		new WP_REST_Request(
			array( 'events' => array( tracking_event( $midnight, 'bot-only' ) ) ),
			array( 'User-Agent' => 'Bot/1.0' )
		)
	);

	assert_same_value( 'all-filtered batch response status', 200, $response->get_status() );
	assert_same_value(
		'all-filtered batch response body',
		array(
			'created'    => 0,
			'duplicates' => 0,
			'ignored'    => 1,
		),
		$response->get_data()
	);
	assert_same_value( 'all-filtered batch does not call Lomnio', 1, count( $GLOBALS['tracking_remote_requests'] ) );

	$response = $controller->handle(
		new WP_REST_Request(
			array( 'events' => array( tracking_event( $midnight + 5678, 'no-user-agent' ) ) )
		)
	);

	assert_same_value( 'request without User-Agent is forwarded', 2, count( $GLOBALS['tracking_remote_requests'] ) );
	assert_same_value(
		'empty User-Agent does not override WordPress HTTP defaults',
		false,
		array_key_exists( 'User-Agent', $GLOBALS['tracking_remote_requests'][1]['arguments']['headers'] )
	);

	fwrite( STDOUT, "PASS: tracking proxy filters midnight artefacts and forwards the browser User-Agent.\n" );
}
