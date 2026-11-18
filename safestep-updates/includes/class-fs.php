<?php
namespace SafeStep;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal native-PHP filesystem helpers. Symlinks are never followed.
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

	/** @return true|\WP_Error */
	public static function copy( string $src, string $dst ) {
		if ( is_link( $src ) ) {
			return new \WP_Error( 'safestep_symlink', 'Refusing to copy a symlink.' );
		}
		if ( is_file( $src ) ) {
			return @copy( $src, $dst ) ? true : new \WP_Error( 'safestep_copy', 'Could not copy file.' );
		}
		if ( ! is_dir( $src ) ) {
			return new \WP_Error( 'safestep_missing', 'Source does not exist.' );
		}
		if ( ! is_dir( $dst ) && ! wp_mkdir_p( $dst ) ) {
			return new \WP_Error( 'safestep_mkdir', 'Could not create destination.' );
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isLink() ) {
				continue;
			}
			$target = rtrim( $dst, '/\\' ) . '/' . $it->getSubPathname();
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
					return new \WP_Error( 'safestep_mkdir', 'Could not create ' . $target );
				}
			} elseif ( ! @copy( $item->getPathname(), $target ) ) {
				return new \WP_Error( 'safestep_copy', 'Could not copy ' . $item->getFilename() );
			}
		}
		return true;
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
