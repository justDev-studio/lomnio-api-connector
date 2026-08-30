<?php
/**
 * Verify full list syncs and webhook writes use the same resource locks.
 *
 * @package LomnioApiConnector
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'DB_NAME', 'lomnio_sync_coordination_test' );

	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function add_data( $data ): void {
			$this->data = $data;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function __( $text, $domain = '' ) {
		return $text;
	}

	function current_time( $type ): string {
		return '2026-08-30 12:00:00';
	}

	$GLOBALS['test_options'] = array();
	$GLOBALS['remote_calls'] = 0;

	function get_option( $name, $default = false ) {
		return $GLOBALS['test_options'][ $name ] ?? $default;
	}

	function update_option( $name, $value, $autoload = null ): bool {
		$GLOBALS['test_options'][ $name ] = $value;
		return true;
	}

	function add_query_arg( $args, $url ): string {
		return $url;
	}

	function wp_remote_get( $url, $args = array() ): array {
		$GLOBALS['remote_calls']++;
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"data":[]}' );
	}

	function wp_remote_retrieve_response_code( $response ): int {
		return (int) $response['response']['code'];
	}

	function wp_remote_retrieve_body( $response ): string {
		return (string) $response['body'];
	}

	final class Fake_WPDB {
		public string $prefix = 'wp_';
		public array $results = array();

		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
				$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
			}

			return $query;
		}

		public function get_var( string $query ) {
			return array_shift( $this->results );
		}
	}
}

namespace LomnioApiConnector\Security {
	final class SecretStorage {
		public function get_authorization_headers(): array {
			return array( 'Authorization' => 'Bearer test' );
		}
	}
}

namespace LomnioApiConnector\Database {
	final class DataRevision {
		public static function bump(): void {}
	}

	final class UnitRepository {
		public int $webhook_calls = 0;

		public function store_list_units( array $units ): array {
			return array();
		}

		public function store_webhook_unit( array $unit, int $project_id = 0 ) {
			$this->webhook_calls++;
			return (string) $unit['id'];
		}

		public function delete_webhook_unit( $unit_id ) {
			$this->webhook_calls++;
			return true;
		}
	}

	final class FloorRepository {
		public int $webhook_calls = 0;

		public function store_list_floors( array $floors ): array {
			return array();
		}

		public function store_webhook_floor( array $floor, int $project_id = 0 ) {
			$this->webhook_calls++;
			return (string) $floor['id'];
		}

		public function delete_webhook_floor( $floor_id ) {
			$this->webhook_calls++;
			return true;
		}
	}

	final class ProjectRepository {}
}

namespace {
	require_once __DIR__ . '/../src/Sync/SyncLock.php';
	require_once __DIR__ . '/../src/Sync/UnitsSync.php';
	require_once __DIR__ . '/../src/Sync/FloorsSync.php';
	require_once __DIR__ . '/../src/Webhook/SyncWebhook.php';

	use LomnioApiConnector\Database\FloorRepository;
	use LomnioApiConnector\Database\ProjectRepository;
	use LomnioApiConnector\Database\UnitRepository;
	use LomnioApiConnector\Security\SecretStorage;
	use LomnioApiConnector\Sync\FloorsSync;
	use LomnioApiConnector\Sync\SyncLock;
	use LomnioApiConnector\Sync\UnitsSync;
	use LomnioApiConnector\Webhook\SyncWebhook;

	$failures = array();

	global $wpdb;
	$wpdb                   = new Fake_WPDB();
	$wpdb->results          = array( '0' );
	$GLOBALS['remote_calls'] = 0;
	$units_sync             = new UnitsSync( new SecretStorage(), new UnitRepository(), new SyncLock() );
	$result                 = $units_sync->run();

	if ( true !== $result || 0 !== $GLOBALS['remote_calls'] ) {
		$failures[] = 'Busy units lock did not skip the remote list request.';
	}

	$wpdb                   = new Fake_WPDB();
	$wpdb->results          = array( null );
	$GLOBALS['remote_calls'] = 0;
	$units_sync             = new UnitsSync( new SecretStorage(), new UnitRepository(), new SyncLock() );
	$result                 = $units_sync->run();

	if ( ! is_wp_error( $result ) || 'lomnio_sync_lock_error' !== $result->get_error_code() || 0 !== $GLOBALS['remote_calls'] ) {
		$failures[] = 'Units lock database error was not returned to the caller.';
	}

	$wpdb                   = new Fake_WPDB();
	$wpdb->results          = array( '1', '1' );
	$GLOBALS['remote_calls'] = 0;
	$units_sync             = new UnitsSync( new SecretStorage(), new UnitRepository(), new SyncLock() );
	$result                 = $units_sync->run();

	if ( true !== $result || 1 !== $GLOBALS['remote_calls'] ) {
		$failures[] = 'Units sync did not run and release an acquired lock.';
	}

	$wpdb                   = new Fake_WPDB();
	$wpdb->results          = array( '0' );
	$GLOBALS['remote_calls'] = 0;
	$floors_sync            = new FloorsSync( new SecretStorage(), new FloorRepository(), new SyncLock() );
	$result                 = $floors_sync->run();

	if ( true !== $result || 0 !== $GLOBALS['remote_calls'] ) {
		$failures[] = 'Busy floors lock did not skip the remote list request.';
	}

	$unit_repository = new UnitRepository();
	$wpdb             = new Fake_WPDB();
	$wpdb->results    = array( '0' );
	$webhook          = new SyncWebhook(
		$unit_repository,
		new SecretStorage(),
		new FloorRepository(),
		new ProjectRepository(),
		new SyncLock()
	);
	$method           = new \ReflectionMethod( $webhook, 'process_resource' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	$result = $method->invoke(
		$webhook,
		'unit',
		array(
			'unit_id' => 'u1',
			'unit'    => array( 'id' => 'u1' ),
		),
		'unit.updated'
	);

	if ( ! is_wp_error( $result ) || 'lomnio_webhook_sync_busy' !== $result->get_error_code() || 0 !== $unit_repository->webhook_calls ) {
		$failures[] = 'Webhook unit write was not blocked by the units sync lock.';
	}

	$unit_repository = new UnitRepository();
	$wpdb             = new Fake_WPDB();
	$wpdb->results    = array( '1', '1' );
	$webhook          = new SyncWebhook(
		$unit_repository,
		new SecretStorage(),
		new FloorRepository(),
		new ProjectRepository(),
		new SyncLock()
	);
	$method           = new \ReflectionMethod( $webhook, 'process_resource' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	$result = $method->invoke(
		$webhook,
		'unit',
		array(
			'unit_id' => 'u1',
			'unit'    => array( 'id' => 'u1' ),
		),
		'unit.updated'
	);

	if ( is_wp_error( $result ) || 1 !== $unit_repository->webhook_calls ) {
		$failures[] = 'Webhook unit write did not run after acquiring the units lock.';
	}

	$floor_repository = new FloorRepository();
	$wpdb              = new Fake_WPDB();
	$wpdb->results     = array( '0' );
	$webhook           = new SyncWebhook(
		new UnitRepository(),
		new SecretStorage(),
		$floor_repository,
		new ProjectRepository(),
		new SyncLock()
	);
	$method           = new \ReflectionMethod( $webhook, 'process_resource' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	$result = $method->invoke(
		$webhook,
		'floor',
		array(
			'floor_id' => 'f8',
			'floor'    => array( 'id' => 'f8' ),
		),
		'floor.updated'
	);

	if ( ! is_wp_error( $result ) || 'lomnio_webhook_sync_busy' !== $result->get_error_code() || 0 !== $floor_repository->webhook_calls ) {
		$failures[] = 'Webhook floor write was not blocked by the floors sync lock.';
	}

	if ( $failures ) {
		echo "FAIL\n";
		foreach ( $failures as $failure ) {
			echo '  - ' . $failure . "\n";
		}
		exit( 1 );
	}

	echo "PASS: full syncs and webhook writes coordinate through resource locks.\n";
}
