<?php

declare( strict_types=1 );

class Test_Activation extends WP_UnitTestCase {

	public function test_features_run_without_any_licence(): void {
		$this->assertTrue( defined( 'SIGIL_PRO_DIR' ), 'Sigil Pro is not loaded; nothing would be tested' );
		$this->assertFalse( class_exists( 'Sigil\Pro\License', false ), 'the licence layer is gone' );
		delete_option( 'sigil_pro_license' );
		$this->assertTrue( (bool) apply_filters( 'sigil_pro_is_active', false ) );
		$this->assertTrue( class_exists( 'Sigil\Pro\Method_Policy', false ) );
		$this->assertTrue( class_exists( 'Sigil\Pro\Bypass', false ) );
	}

	public function test_a_saved_licence_option_changes_nothing(): void {
		update_option( 'sigil_pro_license', array( 'key' => 'x', 'status' => 'invalid' ) );
		$this->assertTrue( (bool) apply_filters( 'sigil_pro_is_active', false ) );
		delete_option( 'sigil_pro_license' );
	}
}
