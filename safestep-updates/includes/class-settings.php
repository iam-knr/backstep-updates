<?php
namespace SafeStep;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'safestep_settings';
	const HELD   = 'safestep_held';
	const MODES  = array( 'off', 'all' );

	public static function defaults(): array {
		return array(
			'mode'         => 'off', // off | all.
			'excluded'     => array(),
			'extra_urls'   => '',
			'notify_email' => '',
			'keep'         => 3,
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = wp_parse_args( $saved, self::defaults() );
		// Settings saved by 0.1.0 used a checkbox called auto_update.
		if ( ! isset( $saved['mode'] ) && ! empty( $saved['auto_update'] ) ) {
			$out['mode'] = 'all';
		}
		return $out;
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$old = self::get();
		$out = self::defaults();

		$mode        = isset( $in['mode'] ) ? sanitize_key( $in['mode'] ) : 'off';
		$out['mode'] = in_array( $mode, self::MODES, true ) ? $mode : 'off';

		$out['excluded'] = array();
		if ( ! empty( $in['excluded'] ) && is_array( $in['excluded'] ) ) {
			foreach ( $in['excluded'] as $file ) {
				$file = sanitize_text_field( wp_unslash( $file ) );
				if ( '' !== $file && 0 === validate_file( $file ) ) {
					$out['excluded'][] = $file;
				}
			}
		}

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
