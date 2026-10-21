<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionEligibilityBudgetGuard;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueService, QuoteCurrentEvidenceValidity, QuoteProviderRegistry};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory, QuoteLifecycleProofPublication};

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$args = json_decode( $argv[1] ?? '', true, 32, JSON_THROW_ON_ERROR ); if ( ! is_array( $args ) ) { throw new RuntimeException( 'E10 worker input refused.' ); } DB::validate_prefix( $args['prefix'] );
	$factory = new QuoteLifecycleProofFactory( $args['prefix'] );
	$eligibility = new class implements FixedPromotionEligibilityBudgetGuard {
		public int $proofs = 0;
		public function tables( OperationSession $session ): array { throw new LogicException( 'Recovery cannot request current eligibility tables.' ); }
		public function validity_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { ++$this->proofs; throw new LogicException( 'Recovery cannot reevaluate eligibility.' ); }
		public function validity_for_requests_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { ++$this->proofs; throw new LogicException( 'Recovery cannot reevaluate eligibility.' ); }
	};
	// Empty provider registry: this process cannot recapture, even accidentally.
	$service = new FixedPromotionQuoteIssueService( $factory, new QuoteProviderRegistry(), new SqlFixedPromotionBudgetStore(), $eligibility, static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, static function(): never { throw new LogicException( 'Recovery cannot reserve funds.' ); }, control: new EmergencyControlStore( static fn(): bool => false ), publication: new QuoteLifecycleProofPublication() );
	if ( ! is_string( $args['recovery_file'] ?? null ) || ! str_starts_with( basename( dirname( $args['recovery_file'] ) ), 'cetech-operation-proof-' ) || 'original-recovery.json' !== basename( $args['recovery_file'] ) ) { throw new RuntimeException( 'E10 recovery file refused.' ); }
	$bytes = file_get_contents( $args['recovery_file'] ); if ( ! is_string( $bytes ) || strlen( $bytes ) > FixedPromotionQuoteIssueCommand::MAX_RECOVERY_BYTES ) { throw new RuntimeException( 'E10 recovery file refused.' ); }
	$recovery = FixedPromotionQuoteIssueCommand::from_recovery_array( json_decode( $bytes, true, 32, JSON_THROW_ON_ERROR ) ); $result = $service->reconcile( $recovery, RequestContext::create() ); $writes = 0;
	foreach ( $factory->transports as $transport ) { foreach ( $transport->sql as $sql ) { if ( 1 === preg_match( '/\A(?:INSERT INTO|UPDATE|DELETE FROM) /', $sql ) && ( str_contains( $sql, 'promotion_budget_' ) || str_contains( $sql, 'delivery_engine_delivery_quote' ) || str_contains( $sql, 'delivery_engine_operation_changes' ) ) ) { ++$writes; } } }
	$out = [ 'process_id' => getmypid(), 'state' => $result->attempt->outcome->state, 'replayed' => $result->attempt->replayed, 'eligibility_proofs' => $eligibility->proofs, 'effect_writes' => $writes, 'header_json' => $result->quote?->header()->to_private_json(), 'error' => $result->attempt->outcome->error?->code ];
	$factory->close_all(); echo json_encode( $out, JSON_THROW_ON_ERROR ), PHP_EOL;
} catch ( Throwable $error ) { fwrite( STDERR, 'Owned E10 recovery worker failed: ' . $error::class . ': ' . $error->getMessage() ); exit( 1 ); }
