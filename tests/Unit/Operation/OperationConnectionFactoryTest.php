<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory as OperationFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;
use PHPUnit\Framework\TestCase;

final class OperationConnectionFactoryTest extends TestCase {

	public function test_explicit_equivalent_factory_is_selected_without_native_or_global_use(): void {
		$session = $this->createMock( OperationSession::class );
		$equivalent = new class( $session ) implements OperationFactory {
			public int $opens = 0;
			public function __construct( private OperationSession $session ) {
			}
			public function open(): OperationSession {
				++$this->opens;
				return $this->session;
			}
		};
		$global_before = $GLOBALS['wpdb'] ?? null;
		$factory = new OperationConnectionFactory( $equivalent );
		self::assertSame( $session, $factory->open() );
		self::assertSame( 1, $equivalent->opens );
		self::assertSame( $global_before, $GLOBALS['wpdb'] ?? null );
	}

	public function test_custom_routing_without_an_equivalent_factory_refuses_without_global_mutation(): void {
		$before = $GLOBALS['wpdb'] ?? null;
		$routed = new \stdClass();
		$routed->prefix = 'routed_';
		$GLOBALS['wpdb'] = $routed;
		try {
			try {
				( new OperationConnectionFactory() )->open();
				self::fail( 'Custom routing was silently treated as authoritative.' );
			} catch ( \RuntimeException $error ) {
				self::assertSame( 'Operation connection is unavailable.', $error->getMessage() );
				self::assertSame( $routed, $GLOBALS['wpdb'] );
			}
		} finally {
			$GLOBALS['wpdb'] = $before;
		}
	}

	public function test_transport_exception_text_is_not_exposed_by_factory(): void {
		$session = $this->createMock( OperationSession::class );
		$equivalent = new class( $session ) implements OperationFactory {
			public function __construct( private OperationSession $session ) {
			}
			public function open(): OperationSession {
				throw new \RuntimeException( 'private-secret database authority' );
			}
		};
		try {
			( new OperationConnectionFactory( $equivalent ) )->open();
			self::fail( 'Failing factory was accepted.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Operation connection is unavailable.', $error->getMessage() );
			self::assertNull( $error->getPrevious() );
		}
	}
}
