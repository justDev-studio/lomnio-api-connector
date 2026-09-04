<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['test_yoast_raw'] = array();

function get_option( string $name, $default = false ) {
	return $default;
}

function sanitize_title( string $value ): string {
	return $value;
}

function get_query_var( string $key, $default = '' ) {
	return $default;
}

function get_bloginfo( string $key ): string {
	if ( 'name' === $key ) {
		return 'Brenner';
	}

	if ( 'url' === $key ) {
		return 'https://example.test';
	}

	return '';
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/' ) . '/';
}

function apply_filters( string $hook, $value, ...$args ) {
	return $value;
}

function post_type_exists( string $post_type ): bool {
	return true;
}

function get_posts( array $arguments ): array {
	return 'single_settings' === $arguments['post_type'] ? array( 101 ) : array( 202 );
}

function get_post_type( int $post_id ): string {
	return 101 === $post_id ? 'single_settings' : 'floor_settings';
}

function get_locale(): string {
	return 'sk_SK';
}

function number_format_i18n( float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals, ',', ' ' );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function get_post_meta( int $post_id, string $key, bool $single = false ) {
	return $GLOBALS['test_yoast_raw'][ $post_id ][ $key ] ?? '';
}

final class FakeYoastMeta {
	public function for_post( int $post_id ): object {
		return (object) array(
			'title'            => sprintf( 'Yoast title %d', $post_id ),
			'meta_description' => sprintf( 'Yoast description %d', $post_id ),
		);
	}
}

final class FakeYoast {
	public FakeYoastMeta $meta;

	public function __construct() {
		$this->meta = new FakeYoastMeta();
	}
}

function YoastSEO(): FakeYoast {
	static $yoast;

	if ( ! $yoast ) {
		$yoast = new FakeYoast();
	}

	return $yoast;
}

function assert_same_value( string $label, $expected, $actual ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		exit( 1 );
	}
}

$plugin_root = dirname( __DIR__ );

require_once $plugin_root . '/src/Pages/PageSettings.php';
require_once $plugin_root . '/src/Pages/PageSettingsPostTypes.php';
require_once $plugin_root . '/src/Units/UnitCode.php';
require_once $plugin_root . '/src/Pages/PageContext.php';
require_once $plugin_root . '/includes/LomnioPages.php';

$settings_post_types = new \LomnioApiConnector\Pages\PageSettingsPostTypes();

assert_same_value(
	'Yoast indexes settings post types',
	array( 'post', 'single_settings', 'floor_settings' ),
	$settings_post_types->include_settings_in_yoast_indexables( array( 'post' ) )
);

$unit = (object) array(
	'code'        => '13.5',
	'type'        => 'byt',
	'type_labels' => (object) array( 'sk' => 'Byt' ),
	'room_count'  => 3,
	'areas'       => (object) array( 'area' => 72.27 ),
	'floor'       => (object) array( 'number' => 13 ),
);

$unit_seo = LomnioPages::unit_seo( $unit );

assert_same_value( 'generated unit title', '13.5 | Brenner', $unit_seo['title'] );
assert_same_value(
	'generated unit description',
	'Byt 13.5 v projekte Brenner. Počet izieb: 3. Celková plocha: 72,27 m². Podlažie: 13.',
	$unit_seo['description']
);
assert_same_value( 'unit canonical', 'https://example.test/apartment/13.5', $unit_seo['canonical'] );

assert_same_value( 'unit URL replaces spaces', 'https://example.test/apartment/H1-02-A1', LomnioPages::unit_link( 'H1 02 A1' ) );
assert_same_value( 'unit URL trims and collapses whitespace', 'https://example.test/apartment/H1-02-A1', LomnioPages::unit_link( " \tH1  02\nA1 " ) );
assert_same_value( 'unit URL normalizes non-breaking spaces', 'https://example.test/apartment/H1-02-A1', LomnioPages::unit_link( "\u{00A0}H1\u{00A0}02\u{202F}A1\u{00A0}" ) );
assert_same_value( 'unit URL preserves punctuation', 'https://example.test/apartment/-H1_02.A-1-', LomnioPages::unit_link( '-H1_02.A-1-' ) );
assert_same_value( 'unit URL preserves phase', 'https://example.test/apartment/phase-1/H1-02-A1', LomnioPages::unit_link( 'H1 02 A1', 'phase-1' ) );
$spaced_unit = clone $unit;
$spaced_unit->code = 'H1 02 A1';
$spaced_seo = LomnioPages::unit_seo( $spaced_unit );
assert_same_value( 'normalized canonical preserves display title', 'H1 02 A1 | Brenner', $spaced_seo['title'] );
assert_same_value( 'canonical uses normalized URL', 'https://example.test/apartment/H1-02-A1', $spaced_seo['canonical'] );

$floor_seo = LomnioPages::floor_seo( 13 );

assert_same_value( 'generated floor title', '13. podlažie | Brenner', $floor_seo['title'] );
assert_same_value(
	'generated floor description',
	'Ponuka bytov na 13. podlaží projektu Brenner. Pozrite si aktuálne byty a apartmány na tomto podlaží.',
	$floor_seo['description']
);
assert_same_value( 'floor canonical', 'https://example.test/floor/13/', $floor_seo['canonical'] );

$GLOBALS['test_yoast_raw'][101] = array(
	'_yoast_wpseo_title'    => 'Configured title',
	'_yoast_wpseo_metadesc' => 'Configured description',
);
$unit_seo = LomnioPages::unit_seo( $unit );

assert_same_value( 'Yoast unit title', 'Yoast title 101', $unit_seo['title'] );
assert_same_value( 'Yoast unit description', 'Yoast description 101', $unit_seo['description'] );
assert_same_value( 'Yoast keeps dynamic canonical', 'https://example.test/apartment/13.5', $unit_seo['canonical'] );

fwrite( STDOUT, "PASS: Lomnio page SEO generates route metadata and respects explicit Yoast fields.\n" );
