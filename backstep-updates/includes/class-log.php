<?php
namespace Backstep;

defined( 'ABSPATH' ) || exit;

final class Log {

	const OPTION = 'backstep_log';
	const MAX    = 200;

	/**
	 * @param string $status ok|rolled_back|rollback_failed|snapshot_failed|manual_restore|manual_restore_failed
	 */
	public static function add( string $plugin_file, string $status, string $from, string $to, string $message = '' ): void {
		$log   = self::all();
		$log[] = array(
			'time'    => time(),
			'plugin'  => $plugin_file,
			'status'  => $status,
			'from'    => $from,
			'to'      => $to,
			'message' => $message,
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
	}

	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
