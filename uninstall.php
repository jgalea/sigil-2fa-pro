<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Earlier releases stored a licence key. Nothing reads it any more.
delete_option( 'sigil_pro_license' );
delete_site_option( 'sigil_pro_license' );
delete_site_transient( 'sigil_pro_release' );
