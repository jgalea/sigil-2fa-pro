<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Pro admin surface: the controls for the Pro features, added under
 * the free plugin's Settings → Sigil screen via admin-post routes.
 */
final class Settings_Page {

	private const PAGE          = 'sigil-2fa';
	private const SAVE_METHODS  = 'sigil_pro_save_methods';
	private const SAVE_BRANDING = 'sigil_pro_save_branding';
	private const EXPORT        = 'sigil_pro_export_settings';
	private const IMPORT        = 'sigil_pro_import_settings';
	private const SAVE_AFTER    = 'sigil_pro_save_after_setup';

	private static ?Settings_Page $instance = null;

	public static function instance(): Settings_Page {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'sigil_settings_after', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::SAVE_METHODS, array( $this, 'save_methods' ) );
		add_action( 'admin_post_' . self::SAVE_BRANDING, array( $this, 'save_branding' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::IMPORT, array( $this, 'import' ) );
		add_action( 'admin_post_' . self::SAVE_AFTER, array( $this, 'save_after_setup' ) );
	}

	public function render(): void {
		// The same capability the free settings ask for. These options are
		// stored network-wide on a network, so manage_options, which every
		// single-site administrator holds, would let one of them set policy for
		// all the others.
		if ( ! current_user_can( \Sigil\Network::manage_capability() ) ) {
			return;
		}

		$this->render_notice();

		$url = admin_url( 'admin-post.php' );
		echo '<hr /><h2>' . esc_html__( 'Sigil Pro', 'sigil-2fa-pro' ) . '</h2>';

		$this->render_methods( $url );
		$this->render_branding( $url );
		$this->render_after_setup( $url );
		$this->render_portability( $url );
	}

	private function render_methods( string $url ): void {
		$roles     = wp_roles()->roles;
		$providers = \Sigil\Providers::instance()->all();
		$policy    = Method_Policy::get();

		echo '<h3>' . esc_html__( 'Allowed methods', 'sigil-2fa-pro' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Unticked methods cannot be enrolled or used by that role. A role with nothing ticked keeps every method, so a misconfiguration cannot lock anyone out.', 'sigil-2fa-pro' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $url ) . '">';
		wp_nonce_field( self::SAVE_METHODS );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_METHODS ) . '" />';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Role', 'sigil-2fa-pro' ) . '</th>';
		foreach ( $providers as $provider ) {
			echo '<th>' . esc_html( $provider->label() ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $roles as $slug => $role ) {
			$slug = sanitize_key( (string) $slug );
			echo '<tr><td>' . esc_html( translate_user_role( (string) ( $role['name'] ?? $slug ) ) ) . '</td>';
			foreach ( $providers as $provider ) {
				$id      = $provider->id();
				$checked = ! isset( $policy[ $slug ][ $id ] ) || $policy[ $slug ][ $id ];
				echo '<td><input type="checkbox" name="methods[' . esc_attr( $slug ) . '][' . esc_attr( $id ) . ']" value="1"' . checked( $checked, true, false ) . ' /></td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save methods', 'sigil-2fa-pro' ) . '</button></p>';
		echo '</form>';
	}

	private function render_branding( string $url ): void {
		$brand = Branding::get();

		echo '<h3>' . esc_html__( 'Wording and appearance', 'sigil-2fa-pro' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Leave a field empty to keep the default. Placeholders:', 'sigil-2fa-pro' ) . ' ';
		$parts = array();
		foreach ( Branding::placeholders() as $tag => $meaning ) {
			$parts[] = '<code>' . esc_html( $tag ) . '</code> ' . esc_html( $meaning );
		}
		echo wp_kses_post( implode( ', ', $parts ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( $url ) . '">';
		wp_nonce_field( self::SAVE_BRANDING );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_BRANDING ) . '" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="e2fa-subject">' . esc_html__( 'Subject', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><input type="text" id="e2fa-subject" name="subject" class="large-text" value="' . esc_attr( $brand['subject'] ) . '" /></td></tr>';
		echo '<tr><th><label for="e2fa-from">' . esc_html__( 'From address', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><input type="email" id="e2fa-from" name="from" class="regular-text" value="' . esc_attr( $brand['from'] ) . '" /></td></tr>';
		echo '<tr><th><label for="e2fa-from-name">' . esc_html__( 'From name', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><input type="text" id="e2fa-from-name" name="from_name" class="regular-text" value="' . esc_attr( $brand['from_name'] ) . '" /></td></tr>';
		echo '<tr><th><label for="e2fa-title">' . esc_html__( 'Challenge heading', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><input type="text" id="e2fa-title" name="title" class="regular-text" value="' . esc_attr( $brand['title'] ) . '" /></td></tr>';
		echo '<tr><th><label for="e2fa-intro">' . esc_html__( 'Challenge intro', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><input type="text" id="e2fa-intro" name="intro" class="large-text" value="' . esc_attr( $brand['intro'] ) . '" />';
		echo '<p class="description">' . esc_html__( '{{login}} is replaced with the username.', 'sigil-2fa-pro' ) . '</p></td></tr>';
		echo '<tr><th><label for="e2fa-css">' . esc_html__( 'Challenge screen CSS', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><textarea id="e2fa-css" name="css" rows="6" class="large-text code">' . esc_textarea( $brand['css'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="e2fa-body">' . esc_html__( 'Email body', 'sigil-2fa-pro' ) . '</label></th>';
		echo '<td><textarea id="e2fa-body" name="body" rows="8" class="large-text code">' . esc_textarea( $brand['body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'A body without {{code}} is ignored and the default is sent, so nobody receives a code-less code email.', 'sigil-2fa-pro' ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save email', 'sigil-2fa-pro' ) . '</button></p>';
		echo '</form>';
	}

	private function render_after_setup( string $url ): void {
		echo '<h3>' . esc_html__( 'After setup', 'sigil-2fa-pro' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Where someone lands once they have set up their first method. Leave empty to return them to the screen they came from. Ignored while backup codes are on display, since those are shown only once.', 'sigil-2fa-pro' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $url ) . '">';
		wp_nonce_field( self::SAVE_AFTER );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_AFTER ) . '" />';
		echo '<input type="url" name="after_setup" class="large-text" placeholder="' . esc_attr( home_url( '/' ) ) . '" value="' . esc_attr( Destinations::get() ) . '" /> ';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save destination', 'sigil-2fa-pro' ) . '</button></p>';
		echo '</form>';
	}

	private function render_portability( string $url ): void {
		echo '<h3>' . esc_html__( 'Import and export', 'sigil-2fa-pro' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Settings only. No accounts, credentials or secrets are included, so an exported file is safe to move between sites.', 'sigil-2fa-pro' ) . '</p>';

		echo '<form method="post" action="' . esc_url( $url ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( self::EXPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Export settings', 'sigil-2fa-pro' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( $url ) . '" enctype="multipart/form-data" style="display:inline-block">';
		wp_nonce_field( self::IMPORT );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT ) . '" />';
		echo '<input type="file" name="settings_file" accept="application/json,.json" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Import settings', 'sigil-2fa-pro' ) . '</button>';
		echo '</form>';
	}

	public function save_methods(): void {
		$this->guard( self::SAVE_METHODS );

		$roles     = array_keys( wp_roles()->roles );
		$providers = \Sigil\Providers::instance()->all();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read key by key below.
		$posted = isset( $_POST['methods'] ) && is_array( $_POST['methods'] ) ? wp_unslash( $_POST['methods'] ) : array();

		// Unticked boxes are absent from the POST, so every cell is written
		// explicitly or clearing one would never stick.
		$policy = array();
		foreach ( $roles as $role ) {
			$role = sanitize_key( (string) $role );
			foreach ( $providers as $provider ) {
				$id                    = $provider->id();
				$policy[ $role ][ $id ] = ! empty( $posted[ $role ][ $id ] );
			}
		}

		$kept = Method_Policy::without_empty_roles( $policy );

		Method_Policy::update( $kept );

		// Dropping a row silently leaves the screen showing every box ticked
		// again, which reads as the save having failed. Say which roles were
		// left alone and why.
		$dropped = array_diff( array_keys( $policy ), array_keys( $kept ) );

		$this->back( array() === $dropped ? '' : 'methods_all_denied' );
	}

	public function save_branding(): void {
		$this->guard( self::SAVE_BRANDING );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Branding::update() sanitizes each field.
		Branding::update(
			array(
				'subject'   => isset( $_POST['subject'] ) ? wp_unslash( (string) $_POST['subject'] ) : '',
				'body'      => isset( $_POST['body'] ) ? wp_unslash( (string) $_POST['body'] ) : '',
				'from'      => isset( $_POST['from'] ) ? wp_unslash( (string) $_POST['from'] ) : '',
				'from_name' => isset( $_POST['from_name'] ) ? wp_unslash( (string) $_POST['from_name'] ) : '',
				'title'     => isset( $_POST['title'] ) ? wp_unslash( (string) $_POST['title'] ) : '',
				'intro'     => isset( $_POST['intro'] ) ? wp_unslash( (string) $_POST['intro'] ) : '',
				'css'       => isset( $_POST['css'] ) ? wp_unslash( (string) $_POST['css'] ) : '',
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$this->back();
	}

	public function save_after_setup(): void {
		$this->guard( self::SAVE_AFTER );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Destinations::update() runs esc_url_raw().
		Destinations::update( isset( $_POST['after_setup'] ) ? wp_unslash( (string) $_POST['after_setup'] ) : '' );
		$this->back();
	}

	public function export(): void {
		$this->guard( self::EXPORT );

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = sanitize_file_name( ( is_string( $host ) ? $host : 'site' ) . '-2fa-settings.json' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		echo wp_json_encode( Portability::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function import(): void {
		$this->guard( self::IMPORT );

		$tmp = isset( $_FILES['settings_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( (string) $_FILES['settings_file']['tmp_name'] ) ) : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->back( 'import_missing' );
		}

		$size = isset( $_FILES['settings_file']['size'] ) ? (int) $_FILES['settings_file']['size'] : 0;
		if ( $size <= 0 || $size > 256 * KB_IN_BYTES ) {
			$this->back( 'import_size' );
		}

		$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an upload, not a remote resource.
		$result   = Portability::import( is_string( $contents ) ? $contents : '' );

		if ( is_wp_error( $result ) ) {
			$this->back( $result->get_error_code() );
		}

		// "Enabled" on its own decides nothing: a policy naming no role and no
		// capability is switched on and applies to nobody. Refusing to import it
		// would be inconsistent, since the settings screen can save that state
		// too, so it is reported instead. What the import promises is that it
		// will not turn the protection off without saying so.
		$this->back( \Sigil\Policy::get()['enabled'] && ! self::policy_covers_anyone() ? 'imported_covers_nobody' : 'imported' );
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( \Sigil\Network::manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'sigil-2fa-pro' ) );
		}
		check_admin_referer( $action );
	}

	private static function policy_covers_anyone(): bool {
		$policy = \Sigil\Policy::get();

		if ( '' !== (string) $policy['min_capability'] ) {
			return true;
		}

		foreach ( (array) $policy['roles'] as $wanted ) {
			if ( ! empty( $wanted ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Say what happened after a save or an import.
	 *
	 * These codes were being put in the URL and read by nobody, so an import
	 * that was refused looked exactly like one that worked.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag, no action taken on it.
		$code = isset( $_GET['sigil_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['sigil_pro_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'imported'                     => array( 'success', __( 'Settings imported.', 'sigil-2fa-pro' ) ),
			'methods_all_denied'           => array(
				'warning',
				__( 'Methods saved. A role with nothing ticked would leave those accounts unable to set up any second factor while the policy still required one, so those roles keep every method.', 'sigil-2fa-pro' ),
			),
			'imported_covers_nobody'       => array(
				'warning',
				__( 'Settings imported. Two-factor authentication is switched on but the imported policy names no roles and no capability, so it currently applies to nobody.', 'sigil-2fa-pro' ),
			),
			'sigil_pro_import_unreadable'  => array( 'error', __( 'That file is not valid JSON, so nothing was changed.', 'sigil-2fa-pro' ) ),
			'sigil_pro_import_foreign'     => array( 'error', __( 'That file was not exported from this plugin, so nothing was changed.', 'sigil-2fa-pro' ) ),
			'sigil_pro_import_format'      => array( 'error', __( 'That file was written by a different version and cannot be read, so nothing was changed.', 'sigil-2fa-pro' ) ),
			'sigil_pro_import_upload'      => array( 'error', __( 'That file could not be read, so nothing was changed.', 'sigil-2fa-pro' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	private function back( string $notice = '' ): void {
		// The free plugin owns this screen and moved it, so ask it where it is
		// rather than keeping a second copy of the answer here.
		$url = class_exists( '\Sigil\Network' )
			? \Sigil\Network::settings_url()
			: admin_url( 'options-general.php?page=sigil-2fa' );

		if ( '' !== $notice ) {
			$url = add_query_arg( 'sigil_pro_notice', $notice, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
