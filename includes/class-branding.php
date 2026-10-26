<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Send the login code email in the site's own voice.
 *
 * The default text says "Someone is trying to sign in", which is right for a
 * plugin and wrong for a bank, a members' club, or anything with a house style.
 * Sites that care about that also care that the mail comes from their own
 * address rather than wordpress@.
 */
final class Branding {

	public const OPTION = 'sigil_pro_branding';

	private static ?Branding $instance = null;

	public static function instance(): Branding {
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
		add_filter( 'sigil_challenge_text', array( $this, 'challenge_text' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'challenge_css' ), 20 );
		add_filter( 'sigil_email_subject', array( $this, 'subject' ), 10, 3 );
		add_filter( 'sigil_email_message', array( $this, 'message' ), 10, 4 );
		add_filter( 'sigil_email_from', array( $this, 'from' ), 10, 1 );
		add_filter( 'sigil_email_from_name', array( $this, 'from_name' ), 10, 1 );
	}

	/**
	 * @return array{subject: string, body: string, from: string, from_name: string, title: string, intro: string, css: string}
	 */
	public static function get(): array {
		$raw = \Sigil\Network::get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$out = array();
		foreach ( array( 'subject', 'body', 'from', 'from_name', 'title', 'intro', 'css' ) as $key ) {
			$out[ $key ] = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
		}

		return $out;
	}

	/**
	 * @param array<string, string> $values
	 */
	public static function update( array $values ): void {
		\Sigil\Network::update_option(
			self::OPTION,
			array(
				'subject'   => sanitize_text_field( $values['subject'] ?? '' ),
				// Plain-text mail, so tags would be read literally rather than rendered.
				'body'      => wp_strip_all_tags( (string) ( $values['body'] ?? '' ) ),
				'from'      => sanitize_email( $values['from'] ?? '' ),
				'from_name' => sanitize_text_field( $values['from_name'] ?? '' ),
				'title'     => sanitize_text_field( $values['title'] ?? '' ),
				'intro'     => sanitize_text_field( $values['intro'] ?? '' ),
				// Stylesheet, not markup. strip_tags keeps a </style> out of it,
				// which is the one thing that would turn CSS into script.
				'css'       => wp_strip_all_tags( (string) ( $values['css'] ?? '' ) ),
			)
		);
	}

	/**
	 * @param array<string, string> $text
	 * @return array<string, string>
	 */
	public function challenge_text( $text ) {
		if ( ! is_array( $text ) ) {
			return $text;
		}

		$brand = self::get();

		if ( '' !== $brand['title'] ) {
			$text['title'] = $brand['title'];
		}

		// The intro carries {{login}} for the username, substituted rather than
		// formatted, so a percent sign here is only a percent sign.
		if ( '' !== $brand['intro'] ) {
			$text['intro'] = $brand['intro'];
		}

		return $text;
	}

	public function challenge_css(): void {
		$css = self::get()['css'];
		if ( '' === $css ) {
			return;
		}

		wp_register_style( 'sigil-brand', false, array(), SIGIL_VERSION );
		wp_enqueue_style( 'sigil-brand' );
		wp_add_inline_style( 'sigil-brand', $css );
	}

	/**
	 * The placeholders a template may use, and what each stands for.
	 *
	 * @return array<string, string>
	 */
	public static function placeholders(): array {
		return array(
			'{{code}}' => __( 'The one-time code', 'sigil-2fa-pro' ),
			'{{site}}' => __( 'Site name', 'sigil-2fa-pro' ),
			'{{user}}' => __( 'Display name of the person signing in', 'sigil-2fa-pro' ),
			'{{login}}' => __( 'Their username', 'sigil-2fa-pro' ),
		);
	}

	/**
	 * @param string    $subject
	 * @param \WP_User  $user
	 * @param string    $site_name
	 */
	public function subject( $subject, $user, $site_name ): string {
		$custom = self::get()['subject'];

		return '' === $custom ? (string) $subject : self::render( $custom, $user, '', (string) $site_name );
	}

	/**
	 * @param string    $message
	 * @param \WP_User  $user
	 * @param string    $code
	 * @param string    $site_name
	 */
	public function message( $message, $user, $code, $site_name ): string {
		$custom = self::get()['body'];
		if ( '' === $custom ) {
			return (string) $message;
		}

		$rendered = self::render( $custom, $user, (string) $code, (string) $site_name );

		// A template that lost its placeholder would mail a code-less code
		// email, so fall back rather than send something useless.
		return false === strpos( $rendered, (string) $code ) ? (string) $message : $rendered;
	}

	/**
	 * @param string $from
	 */
	public function from( $from ): string {
		$custom = self::get()['from'];

		return '' === $custom ? (string) $from : $custom;
	}

	/**
	 * @param string $from_name
	 */
	public function from_name( $from_name ): string {
		$custom = self::get()['from_name'];

		return '' === $custom ? (string) $from_name : $custom;
	}

	/**
	 * @param \WP_User $user
	 */
	private static function render( string $template, $user, string $code, string $site_name ): string {
		$display = $user instanceof \WP_User ? $user->display_name : '';
		$login   = $user instanceof \WP_User ? $user->user_login : '';

		return strtr(
			$template,
			array(
				'{{code}}'  => $code,
				'{{site}}'  => $site_name,
				'{{user}}'  => $display,
				'{{login}}' => $login,
			)
		);
	}
}
