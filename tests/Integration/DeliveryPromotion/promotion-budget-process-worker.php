<?php

declare(strict_types=1);
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetRequest;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\PromotionBudgetSqlFixture as F;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier as Barrier};

use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/PromotionBudgetSqlFixture.php';
try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR ); $factory = new Factory( $args['prefix'] ); $s = $factory->open();
	if ( ! $s->begin() ) { throw new RuntimeException(); }
	$connection = $s->get_row( 'SELECT CONNECTION_ID() AS id' );
	if ( isset( $args['ready'], $args['release'] ) ) { $bytes = json_encode( [ 'process_id' => getmypid(), 'connection_id' => (int) $connection['id'] ], JSON_THROW_ON_ERROR ); if ( false === file_put_contents( $args['ready'] . '.pending', $bytes, LOCK_EX ) || ! rename( $args['ready'] . '.pending', $args['ready'] ) ) { throw new RuntimeException(); } \CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess::wait_for( $args['release'] ); }
	$r = FixedPromotionBudgetRequest::from_array( $args['request'] ); $status = 'refused';
	try { $claim = F::service()->reserve( $s, $r, $r->quote_created_at(), $r->quote_created_at()->plus_seconds( 60 ) ); $status = $claim->state(); } catch ( DomainException|InvalidArgumentException ) {}
	if ( 'held' === $status && isset( $args['hold_ready'], $args['hold_release'] ) ) { Barrier::pause( $args['hold_ready'], $args['hold_release'] ); }
	if ( 'held' === $status ) { if ( OperationCommitResult::Acknowledged !== $s->commit() ) { throw new RuntimeException(); } } else { $s->rollback(); }
	$s->retire(); $factory->close_all();
	echo json_encode( [ 'process_id' => getmypid(), 'connection_id' => (int) $connection['id'], 'state' => $status ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) { fwrite( STDERR, 'Owned promotion SQL worker failed.' ); exit( 1 ); }
