<?php

declare( strict_types=1 );

namespace Sigil\Pro;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-updater.php';
require_once __DIR__ . '/class-settings-page.php';
require_once __DIR__ . '/class-method-policy.php';
require_once __DIR__ . '/class-branding.php';
require_once __DIR__ . '/class-portability.php';
require_once __DIR__ . '/class-password-reset.php';
require_once __DIR__ . '/class-destinations.php';
require_once __DIR__ . '/class-bypass.php';
require_once __DIR__ . '/class-audit.php';

Updater::register();
Settings_Page::instance();

// Free's checks ask this; with no licence layer the answer is always yes.
add_filter( 'sigil_pro_is_active', '__return_true' );

// These only wire filters and actions that fire later (login, admin), so
// booting them here is safe whenever free called sigil_loaded.
Method_Policy::instance();
Branding::instance();
Password_Reset::instance();
Destinations::instance();
Bypass::instance();
Audit::instance();
