<?php
/**
 * Unit code transformations.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Units;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UnitCode {
	/**
	 * Convert a Lomnio unit code into a URL route segment.
	 */
	public static function route_segment( string $code ): string {
		$code = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $code ) ?? trim( $code );

		return preg_replace( '/[\s\p{Z}]+/u', '-', $code ) ?? $code;
	}
}
