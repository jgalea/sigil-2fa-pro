<?php

declare( strict_types=1 );

class Test_Updater extends WP_UnitTestCase {

	private int $requests = 0;

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( defined( 'SIGIL_PRO_DIR' ), 'Sigil Pro is not loaded; nothing would be tested' );
		require_once SIGIL_PRO_DIR . 'includes/class-updater.php';
		delete_site_transient( \Sigil\Pro\Updater::TRANSIENT );
		$this->requests = 0;
	}

	public function tear_down(): void {
		delete_site_transient( \Sigil\Pro\Updater::TRANSIENT );
		parent::tear_down();
	}

	private function github( array $body, int $code = 200 ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body, $code ) {
				$this->assertSame( 'https://api.github.com/repos/jgalea/sigil-2fa-pro/releases/latest', $url );
				++$this->requests;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array( 'code' => $code, 'message' => '' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	private function release( string $tag ): array {
		return array(
			'tag_name' => $tag,
			'html_url' => 'https://github.com/jgalea/sigil-2fa-pro/releases/tag/' . $tag,
			'body'     => 'Notes',
			'assets'   => array(
				array( 'name' => 'other.zip', 'browser_download_url' => 'https://example.com/other.zip' ),
				array( 'name' => 'sigil-2fa-pro.zip', 'browser_download_url' => 'https://github.com/jgalea/sigil-2fa-pro/releases/download/' . $tag . '/sigil-2fa-pro.zip' ),
			),
		);
	}

	private function check( ?string $file = null, ?string $uri = null ) {
		return \Sigil\Pro\Updater::check_update(
			false,
			array( 'UpdateURI' => $uri ?? \Sigil\Pro\Updater::UPDATE_URI ),
			$file ?? plugin_basename( SIGIL_PRO_FILE )
		);
	}

	public function test_a_newer_release_is_offered(): void {
		$this->github( $this->release( 'v9.0.0' ) );
		$update = $this->check();
		$this->assertIsArray( $update );
		$this->assertSame( '9.0.0', $update['version'] );
		$this->assertSame( 'sigil-2fa-pro', $update['slug'] );
		$this->assertStringEndsWith( '/v9.0.0/sigil-2fa-pro.zip', $update['package'] );
	}

	public function test_the_installed_version_is_not_offered_again(): void {
		$this->github( $this->release( 'v' . SIGIL_PRO_VERSION ) );
		$this->assertFalse( $this->check() );
		$this->assertSame( 1, $this->requests, 'the release was fetched, so the false is a comparison and not a skipped check' );
	}

	public function test_an_older_release_is_not_offered(): void {
		$this->github( $this->release( 'v0.0.1' ) );
		$this->assertFalse( $this->check() );
	}

	public function test_an_api_error_fails_quietly(): void {
		$this->github( array( 'message' => 'rate limited' ), 403 );
		$this->assertFalse( $this->check() );
		$this->assertSame( 1, $this->requests );
		$this->assertFalse( $this->check(), 'and the failure is remembered, not retried' );
		$this->assertSame( 1, $this->requests );
	}

	public function test_a_release_without_the_zip_asset_is_ignored(): void {
		$release           = $this->release( 'v9.0.0' );
		$release['assets'] = array( array( 'name' => 'other.zip', 'browser_download_url' => 'https://example.com/other.zip' ) );
		$this->github( $release );
		$this->assertFalse( $this->check() );
	}

	public function test_another_plugin_is_left_alone(): void {
		$this->github( $this->release( 'v9.0.0' ) );
		$this->assertFalse( $this->check( 'other/other.php' ) );
		$this->assertSame( 0, $this->requests, 'no request is made for a plugin that is not ours' );
	}

	public function test_a_different_update_uri_is_ignored(): void {
		$this->github( $this->release( 'v9.0.0' ) );
		$this->assertFalse( $this->check( null, 'https://github.com/someone/else' ) );
		$this->assertSame( 0, $this->requests );
	}

	public function test_plugin_information_answers_for_this_slug_only(): void {
		$this->github( $this->release( 'v9.0.0' ) );
		$info = \Sigil\Pro\Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'sigil-2fa-pro' ) );
		$this->assertSame( '9.0.0', $info->version );
		$this->assertFalse( \Sigil\Pro\Updater::plugin_info( false, 'plugin_information', (object) array( 'slug' => 'sigil-2fa' ) ) );
	}

	public function test_the_update_check_is_hooked_for_github_hosted_plugins(): void {
		\Sigil\Pro\Updater::register();
		$this->assertNotFalse( has_filter( 'update_plugins_github.com', array( \Sigil\Pro\Updater::class, 'check_update' ) ) );
	}

	public function test_the_plugin_header_names_the_update_uri(): void {
		$data = get_plugin_data( SIGIL_PRO_FILE, false, false );
		$this->assertSame( \Sigil\Pro\Updater::UPDATE_URI, $data['UpdateURI'] );
	}
}
