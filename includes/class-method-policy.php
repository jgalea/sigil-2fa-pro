<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Restrict which second factors a role may use.
 *
 * The free plugin decides who must enrol. This decides what they are allowed to
 * enrol with, which is how an organisation says "administrators use passkeys,
 * nobody uses email codes" rather than leaving the weakest option on the table
 * for the account that matters most.
 *
 * Enforced where a method is chosen, not where one is used.
 *
 * Filtering the provider registry during authentication was worse than no
 * policy at all. A user whose only method the policy forbade ended up with no
 * usable factor, and the login path cannot tell that apart from an account that
 * never set one up, so it skipped the challenge and let the password alone
 * through. Tightening the policy switched two-factor off for exactly the people
 * it was tightened against.
 *
 * So: the rule refuses enrolment, and hides disallowed methods from the
 * enrolment screen. Somebody already holding a forbidden method keeps being
 * challenged with it, and enforcement asks them to add an allowed one. The
 * policy tightens as people migrate, and never by removing a factor from
 * someone at the moment they are trying to prove who they are.
 */
final class Method_Policy {

	public const OPTION = 'sigil_pro_method_policy';

	private static ?Method_Policy $instance = null;

	public static function instance(): Method_Policy {
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
		add_filter( 'sigil_can_enrol', array( $this, 'can_enrol' ), 10, 3 );
		add_filter( 'sigil_providers', array( $this, 'filter_providers' ), 10, 2 );
	}

	/**
	 * @param bool   $allowed
	 * @param int    $user_id
	 * @param string $provider_id
	 */
	public function can_enrol( $allowed, $user_id, $provider_id ): bool {
		if ( ! $allowed ) {
			return false;
		}

		return self::allowed_for( (int) $user_id, (string) $provider_id );
	}

	/**
	 * @return array<string, array<string, bool>> Role slug => provider id => allowed.
	 */
	public static function get(): array {
		$raw = \Sigil\Network::get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $role => $methods ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role || ! is_array( $methods ) ) {
				continue;
			}

			foreach ( $methods as $provider_id => $allowed ) {
				$provider_id = sanitize_key( (string) $provider_id );
				if ( '' !== $provider_id ) {
					$clean[ $role ][ $provider_id ] = (bool) $allowed;
				}
			}
		}

		return $clean;
	}

	/**
	 * Forget roles that ended up with nothing ticked.
	 *
	 * The settings screen writes every cell, so unticking a whole row stores it
	 * as all-denied, and that role could then enrol nothing at all while
	 * enforcement went on insisting it enrol something: a locked-out user made
	 * by a screen that promises the opposite. An empty row is a role with no
	 * rule, which is what the screen says it is.
	 *
	 * A row that names one method and denies it is left alone. That is a rule.
	 *
	 * For the settings screen only. An import carries whatever the exporting
	 * site had, which may rule on a subset: two methods denied and the rest
	 * unmentioned is a deny-list, and dropping it would grant back exactly the
	 * methods it was written to refuse.
	 *
	 * @param array<string, array<string, bool>> $policy
	 * @return array<string, array<string, bool>>
	 */
	public static function without_empty_roles( array $policy ): array {
		foreach ( $policy as $role => $methods ) {
			if ( is_array( $methods ) && count( $methods ) > 1 && ! in_array( true, $methods, true ) ) {
				unset( $policy[ $role ] );
			}
		}

		return $policy;
	}

	/**
	 * @param array<string, array<string, bool>> $policy
	 */
	public static function update( array $policy ): void {
		\Sigil\Network::update_option( self::OPTION, $policy );
	}

	/**
	 * A method is allowed unless every role the user holds disallows it, so
	 * adding a role can widen what someone may use but never narrows it below
	 * what another of their roles permits.
	 */
	public static function allowed_for( int $user_id, string $provider_id ): bool {
		$policy = self::get();
		if ( array() === $policy ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || array() === (array) $user->roles ) {
			return true;
		}

		$ruled = false;
		foreach ( (array) $user->roles as $role ) {
			$role = sanitize_key( (string) $role );
			if ( ! isset( $policy[ $role ][ $provider_id ] ) ) {
				continue;
			}

			$ruled = true;
			if ( $policy[ $role ][ $provider_id ] ) {
				return true;
			}
		}

		return ! $ruled;
	}

	/**
	 * @param array<string, \Sigil\Provider> $providers
	 * @return array<string, \Sigil\Provider>
	 */
	/**
	 * Hide disallowed methods from the enrolment screen.
	 *
	 * Presentational. Authentication never consults this: it once did, by
	 * identifying the challenged user from $_REQUEST, which both skipped the
	 * challenge for some accounts and quietly failed to apply at all on the REST
	 * route, where the token arrives in a JSON body instead.
	 *
	 * Applied to the account being asked about, not to whoever is looking. The
	 * users list builds this for every row while an administrator is the current
	 * user, so keying on the viewer answered the wrong question and showed a
	 * subscriber as having nothing set up because the viewer's own role was not
	 * allowed the method the subscriber holds.
	 *
	 * @param array<string, \Sigil\Provider> $providers
	 * @param int                            $user_id
	 * @return array<string, \Sigil\Provider>
	 */
	public function filter_providers( $providers, $user_id = 0 ) {
		$user_id = (int) $user_id;
		if ( ! is_array( $providers ) || $user_id <= 0 ) {
			return $providers;
		}

		$held    = \Sigil\Store::methods( $user_id );
		$allowed = array();
		foreach ( $providers as $id => $provider ) {
			// A method the account already holds stays visible, so it can still
			// be seen and removed rather than becoming invisible and permanent.
			if ( self::allowed_for( $user_id, (string) $id ) || isset( $held[ $id ] ) ) {
				$allowed[ $id ] = $provider;
			}
		}

		// Nothing left is not a state the settings screen can save any more,
		// since a role with nothing ticked is dropped rather than stored as a
		// denial of everything. Should one arrive by another route, showing the
		// full list is the harmless answer: enrolment is decided by can_enrol,
		// not by what is on screen.
		return array() === $allowed ? $providers : $allowed;
	}

}
