<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

/** Fresh OS process with bounded, payload-free barriers and reviewed JSON output. */
final class OperationProofProcess {

	private mixed $process;
	private array $pipes = [];
	private ?int $exit_code = null;

	public function __construct( array $command ) {
		if ( ! function_exists( 'proc_open' ) ) { throw new \RuntimeException( 'Operation proof requires subprocess support.' ); }
		$php = (string) ( getenv( 'CETECH_DE_OPERATION_PHP' ) ?: PHP_BINARY );
		$this->process = proc_open( [ $php, ...$command ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $this->pipes );
		if ( ! is_resource( $this->process ) ) { throw new \RuntimeException( 'Operation proof subprocess could not start.' ); }
		fclose( $this->pipes[0] );
		stream_set_blocking( $this->pipes[1], false );
		stream_set_blocking( $this->pipes[2], false );
	}

	public static function wait_for( string $file, float $seconds = 8.0 ): void {
		$deadline = microtime( true ) + $seconds;
		do {
			clearstatcache( true, $file );
			if ( is_file( $file ) ) { return; }
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		throw new \RuntimeException( 'Operation proof barrier was not reached.' );
	}

	public function pid(): int {
		$status = proc_get_status( $this->process );
		return (int) $status['pid'];
	}

	public function kill(): void {
		if ( is_resource( $this->process ) ) { proc_terminate( $this->process, 9 ); }
	}

	public function kill_and_wait(): int {
		$this->kill();
		$deadline = microtime( true ) + 3.0;
		do {
			$status = proc_get_status( $this->process );
			if ( ! $status['running'] ) {
				$signal = (int) ( $status['termsig'] ?? 0 );
				foreach ( $this->pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
				proc_close( $this->process ); $this->process = null;
				return $signal;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		throw new \RuntimeException( 'Operation proof subprocess did not stop.' );
	}

	public function finish( float $seconds = 10.0 ): array {
		$deadline = microtime( true ) + $seconds;
		$output = '';
		$error = '';
		do {
			$output .= (string) stream_get_contents( $this->pipes[1] );
			$error .= (string) stream_get_contents( $this->pipes[2] );
			$status = proc_get_status( $this->process );
			if ( ! $status['running'] ) { $this->exit_code = (int) $status['exitcode']; break; }
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		if ( null === $this->exit_code ) { $this->kill(); throw new \RuntimeException( 'Operation proof subprocess exceeded its bound.' ); }
		$output .= (string) stream_get_contents( $this->pipes[1] );
		$error .= (string) stream_get_contents( $this->pipes[2] );
		fclose( $this->pipes[1] ); fclose( $this->pipes[2] ); proc_close( $this->process ); $this->process = null;
		if ( 0 !== $this->exit_code || '' !== trim( $error ) ) { throw new \RuntimeException( 'Operation proof subprocess failed.' ); }
		try { $decoded = json_decode( trim( $output ), true, 8, JSON_THROW_ON_ERROR ); }
		catch ( \Throwable ) { throw new \RuntimeException( 'Operation proof subprocess returned invalid evidence.' ); }
		if ( ! is_array( $decoded ) ) { throw new \RuntimeException( 'Operation proof subprocess returned invalid evidence.' ); }
		return $decoded;
	}

	public function __destruct() {
		if ( is_resource( $this->process ) ) {
			$this->kill();
			foreach ( $this->pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			proc_close( $this->process );
		}
	}
}
