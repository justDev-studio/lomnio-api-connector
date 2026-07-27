<?php
/**
 * Public same-site tracking proxy.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackingController {
	private const REST_NAMESPACE = 'lomnio/v1';
	private const REST_ROUTE     = '/tracking/events';
	private const MAX_BATCH      = 50;
	private const RATE_LIMIT     = 120;
	private const RATE_WINDOW    = 60;

	private const EVENT_TYPES = array(
		'page_view',
		'unit_view',
		'floor_plan_view',
		'price_list_view',
		'gallery_browse',
		'contact_form_open',
		'calculator_use',
		'comparison_add',
		'search_filter',
		'time_on_page',
		'download',
		'video_view',
	);

	private TrackingSender $sender;

	public function __construct( ?TrackingSender $sender = null ) {
		$this->sender = $sender ?: new TrackingSender();
	}

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Validate and forward one browser batch.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		if ( ! $this->consume_rate_limit() ) {
			return new \WP_Error(
				'lomnio_tracking_rate_limited',
				__( 'Too many tracking requests.', 'lomnio-api-connector' ),
				array( 'status' => 429 )
			);
		}

		$payload = $request->get_json_params();
		$events  = is_array( $payload ) && isset( $payload['events'] ) && is_array( $payload['events'] )
			? $payload['events']
			: null;

		if ( null === $events || empty( $events ) || count( $events ) > self::MAX_BATCH ) {
			return $this->validation_error( __( 'Events must contain between 1 and 50 items.', 'lomnio-api-connector' ) );
		}

		$validated = array();

		foreach ( $events as $index => $event ) {
			$result = $this->validate_event( $event );

			if ( is_wp_error( $result ) ) {
				return $this->validation_error(
					sprintf(
						/* translators: 1: event index, 2: validation message. */
						__( 'Event %1$d: %2$s', 'lomnio-api-connector' ),
						(int) $index,
						$result->get_error_message()
					)
				);
			}

			$validated[] = $result;
		}

		$result = $this->sender->send( $validated );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result['body'], (int) $result['status_code'] );
	}

	/**
	 * Validate one event and return a clean API payload.
	 *
	 * @return array|\WP_Error
	 */
	private function validate_event( $event ) {
		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'invalid_event', __( 'Event must be an object.', 'lomnio-api-connector' ) );
		}

		$visitor = isset( $event['visitor_token'] ) ? trim( (string) $event['visitor_token'] ) : '';
		$type    = isset( $event['event_type'] ) ? sanitize_key( (string) $event['event_type'] ) : '';
		$ts      = $event['ts'] ?? null;

		if ( '' === $visitor || $this->string_length( $visitor ) > 64 ) {
			return new \WP_Error( 'invalid_visitor', __( 'visitor_token is required and must be at most 64 characters.', 'lomnio-api-connector' ) );
		}

		if ( ! in_array( $type, self::EVENT_TYPES, true ) ) {
			return new \WP_Error( 'invalid_type', __( 'event_type is not supported.', 'lomnio-api-connector' ) );
		}

		if ( ! is_int( $ts ) && ! ( is_string( $ts ) && ctype_digit( $ts ) ) ) {
			return new \WP_Error( 'invalid_ts', __( 'ts must be a Unix timestamp integer.', 'lomnio-api-connector' ) );
		}

		$timestamp = (int) $ts;

		if ( $timestamp > 0 && $timestamp < 1000000000000 ) {
			$timestamp *= 1000;
		}

		$clean = array(
			'visitor_token' => $visitor,
			'event_type'    => $type,
			'ts'            => $timestamp,
		);

		$string_fields = array(
			'session_id' => 64,
			'url'        => 500,
			'title'      => 255,
			'referrer'   => 500,
		);

		foreach ( $string_fields as $key => $max_length ) {
			if ( ! array_key_exists( $key, $event ) || null === $event[ $key ] || '' === $event[ $key ] ) {
				continue;
			}

			if ( ! is_scalar( $event[ $key ] ) || $this->string_length( (string) $event[ $key ] ) > $max_length ) {
				return new \WP_Error( 'invalid_' . $key, sprintf( '%s is too long or invalid.', $key ) );
			}

			$value = sanitize_text_field( (string) $event[ $key ] );

			if ( in_array( $key, array( 'url', 'referrer' ), true ) && false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return new \WP_Error( 'invalid_' . $key, sprintf( '%s must be an absolute URL.', $key ) );
			}

			$clean[ $key ] = $value;
		}

		if ( array_key_exists( 'unit_id', $event ) && null !== $event['unit_id'] ) {
			if ( ! is_numeric( $event['unit_id'] ) || (int) $event['unit_id'] <= 0 ) {
				return new \WP_Error( 'invalid_unit_id', __( 'unit_id must be a positive integer.', 'lomnio-api-connector' ) );
			}
			$clean['unit_id'] = (int) $event['unit_id'];
		}

		if ( array_key_exists( 'duration', $event ) && null !== $event['duration'] ) {
			$duration = is_numeric( $event['duration'] ) ? (int) $event['duration'] : -1;
			if ( $duration < 0 || $duration > 3600 ) {
				return new \WP_Error( 'invalid_duration', __( 'duration must be between 0 and 3600 seconds.', 'lomnio-api-connector' ) );
			}
			$clean['duration'] = $duration;
		}

		if ( array_key_exists( 'metadata', $event ) && null !== $event['metadata'] ) {
			if ( ! is_array( $event['metadata'] ) ) {
				return new \WP_Error( 'invalid_metadata', __( 'metadata must be an array of strings.', 'lomnio-api-connector' ) );
			}
			$metadata = array();
			foreach ( $event['metadata'] as $item ) {
				if ( ! is_scalar( $item ) ) {
					return new \WP_Error( 'invalid_metadata', __( 'metadata must contain strings only.', 'lomnio-api-connector' ) );
				}
				$metadata[] = sanitize_text_field( (string) $item );
			}
			$clean['metadata'] = $metadata;
		}

		if ( array_key_exists( 'utm', $event ) && null !== $event['utm'] ) {
			if ( ! is_array( $event['utm'] ) ) {
				return new \WP_Error( 'invalid_utm', __( 'utm must be an object.', 'lomnio-api-connector' ) );
			}
			$utm = array();
			foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $key ) {
				if ( ! array_key_exists( $key, $event['utm'] ) || null === $event['utm'][ $key ] || '' === $event['utm'][ $key ] ) {
					continue;
				}
				if ( ! is_scalar( $event['utm'][ $key ] ) || $this->string_length( (string) $event['utm'][ $key ] ) > 100 ) {
					return new \WP_Error( 'invalid_utm_' . $key, sprintf( 'utm.%s is too long or invalid.', $key ) );
				}
				$utm[ $key ] = sanitize_text_field( (string) $event['utm'][ $key ] );
			}
			$clean['utm'] = $utm;
		}

		return $clean;
	}

	private function consume_rate_limit(): bool {
		$ip   = $this->remote_ip();
		$key  = 'lomnio_tracking_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$data = get_transient( $key );
		$data = is_array( $data ) ? $data : array(
			'count' => 0,
			'start' => time(),
		);

		if ( time() - (int) $data['start'] >= self::RATE_WINDOW ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$data['count']++;
		set_transient( $key, $data, self::RATE_WINDOW );

		return (int) $data['count'] <= self::RATE_LIMIT;
	}

	private function remote_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : 'unknown';
	}

	private function validation_error( string $message ): \WP_Error {
		return new \WP_Error(
			'lomnio_tracking_validation_error',
			$message,
			array( 'status' => 422 )
		);
	}

	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
