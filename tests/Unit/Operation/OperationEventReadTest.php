<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Operation;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationChangeRepository;
use PHPUnit\Framework\TestCase;

/** SQL contract only; concurrent InnoDB behavior has a separate physical proof. */
final class OperationEventReadTest extends TestCase {
	private function session( \Closure $read ): OperationSession {
		$s = $this->createMock( OperationSession::class ); $s->method( 'site_id' )->willReturn( 1 ); $s->method( 'table_prefix' )->willReturn( 'unit_' ); $s->method( 'in_transaction' )->willReturn( true ); $s->method( 'is_retired' )->willReturn( false );
		$s->method( 'prepare' )->willReturnCallback( static function( string $sql, mixed ...$args ): string { $i = 0; return preg_replace_callback( '/%[ds]/', static function( array $m ) use ( &$i, $args ): string { return (string) $args[$i++]; }, $sql ); } ); $s->method( 'get_row' )->willReturnCallback( $read ); return $s;
	}
	public function test_missing_event_on_owned_pending_parent_does_not_lock_an_audit_index_gap(): void {
		$sql = []; $s = $this->session( static function( string $q ) use ( &$sql ): ?array { $sql[] = $q; return count( $sql ) === 1 ? [ 'state' => 'pending', 'audit_id' => null ] : null; } );
		self::assertNull( ( new WpdbOperationChangeRepository( $s ) )->find_for_operation( 1, 9 ) ); self::assertStringContainsString( 'id = 9 AND site_id = 1', $sql[0] ); self::assertStringEndsWith( 'FOR UPDATE', $sql[0] ); self::assertStringNotContainsString( 'FOR UPDATE', $sql[1] ); self::assertStringContainsString( 'operation_id = 9', $sql[1] );
	}
	public function test_accepted_parent_uses_current_exact_audit_identity_not_an_old_reverse_snapshot(): void {
		$sql = []; $event = [ 'id' => '7', 'site_id' => '1', 'operation_id' => '9', 'event_json' => 'finite_fixture' ];
		$s = $this->session( static function( string $q ) use ( &$sql, $event ): ?array { $sql[] = $q; if ( count( $sql ) === 1 ) { return [ 'state' => 'accepted', 'audit_id' => '7' ]; } return str_contains( $q, 'WHERE id = 7' ) && str_ends_with( $q, 'FOR UPDATE' ) ? $event : null; } );
		self::assertSame( $event, ( new WpdbOperationChangeRepository( $s ) )->find_for_operation( 1, 9 ) ); self::assertCount( 2, $sql ); self::assertStringContainsString( 'id = 7 AND site_id = 1 AND operation_id = 9', $sql[1] ); self::assertStringEndsWith( 'FOR UPDATE', $sql[1] );
	}
	public function test_existing_orphan_is_returned_for_strict_parent_record_hydration_to_reject(): void {
		$i = 0; $event = [ 'id' => 7, 'operation_id' => 9 ]; $s = $this->session( static function() use ( &$i, $event ): array { return ++$i === 1 ? [ 'state' => 'rejected', 'audit_id' => null ] : $event; } );
		self::assertSame( $event, ( new WpdbOperationChangeRepository( $s ) )->find_for_operation( 1, 9 ) );
	}
	public function test_nonaccepted_parent_with_audit_pointer_is_corruption_not_absence(): void {
		$s = $this->session( static fn(): array => [ 'state' => 'pending', 'audit_id' => 7 ] ); $this->expectException( OperationStorageException::class ); ( new WpdbOperationChangeRepository( $s ) )->find_for_operation( 1, 9 );
	}
}
