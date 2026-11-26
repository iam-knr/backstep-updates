<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-fs.php';
require_once __DIR__ . '/includes/class-snapshots.php';

\Backstep\Snapshots::delete_all();

foreach ( array( 'backstep_settings', 'backstep_held', 'backstep_log', 'backstep_snapshots' ) as $backstep_option ) {
	delete_option( $backstep_option );
}
