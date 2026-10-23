<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteBindCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteAcceptSqlStack, PromotionQuoteBindSqlStack as Stack, PromotionQuoteIssueSqlFactory};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;

require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteBindSqlStack.php';

$capture_factory = null; $stack = null;
try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! is_string( $args['command_file'] ?? null ) || ! str_starts_with( basename( dirname( $args['command_file'] ) ), 'cetech-operation-proof-' ) || ! in_array( basename( $args['command_file'] ), [ 'bind-recovery.json', 'bind-left.json', 'bind-right.json' ], true ) ) { throw new RuntimeException( 'Owned bind worker packet refused.' ); }
	DB::validate_prefix( $args['prefix'] ); $mode = $args['mode'] ?? 'reconcile'; $role = $args['role'] ?? 'history'; $clock = $args['clock'] ?? Factory::NOW + 2;
	if ( ! in_array( $mode, [ 'bind', 'reconcile' ], true ) || ! in_array( $role, [ 'left', 'right', 'history' ], true ) || ! in_array( $clock, [ Factory::NOW + 2, Factory::NOW + 400 ], true ) ) { throw new RuntimeException( 'Owned bind worker mode refused.' ); }
	$bytes = file_get_contents( $args['command_file'] );
	if ( ! is_string( $bytes ) || strlen( $bytes ) > FixedPromotionQuoteBindCommand::MAX_RECOVERY_BYTES ) { throw new RuntimeException( 'Owned bind recovery refused.' ); }
	$command = FixedPromotionQuoteBindCommand::from_recovery_array( json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR ) );
	if ( ! hash_equals( $bytes, json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Owned bind recovery is not canonical.' ); }
	$fixture = [];
	if ( 'bind' === $mode ) {
		$capture_factory = new Factory( $args['prefix'] ); $capture = ( new PromotionQuoteIssueSqlFactory( $capture_factory ) )->open();
		if ( ! $capture->begin() ) { throw new RuntimeException( 'Owned bind source transaction refused.' ); }
		$fixture = PromotionQuoteAcceptSqlStack::sources_for( $capture, $command->acceptance() );
		if ( ! $capture->rollback() || ! $capture->retire() ) { throw new RuntimeException( 'Owned bind source cleanup refused.' ); }
	}
	$directory = dirname( $args['command_file'] ); $dispatched = false;
	$stack = new Stack( $args['prefix'], $fixture, $command, $clock, static function( Transport $transport ) use ( $role, $directory, &$dispatched ): void {
		if ( 'right' !== $role ) { return; }
		$transport->before = static function( string $sql, Transport $owner ) use ( $directory, &$dispatched ): void {
			if ( $dispatched || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; }
			$dispatched = true;
			if ( false === file_put_contents( $directory . '/right-dispatched', json_encode( [ 'connection_id' => $owner->connection_id() ], JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Owned bind dispatch signal failed.' ); }
		};
	} );
	if ( 'left' === $role ) {
		$stack->observer->callback = static function( string $phase ) use ( $stack, $directory ): void {
			if ( 'before_effect_commit' !== $phase ) { return; }
			if ( false === file_put_contents( $directory . '/left-held', json_encode( [ 'connection_id' => $stack->physical->transports[0]->connection_id() ], JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Owned bind lock signal failed.' ); }
			OperationProofProcess::wait_for( $directory . '/release', 12.0 );
		};
	}
	$stack->eligibility->poison = 'reconcile' === $mode; $stack->order->poison = 'reconcile' === $mode;
	$result = 'bind' === $mode ? $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ) : $stack->service->reconcile( $command, RequestContext::create() );
	$out = [ 'process_id' => getmypid(), 'state' => $result->attempt->outcome->state, 'replayed' => $result->attempt->replayed,
		'quote_state' => $result->quote?->state(), 'binding_state' => $result->binding?->state(), 'binding_revision' => $result->binding?->revision(), 'binding_id' => $result->binding?->id(), 'binding_created_at' => $result->binding?->row()['created_at'],
		'order_id' => $result->binding?->row()['order_id'], 'header_json' => $result->quote?->header()->to_private_json(), 'eligibility_proofs' => count( $stack->eligibility->owners ), 'order_proofs' => count( $stack->order->owners ),
		'binding_inserts' => $stack->binding_inserts(), 'protected_writes' => count( $stack->ledger_writes() ), 'native_input_reads' => $fixture['source']->reads ?? 0, 'error' => $result->attempt->outcome->error?->code ];
	$stack->physical->close_all(); $capture_factory?->close_all(); echo json_encode( $out, JSON_THROW_ON_ERROR ), PHP_EOL;
} catch ( Throwable $error ) {
	$stack?->physical->close_all(); $capture_factory?->close_all(); fwrite( STDERR, 'Owned E13 bind worker failed: ' . $error::class . ': ' . $error->getMessage() ); exit( 1 );
}
