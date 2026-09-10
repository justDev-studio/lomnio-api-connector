<?php
/**
 * Yoast sitemap for dynamic Lomnio floor and unit routes.
 *
 * @package LomnioApiConnector
 */

namespace LomnioApiConnector\Seo;

use LomnioApiConnector\Database\DataRevision;
use LomnioApiConnector\Database\FloorRepository;
use LomnioApiConnector\Database\UnitRepository;
use LomnioApiConnector\Pages\PageSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApartmentsSitemap {
	private const SITEMAP_NAME = 'apartments';

	private UnitRepository $units;
	private FloorRepository $floors;
	private PageSettings $settings;

	public function __construct(
		?UnitRepository $units = null,
		?FloorRepository $floors = null,
		?PageSettings $settings = null
	) {
		$this->units    = $units ?: new UnitRepository();
		$this->floors   = $floors ?: new FloorRepository();
		$this->settings = $settings ?: new PageSettings();
	}

	/**
	 * Register WordPress and Yoast hooks.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_filter( 'wpseo_sitemap_index_links', array( $this, 'add_index_link' ) );
	}

	/**
	 * Register the custom sitemap renderer with Yoast.
	 */
	public function register(): void {
		global $wpseo_sitemaps;

		if ( ! $this->is_enabled() || ! is_object( $wpseo_sitemaps ) || ! method_exists( $wpseo_sitemaps, 'register_sitemap' ) ) {
			return;
		}

		$wpseo_sitemaps->register_sitemap( self::SITEMAP_NAME, array( $this, 'render' ) );
	}

	/**
	 * Add the Lomnio sitemap to the Yoast sitemap index.
	 */
	public function add_index_link( array $links ): array {
		if ( ! $this->is_enabled() || empty( $this->urls() ) ) {
			return $links;
		}

		$link = array(
			'loc' => home_url( '/' . self::SITEMAP_NAME . '-sitemap.xml' ),
		);
		$last_modified = $this->last_modified();

		if ( '' !== $last_modified ) {
			$link['lastmod'] = $last_modified;
		}

		$links[] = $link;

		return $links;
	}

	/**
	 * Render the custom sitemap through Yoast.
	 */
	public function render(): void {
		global $wpseo_sitemaps;

		if ( ! is_object( $wpseo_sitemaps ) || ! method_exists( $wpseo_sitemaps, 'set_sitemap' ) ) {
			return;
		}

		$xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $this->urls() as $url ) {
			$xml .= "\t<url><loc>" . esc_xml( $url ) . '</loc></url>' . "\n";
		}

		$xml .= '</urlset>';
		$wpseo_sitemaps->set_sitemap( $xml );
	}

	/**
	 * Get unique public URLs for all current Lomnio floors and units.
	 *
	 * @return string[]
	 */
	public function urls(): array {
		if ( ! $this->is_enabled() ) {
			return array();
		}

		$urls = array();

		foreach ( $this->floors->get_floors() as $floor ) {
			if ( isset( $floor->url ) && is_string( $floor->url ) && '' !== $floor->url ) {
				$urls[] = $floor->url;
			}
		}

		foreach ( $this->units->get_units() as $unit ) {
			if ( isset( $unit->url ) && is_string( $unit->url ) && '' !== $unit->url ) {
				$urls[] = trailingslashit( $unit->url );
			}
		}

		return array_values( array_unique( $urls ) );
	}

	private function is_enabled(): bool {
		return ! empty( $this->settings->get( 'enabled' ) );
	}

	private function last_modified(): string {
		$revision = (float) get_option( DataRevision::OPTION_NAME, 0 );

		return $revision > 0 ? gmdate( DATE_W3C, (int) $revision ) : '';
	}
}
