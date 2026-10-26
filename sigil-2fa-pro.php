<?php
/**
 * Plugin Name: Sigil Pro
 * Description: Method policy, branding, settings portability, password-reset protection, destinations, sign-in bypass and audit history for Sigil two-factor authentication.
 * Version: 0.5.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: sigil-2fa
 * Author: Jean Galea
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI: https://github.com/jgalea/sigil-2fa-pro
 * Text Domain: sigil-2fa-pro
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'SIGIL_PRO_VERSION', '0.5.0' );
define( 'SIGIL_PRO_FILE', __FILE__ );
define( 'SIGIL_PRO_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Boots once the free plugin says it is ready. Nothing from includes/ is loaded
 * before then, so a missing or outdated free plugin costs a notice, not a fatal.
 */
function sigil_pro_boot(): void {
	if ( class_exists( 'Sigil\Pro\License', false ) ) {
		// The old combined build already declared these classes.
		return;
	}
	require_once SIGIL_PRO_DIR . 'includes/loader.php';
}

if ( did_action( 'sigil_loaded' ) ) {
	sigil_pro_boot();
} else {
	add_action( 'sigil_loaded', 'sigil_pro_boot' );
}

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'sigil-2fa-pro', false, dirname( plugin_basename( SIGIL_PRO_FILE ) ) . '/languages' );
} );

// Plugins load alphabetically by folder, so this file runs before the free
// plugin's. By plugins_loaded the free plugin has had its chance to announce itself.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( did_action( 'sigil_loaded' ) ) {
			return;
		}

		// Worded when the notice prints: translating any earlier loads the text domain too soon.
		$show = static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			// The old combined build defined SIGIL_PRO and carried Pro inside the free plugin.
			if ( defined( 'SIGIL_PRO' ) && SIGIL_PRO ) {
				$message = __( 'Sigil Pro is now a separate add-on and cannot run beside the old combined build. Update the Sigil plugin to 0.5 or later, then reload this page.', 'sigil-2fa-pro' );
			} elseif ( defined( 'SIGIL_VERSION' ) ) {
				$message = __( 'Sigil Pro needs a newer version of the Sigil plugin. Update Sigil to 0.5 or later.', 'sigil-2fa-pro' );
			} else {
				$message = __( 'Sigil Pro needs the Sigil plugin. Install and activate Sigil (sigil-2fa), then reload this page.', 'sigil-2fa-pro' );
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		};
		add_action( 'admin_notices', $show );
		add_action( 'network_admin_notices', $show );
	},
	20
);
