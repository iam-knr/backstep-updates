<?php
namespace SafeStep;

defined( 'ABSPATH' ) || exit;

final class Updater {

	/** @var array<string,array> Plugin file => snapshot + old version, for the update running in this request. */
	private static $pending = array();

	/** @var string[]|null Health failures measured before the first update of this request. */
	private static $baseline = null;

	public static function init(): void {
		add_filter( 'auto_update_plugin', array( __CLASS__, 'filter_auto_update' ), 20, 2 );
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'before_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 20, 2 );

		// Keep a recent "site was fine" reference so the check works even during maintenance mode.
		add_action( 'safestep_refresh_baseline', array( Health::class, 'baseline' ) );
		if ( ! wp_next_scheduled( 'safestep_refresh_baseline' ) ) {
			wp_schedule_event( time() + 60, 'twicedaily', 'safestep_refresh_baseline' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'safestep_refresh_baseline' );
	}

	/** Opt-in auto-updates, minus excluded plugins and versions we already rolled back. */
	public static function filter_auto_update( $update, $item ) {
		$settings = Settings::get();
		if ( 'off' === $settings['mode'] || ! is_object( $item ) || empty( $item->plugin ) ) {
			return $update;
		}
		if ( in_array( $item->plugin, $settings['excluded'], true ) ) {
			return false;
		}
		$held = Settings::held();
		if ( isset( $held[ $item->plugin ], $item->new_version ) && $held[ $item->plugin ] === $item->new_version ) {
			return false;
		}
		if ( 'all' === $settings['mode'] ) {
			return true;
		}
		return $update;
	}

	/**
	 * Runs just before WordPress replaces a plugin's files.
	 *
	 * @param bool|\WP_Error $response
	 * @param array          $extra    Upgrader hook_extra.
	 */
	public static function before_install( $response, $extra ) {
		// Single updates pass type+action; bulk updates (WP-CLI, Updates screen) pass only 'plugin'.
		// Fresh installs never carry a 'plugin' key, so its presence means "updating this plugin".
		if ( is_wp_error( $response ) || ! is_array( $extra ) || empty( $extra['plugin'] ) || ( isset( $extra['type'] ) && 'plugin' !== $extra['type'] ) ) {
			return $response;
		}
		$file = (string) $extra['plugin'];
		if ( plugin_basename( SAFESTEP_FILE ) === $file ) {
			return $response; // Never snapshot ourselves mid-update.
		}

		if ( null === self::$baseline ) {
			self::$baseline = Health::baseline();
		}

		$from = self::version_of( $file );
		$snap = Snapshots::create( $file, $from );
		if ( is_wp_error( $snap ) ) {
			// Fail open: a missing snapshot must not block an update.
			Log::add( $file, 'snapshot_failed', $from, '', $snap->get_error_message() );
			return $response;
		}

		self::$pending[ $file ] = array(
			'snapshot' => $snap,
			'from'     => $from,
		);
		return $response;
	}

	/**
	 * @param \WP_Upgrader $upgrader
	 * @param array        $extra
	 */
	public static function after_update( $upgrader, $extra ): void {
		if ( ! self::is_plugin_update( $extra ) ) {
			return;
		}
		$files = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		$files = array_values( array_filter( $files, static function ( $f ) {
			return isset( self::$pending[ $f ] );
		} ) );
		if ( ! $files ) {
			return;
		}

		wp_clean_plugins_cache( true );
		$baseline = (array) self::$baseline;
		$new      = Health::new_failures( $baseline, Health::check_fresh() );

		if ( ! $new ) {
			$note = Health::$unverified ? 'Updated; could not check the site (maintenance mode could not be lifted).' : 'Updated; site healthy.';
			foreach ( $files as $f ) {
				$p = self::$pending[ $f ];
				Log::add( $f, 'ok', $p['from'], self::version_of( $f ), $note );
				unset( self::$pending[ $f ] );
			}
			return;
		}

		// Something broke. Roll back one plugin at a time and stop as soon as the site is healthy again,
		// so a bulk update does not revert more than necessary.
		$rolled_back = array();
		foreach ( $files as $f ) {
			$p      = self::$pending[ $f ];
			$to     = self::version_of( $f );
			$result = Snapshots::restore( $f, $p['snapshot']['id'] );

			if ( is_wp_error( $result ) ) {
				Log::add( $f, 'rollback_failed', $p['from'], $to, $result->get_error_message() );
				self::notify( $f, $p['from'], $to, $new, false );
				unset( self::$pending[ $f ] );
				continue;
			}

			Settings::hold( $f, $to );
			Log::add( $f, 'rolled_back', $p['from'], $to, 'Site check failed: ' . implode( '; ', $new ) );
			$rolled_back[ $f ] = array( $p['from'], $to );
			unset( self::$pending[ $f ] );

			$new = Health::new_failures( $baseline, Health::check_fresh() );
			if ( ! $new ) {
				break;
			}
		}

		foreach ( $rolled_back as $f => $v ) {
			self::notify( $f, $v[0], $v[1], array(), true );
		}
		// Anything still pending after an early stop was left updated and the site is healthy.
		foreach ( self::$pending as $f => $p ) {
			if ( in_array( $f, $files, true ) ) {
				Log::add( $f, 'ok', $p['from'], self::version_of( $f ), 'Updated; site healthy after another plugin was rolled back.' );
				unset( self::$pending[ $f ] );
			}
		}
	}

	private static function is_plugin_update( $extra ): bool {
		return is_array( $extra )
			&& ( $extra['type'] ?? '' ) === 'plugin'
			&& ( $extra['action'] ?? '' ) === 'update';
	}

	private static function version_of( string $plugin_file ): string {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $path, false, false );
		return (string) ( $data['Version'] ?? '' );
	}

	private static function notify( string $file, string $from, string $to, array $failures, bool $restored ): void {
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$name = ( isset( $all[ $file ]['Name'] ) && '' !== $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $file;
		$subject = $restored
			? sprintf( '[%s] SafeStep rolled back %s', $site, $name )
			: sprintf( '[%s] SafeStep could NOT roll back %s', $site, $name );
		$body    = $restored
			? sprintf( "Updating %s from %s to %s broke the site, so SafeStep restored %s.\nThis version will not be retried automatically.", $name, $from, $to, $from )
			: sprintf( "Updating %s from %s to %s broke the site and the automatic rollback failed.\nRestore it manually from Tools > SafeStep.\n\nProblems:\n%s", $name, $from, $to, implode( "\n", $failures ) );
		wp_mail( Settings::notify_email(), $subject, $body );
	}
}
