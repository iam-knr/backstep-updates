<?php
namespace Backstep;

defined( 'ABSPATH' ) || exit;

/**
 * Loopback health check. Each request runs in a fresh PHP process, so it sees
 * the newly installed plugin code, not the old code held in memory.
 */
final class Health {

	const MARKERS = array(
		'There has been a critical error',
		'Fatal error:',
		'Parse error:',
		'Uncaught Error',
		'Uncaught Exception',
	);

	public static function urls(): array {
		$urls = array( home_url( '/' ), wp_login_url() );

		foreach ( preg_split( '/\R/', (string) Settings::get()['extra_urls'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Only same-site URLs: this runs server-side, so never fetch arbitrary hosts.
			$url = ( 0 === strpos( $line, '/' ) ) ? home_url( $line ) : $line;
			if ( wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( (array) apply_filters( 'backstep_health_urls', $urls ) ) );
	}

	/**
	 * @return array<string,string> Failure key ("url|kind") => human description. Empty means healthy.
	 */
	public static function check(): array {
		$failures = array();
		foreach ( self::urls() as $url ) {
			$request = apply_filters( 'backstep_health_url', add_query_arg( 'backstep_check', (string) time(), $url ) );
			$res     = wp_remote_get(
				$request,
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
					'user-agent'  => 'Backstep/' . BACKSTEP_VERSION,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);

			// Keys are stable (URL + kind of failure) so error text that varies run to run
			// cannot make an existing problem look new.
			if ( is_wp_error( $res ) ) {
				$failures[ $url . '|unreachable' ] = $url . ' unreachable: ' . $res->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 500 ) {
				$failures[ $url . '|http5xx' ] = $url . ' returned HTTP ' . $code;
				continue;
			}
			$body = (string) wp_remote_retrieve_body( $res );
			foreach ( self::MARKERS as $marker ) {
				if ( false !== strpos( $body, $marker ) ) {
					$failures[ $url . '|fatal' ] = $url . ' shows "' . $marker . '"';
					break;
				}
			}
		}
		return $failures;
	}

	/**
	 * Health "before" picture. While WordPress is in maintenance mode every page answers 503,
	 * so a fresh measurement would be meaningless: use the last good measurement instead
	 * (empty if none, i.e. assume the site was healthy).
	 */
	public static function baseline(): array {
		if ( file_exists( ABSPATH . '.maintenance' ) ) {
			$cached = get_transient( 'backstep_baseline' );
			return is_array( $cached ) ? $cached : array();
		}
		$fresh = self::check();
		set_transient( 'backstep_baseline', $fresh, 12 * HOUR_IN_SECONDS );
		return $fresh;
	}

	/**
	 * Check after files changed on disk. The web server may still hold the old compiled
	 * code in opcache, which would make a broken update look healthy, so clear it first.
	 */
	public static function check_fresh(): array {
		self::$unverified = false;

		// WordPress's single-plugin updater fires its "done" hook while maintenance mode is still on,
		// so every page would answer 503. The files are already in place, so lift the flag for the
		// duration of the check and put it back exactly as it was.
		$flag    = ABSPATH . '.maintenance';
		$content = null;
		if ( file_exists( $flag ) ) {
			$content = @file_get_contents( $flag );
			if ( false !== $content ) {
				wp_delete_file( $flag );
			}
			if ( false === $content || file_exists( $flag ) ) {
				self::$unverified = true; // Cannot lift it: do not judge the site by 503s.
				return array();
			}
		}

		try {
			self::flush_web_opcache();
			return self::check();
		} finally {
			if ( null !== $content ) {
				@file_put_contents( $flag, $content ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- puts WordPress's own maintenance flag back exactly as it was
			}
		}
	}

	/** True when the last check_fresh() could not look at the site at all. */
	public static $unverified = false;

	private static function flush_web_opcache(): void {
		$token = wp_generate_password( 32, false );
		$hash  = hash( 'sha256', $token );
		$key   = 'backstep_f_' . substr( $hash, 0, 20 ); // Unique per request: see backstep-updates.php.
		set_transient( $key, $hash, 60 );
		$res = wp_remote_get(
			apply_filters( 'backstep_health_url', add_query_arg( 'backstep_flush', $token, home_url( '/' ) ) ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
			)
		);
		delete_transient( $key );
		$ok = ! is_wp_error( $res ) && 'backstep-flushed' === trim( (string) wp_remote_retrieve_body( $res ) );
		if ( ! $ok ) {
			// Could not reach the flush endpoint: wait out the default opcache revalidation window.
			sleep( (int) apply_filters( 'backstep_fallback_delay', 3 ) );
		}
	}

	/** Failures present now that were not present in the baseline. */
	public static function new_failures( array $baseline, array $after ): array {
		return array_diff_key( $after, $baseline );
	}
}
