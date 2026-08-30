<?php
/**
 * Verify unit and floor list syncs publish complete snapshots atomically.
 *
 * The frontend queries units with in_latest_list = 1, and a floor page renders
 * as not found when its floor returns no units. Clearing that flag before the
 * new list is stored therefore makes every floor briefly disappear.
 *
 * @package LomnioApiConnector
 */

define( 'ABSPATH', __DIR__ . '/' );

define( 'JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

class WP_Error {
	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = '' ) {
	return $text;
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function current_time( $type ) {
	return '2026-08-28 16:00:00';
}

function get_option( $name, $default = false ) {
	// Pretend the schema is current so ensure_table() short circuits.
	return in_array( $name, array( 'lomnio_units_schema_version', 'lomnio_floors_schema_version', 'lomnio_project_schema_version' ), true )
		? $GLOBALS['schema_versions'][ $name ]
		: $default;
}

function update_option( $name, $value, $autoload = null ) {
	return true;
}

function dbDelta( $queries ) {
	return array();
}

/**
 * In-memory stand-in for $wpdb holding one units table.
 */
class Fake_WPDB {
	public $prefix = 'wp_';

	/** Rows keyed by unit_id: array( 'id' => int, 'floor' => int, 'visible' => int ). */
	public array $units = array();
	private array $committed_units = array();
	private bool $transaction_open = false;
	public bool $fail_cleanup = false;

	/** Lowest number of visible units on the watched floor seen during the run. */
	public int $min_visible_on_watched_floor = PHP_INT_MAX;

	public int $watched_floor = 8;

	private int $next_id = 1;

	public function seed( int $floor, array $unit_ids ): void {
		foreach ( $unit_ids as $unit_id ) {
			$this->units[ $unit_id ] = array(
				'id'      => $this->next_id++,
				'floor'   => $floor,
				'visible' => 1,
			);
		}

		$this->committed_units = $this->units;
	}

	public function visible_on_floor( int $floor ): int {
		$count = 0;
		$rows  = $this->transaction_open ? $this->committed_units : $this->units;

		foreach ( $rows as $row ) {
			if ( $row['floor'] === $floor && 1 === $row['visible'] ) {
				$count++;
			}
		}

		return $count;
	}

	/** Sample what the frontend would see right now. */
	private function probe(): void {
		$this->min_visible_on_watched_floor = min(
			$this->min_visible_on_watched_floor,
			$this->visible_on_floor( $this->watched_floor )
		);
	}

	public function get_charset_collate() {
		return '';
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . $arg . "'";
			$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function get_var( $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/i", $query, $m ) ) {
			// Every table this test touches is treated as already present.
			return str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $m[1] );
		}

		if ( false !== stripos( $query, 'SELECT project_id' ) ) {
			return '1';
		}

		if ( preg_match( "/WHERE (?:unit_id|floor_id) = '([^']*)'/", $query, $m ) ) {
			return isset( $this->units[ $m[1] ] ) ? (string) $this->units[ $m[1] ]['id'] : null;
		}

		return null;
	}

	public function insert( $table, $data ) {
		$resource_id = isset( $data['unit_id'] ) ? (string) $data['unit_id'] : (string) $data['floor_id'];

		$this->units[ $resource_id ] = array(
			'id'      => $this->next_id++,
			'floor'   => (int) $data['floor_number'],
			'visible' => (int) $data['in_latest_list'],
		);

		$this->probe();

		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		foreach ( $this->units as $unit_id => $row ) {
			$matches = isset( $where['id'] )
				? $row['id'] === (int) $where['id']
				: ( ! isset( $where['in_latest_list'] ) || $row['visible'] === (int) $where['in_latest_list'] );

			if ( ! $matches ) {
				continue;
			}

			if ( isset( $data['in_latest_list'] ) ) {
				$this->units[ $unit_id ]['visible'] = (int) $data['in_latest_list'];
			}

			if ( isset( $data['floor_number'] ) ) {
				$this->units[ $unit_id ]['floor'] = (int) $data['floor_number'];
			}
		}

		$this->probe();

		return 1;
	}

	public function query( $sql ) {
		if ( 'START TRANSACTION' === strtoupper( trim( $sql ) ) ) {
			$this->transaction_open = true;
			$this->committed_units  = $this->units;
			$this->probe();
			return 1;
		}

		if ( 'COMMIT' === strtoupper( trim( $sql ) ) ) {
			$this->transaction_open = false;
			$this->committed_units  = $this->units;
			$this->probe();
			return 1;
		}

		if ( 'ROLLBACK' === strtoupper( trim( $sql ) ) ) {
			$this->units            = $this->committed_units;
			$this->transaction_open = false;
			$this->probe();
			return 1;
		}

		if ( preg_match( '/SET in_latest_list = 0 .*NOT IN \((.*)\)/s', $sql, $m ) ) {
			if ( $this->fail_cleanup ) {
				return false;
			}

			preg_match_all( "/'([^']*)'/", $m[1], $ids );
			$keep = $ids[1];

			foreach ( $this->units as $unit_id => $row ) {
				if ( ! in_array( (string) $unit_id, $keep, true ) ) {
					$this->units[ $unit_id ]['visible'] = 0;
				}
			}
		}

		$this->probe();

		return 1;
	}
}

$GLOBALS['schema_versions'] = array(
	'lomnio_units_schema_version'   => '1.0.2',
	'lomnio_floors_schema_version'  => '1.0.2',
	'lomnio_project_schema_version' => '1.0.0',
);

// Overridable so the same test can be pointed at another checkout of the
// plugin, which is how the pre-fix behaviour is verified to fail.
$plugin_src = getenv( 'LOMNIO_PLUGIN_SRC' )
	?: __DIR__ . '/../src';

require_once $plugin_src . '/Pages/PageContext.php';
require_once $plugin_src . '/Database/ProjectRepository.php';
if ( file_exists( $plugin_src . '/Database/Transaction.php' ) ) {
	require_once $plugin_src . '/Database/Transaction.php';
}
require_once $plugin_src . '/Database/UnitRepository.php';
require_once $plugin_src . '/Database/FloorRepository.php';

// Keep ensure_table() from running dbDelta for the project repository.
$GLOBALS['schema_versions']['lomnio_project_schema_version'] =
	( new ReflectionClass( \LomnioApiConnector\Database\ProjectRepository::class ) )
		->getConstant( 'SCHEMA_VERSION' );

$GLOBALS['schema_versions']['lomnio_units_schema_version'] =
	( new ReflectionClass( \LomnioApiConnector\Database\UnitRepository::class ) )
		->getConstant( 'SCHEMA_VERSION' );

$GLOBALS['schema_versions']['lomnio_floors_schema_version'] =
	( new ReflectionClass( \LomnioApiConnector\Database\FloorRepository::class ) )
		->getConstant( 'SCHEMA_VERSION' );

/** Build one API list payload entry. */
function make_unit( string $id, int $floor ): array {
	return array(
		'id'    => $id,
		'code'  => $floor . '.' . $id,
		'floor' => array(
			'id'     => 'f' . $floor,
			'name'   => $floor . '. podlažie',
			'number' => $floor,
		),
	);
}

/** Build one API floor list payload entry. */
function make_floor( string $id, int $floor ): array {
	return array(
		'id'     => $id,
		'name'   => $floor . '. podlažie',
		'number' => $floor,
	);
}

$failures = array();

/* ---------------------------------------------------------------------------
 * Case 1: a normal re-sync must never make floor 8 disappear.
 * ------------------------------------------------------------------------ */
global $wpdb;
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8', 'u9', 'u10', 'u11' ) );
$wpdb->min_visible_on_watched_floor = PHP_INT_MAX;

$list = array();
foreach ( range( 1, 11 ) as $n ) {
	$list[] = make_unit( 'u' . $n, 8 );
}

$repository = new \LomnioApiConnector\Database\UnitRepository();
$stored     = $repository->store_list_units( $list );

if ( is_wp_error( $stored ) ) {
	$failures[] = 'Case 1: sync returned an error: ' . $stored->get_error_message();
}

if ( $wpdb->min_visible_on_watched_floor < 1 ) {
	$failures[] = sprintf(
		'Case 1: floor 8 dropped to %d visible units during the sync (expected to stay >= 1).',
		$wpdb->min_visible_on_watched_floor
	);
}

if ( 11 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = sprintf( 'Case 1: floor 8 ended with %d visible units, expected 11.', $wpdb->visible_on_floor( 8 ) );
}

/* ---------------------------------------------------------------------------
 * Case 2: units dropped from the list must end up hidden.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'u1', 'u2', 'u3' ) );
$wpdb->min_visible_on_watched_floor = PHP_INT_MAX;

$repository = new \LomnioApiConnector\Database\UnitRepository();
$repository->store_list_units( array( make_unit( 'u1', 8 ), make_unit( 'u2', 8 ) ) );

if ( 0 !== $wpdb->units['u3']['visible'] ) {
	$failures[] = 'Case 2: unit removed from the list is still visible.';
}

if ( 2 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = sprintf( 'Case 2: floor 8 ended with %d visible units, expected 2.', $wpdb->visible_on_floor( 8 ) );
}

/* ---------------------------------------------------------------------------
 * Case 3: an empty list must not blank the site.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'u1', 'u2', 'u3' ) );
$wpdb->min_visible_on_watched_floor = PHP_INT_MAX;

$repository = new \LomnioApiConnector\Database\UnitRepository();
$repository->store_list_units( array() );

if ( 3 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = sprintf(
		'Case 3: an empty list left %d visible units on floor 8, expected the previous 3 to survive.',
		$wpdb->visible_on_floor( 8 )
	);
}

/* ---------------------------------------------------------------------------
 * Case 4: moving old units away before inserting a replacement must be atomic.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'u1', 'u2' ) );
$wpdb->min_visible_on_watched_floor = PHP_INT_MAX;

$repository = new \LomnioApiConnector\Database\UnitRepository();
$repository->store_list_units(
	array(
		make_unit( 'u1', 9 ),
		make_unit( 'u2', 9 ),
		make_unit( 'u3', 8 ),
	)
);

if ( $wpdb->min_visible_on_watched_floor < 1 ) {
	$failures[] = sprintf(
		'Case 4: moving units exposed %d visible units on floor 8 before its replacement was stored.',
		$wpdb->min_visible_on_watched_floor
	);
}

if ( 1 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = sprintf( 'Case 4: floor 8 ended with %d visible units, expected 1.', $wpdb->visible_on_floor( 8 ) );
}

/* ---------------------------------------------------------------------------
 * Case 5: cleanup SQL failures must roll back and reach the caller.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'u1', 'u2' ) );
$wpdb->fail_cleanup = true;

$repository = new \LomnioApiConnector\Database\UnitRepository();
$stored     = $repository->store_list_units( array( make_unit( 'u1', 8 ) ) );

if ( ! is_wp_error( $stored ) ) {
	$failures[] = 'Case 5: cleanup SQL failure was reported as a successful sync.';
}

if ( 2 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = 'Case 5: failed cleanup did not roll back the list update.';
}

/* ---------------------------------------------------------------------------
 * Case 6: floor list updates must never hide a floor that remains in the list.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'f8' ) );
$wpdb->min_visible_on_watched_floor = PHP_INT_MAX;

$repository = new \LomnioApiConnector\Database\FloorRepository();
$stored     = $repository->store_list_floors( array( make_floor( 'f8', 8 ) ) );

if ( is_wp_error( $stored ) ) {
	$failures[] = 'Case 6: floors sync returned an error: ' . $stored->get_error_message();
}

if ( $wpdb->min_visible_on_watched_floor < 1 ) {
	$failures[] = 'Case 6: floor 8 disappeared during a normal floors sync.';
}

/* ---------------------------------------------------------------------------
 * Case 7: an empty floor list must preserve the last usable snapshot.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'f8' ) );

$repository = new \LomnioApiConnector\Database\FloorRepository();
$repository->store_list_floors( array() );

if ( 1 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = 'Case 7: an empty floors list hid the last usable floor snapshot.';
}

/* ---------------------------------------------------------------------------
 * Case 8: floor cleanup failures must roll back and reach the caller.
 * ------------------------------------------------------------------------ */
$wpdb = new Fake_WPDB();
$wpdb->seed( 8, array( 'f8', 'f8-old' ) );
$wpdb->fail_cleanup = true;

$repository = new \LomnioApiConnector\Database\FloorRepository();
$stored     = $repository->store_list_floors( array( make_floor( 'f8', 8 ) ) );

if ( ! is_wp_error( $stored ) ) {
	$failures[] = 'Case 8: floor cleanup SQL failure was reported as a successful sync.';
}

if ( 2 !== $wpdb->visible_on_floor( 8 ) ) {
	$failures[] = 'Case 8: failed floor cleanup did not roll back the list update.';
}

if ( $failures ) {
	echo "FAIL\n";
	foreach ( $failures as $failure ) {
		echo '  - ' . $failure . "\n";
	}
	exit( 1 );
}

echo "PASS: unit and floor list syncs are atomic, preserve empty responses, and report cleanup failures.\n";
