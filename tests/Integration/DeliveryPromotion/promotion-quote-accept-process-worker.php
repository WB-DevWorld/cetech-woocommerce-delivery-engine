<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteAcceptCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteAcceptSqlStack as Stack, PromotionQuoteIssueSqlFactory};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};

require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteAcceptSqlStack.php';

try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! is_string( $args['command_file'] ?? null ) || ! str_starts_with( basename( dirname( $args['command_file'] ) ), 'cetech-operation-proof-' ) || 'accept-recovery.json' !== basename( $args['command_file'] ) ) { throw new RuntimeException( 'Owned acceptance worker packet refused.' ); }
	DB::validate_prefix( $args['prefix'] ); $mode = $args['mode'] ?? 'reconcile'; $role = $args['role'] ?? 'history';
	if ( ! in_array( $mode, [ 'accept', 'reconcile' ], true ) || ! in_array( $role, [ 'left', 'right', 'history' ], true ) ) { throw new RuntimeException( 'Owned acceptance worker mode refused.' ); }
	$bytes = file_get_contents( $args['command_file'] );
	if ( ! is_string( $bytes ) || strlen( $bytes ) > FixedPromotionQuoteAcceptCommand::MAX_RECOVERY_BYTES ) { throw new RuntimeException( 'Owned acceptance worker packet refused.' ); }
	$command = FixedPromotionQuoteAcceptCommand::from_recovery_array( json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR ) );
	$clock = $args['clock'] ?? Factory::NOW + 1;
	if ( ! in_array( $clock, [ Factory::NOW + 1, Factory::NOW + 400 ], true ) ) { throw new RuntimeException( 'Owned acceptance worker clock refused.' ); }
	$fixture = []; $capture_factory = null;
	if ( 'accept' === $mode ) {
		$capture_factory = new Factory( $args['prefix'] ); $capture = ( new PromotionQuoteIssueSqlFactory( $capture_factory ) )->open();
		if ( ! $capture->begin() ) { throw new RuntimeException( 'Owned acceptance source transaction refused.' ); }
		$fixture = Stack::sources_for( $capture, $command );
		if ( ! $capture->rollback() || ! $capture->retire() ) { throw new RuntimeException( 'Owned acceptance source cleanup refused.' ); }
	}
	$directory = dirname( $args['command_file'] ); $dispatched = false;
	$stack = new Stack( $args['prefix'], $fixture, $clock, static function( Transport $transport ) use ( $role, $command, $directory, &$dispatched ): void {
		if ( 'right' !== $role ) { return; }
		$transport->before = static function( string $sql, Transport $owner ) use ( $command, $directory, &$dispatched ): void {
			if ( $dispatched || ! str_starts_with( $sql, 'INSERT INTO ' ) || ! str_contains( $sql, 'delivery_engine_operation_records' ) || ! str_contains( $sql, $command->identity->namespace_digest() ) ) { return; }
			$dispatched = true;
			$signal = json_encode( [ 'connection_id' => $owner->connection_id() ], JSON_THROW_ON_ERROR ); $file = $directory . '/right-dispatched';
			if ( strlen( $signal ) !== file_put_contents( $file . '.tmp', $signal, LOCK_EX ) || ! chmod( $file . '.tmp', 0600 ) || ! rename( $file . '.tmp', $file ) ) { throw new RuntimeException( 'Owned acceptance dispatch signal failed.' ); }
		};
	} );
	if ( 'left' === $role ) {
		$stack->observer->callback = static function( string $phase ) use ( $stack, $directory ): void {
			if ( 'before_effect_commit' !== $phase ) { return; }
			$signal = json_encode( [ 'connection_id' => $stack->physical->transports[0]->connection_id() ], JSON_THROW_ON_ERROR ); $file = $directory . '/left-held';
			if ( strlen( $signal ) !== file_put_contents( $file . '.tmp', $signal, LOCK_EX ) || ! chmod( $file . '.tmp', 0600 ) || ! rename( $file . '.tmp', $file ) ) { throw new RuntimeException( 'Owned acceptance lock signal failed.' ); }
			OperationProofProcess::wait_for( $directory . '/release', 12.0 );
		};
	}
	$stack->eligibility->poison = 'reconcile' === $mode;
	$result = 'accept' === $mode ? $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ) : $stack->service->reconcile( $command, RequestContext::create() );
	$ledger_writes = 0; $quote_writes = 0; $audit_appends = 0;
	foreach ( $stack->physical->transports as $transport ) {
		$quote_writes += $transport->quote_writes; $audit_appends += $transport->audit_appends;
		foreach ( $transport->sql as $sql ) { if ( 1 === preg_match( '/\A(?:INSERT INTO|UPDATE|DELETE FROM) /', $sql ) && 1 === preg_match( '/delivery_engine_(?:promotion_budget_accounts|promotion_budget_reservations|delivery_quote_budget_windows)/', $sql ) ) { ++$ledger_writes; } }
	}
	$out = [ 'process_id' => getmypid(), 'state' => $result->attempt->outcome->state, 'replayed' => $result->attempt->replayed, 'quote_state' => $result->quote?->state(), 'quote_revision' => $result->quote?->revision(), 'accepted_at' => $result->quote?->accepted_at()?->sql(), 'header_json' => $result->quote?->header()->to_private_json(), 'eligibility_proofs' => count( $stack->eligibility->owners ), 'quote_writes' => $quote_writes, 'audit_appends' => $audit_appends, 'ledger_writes' => $ledger_writes, 'native_input_reads' => $fixture['source']->reads ?? 0, 'error' => $result->attempt->outcome->error?->code ];
	$stack->physical->close_all(); $capture_factory?->close_all(); echo json_encode( $out, JSON_THROW_ON_ERROR ), PHP_EOL;
} catch ( Throwable $error ) { fwrite( STDERR, 'Owned E11 acceptance worker failed: ' . $error::class . ': ' . $error->getMessage() ); exit( 1 ); }
