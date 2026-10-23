<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteVerifyCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteAcceptSqlStack, PromotionQuoteVerifySqlStack as Stack, PromotionQuoteIssueSqlFactory};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;

$capture_factory = null; $stack = null; $write_status = null; $diagnostic_phase = 'bootstrap';
try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! is_string( $args['command_file'] ?? null ) || ! str_starts_with( basename( dirname( $args['command_file'] ) ), 'cetech-operation-proof-' ) || ! in_array( basename( $args['command_file'] ), [ 'verify-recovery.json', 'verify-left.json', 'verify-right.json' ], true ) ) { throw new RuntimeException( 'Owned verify worker packet refused.' ); }
	$directory = dirname( $args['command_file'] ); $role = $args['role'] ?? 'history';
	if ( ! in_array( $role, [ 'left', 'right', 'history' ], true ) ) { throw new RuntimeException( 'Owned verify worker role refused.' ); }
	// Only closed phase/state/class enums enter these private atomic files.
	$write_status = static function( string $phase, string $state = 'running', string $exception_class = 'none' ) use ( $directory, $role, &$diagnostic_phase ): void {
		$diagnostic_phase = $phase; $file = $directory . '/verify-' . $role . '-status.json';
		$bytes = json_encode( [ 'phase' => $phase, 'state' => $state, 'exception_class' => $exception_class ], JSON_THROW_ON_ERROR );
		if ( strlen( $bytes ) !== file_put_contents( $file . '.tmp', $bytes, LOCK_EX ) || ! chmod( $file . '.tmp', 0600 ) || ! rename( $file . '.tmp', $file ) ) { throw new RuntimeException( 'Owned verify status failed.' ); }
	};
	$write_status( 'bootstrap' );
	require dirname( __DIR__, 2 ) . '/bootstrap.php';
	require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteVerifySqlStack.php';
	DB::validate_prefix( $args['prefix'] ); $mode = $args['mode'] ?? 'reconcile'; $role = $args['role'] ?? 'history'; $clock = $args['clock'] ?? Factory::NOW + 3; $barrier = $args['barrier'] ?? 'publication';
	if ( ! in_array( $barrier, [ 'namespace', 'publication' ], true ) || ! in_array( $mode, [ 'verify', 'reconcile' ], true ) || ! in_array( $role, [ 'left', 'right', 'history' ], true ) || ! in_array( $clock, [ Factory::NOW + 3, Factory::NOW + 400 ], true ) ) { throw new RuntimeException( 'Owned verify worker mode refused.' ); }
	$write_status( 'recovery' );
	$bytes = file_get_contents( $args['command_file'] );
	if ( ! is_string( $bytes ) || strlen( $bytes ) > FixedPromotionQuoteVerifyCommand::MAX_RECOVERY_BYTES ) { throw new RuntimeException( 'Owned verify recovery refused.' ); }
	$command = FixedPromotionQuoteVerifyCommand::from_recovery_array( json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR ) );
	if ( ! hash_equals( $bytes, json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Owned verify recovery is not canonical.' ); }
	$fixture = [];
	if ( 'verify' === $mode ) {
		$write_status( 'source_open' );
		$capture_factory = new Factory( $args['prefix'] ); $capture = ( new PromotionQuoteIssueSqlFactory( $capture_factory ) )->open();
		if ( ! $capture->begin() ) { throw new RuntimeException( 'Owned verify source transaction refused.' ); }
		$write_status( 'source_capture' );
		$fixture = PromotionQuoteAcceptSqlStack::sources_for( $capture, $command->binding_command()->acceptance() );
		$write_status( 'source_cleanup' );
		if ( ! $capture->rollback() || ! $capture->retire() ) { throw new RuntimeException( 'Owned verify source cleanup refused.' ); }
	}
	$write_status( 'configured' ); $dispatched = false;
	$stack = new Stack( $args['prefix'], $fixture, $command, $clock, static function( Transport $transport ) use ( $role, $directory, $barrier, &$dispatched, $write_status ): void {
		$transport->before = static function( string $sql, Transport $owner ) use ( $role, $directory, $barrier, &$dispatched, $write_status ): void {
			if ( str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'delivery_engine_operation_records' ) ) { $write_status( 'namespace_reservation' ); }
			elseif ( str_contains( $sql, 'FOR UPDATE' ) ) {
				if ( str_contains( $sql, 'delivery_engine_operation_records' ) ) { $write_status( 'namespace_lock' ); }
				elseif ( str_starts_with( $sql, 'SELECT option_id, option_name, CASE WHEN' ) ) { $write_status( 'control_lock' ); }
				elseif ( str_contains( $sql, 'delivery_engine_promotion_budget_accounts' ) ) { $write_status( 'account_lock' ); }
				elseif ( str_contains( $sql, 'e13_candidate_orders' ) ) { $write_status( 'order_lock' ); }
				elseif ( str_contains( $sql, 'e14_saved_snapshots' ) ) { $write_status( 'saved_lock' ); }
				elseif ( str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { $write_status( 'binding_lock' ); }
				elseif ( str_contains( $sql, 'delivery_engine_delivery_quotes' ) ) { $write_status( 'quote_lock' ); }
			}
			$namespace = str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'delivery_engine_operation_records' ) && str_contains( $sql, 'delivery_quote.verify_binding' );
			$publication = str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) && str_contains( $sql, 'FOR UPDATE' );
			if ( 'right' !== $role || $dispatched || ! ( 'namespace' === $barrier ? $namespace : $publication ) ) { return; }
			$dispatched = true; $write_status( 'namespace' === $barrier ? 'namespace_dispatched' : 'publication_dispatched' );
			if ( false === file_put_contents( $directory . '/right-dispatched', json_encode( [ 'connection_id' => $owner->connection_id() ], JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Owned verify dispatch signal failed.' ); }
		};
	} );
	if ( 'left' === $role ) {
		$stack->observer->callback = static function( string $phase ) use ( $stack, $directory, $write_status ): void {
			if ( 'before_effect_commit' !== $phase ) { return; }
			$write_status( 'effect_held' );
			if ( false === file_put_contents( $directory . '/left-held', json_encode( [ 'connection_id' => $stack->physical->transports[0]->connection_id() ], JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Owned verify lock signal failed.' ); }
			OperationProofProcess::wait_for( $directory . '/release', 12.0 );
			$write_status( 'effect_released' );
		};
	}
	if ( 'right' === $role && 'publication' === $barrier ) {
		$stack->observer->callback = static function( string $phase ) use ( $stack, $directory, $write_status ): void {
			if ( 'reservation_committed' !== $phase ) { return; }
			$transport = $stack->physical->transports[0]; $state = $transport->transaction_state(); $session = $stack->physical->sessions[0];
			if ( $session->is_retired() || $session->in_transaction() || ! is_array( $state ) || false !== $state['in_transaction'] || true !== $state['autocommit'] ) { throw new RuntimeException( 'Owned verify committed reservation refused.' ); }
			$write_status( 'reservation_held' );
			if ( false === file_put_contents( $directory . '/right-reserved', json_encode( [ 'connection_id' => $transport->connection_id(), 'in_transaction' => $state['in_transaction'], 'autocommit' => $state['autocommit'] ], JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Owned verify reservation signal failed.' ); }
			OperationProofProcess::wait_for( $directory . '/right-start-effect', 12.0 ); $write_status( 'reservation_released' );
		};
	}
	$stack->eligibility->poison = 'reconcile' === $mode; $stack->order->poison = 'reconcile' === $mode; $stack->saved->poison = 'reconcile' === $mode;
	$write_status( 'running' );
	$result = 'verify' === $mode ? $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) : $stack->service->reconcile( $command, RequestContext::create() );
	$out = [ 'process_id' => getmypid(), 'state' => $result->attempt->outcome->state, 'replayed' => $result->attempt->replayed,
		'quote_state' => $result->quote?->state(), 'binding_state' => $result->binding?->state(), 'binding_revision' => $result->binding?->revision(), 'binding_id' => $result->binding?->id(), 'binding_created_at' => $result->binding?->row()['created_at'], 'binding_verified_at' => $result->binding?->row()['verified_at'], 'snapshot_digest' => $result->binding?->row()['snapshot_digest'], 'context_digest' => $result->binding?->row()['context_digest'],
		'order_id' => $result->binding?->row()['order_id'], 'header_json' => $result->quote?->header()->to_private_json(), 'eligibility_proofs' => count( $stack->eligibility->owners ), 'order_proofs' => count( $stack->order->owners ),
		'saved_proofs' => count( $stack->saved->owners ), 'binding_updates' => $stack->binding_updates(), 'protected_writes' => count( $stack->protected_writes() ), 'native_input_reads' => $fixture['source']->reads ?? 0, 'error' => $result->attempt->outcome->error?->code ];
	$last_phase = $diagnostic_phase; $write_status( 'cleanup' ); $stack->physical->close_all(); $capture_factory?->close_all();
	$state = in_array( $out['state'], [ 'accepted', 'rejected', 'unconfirmed', 'not_applicable' ], true ) ? $out['state'] : 'failed'; $write_status( $last_phase, $state ); echo json_encode( $out, JSON_THROW_ON_ERROR ), PHP_EOL;
} catch ( Throwable $error ) {
	$class = match ( true ) { $error instanceof TypeError => 'TypeError', $error instanceof InvalidArgumentException => 'InvalidArgumentException', $error instanceof LogicException => 'LogicException', $error instanceof RuntimeException => 'RuntimeException', $error instanceof ErrorException => 'ErrorException', $error instanceof Error => 'Error', $error instanceof Exception => 'Exception', default => 'Throwable' };
	if ( null !== $write_status ) { try { $write_status( $diagnostic_phase, 'failed', $class ); } catch ( Throwable ) {} }
	$stack?->physical->close_all(); $capture_factory?->close_all(); fwrite( STDERR, 'Owned E14 verify worker failed.' ); exit( 1 );
}
