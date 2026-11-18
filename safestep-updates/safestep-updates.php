<?php
/**
 * Plugin Name:       SafeStep Updates
 * Description:       Update plugins without fear: snapshot before every update, health-check the site afterwards, and roll back automatically if something breaks.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       safestep-updates
 */

namespace SafeStep;

defined( 'ABSPATH' ) || exit;

define( 'SAFESTEP_VERSION', '0.2.0' );
define( 'SAFESTEP_FILE', __FILE__ );

/*
 * Opcache flush endpoint, handled as early as possible (before other plugins load, so a
 * broken plugin cannot stop it). Only works with a one-time secret token that this site
 * generated moments ago; it does nothing else.
 */
if ( isset( $_GET['safestep_flush'] ) && is_string( $_GET['safestep_flush'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
	$safestep_given = hash( 'sha256', sanitize_text_field( wp_unslash( $_GET['safestep_flush'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	// Each flush request uses its own one-time key, so a stale cached copy of an earlier key can never be reused.
	$safestep_key      = 'safestep_f_' . substr( $safestep_given, 0, 20 );
	$safestep_expected = get_transient( $safestep_key );
	if ( is_string( $safestep_expected ) && hash_equals( $safestep_expected, $safestep_given ) ) {
		delete_transient( $safestep_key );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		header( 'Content-Type: text/plain' );
		echo 'safestep-flushed';
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

register_deactivation_hook( SAFESTEP_FILE, array( Updater::class, 'deactivate' ) );
Updater::init();
if ( is_admin() ) {
	Admin::init();
}
