<?php
/**
 * Global Lomnio leads facade.
 *
 * @package LomnioApiConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LomnioLeads' ) ) {
	final class LomnioLeads {
		/**
		 * Send a lead without importing namespaced plugin classes.
		 *
		 * @return array|\WP_Error
		 */
		public static function send( array $fields, array $context = array() ) {
			return ( new \LomnioApiConnector\Leads\LeadSender() )->send( $fields, $context );
		}
	}
}
