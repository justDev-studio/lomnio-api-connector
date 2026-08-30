<?php
/**
 * Database transaction helper.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Transaction {
	/**
	 * Run a callback atomically and roll it back when it returns WP_Error.
	 *
	 * @return mixed|\WP_Error
	 */
	public static function run( callable $callback, string $error_code, string $error_message ) {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( $error_code, $error_message );
		}

		try {
			$result = $callback();

			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $result;
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( $error_code, $error_message );
			}

			return $result;
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}
}
