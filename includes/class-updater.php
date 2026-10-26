<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Updates the add-on from the latest release of its GitHub repository.
 *
 * WordPress routes plugins whose Update URI header names github.com through the
 * update_plugins_github.com filter, so WordPress.org never answers for this slug.
 */
final class Updater {

	public const REPO       = 'jgalea/sigil-2fa-pro';
	public const ASSET      = 'sigil-2fa-pro.zip';
	public const UPDATE_URI = 'https://github.com/jgalea/sigil-2fa-pro';
	public const TRANSIENT  = 'sigil_pro_release';

	public static function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( self::class, 'clear_cache' ), 10, 2 );
	}

	/** @return array{version:string,package:string,url:string,notes:string}|null */
	public static function latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return is_array( $cached ) && $cached ? $cached : null;
		}

		$release  = array();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			foreach ( (array) ( is_array( $data ) ? ( $data['assets'] ?? array() ) : array() ) as $asset ) {
				if ( self::ASSET === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) && ! empty( $data['tag_name'] ) ) {
					$release = array(
						'version' => ltrim( (string) $data['tag_name'], 'v' ),
						'package' => (string) $asset['browser_download_url'],
						'url'     => (string) ( $data['html_url'] ?? self::UPDATE_URI ),
						'notes'   => (string) ( $data['body'] ?? '' ),
					);
					break;
				}
			}
		}

		// A failure is remembered for an hour so a GitHub outage is not retried on every admin load.
		set_site_transient( self::TRANSIENT, $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $release ? $release : null;
	}

	/**
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @return array|false
	 */
	public static function check_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( SIGIL_PRO_FILE ) !== $plugin_file || self::UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release || ! version_compare( $release['version'], SIGIL_PRO_VERSION, '>' ) ) {
			return $update;
		}

		return array(
			'slug'    => dirname( plugin_basename( SIGIL_PRO_FILE ) ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || dirname( plugin_basename( SIGIL_PRO_FILE ) ) !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Sigil Pro',
			'slug'          => dirname( plugin_basename( SIGIL_PRO_FILE ) ),
			'version'       => $release['version'],
			'homepage'      => self::UPDATE_URI,
			'download_link' => $release['package'],
			'requires'      => '6.9',
			'requires_php'  => '7.4',
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( $release['notes'] ) ) ),
		);
	}

	public static function clear_cache( $upgrader, $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_site_transient( self::TRANSIENT );
		}
	}
}
