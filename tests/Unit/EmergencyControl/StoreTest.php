<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once dirname( __DIR__ ) . '/Operation/OperationConnectionTest.php';

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Tests\Unit\Operation\OperationConnectionTestTransport;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase {
	public function test_native_firewall_accepts_only_the_fixed_unique_key_control_read(): void {
		$t = new OperationConnectionTestTransport(); $s = new OperationConnection( 1, 'op_', $t ); self::assertTrue( $s->begin() );
		$sql = $s->prepare( 'SELECT option_id FROM `op_options` FORCE INDEX (`option_name`) WHERE option_name = %s LIMIT 1 FOR UPDATE', EmergencyControlStore::OPTION_NAME );
		self::assertIsArray( $s->get_row( $sql ) ); self::assertContains( $sql, $t->statements ); self::assertFalse( $s->get_row( 'SELECT INDEX(1)' ) ); self::assertFalse( $s->get_row( 'SELECT * FROM `op_options` FORCE INDEX (hidden_effect())' ) ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
	}
	public function test_current_read_is_bounded_fixed_unique_key_and_authoritative_lock(): void {
		$s = $this->createMock( OperationSession::class ); $s->method( 'site_id' )->willReturn( 1 ); $s->method( 'table_prefix' )->willReturn( 'op_' ); $s->method( 'in_transaction' )->willReturn( true ); $sql_seen = '';
		$s->method( 'prepare' )->willReturnCallback( static function ( string $sql, mixed ...$args ) use ( &$sql_seen ): string { self::assertSame( [ 2048, EmergencyControlStore::OPTION_NAME ], $args ); $sql_seen = $sql; return $sql; } ); $s->method( 'get_row' )->willReturn( null );
		$state = ( new EmergencyControlStore() )->current( $s ); self::assertFalse( $state->initialized ); self::assertStringContainsString( 'CASE WHEN OCTET_LENGTH(option_value) <= %d', $sql_seen ); self::assertStringContainsString( 'FORCE INDEX (`option_name`)', $sql_seen ); self::assertStringContainsString( 'WHERE option_name = %s LIMIT 1 FOR UPDATE', $sql_seen );
	}
	public function test_update_preserves_autoload_and_guards_exact_identity_and_bytes(): void {
		$s = $this->createMock( OperationSession::class ); $s->method( 'site_id' )->willReturn( 1 ); $s->method( 'table_prefix' )->willReturn( 'op_' ); $s->method( 'in_transaction' )->willReturn( true );
		$old = ' ' . EmergencyControlState::record_json( 1, 'enabled', 4, 'resume_verified', 7, 10 ); $before = EmergencyControlState::from_physical( 1, 9, $old ); $next = EmergencyControlState::record_json( 1, 'checkout_suspended', 5, 'operator_pause', 7, 11 );
		$s->method( 'prepare' )->willReturnCallback( static function ( string $sql, mixed ...$args ) use ( $old, $next ): string { self::assertStringNotContainsString( 'autoload', $sql ); self::assertStringContainsString( 'option_id = %d AND BINARY option_name = %s AND BINARY option_value = %s', $sql ); self::assertSame( [ $next, 9, EmergencyControlStore::OPTION_NAME, $old ], $args ); return $sql; } ); $s->method( 'query' )->willReturn( 1 );
		$after = ( new EmergencyControlStore() )->write( $s, $before, $next ); self::assertSame( 9, $after->row_id ); self::assertSame( 5, $after->revision );
	}
	public function test_unacknowledged_current_read_cannot_become_virtual_absence(): void { $s = $this->createMock( OperationSession::class ); $s->method( 'site_id' )->willReturn( 1 ); $s->method( 'table_prefix' )->willReturn( 'op_' ); $s->method( 'in_transaction' )->willReturn( true ); $s->method( 'prepare' )->willReturn( 'SELECT safe' ); $s->method( 'get_row' )->willReturn( false ); $this->expectException( OperationStorageException::class ); ( new EmergencyControlStore() )->current( $s ); }
	public function test_publication_is_only_three_fixed_cache_invalidations_and_absence_is_success(): void { $seen = []; $store = new EmergencyControlStore( static function ( string $name, string $group ) use ( &$seen ): bool { $seen[] = [ $name, $group ]; return false; } ); self::assertTrue( $store->invalidate() ); self::assertSame( [ [ EmergencyControlStore::OPTION_NAME, 'options' ], [ 'alloptions', 'options' ], [ 'notoptions', 'options' ] ], $seen ); }
	public function test_publication_exception_is_truthfully_pending(): void { self::assertFalse( ( new EmergencyControlStore( static function (): never { throw new \RuntimeException( 'PRIVATE_CACHE_QUERY' ); } ) )->invalidate() ); }
}
