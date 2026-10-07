<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase;

/** Each call obtains a new native connection; no WordPress cache or implicit reconnect. */
final class QuoteStorageProofFactory {
	private array $sessions = [];
	public function __construct( private readonly string $prefix ) { DataLifecycleProofDatabase::validate_prefix( $prefix ); }
	public function open( int $site = 1 ): OperationSession {
		$transport = OperationConnectionMysqliTransport::connect(
			(string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' ),
			(string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' ),
			(string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: '' ),
			(string) ( getenv( 'CETECH_DE_REAL_DB_NAME' ) ?: '' ),
			(int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 )
		);
		$session = new OperationConnection( $site, $this->prefix, $transport, 'utf8mb4', 'utf8mb4_unicode_ci' );
		$this->sessions[] = $session; return $session;
	}
	public function close_all(): void { foreach ( $this->sessions as $session ) { $session->retire(); } $this->sessions = []; }
}
