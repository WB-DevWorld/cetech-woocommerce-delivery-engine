<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DataLifecycle;

use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;

/** New physical native connection for every unit; optional SQL clock stays fixture-only. */
final class DataLifecycleProofFactory implements OperationConnectionFactory {
	public array $transports = [];
	public array $sessions = [];
	public ?\Closure $configure = null;
	public function __construct( public readonly string $prefix, public readonly int $site_id = 1, public ?int $clock = null ) { DataLifecycleProofDatabase::validate_prefix( $prefix ); }
	public function open(): OperationSession {
		$native = OperationConnectionMysqliTransport::connect( (string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' ), (string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' ), (string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: '' ), (string) ( getenv( 'CETECH_DE_REAL_DB_NAME' ) ?: '' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) );
		if ( null !== $this->clock && ! $native->execute( 'SET timestamp = ' . $this->clock )->acknowledged ) { $native->close(); throw new \RuntimeException( 'Disposable lifecycle clock setup failed.' ); }
		$transport = new DataLifecycleProofTransport( $native ); $this->transports[] = $transport;
		if ( null !== $this->configure ) { ( $this->configure )( $transport, count( $this->transports ) ); }
		$session = new OperationConnection( $this->site_id, $this->prefix, $transport, 'utf8mb4', 'utf8mb4_unicode_ci' ); $this->sessions[] = $session; return $session;
	}
	public function close_all(): void { foreach ( $this->transports as $transport ) { $transport->force_close(); } }
}
