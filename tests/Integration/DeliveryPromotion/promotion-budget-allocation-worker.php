<?php

declare(strict_types=1);

use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionBudgetAllocationSqlFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier as Barrier, OperationProofProcess as Process};

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Fresh actual SQL owner; caller-supplied packets are fixture replay, not merchant authority. */
try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR ); $factory = new Factory( $args['prefix'] ); $locks = [];
	$factory->configure = static function( $transport ) use ( $args, &$locks ): void {
		$signaled = false;
		$transport->before = static function( string $sql, $t ) use ( $args, &$locks, &$signaled ): void {
			if ( ! str_starts_with( $sql, 'SELECT ' ) || ! str_ends_with( $sql, 'FOR UPDATE' ) ) { return; }
			if ( str_contains( $sql, 'promotion_budget_accounts`' ) && preg_match( '/`account_key` = \'([a-f0-9]{64})\'/', $sql, $m ) ) {
				$locks[] = 'account:' . $m[1];
				if ( ! $signaled && isset( $args['lock_dispatch'] ) ) { $signaled = true; self_write_allocation_barrier( $args['lock_dispatch'], [ 'process_id' => getmypid(), 'connection_id' => $t->connection_id() ] ); }
			} elseif ( str_contains( $sql, 'promotion_budget_reservations`' ) ) { $locks[] = 'claim'; }
		};
	};
	$s = $factory->open(); if ( ! $s->begin() ) { throw new RuntimeException(); } $connection = $s->get_row( 'SELECT CONNECTION_ID() AS id' );
	if ( isset( $args['ready'], $args['release'] ) ) { self_write_allocation_barrier( $args['ready'], [ 'process_id' => getmypid(), 'connection_id' => (int) $connection['id'] ] ); Process::wait_for( $args['release'] ); }
	$set = F::from_packet( $args['packet'] ); $at = $set->header()->created_at()->plus_seconds( $args['at_offset'] ?? 0 ); $state = 'refused';
	try {
		$claims = 'commit' === ( $args['operation'] ?? 'reserve' ) ? F::service()->commit_all( $s, $set, $at ) : F::service()->reserve_all( $s, $set, $at, $set->header()->created_at()->plus_seconds( 60 ) );
		$states = array_values( array_unique( array_map( static fn( $c ): string => $c->state(), $claims ) ) ); if ( 1 !== count( $states ) || 2 !== count( $claims ) ) { throw new RuntimeException(); } $state = $states[0];
	} catch ( DomainException|InvalidArgumentException ) {}
	if ( 'held' === $state && isset( $args['hold_ready'], $args['hold_release'] ) ) { Barrier::pause( $args['hold_ready'], $args['hold_release'] ); }
	if ( 'refused' === $state ) { if ( ! $s->rollback() ) { throw new RuntimeException(); } } elseif ( OperationCommitResult::Acknowledged !== $s->commit() ) { throw new RuntimeException(); }
	$s->retire(); $factory->close_all();
	echo json_encode( [ 'process_id' => getmypid(), 'connection_id' => (int) $connection['id'], 'state' => $state, 'locks' => array_slice( $locks, 0, 6 ) ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) { fwrite( STDERR, 'Owned allocation SQL worker failed.' ); exit( 1 ); }

function self_write_allocation_barrier( string $path, array $data ): void {
	$bytes = json_encode( $data, JSON_THROW_ON_ERROR ); if ( false === file_put_contents( $path . '.pending', $bytes, LOCK_EX ) || ! rename( $path . '.pending', $path ) ) { throw new RuntimeException(); }
}
