<?php
/**
 * Verify database sync locks distinguish busy locks from database errors and
 * are scoped to the current WordPress installation.
 *
 * @package LomnioApiConnector
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'lomnio_sync_lock_test' );

class WP_Error {
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

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function __( $text, $domain = '' ) {
	return $text;
}

final class Fake_WPDB {
	public string $prefix;
	public string $last_error = '';

	/** @var array<int, mixed> */
	public array $results = array();

	/** @var array<int, string> */
	public array $queries = array();

	public function __construct( string $prefix ) {
		$this->prefix = $prefix;
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function get_var( string $query ) {
		$this->queries[] = $query;

		return array_shift( $this->results );
	}
}

require_once __DIR__ . '/../src/Sync/SyncLock.php';

use LomnioApiConnector\Sync\SyncLock;

$failures = array();

global $wpdb;
$wpdb          = new Fake_WPDB( 'wp_' );
$wpdb->results = array( '1' );
$lock          = new SyncLock();

if ( true !== $lock->acquire( 'units' ) ) {
	$failures[] = 'Expected GET_LOCK=1 to acquire the lock.';
}

preg_match( "/GET_LOCK\\('([^']+)'/", $wpdb->queries[0] ?? '', $first_name );
$first_name = $first_name[1] ?? '';

$wpdb          = new Fake_WPDB( 'wp_2_' );
$wpdb->results = array( '1' );
$other_lock    = new SyncLock();
$other_lock->acquire( 'units' );
preg_match( "/GET_LOCK\\('([^']+)'/", $wpdb->queries[0] ?? '', $second_name );
$second_name = $second_name[1] ?? '';

if ( '' === $first_name || '' === $second_name || $first_name === $second_name ) {
	$failures[] = 'Lock names must differ between WordPress table prefixes.';
}

$wpdb          = new Fake_WPDB( 'wp_' );
$wpdb->results = array( '0', null, '1', null );
$lock          = new SyncLock();

if ( false !== $lock->acquire( 'units' ) ) {
	$failures[] = 'Expected GET_LOCK=0 to report a busy lock.';
}

$error = $lock->acquire( 'units' );
if ( ! is_wp_error( $error ) || 'lomnio_sync_lock_error' !== $error->get_error_code() ) {
	$failures[] = 'Expected GET_LOCK=NULL to return lomnio_sync_lock_error.';
}

if ( true !== $lock->release( 'units' ) ) {
	$failures[] = 'Expected RELEASE_LOCK=1 to release the lock.';
}

$error = $lock->release( 'units' );
if ( ! is_wp_error( $error ) || 'lomnio_sync_unlock_error' !== $error->get_error_code() ) {
	$failures[] = 'Expected RELEASE_LOCK=NULL to return lomnio_sync_unlock_error.';
}

if ( $failures ) {
	echo "FAIL\n";
	foreach ( $failures as $failure ) {
		echo '  - ' . $failure . "\n";
	}
	exit( 1 );
}

echo "PASS: sync locks are scoped and distinguish busy locks from database errors.\n";
