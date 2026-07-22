<?php
/**
 * Lomnio data revision used to invalidate dependent caches.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DataRevision {
	public const OPTION_NAME = 'lomnio_api_connector_data_revision';

	/**
	 * Change the revision after a successful Lomnio database write.
	 */
	public static function bump(): void {
		update_option( self::OPTION_NAME, sprintf( '%.6F', microtime( true ) ), false );
	}
}
