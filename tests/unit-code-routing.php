<?php
/**
 * Read-only integration check: wp eval-file <plugin>/tests/unit-code-routing.php
 */

if ( ! class_exists( 'LomnioUnits' ) || ! class_exists( 'LomnioPages' ) ) {
	throw new RuntimeException( 'Run this test with WP-CLI and the Lomnio plugin active.' );
}

$units = LomnioUnits::get();

if ( empty( $units ) ) {
	throw new RuntimeException( 'This integration check requires synced units.' );
}

$units_by_route = array();

foreach ( $units as $unit ) {
	$route_segment                      = \LomnioApiConnector\Units\UnitCode::route_segment( (string) $unit->code );
	$units_by_route[ $route_segment ][] = $unit;
}

foreach ( $units as $unit ) {
	$code          = (string) $unit->code;
	$route_segment = \LomnioApiConnector\Units\UnitCode::route_segment( $code );
	$url           = LomnioPages::unit_link( $code );

	if ( preg_match( '/[\\s\\p{Z}]/u', rawurldecode( basename( $url ) ) ) ) {
		throw new RuntimeException( 'Whitespace remains in the URL for ' . $code );
	}

	foreach ( array_unique( array( $code, $route_segment ) ) as $lookup ) {
		$resolved = LomnioUnits::find_by_code( $lookup );

		if ( count( $units_by_route[ $route_segment ] ) > 1 && null !== $resolved ) {
			throw new RuntimeException( 'Ambiguous unit route resolved to a unit for ' . $lookup );
		}

		if ( 1 === count( $units_by_route[ $route_segment ] ) && ( ! $resolved || (string) $resolved->id !== (string) $unit->id || $resolved->code !== $code ) ) {
			throw new RuntimeException( 'Unit lookup changed identity for ' . $lookup );
		}
	}
}

if (
	null !== LomnioUnits::find_by_code( '' )
	|| null !== LomnioUnits::find_by_code( "\u{00A0}\u{202F}" )
	|| null !== LomnioUnits::find_by_code( 'nonexistent-test-unit-code' )
) {
	throw new RuntimeException( 'An empty or unknown code must not resolve to a unit.' );
}

echo sprintf( "PASS: %d unit routes resolve uniquely or reject ambiguous codes, with no whitespace in URLs.\n", count( $units ) );
