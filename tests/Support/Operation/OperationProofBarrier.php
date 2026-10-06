<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

/** Disposable process barriers contain names only, never commands or credentials. */
final class OperationProofBarrier {

	public static function directory(): string {
		$path = sys_get_temp_dir() . '/cetech-operation-proof-' . getmypid() . '-' . bin2hex( random_bytes( 5 ) );
		if ( ! mkdir( $path, 0700 ) ) { throw new \RuntimeException( 'Operation proof barrier could not be created.' ); }
		return $path;
	}

	public static function signal( string $path ): void {
		if ( false === file_put_contents( $path, 'ready', LOCK_EX ) ) { throw new \RuntimeException( 'Operation proof barrier signal failed.' ); }
	}

	public static function pause( string $ready, string $release ): void {
		self::signal( $ready );
		OperationProofProcess::wait_for( $release, 12.0 );
	}

	public static function cleanup( string $directory ): void {
		if ( ! is_dir( $directory ) ) { return; }
		if ( ! str_starts_with( basename( $directory ), 'cetech-operation-proof-' ) ) { throw new \RuntimeException( 'Unsafe proof barrier cleanup refused.' ); }
		foreach ( scandir( $directory ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) { continue; }
			$path = $directory . '/' . $item;
			if ( is_file( $path ) ) { unlink( $path ); }
		}
		rmdir( $directory );
	}
}
