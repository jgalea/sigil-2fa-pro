<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Let an administrator excuse one sign-in for someone who has lost their device.
 *
 * This is a hole, opened deliberately, because the alternative people reach for
 * is worse: without it the practical answer to "I am locked out and my backup
 * codes are gone" is to turn the policy off for the whole site, and that stays
 * off. Resetting the account is the other route, and it is the better one when
 * the factor is gone for good; this is for when it is coming back.
 *
 * It is therefore built to be as small a hole as possible. One sign-in, not a
 * window. Expires on its own. Consumed the moment it is used, before the session
 * exists. Never granted to yourself. Written to the same audit log as a reset.
 */
final class Bypass {

	private const META  = '_sigil_pro_bypass';
	private const TTL   = 900;
	private const LOG   = '_sigil_reset_log';
	private const NONCE = 'sigil_pro_grant_bypass_';

	private static ?Bypass $instance = null;

	public static function instance(): Bypass {
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
		add_filter( 'sigil_skip_challenge', array( $this, 'maybe_skip' ), 20, 2 );

		// The grant is spent here rather than in the filter above. That filter
		// is a documented extension point, so anything asking "would this person
		// be challenged?" would otherwise burn a one-time excuse just by asking,
		// and the person it was granted to would still be locked out. wp_login
		// fires once, after the challenge has decided, and only for a sign-in
		// that actually happened.
		add_action( 'wp_login', array( $this, 'spend' ), 20, 2 );
		add_filter( 'user_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_post_sigil_pro_grant_bypass', array( $this, 'grant' ) );
	}

	/**
	 * Who may excuse a sign-in: the same people WordPress trusts to edit that
	 * account, and never for themselves. An administrator who could excuse their
	 * own next login has no second factor at all, only a slower one.
	 */
	public static function can_grant( int $actor_id, int $user_id ): bool {
		if ( $actor_id <= 0 || $user_id <= 0 || $actor_id === $user_id ) {
			return false;
		}

		return user_can( $actor_id, 'edit_users' ) && user_can( $actor_id, 'edit_user', $user_id );
	}

	public static function grant_for( int $user_id, int $actor_id ): bool {
		if ( ! self::can_grant( $actor_id, $user_id ) ) {
			return false;
		}

		update_user_meta(
			$user_id,
			self::META,
			array(
				'expires' => time() + self::TTL,
				'actor'   => $actor_id,
			)
		);

		self::log( $user_id, $actor_id );

		return true;
	}

	public static function has_grant( int $user_id ): bool {
		$grant = get_user_meta( $user_id, self::META, true );

		return is_array( $grant ) && isset( $grant['expires'] ) && (int) $grant['expires'] > time();
	}

	public static function revoke( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
	}

	/**
	 * @param bool     $skip
	 * @param \WP_User $user
	 */
	public function maybe_skip( $skip, $user ): bool {
		if ( $skip || ! $user instanceof \WP_User ) {
			return (bool) $skip;
		}

		return null !== self::live_grant( (int) $user->ID );
	}

	/**
	 * @param string   $user_login
	 * @param \WP_User $user
	 */
	public function spend( $user_login, $user = null ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$grant = self::live_grant( (int) $user->ID );
		if ( null === $grant ) {
			return;
		}

		// Deleting on the exact value is what makes this one-time under two
		// simultaneous sign-ins. delete_user_meta reads and then deletes, so the
		// read is not the serialisation point: both racers can see the same row.
		// The DELETE is, because it reports rows affected, so the second one
		// deletes nothing and returns false. Removing the value argument as a
		// redundant-looking read would quietly break that.
		delete_user_meta( (int) $user->ID, self::META, $grant );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function live_grant( int $user_id ): ?array {
		$grant = get_user_meta( $user_id, self::META, true );

		if ( ! is_array( $grant ) || ! isset( $grant['expires'] ) || (int) $grant['expires'] <= time() ) {
			return null;
		}

		return $grant;
	}

	/**
	 * @param array<string, string> $actions
	 * @param \WP_User              $user
	 * @return array<string, string>
	 */
	public function row_action( $actions, $user ) {
		if ( ! is_array( $actions ) || ! $user instanceof \WP_User ) {
			return $actions;
		}

		$user_id = (int) $user->ID;
		if ( ! self::can_grant( (int) get_current_user_id(), $user_id ) ) {
			return $actions;
		}

		if ( ! \Sigil\Providers::instance()->has_usable( $user_id ) ) {
			return $actions;
		}

		if ( self::has_grant( $user_id ) ) {
			$actions['sigil_pro_bypass'] = '<span>' . esc_html__( 'Next sign-in excused', 'sigil-2fa-pro' ) . '</span>';

			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sigil_pro_grant_bypass&user_id=' . $user_id ),
			self::NONCE . $user_id
		);

		$actions['sigil_pro_bypass'] = '<a href="' . esc_url( $url ) . '">'
			. esc_html__( 'Excuse next sign-in', 'sigil-2fa-pro' ) . '</a>';

		return $actions;
	}

	public function grant(): void {
		$user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;

		check_admin_referer( self::NONCE . $user_id );

		if ( ! self::grant_for( $user_id, (int) get_current_user_id() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to excuse a sign-in for this user.', 'sigil-2fa-pro' ),
				'',
				array( 'response' => 403 )
			);
		}

		wp_safe_redirect( add_query_arg( 'sigil_pro_notice', 'bypass_granted', admin_url( 'users.php' ) ) );
		exit;
	}

	/**
	 * The same log a reset writes to, because both answer the same question
	 * later: who let this account past its second factor, and when.
	 */
	private static function log( int $user_id, int $actor_id ): void {
		$log = get_user_meta( $user_id, self::LOG, true );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'actor'  => $actor_id,
				'time'   => time(),
				'action' => 'bypass',
			)
		);

		update_user_meta( $user_id, self::LOG, array_slice( $log, 0, 50 ) );
	}
}
