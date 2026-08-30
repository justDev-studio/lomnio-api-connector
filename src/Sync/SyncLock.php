<?php
/**
 * Cross-request synchronization lock.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncLock {
	/**
	 * Acquire a MySQL named lock.
	 *
	 * @return true|false|\WP_Error True when acquired, false when busy.
	 */
	public function acquire( string $resource, int $timeout = 0 ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$this->name( $resource ),
				max( 0, $timeout )
			)
		);

		if ( '1' === (string) $result ) {
			return true;
		}

		if ( '0' === (string) $result ) {
			return false;
		}

		return new \WP_Error(
			'lomnio_sync_lock_error',
			sprintf(
				/* translators: %s: synchronized resource name. */
				__( 'Could not acquire the %s synchronization lock.', 'lomnio-api-connector' ),
				$resource
			)
		);
	}

	/**
	 * Release a MySQL named lock.
	 *
	 * @return true|\WP_Error
	 */
	public function release( string $resource ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name( $resource ) )
		);

		if ( '1' === (string) $result ) {
			return true;
		}

		return new \WP_Error(
			'lomnio_sync_unlock_error',
			sprintf(
				/* translators: %s: synchronized resource name. */
				__( 'Could not release the %s synchronization lock.', 'lomnio-api-connector' ),
				$resource
			)
		);
	}

	/**
	 * Build a server-wide lock name scoped to this database and site prefix.
	 */
	private function name( string $resource ): string {
		global $wpdb;

		$resource = preg_replace( '/[^a-z0-9_-]+/i', '_', strtolower( $resource ) );
		$scope    = ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . (string) $wpdb->prefix;

		return substr( 'lomnio_' . $resource . '_' . hash( 'sha256', $scope ), 0, 64 );
	}
}
