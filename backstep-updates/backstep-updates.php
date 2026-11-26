<?php
/**
 * Plugin Name:       Backstep Updates
 * Description:       Update plugins without fear: snapshot before every update, health-check the site afterwards, and roll back automatically if something breaks.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kailas Nath R
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       backstep-updates
 */

namespace Backstep;

defined( 'ABSPATH' ) || exit;

define( 'BACKSTEP_VERSION', '0.2.1' );
define( 'BACKSTEP_FILE', __FILE__ );

/*
 * Opcache flush endpoint, handled as early as possible (before other plugins load, so a
 * broken plugin cannot stop it). Only works with a one-time secret token that this site
 * generated moments ago; it does nothing else.
 */
if ( isset( $_GET['backstep_flush'] ) && is_string( $_GET['backstep_flush'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	$backstep_given = hash( 'sha256', sanitize_text_field( wp_unslash( $_GET['backstep_flush'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	// Each flush request uses its own one-time key, so a stale cached copy of an earlier key can never be reused.
	$backstep_key      = 'backstep_f_' . substr( $backstep_given, 0, 20 );
	$backstep_expected = get_transient( $backstep_key );
	if ( is_string( $backstep_expected ) && hash_equals( $backstep_expected, $backstep_given ) ) {
		delete_transient( $backstep_key );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		header( 'Content-Type: text/plain' );
		echo 'backstep-flushed';
		exit;
	}
}

require_once __DIR__ . '/includes/class-fs.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-log.php';
require_once __DIR__ . '/includes/class-snapshots.php';
require_once __DIR__ . '/includes/class-health.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-admin.php';

register_deactivation_hook( BACKSTEP_FILE, array( Updater::class, 'deactivate' ) );
Updater::init();
if ( is_admin() ) {
	Admin::init();
}
