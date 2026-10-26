<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Move a configuration between sites.
 *
 * Anyone running more than one site sets the same policy repeatedly and gets it
 * subtly different each time, which is how one site ends up not requiring 2FA
 * for editors. This exports settings only: no user, no credential, no secret,
 * and nothing that would let an import hand someone else's second factor to a
 * different site.
 */
final class Portability {

	private const FORMAT = 1;

	/**
	 * @return array<string, mixed>
	 */
	public static function export(): array {
		$payload = array(
			'format'   => self::FORMAT,
			'plugin'   => 'sigil-2fa',
			'exported' => gmdate( 'c' ),
			'policy'   => \Sigil\Policy::get(),
			'methods'  => Method_Policy::get(),
			'branding' => Branding::get(),
			'trust'    => array(
				'days' => \Sigil\Trusted_Devices::trust_days(),
			),
			'after_setup' => Destinations::get(),
			'zero_setup'  => \Sigil\Zero_Setup::is_enabled(),
		);

		/**
		 * Filter the exported configuration.
		 *
		 * Anything added here travels to another site verbatim, so nothing
		 * user-specific or secret belongs in it.
		 *
		 * @param array<string, mixed> $payload
		 */
		return (array) apply_filters( 'sigil_pro_export', $payload );
	}

	public static function to_json(): string {
		$json = wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function import( string $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'sigil_pro_import_unreadable', __( 'That file is not valid JSON.', 'sigil-2fa-pro' ) );
		}

		if ( ( $data['plugin'] ?? '' ) !== 'sigil-2fa' ) {
			return new \WP_Error( 'sigil_pro_import_foreign', __( 'That file was not exported from this plugin.', 'sigil-2fa-pro' ) );
		}

		if ( (int) ( $data['format'] ?? 0 ) !== self::FORMAT ) {
			return new \WP_Error( 'sigil_pro_import_format', __( 'That file was written by a different version and cannot be read.', 'sigil-2fa-pro' ) );
		}

		// Importing a policy that requires 2FA is safe; importing one that turns
		// it off silently is not what an administrator expects from "import
		// settings", so enabling is honoured and disabling is left alone.
		//
		// This covers the flag only. A policy can be switched on and still name
		// no role and no capability, which applies to nobody, so the screen
		// reports that case rather than leaving "enabled" to imply coverage it
		// does not have.
		if ( isset( $data['policy'] ) && is_array( $data['policy'] ) ) {
			$policy = $data['policy'];
			if ( empty( $policy['enabled'] ) ) {
				unset( $policy['enabled'] );
			}
			\Sigil\Policy::update( $policy );
		}

		if ( isset( $data['methods'] ) && is_array( $data['methods'] ) ) {
			Method_Policy::update( $data['methods'] );
		}

		if ( isset( $data['branding'] ) && is_array( $data['branding'] ) ) {
			Branding::update( $data['branding'] );
		}

		if ( isset( $data['zero_setup'] ) ) {
			\Sigil\Zero_Setup::set_enabled( (bool) $data['zero_setup'] );
		}

		if ( isset( $data['after_setup'] ) && is_string( $data['after_setup'] ) ) {
			Destinations::update( $data['after_setup'] );
		}

		if ( isset( $data['trust']['days'] ) ) {
			\Sigil\Network::update_option( \Sigil\Trusted_Devices::OPTION_DAYS, max( 1, min( 365, (int) $data['trust']['days'] ) ) );
		}

		/**
		 * Fires after a configuration has been imported.
		 *
		 * @param array<string, mixed> $data The imported payload.
		 */
		do_action( 'sigil_pro_imported', $data );

		return true;
	}
}
