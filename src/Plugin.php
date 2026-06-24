<?php
/**
 * Main plugin bootstrap.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector;

use LomnioApiConnector\Admin\EndpointsPage;
use LomnioApiConnector\Admin\PageDisplaySettingsPage;
use LomnioApiConnector\Admin\SettingsPage;
use LomnioApiConnector\Database\FloorRepository;
use LomnioApiConnector\Database\ProjectRepository;
use LomnioApiConnector\Database\UnitRepository;
use LomnioApiConnector\Pages\PageRouter;
use LomnioApiConnector\Pages\PageSettings;
use LomnioApiConnector\Pages\PageSettingsPostTypes;
use LomnioApiConnector\Security\SecretStorage;
use LomnioApiConnector\Sync\FloorsSync;
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
		$floors_sync = new FloorsSync( $this->secret_storage(), new FloorRepository() );
		$floors_sync->hooks();
		$page_settings = new PageSettings();
		$page_settings_post_types = new PageSettingsPostTypes();
		$page_settings_post_types->hooks();
		$page_router   = new PageRouter( $page_settings );
		$page_router->hooks();

		if ( is_admin() ) {
			$endpoints_page = new EndpointsPage( $project_sync, $units_sync, $floors_sync );
			$endpoints_page->hooks();

			$settings_page = new SettingsPage( $this->secret_storage() );
			$settings_page->hooks();

			$page_display_settings_page = new PageDisplaySettingsPage( $page_settings );
			$page_display_settings_page->hooks();
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

		$floors_sync = new FloorsSync( $this->secret_storage(), new FloorRepository() );
		$floors_sync->activate();

		( new PageRouter( new PageSettings() ) )->register_routes();
		flush_rewrite_rules( false );
	}

	/**
	 * Run plugin deactivation tasks.
	 */
	public function deactivate(): void {
		$project_sync = new ProjectSync( $this->secret_storage(), new ProjectRepository() );
		$project_sync->deactivate();

		$units_sync = new UnitsSync( $this->secret_storage(), new UnitRepository() );
		$units_sync->deactivate();

		$floors_sync = new FloorsSync( $this->secret_storage(), new FloorRepository() );
		$floors_sync->deactivate();

		flush_rewrite_rules( false );
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
