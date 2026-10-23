<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteAcceptCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteAcceptSqlStack, PromotionQuoteIssueSqlFactory, PromotionQuotePlacementReadSqlStack as Stack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;

require dirname( __DIR__, 2 ) . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuotePlacementReadSqlStack.php';

$capture_factory = null; $stack = null;
try {
	$args = json_decode( $argv[1] ?? '', true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! is_string( $args['command_file'] ?? null ) || ! str_starts_with( basename( dirname( $args['command_file'] ) ), 'cetech-operation-proof-' ) || 'placement-read-recovery.json' !== basename( $args['command_file'] ) ) { throw new RuntimeException( 'Owned placement reader worker refused.' ); }
	DB::validate_prefix( $args['prefix'] );
	$bytes = file_get_contents( $args['command_file'] );
	if ( ! is_string( $bytes ) || strlen( $bytes ) > FixedPromotionQuoteAcceptCommand::MAX_RECOVERY_BYTES ) { throw new RuntimeException( 'Owned placement reader packet refused.' ); }
	$command = FixedPromotionQuoteAcceptCommand::from_recovery_array( json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR ) );
	$decoded = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR );
	if ( ! hash_equals( $bytes, $decoded ) ) { throw new RuntimeException( 'Owned placement reader packet is not canonical.' ); }
	// A fresh synthetic source observation reuses the saved original provenance;
	// it does not invoke issuance, acceptance or any live provider.
	$capture_factory = new Factory( $args['prefix'] ); $session = ( new PromotionQuoteIssueSqlFactory( $capture_factory ) )->open();
	if ( ! $session->begin() ) { throw new RuntimeException( 'Owned placement source transaction refused.' ); }
	$fixture = PromotionQuoteAcceptSqlStack::sources_for( $session, $command );
	if ( ! $session->rollback() || ! $session->retire() ) { throw new RuntimeException( 'Owned placement source cleanup refused.' ); }
	$stack = new Stack( $args['prefix'], $fixture );
	$result = $stack->reader->current( $command, $stack->inputs(), RequestContext::create() );
	$out = [ 'process_id' => getmypid(), 'status' => $result->status, 'body_digest' => $result->evidence?->header()->body_digest(), 'accepted_at' => $result->evidence?->accepted_at()->sql(), 'effect_sql' => $stack->effect_sql(), 'retired' => 1 === count( $stack->factory->sessions ) && $stack->factory->sessions[0]->is_retired() && ! $stack->factory->sessions[0]->in_transaction(), 'synthetic_native_captures' => $fixture['source']->reads ];
	$stack->physical->close_all(); $capture_factory->close_all(); echo json_encode( $out, JSON_THROW_ON_ERROR ), PHP_EOL;
} catch ( Throwable $error ) {
	$stack?->physical->close_all(); $capture_factory?->close_all(); fwrite( STDERR, 'Owned E12 placement reader worker failed: ' . $error::class . ': ' . $error->getMessage() ); exit( 1 );
}
