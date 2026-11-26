<?php
namespace Backstep;

defined( 'ABSPATH' ) || exit;

/**
 * Small filesystem helpers (size, recursive delete). Symlinks are never followed.
 */
final class Fs {

	public static function size( string $path ): int {
		if ( is_link( $path ) ) {
			return 0;
		}
		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}
		$total = 0;
		if ( ! is_dir( $path ) ) {
			return 0;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$total += $file->getSize();
			}
		}
		return $total;
	}

	public static function rrmdir( string $dir ): bool {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			wp_delete_file( $dir );
			return ! file_exists( $dir ) && ! is_link( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- recursive cleanup of our own snapshot folders without needing filesystem credentials
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- see above
	}
}
