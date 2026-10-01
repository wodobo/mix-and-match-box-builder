<?php
/**
 * Public GitHub release updates for Mix & Match Box Builder.
 *
 * Uses WordPress 5.8+ Update URI support. Only an explicitly attached,
 * version-matched plugin ZIP can be offered for installation.
 */

 defined( 'ABSPATH' ) || exit;

final class PPBB_GitHub_Updates {
	private const REPOSITORY_URL = 'https://github.com/wodobo/mix-and-match-box-builder';
	private const API_URL        = 'https://api.github.com/repos/wodobo/mix-and-match-box-builder/releases/latest';
	private const PACKAGE_PREFIX = 'mix-and-match-box-builder-by-wodobo-labs-';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_for_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
	}

	/** Only inspect our own plugin; other plugins may also use github.com. */
	public static function check_for_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( PPBB_FILE ) !== $plugin_file || self::REPOSITORY_URL !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
			return $update;
		}

		$release = self::get_latest_release();
		if ( ! $release ) {
			return $update;
		}

		$version = self::release_version( $release );
		if ( ! $version || ! version_compare( $version, $plugin_data['Version'], '>' ) ) {
			return $update;
		}

		$package = self::release_package( $release, $version );
		if ( ! $package ) {
			// Do not fall back to GitHub's source-code ZIP; it has a different folder name.
			return $update;
		}

		return array(
			'version'      => $version,
			'slug'         => 'picky-plate-box-builder',
			'url'          => self::REPOSITORY_URL . '/releases',
			'package'      => $package,
			'requires_php' => '7.4',
			'autoupdate'   => false,
		);
	}

	/** Display release notes using WordPress's normal "View details" window. */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'picky-plate-box-builder' !== $args->slug ) {
			return $result;
		}

		$release = self::get_latest_release();
		$version = $release ? self::release_version( $release ) : false;
		$package = $version ? self::release_package( $release, $version ) : false;
		if ( ! $version || ! $package ) {
			return $result;
		}

		$notes = isset( $release['body'] ) && is_string( $release['body'] ) ? $release['body'] : '';

		return (object) array(
			'name'          => 'Mix & Match Box Builder by Wodobo Labs',
			'slug'          => 'picky-plate-box-builder',
			'version'       => $version,
			'author'        => '<a href="https://wodobolabs.com/">Wodobo Labs</a>',
			'homepage'      => self::REPOSITORY_URL,
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'download_link' => $package,
			'sections'      => array(
				'description' => 'A storefront presentation layer that augments the official WooCommerce Mix and Match Products extension.',
				'changelog'   => $notes ? nl2br( esc_html( $notes ) ) : 'See the GitHub Releases page for release notes.',
			),
		);
	}

	private static function get_latest_release() {
		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WodoboLabs-MixAndMatchBoxBuilder/' . PPBB_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $release ) && empty( $release['draft'] ) && empty( $release['prerelease'] ) ? $release : false;
	}

	private static function release_version( $release ) {
		if ( ! isset( $release['tag_name'] ) || ! is_string( $release['tag_name'] ) ) {
			return false;
		}

		$version = preg_replace( '/^v/i', '', $release['tag_name'] );
		return preg_match( '/^\d+\.\d+\.\d+$/', $version ) ? $version : false;
	}

	private static function release_package( $release, $version ) {
		$expected = self::PACKAGE_PREFIX . $version . '.zip';
		foreach ( $release['assets'] ?? array() as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['name'] ?? '' ) !== $expected ) {
				continue;
			}
			$url = $asset['browser_download_url'] ?? '';
			if ( ! is_string( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'github.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
				continue;
			}
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( ! is_string( $path ) || 0 !== strpos( $path, '/wodobo/mix-and-match-box-builder/releases/download/' ) || basename( $path ) !== $expected ) {
				continue;
			}
			return $url;
		}
		return false;
	}
}
