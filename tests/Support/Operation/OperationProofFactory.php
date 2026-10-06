<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;

/** Every open creates a real independent session. Decorators are test-only. */
final class OperationProofFactory implements OperationConnectionFactory {
	public array $transports = [];
	public array $sessions = [];
	private ?\Closure $configure;

	public function __construct( private string $prefix, private int $site_id = 1, ?callable $configure = null ) {
		OperationProofDatabase::validate_prefix( $prefix );
		$this->configure = null === $configure ? null : \Closure::fromCallable( $configure );
	}
	public function open(): OperationSession {
		$native = OperationConnectionMysqliTransport::connect(
			(string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' ),
			(string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' ),
			(string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: '' ),
			(string) ( getenv( 'CETECH_DE_REAL_DB_NAME' ) ?: '' ),
			(int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 )
		);
		$transport = new OperationProofTransport( $native );
		$this->transports[] = $transport;
		if ( null !== $this->configure ) { ( $this->configure )( $transport, count( $this->transports ) ); }
		$session = new OperationConnection( $this->site_id, $this->prefix, $transport, 'utf8mb4', 'utf8mb4_unicode_ci' );
		$this->sessions[] = $session;
		return $session;
	}
	public function close_all(): void {
		foreach ( $this->transports as $transport ) { $transport->force_close(); }
	}
}
