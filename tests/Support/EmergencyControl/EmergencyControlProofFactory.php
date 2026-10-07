<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\EmergencyControl;

use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase;

/** Every owner is a separate mysqli connection; never reconnects inside a unit. */
final class EmergencyControlProofFactory implements OperationConnectionFactory {
	public array $transports = [];
	public array $sessions = [];
	public ?\Closure $configure = null;
	public int $clock = 2000000000;
	public string $isolation = 'REPEATABLE READ';
	public int $snapshot_isolation = 0;
	public function __construct( public readonly string $prefix, public readonly int $site_id = 1 ) { DataLifecycleProofDatabase::validate_prefix( $prefix ); }
	public function open(): OperationSession {
		$native = OperationConnectionMysqliTransport::connect( (string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' ), (string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' ), (string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: '' ), (string) ( getenv( 'CETECH_DE_REAL_DB_NAME' ) ?: '' ), (int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 ) );
		if ( ! in_array( $this->isolation, [ 'REPEATABLE READ', 'READ COMMITTED' ], true ) || ! in_array( $this->snapshot_isolation, [ 0, 1 ], true ) || $this->clock < 1 ) { $native->close(); throw new \RuntimeException( 'Invalid disposable emergency runtime.' ); }
		foreach ( [ 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $this->isolation, 'SET SESSION innodb_snapshot_isolation=' . $this->snapshot_isolation, 'SET timestamp=' . $this->clock ] as $sql ) { if ( ! $native->execute( $sql )->acknowledged ) { $native->close(); throw new \RuntimeException( 'Disposable emergency runtime setup failed.' ); } }
		$transport = new EmergencyControlProofTransport( $native ); $this->transports[] = $transport;
		if ( null !== $this->configure ) { ( $this->configure )( $transport, count( $this->transports ) ); }
		$session = new OperationConnection( $this->site_id, $this->prefix, $transport, 'utf8mb4', 'utf8mb4_unicode_ci' ); $this->sessions[] = $session; return $session;
	}
	public function close_all(): void { foreach ( $this->transports as $transport ) { $transport->force_close(); } }
}
