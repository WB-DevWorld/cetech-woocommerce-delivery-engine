<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionAttempt;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofStack;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

try {
	$a = json_decode( $argv[1] ?? '', true, 32, JSON_THROW_ON_ERROR ); if ( ! is_array( $a ) ) { throw new RuntimeException(); }
	$signal = static function( string $path, QuoteLifecycleProofTransport $t ): void {
		$bytes = json_encode( [ 'connection_id' => $t->connection_id(), 'process_id' => getmypid() ], JSON_THROW_ON_ERROR );
		if ( false === file_put_contents( $path . '.pending', $bytes, LOCK_EX ) || ! rename( $path . '.pending', $path ) ) { throw new RuntimeException( 'Quote proof connection signal refused.' ); }
	};
	$configure = static function( QuoteLifecycleProofTransport $t ) use ( $a, $signal ): void {
		$match = static function( string $sql ) use ( $a ): bool {
			if('quote_page'===($a['lock_kind']??''))return str_starts_with($sql,'SELECT ')&&str_contains($sql,'delivery_engine_delivery_quotes')&&str_contains($sql,'ORDER BY id ASC LIMIT 100');
			if ( 'namespace_insert' === ( $a['lock_kind'] ?? '' ) ) { return str_starts_with($sql,'INSERT INTO ')&&str_contains($sql,'delivery_engine_operation_records')&&str_contains($sql,(string)($a['namespace_hash']??'unmatched')); }
			if ( ! str_starts_with( $sql, 'SELECT ' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return false; }
			return match ( $a['lock_kind'] ?? '' ) {
				'control' => str_contains( $sql, 'cetech_de_checkout_control_v1' ),
				'quote' => str_contains( $sql, 'delivery_engine_delivery_quotes' ),
				'namespace' => str_contains( $sql, 'delivery_engine_operation_records' ) && str_contains( $sql, (string) ( $a['namespace_hash'] ?? 'unmatched' ) ),
				'producer' => str_contains($sql,'delivery_engine_operation_records')&&count(array_filter($a['namespace_hashes']??[],static fn(string $hash):bool=>str_contains($sql,$hash)))>0,
				'budget' => str_contains( $sql, 'delivery_engine_delivery_quote_budget_windows' ),
				'history' => str_contains($sql,'quote_fixture_history_refs'),
				default => false,
			};
		};
		$before_done = false; $after_done = false;
		$t->before = static function( string $sql, QuoteLifecycleProofTransport $transport ) use ( $a, $signal, $match, &$before_done ): void {
			if ( $before_done || ! $match( $sql ) ) { return; } $before_done = true;
			if ( isset( $a['snapshot_ready'], $a['snapshot_release'] ) ) {
				$result = $transport->native_execute( "SELECT option_value FROM `{$a['prefix']}options` WHERE option_name='cetech_de_checkout_control_v1'" ); if ( ! $result->acknowledged ) { throw new RuntimeException( 'Quote proof snapshot refused.' ); }
				$signal( $a['snapshot_ready'], $transport ); OperationProofProcess::wait_for( $a['snapshot_release'], 12.0 );
			}
			if ( isset( $a['lock_dispatch'] ) ) { $signal( $a['lock_dispatch'], $transport ); }
			if ( isset( $a['dispatch_release'] ) ) { OperationProofProcess::wait_for( $a['dispatch_release'], 12.0 ); }
		};
		$t->after = static function( string $sql, QuoteLifecycleProofTransport $transport, $result ) use ( $a, $signal, $match, &$after_done ): void {
			if ( $after_done || ! $match( $sql ) || ! $result->acknowledged || ! isset( $a['lock_ready'], $a['lock_release'] ) ) { return; }
			$after_done = true; $signal( $a['lock_ready'], $transport ); OperationProofProcess::wait_for( $a['lock_release'], 12.0 );
		};
	};

	$observer=isset($a['phase_ready'],$a['phase_release'])?new class($a['phase_ready'],$a['phase_release']) implements CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver { public function __construct(private string $ready,private string $release){} public function observe(string $phase,CetechDeliveryEngine\Domain\Contracts\OperationIdentity $identity):void { if('reservation_committed'===$phase&&'delivery_quote.retention.batch'===$identity->operation)OperationProofBarrier::pause($this->ready,$this->release); } }:null;
	$stack = new QuoteLifecycleProofStack( $a['prefix'], (int) ( $a['clock'] ?? QuoteLifecycleProofFactory::NOW ), $configure, $observer );
	$stack->provider->counter_file = $a['capture_counter'] ?? null; $stack->provider->capture_ready = $a['capture_ready'] ?? null; $stack->provider->capture_release = $a['capture_release'] ?? null;
	if ( isset( $a['start_ready'], $a['start_release'] ) ) { OperationProofBarrier::pause( $a['start_ready'], $a['start_release'] ); }
	$owner = isset( $a['owner'] ) ? QuoteOwner::from_array( $a['owner'] ) : QuoteFixtures::owner(); $current = isset( $a['context'] ) ? QuoteContext::from_array( $a['context'] ) : QuoteFixtures::context();
	$context = RequestContext::create(); $started = microtime( true );
	if ( 'retention_batch' === $a['action'] || 'retention_reconcile' === $a['action'] ) {
		$refs = new CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofReferences();
		$service = new CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionService($stack->factory,CetechDeliveryEngine\Application\DeliveryQuote\QuoteOperationProfile::registry(),static fn(int $site):bool=>1===$site,$refs,new CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionNativeControl(true),observer:$observer);
		if('retention_batch' === $a['action']) { $cp=CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionCheckpoint::from_completion($a['checkpoint'],$a['checkpoint_namespace']);$rr=$service->batch($cp,$context); }
		else { $request=CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionRequest::from_private_array($a['retention_request']);$rr=$service->reconcile($request,$context); }
		$out=['state'=>$rr->attempt->outcome->state,'accepted'=>$rr->attempt->outcome->mutation_accepted,'replayed'=>$rr->attempt->replayed,'checkpoint'=>$rr->checkpoint?->safe(),'error_code'=>$rr->attempt->outcome->error?->code];
	} elseif ( 'history_writer' === $a['action'] ) {
		$session=$stack->factory->open();if(!$session->begin()||!$session->validate_tables([$a['prefix'].'delivery_engine_delivery_quotes',$a['prefix'].'quote_fixture_history_refs']))throw new RuntimeException();
		$repo=new CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository($session);$quote=$repo->find_quote(CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string($a['quote_id']),true);
		if(null===$quote||'stripped'===$quote->state()) { $session->rollback();$out=['state'=>'body_unavailable']; } else {
		 $table=$a['prefix'].'quote_fixture_history_refs';$rows=$session->get_results($session->prepare("SELECT id FROM `{$table}` WHERE site_id=%d AND quote_uuid=%s LIMIT 2 FOR UPDATE",1,$a['quote_id']));if(false===$rows)throw new RuntimeException();
		 if(1!==$session->query($session->prepare("INSERT INTO `{$table}` (site_id,quote_uuid) VALUES(%d,%s)",1,$a['quote_id']))||CetechDeliveryEngine\Domain\Operation\OperationCommitResult::Acknowledged!==$session->commit())throw new RuntimeException();$out=['state'=>'stored'];
		}$session->retire();
	} elseif ( 'control' === $a['action'] ) {
		$service = new EmergencyControlService( $stack->factory, static fn( int $site, int $actor ): bool => 1 === $site && 9 === $actor, new EmergencyControlStore( static fn(): bool => false ), trusted_equivalent_route: true );
		$r = $service->transition( EmergencyControlCommand::identity( 1, 9, $a['token'] ), $a['payload'], $context );
		$out = [ 'state' => $r->outcome->state, 'accepted' => $r->outcome->mutation_accepted, 'replayed' => $r->replayed, 'error_code' => $r->outcome->error?->code ];
	} elseif ( 'admit' === $a['action'] || 'inspect_admission' === $a['action'] ) {
		$command = QuoteLifecycleProofStack::command( $a['token'], $owner, $current );
		$r = 'admit' === $a['action'] ? $stack->gate->admit( $command, QuoteAdmissionAttempt::generate() ) : $stack->gate->inspect( $command );
		$out = [ 'status' => $r->status, 'reason' => $r->reason, 'quote_id' => $r->quote_id?->value() ];
	} else {
		if ( 'issue' === $a['action'] || 'reconcile_issue' === $a['action'] ) {
			$command = QuoteLifecycleProofStack::command( $a['token'], $owner, $current );
			$r = 'issue' === $a['action'] ? $stack->service->issue( $command, QuoteAdmissionAttempt::generate(), $context ) : $stack->service->reconcile( QuoteDurableCommand::issue_probe( $command ), $context );
		} else {
			$reference = QuoteReference::from_array( $a['reference'] ); $header = QuoteHeader::from_json( $a['header_json'] );
			$r = match ( $a['action'] ) {
				'accept' => $stack->service->accept( $owner, $reference, $header, $current, $context ),
				'invalidate' => $stack->service->invalidate( $owner, $reference, $header, $current, $context ),
				'reconcile_accept' => $stack->service->reconcile( QuoteDurableCommand::accept( $owner, $reference, $header, $current ), $context ),
				default => throw new RuntimeException( 'Quote proof action refused.' ),
			};
		}
		$attempt = $r->attempt;
		$out = [ 'state' => $attempt->outcome->state, 'accepted' => $attempt->outcome->mutation_accepted, 'replayed' => $attempt->replayed, 'publication_pending' => $attempt->outcome->publication_pending, 'error_code' => $attempt->outcome->error?->code, 'reason' => $r->reason, 'quote_id' => $r->quote?->header()->id()->value(), 'quote_state' => $r->quote?->state(), 'expiry' => $r->quote?->header()->expires_at()->sql() ];
		if ( isset( $a['envelope_out'] ) && null !== $r->quote && null !== $r->command?->reference() ) {
			$bytes = json_encode( [ 'owner' => $owner->facts(), 'reference' => $r->command->reference()->public_fields(), 'header_json' => $r->quote->header()->to_private_json(), 'context' => $current->private_facts() ], JSON_THROW_ON_ERROR );
			if ( false === file_put_contents( $a['envelope_out'], $bytes, LOCK_EX ) ) { throw new RuntimeException( 'Quote proof envelope refused.' ); }
		}
	}
	$out += [ 'process_id' => getmypid(), 'captures' => $stack->provider->captures, 'elapsed_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'quote_writes' => array_sum( array_column( $stack->factory->transports, 'quote_writes' ) ), 'audit_appends' => array_sum( array_column( $stack->factory->transports, 'audit_appends' ) ), 'record_writes' => array_sum( array_column( $stack->factory->transports, 'record_writes' ) ), 'lock_timeouts' => array_sum( array_column( $stack->factory->transports, 'lock_timeouts' ) ), 'deadlocks' => array_sum(array_column($stack->factory->transports,'deadlocks')) ];
	if(isset($a['deadlock_trace'])) { $traces=array_values(array_filter(array_column($stack->factory->transports,'deadlock_trace')));if([]!==$traces)file_put_contents($a['deadlock_trace'],implode("\n",$traces),LOCK_EX); }
	$stack->factory->close_all(); echo json_encode( $out, JSON_THROW_ON_ERROR );
} catch ( Throwable $error ) { echo json_encode( [ 'proof_error' => 'worker_failed', 'error_class' => get_class( $error ) ], JSON_THROW_ON_ERROR ); exit( 1 ); }
