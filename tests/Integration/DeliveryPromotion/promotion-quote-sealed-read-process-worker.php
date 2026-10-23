<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteSealCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteAcceptSqlStack, PromotionQuoteIssueSqlFactory, PromotionQuoteSealSqlStack, PromotionQuoteSealedReadSqlStack as Stack, PromotionQuoteVerifySavedPort};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory};
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;

require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteSealedReadSqlStack.php';

$capture_factory = null; $stack = null; $writer = null;
try {
	$args = json_decode( $argv[1] ?? '', true, 16, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! is_string( $args['command_file'] ?? null ) || ! str_starts_with( basename( dirname( $args['command_file'] ) ), 'cetech-operation-proof-' ) || 'sealed-read-recovery.json' !== basename( $args['command_file'] ) ) { throw new RuntimeException( 'Owned sealed observation packet refused.' ); }
	DB::validate_prefix( $args['prefix'] ); $role = $args['role'] ?? 'read'; $clock = $args['clock'] ?? Factory::NOW + 5; $revision = $args['revision'] ?? 1; $kind = $args['kind'] ?? 'control';
	if ( ! in_array( $role, [ 'read', 'reader-left', 'writer-right', 'history' ], true ) || ! in_array( $clock, [ Factory::NOW + 5, Factory::NOW + 400 ], true ) || ! in_array( $revision, [ 1, 2 ], true ) || ! in_array( $kind, [ 'control', 'saved' ], true ) ) { throw new RuntimeException( 'Owned sealed observation mode refused.' ); }
	$bytes = file_get_contents( $args['command_file'] );
	if ( ! is_string( $bytes ) || strlen( $bytes ) > FixedPromotionQuoteSealCommand::MAX_RECOVERY_BYTES ) { throw new RuntimeException( 'Owned sealed observation recovery refused.' ); }
	$command = FixedPromotionQuoteSealCommand::from_recovery_array( json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR ) );
	if ( ! hash_equals( $bytes, json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Owned sealed observation recovery is not canonical.' ); }
	$directory = dirname( $args['command_file'] );
	if ( 'writer-right' === $role ) {
		$writer = DB::connect(); DB::execute( $writer, 'SET SESSION innodb_lock_wait_timeout=2' );
		if ( ! $writer->begin_transaction() ) { throw new RuntimeException( 'Owned competing writer did not begin.' ); }
		if ( 'control' === $kind ) {
			$table = $args['prefix'] . 'options'; $value = EmergencyControlState::record_json( 1, 'checkout_suspended', 3, 'operator_pause', 9, Factory::NOW + 5 );
			$sql = "UPDATE `{$table}` SET option_value='" . $writer->real_escape_string( $value ) . "' WHERE option_name='cetech_de_checkout_control_v1'";
		} else {
			$table = PromotionQuoteVerifySavedPort::table( $args['prefix'] ); $packet = PromotionQuoteVerifySavedPort::packet( $command->verified_binding() ); $packet['snapshot_digest'] = Q::digest( 'E16 separately committed saved change' );
			$sql = "UPDATE `{$table}` SET packet_json='" . $writer->real_escape_string( QuoteJson::encode( $packet ) ) . "' WHERE site_id=1 AND order_id=" . $command->verified_binding()->row()['order_id'];
		}
		$signal = json_encode( [ 'connection_id' => $writer->thread_id, 'table' => $table ], JSON_THROW_ON_ERROR ); $file = $directory . '/writer-dispatched';
		if ( strlen( $signal ) !== file_put_contents( $file . '.tmp', $signal, LOCK_EX ) || ! chmod( $file . '.tmp', 0600 ) || ! rename( $file . '.tmp', $file ) ) { throw new RuntimeException( 'Owned competing writer signal failed.' ); }
		DB::execute( $writer, $sql ); $affected = $writer->affected_rows;
		if ( 1 !== $affected || ! $writer->commit() ) { throw new RuntimeException( 'Owned competing write was not acknowledged.' ); }
		echo json_encode( [ 'process_id' => getmypid(), 'connection_id' => $writer->thread_id, 'changed' => $affected, 'table' => $table ], JSON_THROW_ON_ERROR ), PHP_EOL; $writer->close(); $writer = null;
	} elseif ( 'history' === $role ) {
		$stack = new PromotionQuoteSealSqlStack( $args['prefix'], [], $command, $clock ); $stack->eligibility->poison = true; $stack->order->poison = true; $stack->saved->poison = true;
		$result = $stack->service->reconcile( $command, RequestContext::create() );
		echo json_encode( [ 'process_id' => getmypid(), 'state' => $result->attempt->outcome->state, 'binding_id' => $result->binding?->id(), 'sealed_at' => $result->binding?->row()['sealed_at'], 'current_proofs' => count( $stack->eligibility->owners ) + count( $stack->order->owners ) + count( $stack->saved->owners ), 'protected_writes' => count( $stack->protected_writes() ), 'binding_updates' => $stack->binding_updates() ], JSON_THROW_ON_ERROR ), PHP_EOL;
		$stack->physical->close_all();
	} else {
		$capture_factory = new Factory( $args['prefix'] ); $capture = ( new PromotionQuoteIssueSqlFactory( $capture_factory ) )->open();
		if ( ! $capture->begin() ) { throw new RuntimeException( 'Owned sealed observation source owner refused.' ); }
		$fixture = PromotionQuoteAcceptSqlStack::sources_for( $capture, $command->verification()->binding_command()->acceptance() );
		if ( ! $capture->rollback() || ! $capture->retire() ) { throw new RuntimeException( 'Owned sealed observation source cleanup refused.' ); }
		$stack = new Stack( $args['prefix'], $fixture, $command, $clock );
		if ( 'reader-left' === $role ) {
			$stack->saved->on_proof = static function( $session, int $proof ) use ( $stack, $directory ): void {
				if ( 2 !== $proof ) { return; }
				$signal = json_encode( [ 'connection_id' => $stack->physical->transports[0]->connection_id() ], JSON_THROW_ON_ERROR ); $file = $directory . '/reader-held';
				if ( strlen( $signal ) !== file_put_contents( $file . '.tmp', $signal, LOCK_EX ) || ! chmod( $file . '.tmp', 0600 ) || ! rename( $file . '.tmp', $file ) ) { throw new RuntimeException( 'Owned sealed observation lock signal failed.' ); }
				OperationProofProcess::wait_for( $directory . '/release', 12.0 );
			};
		}
		$evidence = $stack->reader->current_sealed( $command, $stack->inputs(), $revision, RequestContext::create() );
		$out = [ 'process_id' => getmypid(), 'ready' => null !== $evidence, 'quote_id' => $evidence?->header()->id()->value(), 'binding_id' => $evidence?->binding()->id(), 'binding_revision' => $evidence?->binding()->revision(), 'sealed_at' => $evidence?->sealed_at()->sql(), 'evaluated_at' => $evidence?->evaluated_at()->sql(), 'control_revision' => $evidence?->control_revision(), 'snapshot_digest' => $evidence?->binding()->row()['snapshot_digest'], 'context_digest' => $evidence?->binding()->row()['context_digest'], 'effect_sql' => $stack->effect_sql(), 'retired' => $stack->factory->sessions[0]->is_retired() ?? false, 'in_transaction' => $stack->factory->sessions[0]->in_transaction() ?? false, 'synthetic_native_captures' => $fixture['source']->reads, 'saved_proofs' => count( $stack->saved->owners ) ];
		$stack->physical->close_all(); $capture_factory->close_all(); echo json_encode( $out, JSON_THROW_ON_ERROR ), PHP_EOL;
	}
} catch ( Throwable $error ) {
	$stack?->physical->close_all(); $capture_factory?->close_all(); if ( $writer instanceof mysqli ) { try { $writer->rollback(); $writer->close(); } catch ( Throwable ) {} }
	fwrite( STDERR, 'Owned E16 observation worker failed: ' . $error::class . ': ' . $error->getMessage() ); exit( 1 );
}
