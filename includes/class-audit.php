<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Show the record that was already being kept.
 *
 * Every reset and every excused sign-in is written to the account it happened
 * to, and until now nothing read it back. The question it answers is the one
 * asked after something goes wrong: who let this account past its second
 * factor, and when.
 *
 * Read-only by design. An audit trail that the interface can edit is not one.
 */
final class Audit {

	private const LOG = '_sigil_reset_log';

	private static ?Audit $instance = null;

	public static function instance(): Audit {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register();
	}

	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
	}

	/**
	 * @return list<array{actor: int, time: int, action: string}>
	 */
	public static function entries( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::LOG, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$out[] = array(
				'actor'  => isset( $entry['actor'] ) ? (int) $entry['actor'] : 0,
				'time'   => isset( $entry['time'] ) ? (int) $entry['time'] : 0,
				// Entries written before this existed are all resets.
				'action' => isset( $entry['action'] ) ? (string) $entry['action'] : 'reset',
			);
		}

		return $out;
	}

	/**
	 * @param \WP_User $user
	 */
	public function render( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$user_id = (int) $user->ID;

		// Whoever may edit the account may read its history. Anyone else viewing
		// this profile has no business with it.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$entries = self::entries( $user_id );
		if ( array() === $entries ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Two-factor history', 'sigil-2fa-pro' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'sigil-2fa-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'What', 'sigil-2fa-pro' ) . '</th>';
		echo '<th>' . esc_html__( 'By', 'sigil-2fa-pro' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::when( $entry['time'] ) ) . '</td>';
			echo '<td>' . esc_html( self::what( $entry['action'] ) ) . '</td>';
			echo '<td>' . esc_html( self::who( $entry['actor'] ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function when( int $time ): string {
		if ( $time <= 0 ) {
			return __( 'Unknown', 'sigil-2fa-pro' );
		}

		return sprintf(
			/* translators: %s: how long ago, e.g. "3 days" */
			__( '%s ago', 'sigil-2fa-pro' ),
			human_time_diff( $time, time() )
		);
	}

	private static function what( string $action ): string {
		if ( 'bypass' === $action ) {
			return __( 'A sign-in was excused', 'sigil-2fa-pro' );
		}

		return __( 'Methods were reset', 'sigil-2fa-pro' );
	}

	private static function who( int $actor_id ): string {
		if ( 0 === $actor_id ) {
			return __( 'Command line', 'sigil-2fa-pro' );
		}

		$actor = get_userdata( $actor_id );

		return $actor instanceof \WP_User ? $actor->user_login : __( 'A deleted account', 'sigil-2fa-pro' );
	}
}
