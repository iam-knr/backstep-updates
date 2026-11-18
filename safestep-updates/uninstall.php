<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-fs.php';
require_once __DIR__ . '/includes/class-snapshots.php';

\SafeStep\Snapshots::delete_all();

foreach ( array( 'safestep_settings', 'safestep_held', 'safestep_log', 'safestep_snapshots', 'safestep_dir' ) as $safestep_option ) {
	delete_option( $safestep_option );
}
