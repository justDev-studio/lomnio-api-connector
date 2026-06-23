<?php
/**
 * Main plugin bootstrap.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector;

use LomnioApiConnector\Admin\EndpointsPage;
use LomnioApiConnector\Admin\SettingsPage;
use LomnioApiConnector\Database\ProjectRepository;
use LomnioApiConnector\Database\UnitRepository;
use LomnioApiConnector\Security\SecretStorage;
use LomnioApiConnector\Sync\ProjectSync;
use LomnioApiConnector\Sync\UnitsSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Plugin instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the plugin was booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Encrypted secret storage.
	 *
	 * @var SecretStorage|null
	 */
	private ?SecretStorage $secret_storage = null;

	/**
	 * Get plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot plugin services.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$project_sync = new ProjectSync( $this->secret_storage(), new ProjectRepository() );
		$project_sync->hooks();
		$units_sync = new UnitsSync( $this->secret_storage(), new UnitRepository() );
		$units_sync->hooks();

		if ( is_admin() ) {
			$endpoints_page = new EndpointsPage( $project_sync, $units_sync );
			$endpoints_page->hooks();

			$settings_page = new SettingsPage( $this->secret_storage() );
			$settings_page->hooks();
		}
	}

	/**
	 * Run plugin activation tasks.
	 */
	public function activate(): void {
		$project_sync = new ProjectSync( $this->secret_storage(), new ProjectRepository() );
		$project_sync->activate();

		$units_sync = new UnitsSync( $this->secret_storage(), new UnitRepository() );
		$units_sync->activate();
	}

	/**
	 * Run plugin deactivation tasks.
	 */
	public function deactivate(): void {
		$project_sync = new ProjectSync( $this->secret_storage(), new ProjectRepository() );
		$project_sync->deactivate();

		$units_sync = new UnitsSync( $this->secret_storage(), new UnitRepository() );
		$units_sync->deactivate();
	}

	/**
	 * Get encrypted secret storage.
	 */
	public function secret_storage(): SecretStorage {
		if ( null === $this->secret_storage ) {
			$this->secret_storage = new SecretStorage();
		}

		return $this->secret_storage;
	}

	/**
	 * Keep construction private for singleton usage.
	 */
	private function __construct() {}
}
