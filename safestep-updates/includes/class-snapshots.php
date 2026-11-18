<?php
namespace SafeStep;

defined( 'ABSPATH' ) || exit;

/**
 * Stores copies of a plugin's files before it is updated, and restores them.
 *
 * Paths are never taken from request input: callers pass a plugin file and a
 * snapshot id, and both are looked up in the stored index before use.
 */
final class Snapshots {

	const INDEX   = 'safestep_snapshots';
	const DIR_OPT = 'safestep_dir';

	public static function base_dir(): string {
		$name = get_option( self::DIR_OPT );
		if ( ! $name ) {
			// Random suffix so the folder cannot be guessed on servers that ignore .htaccess.
			$name = 'safestep-' . strtolower( wp_generate_password( 14, false ) );
			update_option( self::DIR_OPT, $name, false );
		}
		$uploads = wp_upload_dir( null, false );
		$path    = trailingslashit( $uploads['basedir'] ) . $name;

		if ( ! is_dir( $path ) && wp_mkdir_p( $path ) ) {
			@file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents(
				$path . '/.htaccess',
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}
		return $path;
	}

	public static function index(): array {
		$i = get_option( self::INDEX, array() );
		return is_array( $i ) ? $i : array();
	}

	public static function for_plugin( string $plugin_file ): array {
		$i = self::index();
		return isset( $i[ $plugin_file ] ) && is_array( $i[ $plugin_file ] ) ? $i[ $plugin_file ] : array();
	}

	/** @return array|\WP_Error Snapshot entry. */
	public static function create( string $plugin_file, string $version ) {
		$src = self::plugin_path( $plugin_file );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		if ( ! file_exists( $src ) ) {
			return new \WP_Error( 'safestep_missing', 'Plugin files not found.' );
		}

		$max = (int) apply_filters( 'safestep_max_snapshot_bytes', 100 * MB_IN_BYTES );
		if ( Fs::size( $src ) > $max ) {
			return new \WP_Error( 'safestep_too_big', 'Plugin is larger than the snapshot size limit.' );
		}

		$id   = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 5, false ) );
		$rel  = md5( $plugin_file ) . '/' . $id;
		$dest = self::base_dir() . '/' . $rel;

		if ( ! wp_mkdir_p( $dest ) ) {
			return new \WP_Error( 'safestep_mkdir', 'Could not create snapshot folder.' );
		}

		// A single-file plugin (e.g. hello.php) is stored inside the snapshot folder.
		$ok = is_dir( $src )
			? Fs::copy( $src, $dest . '/plugin' )
			: Fs::copy( $src, $dest . '/' . basename( $src ) );
		if ( is_wp_error( $ok ) ) {
			Fs::rrmdir( $dest );
			return $ok;
		}

		$entry = array(
			'id'      => $id,
			'version' => $version,
			'time'    => time(),
			'path'    => $rel,
		);

		$index                  = self::index();
		$index[ $plugin_file ]  = self::for_plugin( $plugin_file );
		$index[ $plugin_file ][] = $entry;
		update_option( self::INDEX, $index, false );

		self::prune( $plugin_file );
		return $entry;
	}

	public static function prune( string $plugin_file ): void {
		$keep    = (int) Settings::get()['keep'];
		$index   = self::index();
		$entries = self::for_plugin( $plugin_file );
		while ( count( $entries ) > $keep ) {
			$old = array_shift( $entries );
			Fs::rrmdir( self::base_dir() . '/' . $old['path'] );
		}
		$index[ $plugin_file ] = $entries;
		update_option( self::INDEX, $index, false );
	}

	public static function find( string $plugin_file, string $id ): ?array {
		foreach ( self::for_plugin( $plugin_file ) as $e ) {
			if ( isset( $e['id'] ) && hash_equals( (string) $e['id'], $id ) ) {
				return $e;
			}
		}
		return null;
	}

	/** @return true|\WP_Error */
	public static function restore( string $plugin_file, string $id ) {
		$entry = self::find( $plugin_file, $id );
		if ( ! $entry ) {
			return new \WP_Error( 'safestep_nosnap', 'Snapshot not found.' );
		}
		$target = self::plugin_path( $plugin_file );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$snap = self::base_dir() . '/' . $entry['path'];
		if ( ! is_dir( $snap ) ) {
			return new \WP_Error( 'safestep_nosnap', 'Snapshot files are missing on disk.' );
		}

		if ( is_dir( $snap . '/plugin' ) ) {
			// Directory plugin: move current aside, copy snapshot in, drop the old copy.
			$aside = $target . '.safestep-old';
			if ( file_exists( $aside ) ) {
				Fs::rrmdir( $aside );
			}
			if ( file_exists( $target ) && ! @rename( $target, $aside ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-directory swap so the old copy can be put back on failure
				return new \WP_Error( 'safestep_rename', 'Plugins folder is not writable.' );
			}
			$ok = Fs::copy( $snap . '/plugin', $target );
			if ( is_wp_error( $ok ) ) {
				Fs::rrmdir( $target );
				if ( file_exists( $aside ) ) {
					@rename( $aside, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- put things back as they were
				}
				return $ok;
			}
			Fs::rrmdir( $aside );
		} else {
			$ok = Fs::copy( $snap . '/' . basename( $target ), $target );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		return true;
	}

	/**
	 * Absolute path of a plugin's folder (or file, for single-file plugins),
	 * guaranteed to be inside the plugins directory.
	 *
	 * @return string|\WP_Error
	 */
	private static function plugin_path( string $plugin_file ) {
		if ( '' === $plugin_file || 0 !== validate_file( $plugin_file ) || false !== strpos( $plugin_file, '..' ) ) {
			return new \WP_Error( 'safestep_badpath', 'Invalid plugin path.' );
		}
		$dir = dirname( $plugin_file );
		$rel = ( '.' === $dir ) ? $plugin_file : $dir;
		if ( ! preg_match( '/^[A-Za-z0-9._-]+(\.php)?$/', $rel ) ) {
			return new \WP_Error( 'safestep_badpath', 'Unsupported plugin path.' );
		}
		return rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . $rel;
	}

	public static function delete_all(): void {
		$name = get_option( self::DIR_OPT );
		if ( $name ) {
			$uploads = wp_upload_dir( null, false );
			Fs::rrmdir( trailingslashit( $uploads['basedir'] ) . $name );
		}
	}
}
