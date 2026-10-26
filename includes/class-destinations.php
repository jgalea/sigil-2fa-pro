<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Decide where people land after they finish setting up.
 *
 * The free plugin returns them to the screen they came from, which is right for
 * an administrator managing their own account and wrong for a membership site
 * that pushed a new member through enrolment on the way to somewhere else. This
 * sends them on once they actually hold a factor, and leaves them where they
 * were while they are still part-way through.
 */
final class Destinations {

	public const OPTION = 'sigil_pro_after_setup';

	private static ?Destinations $instance = null;

	public static function instance(): Destinations {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register();
	}

	/**
	 * Hook registration, separate from construction so it can be applied
	 * again. The test suite restores WordPress's hook table between tests,
	 * which leaves a singleton constructed in an earlier one holding its
	 * instance but none of its filters. add_filter is idempotent, so calling
	 * this twice in production changes nothing.
	 */
	public function register(): void {
		add_filter( 'sigil_enrol_redirect', array( $this, 'after_setup' ), 10, 4 );
	}

	public static function get(): string {
		$raw = \Sigil\Network::get_option( self::OPTION, '' );

		return is_string( $raw ) ? $raw : '';
	}

	public static function update( string $url ): void {
		\Sigil\Network::update_option( self::OPTION, esc_url_raw( $url ) );
	}

	/**
	 * @param string $url
	 * @param int    $user_id
	 * @param string $status
	 * @param string $code
	 */
	public function after_setup( $url, $user_id, $status, $code ): string {
		$destination = self::get();
		if ( '' === $destination || 'success' !== $status ) {
			return (string) $url;
		}

		// Removing a method is a success too, and sending someone away from the
		// screen they are managing would read as the page vanishing.
		if ( 'removed' === $code ) {
			return (string) $url;
		}

		// Backup codes are displayed once and never again, so the screen showing
		// them is the one place nobody should be redirected off.
		if ( 'enrolled_backup' === $code ) {
			return (string) $url;
		}

		// Only once they hold something usable. Part-way through, the setup
		// screen is still where they need to be.
		if ( ! \Sigil\Providers::instance()->has_usable( (int) $user_id ) ) {
			return (string) $url;
		}

		return $destination;
	}
}
