<?php
namespace Backstep;

defined( 'ABSPATH' ) || exit;

/**
 * Stores copies of a plugin's files before it is updated, and restores them.
 *
 * Paths are never taken from request input: callers pass a plugin file and a
 * snapshot id, and both are looked up in the stored index before use.
 */
final class Snapshots {

	const INDEX   = 'backstep_snapshots';

	public static function base_dir(): string {
		$name = 'backstep-updates'; // Folder named after the plugin slug, inside uploads.
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
			return new \WP_Error( 'backstep_missing', 'Plugin files not found.' );
		}

		if ( ! is_dir( $src ) ) {
			return new \WP_Error( 'backstep_single_file', 'Single-file plugins are not snapshotted.' );
		}

		$max = (int) apply_filters( 'backstep_max_snapshot_bytes', 100 * MB_IN_BYTES );
		if ( Fs::size( $src ) > $max ) {
			return new \WP_Error( 'backstep_too_big', 'Plugin is larger than the snapshot size limit.' );
		}

		$id   = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 5, false ) );
		$dir  = self::base_dir() . '/' . md5( $plugin_file );
		$rel  = md5( $plugin_file ) . '/' . $id . '.zip';
		$dest = self::base_dir() . '/' . $rel;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'backstep_mkdir', 'Could not create snapshot folder.' );
		}

		// Stored as a zip archive (data, not runnable code). Entries are rooted at the plugin folder
		// (or the single file), so restoring is a plain unzip into the plugins directory.
		$ok = self::zip_plugin( $src, $dest );
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
			return new \WP_Error( 'backstep_nosnap', 'Snapshot not found.' );
		}
		$target = self::plugin_path( $plugin_file );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$zip = self::base_dir() . '/' . $entry['path'];
		if ( ! is_file( $zip ) ) {
			return new \WP_Error( 'backstep_nosnap', 'Snapshot file is missing on disk.' );
		}

		if ( ! class_exists( '\Plugin_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		// Restore through WordPress's own plugin installer, which handles file permissions and credentials.
		// The current copy is moved aside first so it can be put back if the restore fails.
		$aside = $target . '.backstep-old';
		if ( file_exists( $aside ) ) {
			Fs::rrmdir( $aside );
		}
		if ( ! @rename( $target, $aside ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-directory swap so the old copy can be put back on failure
			return new \WP_Error( 'backstep_rename', 'Plugins folder is not writable.' );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->init();
		$upgrader->upgrade_strings();
		$ok = $upgrader->run(
			array(
				'package'                     => $zip,
				'destination'                 => WP_PLUGIN_DIR,
				'clear_destination'           => true,
				'clear_working'               => true,
				'abort_if_destination_exists' => false,
				'is_multi'                    => true,
				'hook_extra'                  => array(
					'type'   => 'plugin',
					'action' => 'restore',
					// Core's updater hooks that are still attached while an update finishes expect this key.
					'plugin' => $plugin_file,
				),
			)
		);
		if ( is_wp_error( $ok ) || ! $ok || ! is_dir( $target ) ) {
			Fs::rrmdir( $target );
			@rename( $aside, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- put things back as they were
			return is_wp_error( $ok ) ? $ok : new \WP_Error( 'backstep_restore', 'Could not restore the snapshot.' );
		}
		Fs::rrmdir( $aside );

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function zip_plugin( string $src, string $zip_file ) {
		$base  = dirname( $src );
		$files = array();
		if ( is_dir( $src ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $item ) {
				if ( $item->isLink() ) {
					continue;
				}
				$name = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $base ) ) ), '/' );
				$files[ $name ] = $item->isDir() ? null : $item->getPathname();
			}
			$files[ basename( $src ) . '/' ] = null;
		} else {
			$files[ basename( $src ) ] = $src;
		}

		if ( class_exists( '\ZipArchive' ) ) {
			$z = new \ZipArchive();
			if ( true !== $z->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'backstep_zip', 'Could not create the snapshot archive.' );
			}
			foreach ( $files as $name => $path ) {
				if ( null === $path ) {
					$z->addEmptyDir( rtrim( $name, '/' ) );
				} else {
					$z->addFile( $path, $name );
				}
			}
			return $z->close() ? true : new \WP_Error( 'backstep_zip', 'Could not write the snapshot archive.' );
		}

		// Fallback for servers without the zip extension.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$list = array();
		foreach ( $files as $name => $path ) {
			if ( null !== $path ) {
				$list[] = array( PCLZIP_ATT_FILE_NAME => $path, PCLZIP_ATT_FILE_NEW_FULL_NAME => $name );
			}
		}
		$z = new \PclZip( $zip_file );
		return ( 0 !== $z->create( $list ) ) ? true : new \WP_Error( 'backstep_zip', 'Could not create the snapshot archive.' );
	}

	/**
	 * Absolute path of a plugin's folder (or file, for single-file plugins),
	 * guaranteed to be inside the plugins directory.
	 *
	 * @return string|\WP_Error
	 */
	private static function plugin_path( string $plugin_file ) {
		if ( '' === $plugin_file || 0 !== validate_file( $plugin_file ) || false !== strpos( $plugin_file, '..' ) ) {
			return new \WP_Error( 'backstep_badpath', 'Invalid plugin path.' );
		}
		$dir = dirname( $plugin_file );
		$rel = ( '.' === $dir ) ? $plugin_file : $dir;
		if ( ! preg_match( '/^[A-Za-z0-9._-]+(\.php)?$/', $rel ) ) {
			return new \WP_Error( 'backstep_badpath', 'Unsupported plugin path.' );
		}
		return rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . $rel;
	}

	public static function delete_all(): void {
		$uploads = wp_upload_dir( null, false );
		Fs::rrmdir( trailingslashit( $uploads['basedir'] ) . 'backstep-updates' );
	}
}
