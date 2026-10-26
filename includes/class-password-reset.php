<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Require a second factor to complete a password reset.
 *
 * Without this, two-factor authentication protects the front door and leaves the
 * window open: whoever reads the account's inbox requests a reset link, sets a
 * new password, and signs in as them. The second factor is never asked for,
 * because from WordPress's point of view nothing failed.
 *
 * A passkey cannot be presented on the reset form, so the check accepts any code
 * the account holds. The free plugin issues backup codes at first enrolment, so
 * a passkey-only account still has something to type here.
 */
final class Password_Reset {

	private const FIELD = 'sigil_pro_reset_code';

	private static ?Password_Reset $instance = null;

	public static function instance(): Password_Reset {
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
		add_action( 'resetpass_form', array( $this, 'render_field' ) );
		add_action( 'validate_password_reset', array( $this, 'validate' ), 10, 2 );
	}

	/**
	 * @param \WP_User|\WP_Error $user
	 */
	public function render_field( $user ): void {
		if ( ! $user instanceof \WP_User || ! self::applies_to( (int) $user->ID ) ) {
			return;
		}

		// Send one, if email is the only thing this account can answer with.
		// Without this the form demanded a code that such an account had no way
		// to obtain: the email provider only accepts a code minted by a login
		// challenge, and someone who has forgotten their password cannot reach
		// one. The form was a dead end, and the way out people take is switching
		// the protection off.
		self::maybe_send_code( (int) $user->ID );

		echo '<p><label for="' . esc_attr( self::FIELD ) . '">';
		echo esc_html__( 'Two-factor code', 'sigil-2fa-pro' );
		echo '</label><input type="text" name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" class="input" size="20" autocomplete="one-time-code" inputmode="text" autocapitalize="characters" spellcheck="false" /></p>';
		echo '<p class="description">' . esc_html__( 'Enter a code from your authenticator app, your email, or one of your backup codes.', 'sigil-2fa-pro' ) . '</p>';
	}

	/**
	 * @param \WP_Error         $errors
	 * @param \WP_User|\WP_Error $user
	 */
	public function validate( $errors, $user ): void {
		if ( ! $errors instanceof \WP_Error || ! $user instanceof \WP_User ) {
			return;
		}

		$user_id = (int) $user->ID;
		if ( ! self::applies_to( $user_id ) ) {
			return;
		}

		// This action also fires when the form is merely displayed. Only a
		// submitted new password is an attempt to complete the reset.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core validates the reset key for this request; this only detects which stage it is.
		if ( ! isset( $_POST['pass1'] ) || '' === $_POST['pass1'] ) {
			return;
		}

		$user_key = 'reset:u:' . $user_id;
		if ( \Sigil\Rate_Limit::blocked( $user_key ) ) {
			$errors->add(
				'sigil_pro_reset_rate_limited',
				esc_html__( 'Too many incorrect codes. Please wait before trying again.', 'sigil-2fa-pro' )
			);
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- providers validate their own input; the reset key is core's proof of intent.
		$code = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD ] ) ) : '';

		if ( '' === $code ) {
			$errors->add(
				'sigil_pro_reset_code_missing',
				esc_html__( 'Enter your two-factor code to set a new password.', 'sigil-2fa-pro' )
			);
			return;
		}

		// Reserved here rather than above, so submitting the form with the field
		// left empty is not counted as a guess.
		if ( \Sigil\Rate_Limit::reserve( $user_key ) > \Sigil\Rate_Limit::max_attempts() ) {
			$errors->add(
				'sigil_pro_reset_rate_limited',
				esc_html__( 'Too many incorrect codes. Please wait before trying again.', 'sigil-2fa-pro' )
			);
			return;
		}

		if ( ! self::code_matches( $user_id, $code ) ) {
			$errors->add(
				'sigil_pro_reset_code_invalid',
				esc_html__( 'That two-factor code was not correct.', 'sigil-2fa-pro' )
			);
			return;
		}

		\Sigil\Rate_Limit::clear( $user_key );
	}

	/**
	 * Issue an email code when that is the only method the account can use here.
	 *
	 * Cooldown-guarded by the provider, so a reloaded form does not send again
	 * and does not invalidate the code already in the inbox.
	 */
	private static function maybe_send_code( int $user_id ): void {
		foreach ( \Sigil\Providers::instance()->enrolled_for( $user_id ) as $provider ) {
			// Anything that is not passkey or email can already answer this form.
			if ( 'passkey' !== $provider->id() && 'email' !== $provider->id() ) {
				return;
			}
		}

		$email = \Sigil\Providers::instance()->get( 'email' );
		if ( $email instanceof \Sigil\Providers\Email ) {
			$email->send_code( $user_id );
		}
	}

	/**
	 * Only guard accounts that hold a factor this form can actually take.
	 *
	 * A passkey cannot be typed into a text field, so an account holding nothing
	 * but passkeys has no answer to give. Asking anyway sent it a code that
	 * could never be accepted, because the code is checked against the methods
	 * the account has enrolled and email was not one of them: a form that
	 * refuses every possible entry, on the screen someone reaches precisely
	 * because they have lost their way in. Letting that reset through is the
	 * lesser harm; the link came from the mailbox, and the alternative is an
	 * account nobody can recover and an administrator switching the protection
	 * off to get them back.
	 */
	private static function applies_to( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		foreach ( \Sigil\Providers::instance()->enrolled_for( $user_id ) as $provider ) {
			if ( 'passkey' !== $provider->id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Try the code against every method the account holds that takes one.
	 *
	 * A passkey has no code to type, so it is skipped rather than failed, and
	 * the account's backup codes carry the check instead.
	 */
	private static function code_matches( int $user_id, string $code ): bool {
		foreach ( \Sigil\Providers::instance()->enrolled_for( $user_id ) as $provider ) {
			if ( 'passkey' === $provider->id() ) {
				continue;
			}

			if ( $provider->validate( $user_id, array( 'code' => $code ) ) ) {
				return true;
			}
		}

		return false;
	}
}
