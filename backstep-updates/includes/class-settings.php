<?php
namespace Backstep;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'backstep_settings';
	const HELD   = 'backstep_held';

	public static function defaults(): array {
		return array(
			'extra_urls'   => '',
			'notify_email' => '',
			'keep'         => 3,
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = wp_parse_args( $saved, self::defaults() );
		return $out;
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = self::defaults();

		$lines = preg_split( '/\R/', (string) ( $in['extra_urls'] ?? '' ) );
		$keep  = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$keep[] = sanitize_text_field( $line );
			}
		}
		$out['extra_urls'] = implode( "\n", array_slice( $keep, 0, 10 ) );

		$email               = sanitize_email( (string) ( $in['notify_email'] ?? '' ) );
		$out['notify_email'] = is_email( $email ) ? $email : '';

		$out['keep'] = max( 1, min( 10, (int) ( $in['keep'] ?? 3 ) ) );

		return $out;
	}

	public static function notify_email(): string {
		$s = self::get();
		return $s['notify_email'] ? $s['notify_email'] : (string) get_option( 'admin_email' );
	}

	/* ---- "Held" versions: a version that was rolled back is not auto-retried. ---- */

	public static function held(): array {
		$h = get_option( self::HELD, array() );
		return is_array( $h ) ? $h : array();
	}

	public static function hold( string $plugin_file, string $version ): void {
		$h                 = self::held();
		$h[ $plugin_file ] = $version;
		update_option( self::HELD, $h, false );
	}

	public static function release( string $plugin_file ): void {
		$h = self::held();
		unset( $h[ $plugin_file ] );
		update_option( self::HELD, $h, false );
	}
}
