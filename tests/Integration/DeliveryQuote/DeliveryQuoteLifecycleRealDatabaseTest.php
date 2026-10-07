<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionAttempt;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionGate;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofStack as Stack;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofTransport as Transport;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageProofWpdb;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier as Barrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess as Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Native Q03 SQL/process proofs. Missing prerequisites fail; no native pricing/adoption claim. */
#[Group( 'quote-lifecycle-real-db' )]
final class DeliveryQuoteLifecycleRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];
	private array $globals;
	protected function setUp(): void {
		$this->globals = []; foreach ( [ 'wpdb', 'blog_id' ] as $key ) { $this->globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix );
		DB::execute($this->database, "CREATE TABLE `{$this->prefix}quote_fixture_history_refs` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,site_id bigint unsigned NOT NULL,quote_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,UNIQUE KEY exact_quote (site_id,quote_uuid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		$GLOBALS['wpdb'] = new QuoteStorageProofWpdb( $this->database, $this->prefix ); $GLOBALS['blog_id'] = 1;
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); } foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { Barrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
		foreach ( $this->globals as $key => [ $existed, $value ] ) { if ( $existed ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
	}
	private function stack( int $clock = Factory::NOW, ?callable $configure = null ): Stack { $s = new Stack( $this->prefix, $clock, $configure ); $this->factories[] = $s->factory; return $s; }
	private function factory( int $clock = Factory::NOW, ?callable $configure = null ): Factory { $f = new Factory( $this->prefix ); $f->clock = $clock; $f->configure = null === $configure ? null : \Closure::fromCallable( $configure ); $this->factories[] = $f; return $f; }
	private function gate( Factory $factory ): QuoteAdmissionGate { return new QuoteAdmissionGate( $factory, static fn( QuoteOwner $owner, string $purpose ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $purpose, null, new EmergencyControlStore( static fn(): bool => false ) ); }
	private function native_count( string $suffix ): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}`" ); }
	private function rows( string $suffix ): array { $r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Quote proof rows refused.' ); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function owner( string $session ): QuoteOwner { return QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'principal_hash' => QuoteFixtures::digest( 'principal:' . $session ), 'session_hash' => QuoteFixtures::digest( 'session:' . $session ) ] ) ); }
	private function directory(): string { $d = Barrier::directory(); $this->directories[] = $d; return $d; }
	private function worker( array $args ): Process { $p = new Process( [ __DIR__ . '/lifecycle-process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $args, JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	private function signal( string $path, Process $p ): int { Process::wait_for( $path ); $r = json_decode( (string) file_get_contents( $path ), true, 4, JSON_THROW_ON_ERROR ); self::assertSame( $p->pid(), $r['process_id'] ); self::assertGreaterThan( 0, $r['connection_id'] ); return $r['connection_id']; }
	private function wait_for_wait( int $waiter, int $blocker, string $suffix = 'options' ): void {
		self::assertNotSame( $waiter, $blocker ); $end = microtime( true ) + 1.5;
		$table = 'options' === $suffix ? $this->prefix . 'options' : $this->prefix . 'delivery_engine_' . $suffix;
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		do { if ( (int) DB::scalar( $this->database, $sql ) > 0 ) { self::assertTrue( true ); return; } $remaining = $end - microtime( true ); if ( $remaining <= 0 ) { break; } usleep( (int) min( 120000, ceil( $remaining * 1000000 ) ) ); } while ( microtime( true ) < $end );
		self::fail( 'The exact second native quote owner did not enter a lock wait.' );
	}
	private function accept_result( QuoteDurableResult $r ): void { self::assertSame( 'accepted', $r->attempt->outcome->state ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertNull( $r->attempt->outcome->error ); }
	private function issue( ?Stack $stack = null, string $token = 'original', ?QuoteOwner $owner = null ): QuoteDurableResult { $s = $stack ?? $this->stack(); $r = $s->service->issue( Stack::command( $token, $owner ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame('accepted',$r->attempt->outcome->state,json_encode(['owners'=>count($s->factory->sessions),'captures'=>$s->provider->captures,'command_capture'=>null!==$r->command?->capture(),'quote_rows'=>$this->native_count('delivery_quotes'),'record_rows'=>$this->native_count('operation_records'),'audit_rows'=>$this->native_count('operation_changes'),'error'=>$r->attempt->outcome->error?->code],JSON_THROW_ON_ERROR)); $this->accept_result( $r ); self::assertNotNull( $r->quote ); self::assertNotNull( $r->command?->reference() ); return $r; }
	private function envelope( QuoteDurableResult $issued ): array { return [ 'owner' => $issued->quote->header()->owner()->facts(), 'reference' => $issued->command->reference()->public_fields(), 'header_json' => $issued->quote->header()->to_private_json(), 'context' => $issued->quote->context()->private_facts() ]; }
	private function control( string $state, string $token ): void {
		$f = $this->factory(); $s = new EmergencyControlService( $f, static fn( int $site, int $actor ): bool => 1 === $site && 9 === $actor, new EmergencyControlStore( static fn(): bool => false ), trusted_equivalent_route: true );
		$opened = $s->read( 1 ); self::assertTrue( $opened->available ); $r = $s->transition( EmergencyControlCommand::identity( 1, 9, $token ), EmergencyControlCommand::payload( $opened->state, $state, 'enabled' === $state ? 'resume_verified' : 'operator_pause' ), RequestContext::create() ); self::assertSame( 'accepted', $r->outcome->state );
	}

	public function test_native_owner_has_actual_rr_snapshot_zero_clock_and_two_second_lock_budget(): void {
		$s = $this->factory()->open(); self::assertTrue( $s->begin() ); $r = $s->get_row( 'SELECT @@SESSION.tx_isolation AS isolation_level,@@SESSION.innodb_snapshot_isolation AS snapshot_isolation,@@SESSION.innodb_lock_wait_timeout AS wait_seconds,UTC_TIMESTAMP(6) AS utc' );
		self::assertSame( [ 'REPEATABLE-READ', '0', '2', '2026-10-07 05:00:00.000000' ], array_values( $r ) ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
	}
	public function test_original_admission_replay_never_spends_twice_or_grants_another_capture(): void {
		$f = $this->factory(); $g = $this->gate( $f ); $command = Stack::command(); $attempt = QuoteAdmissionAttempt::generate(); $r = $g->admit( $command, $attempt ); self::assertSame( 'capture_allowed', $r->status ); self::assertTrue( $r->lease->claim_capture() ); self::assertFalse( $r->lease->claim_capture() ); $before = $this->rows( 'delivery_quote_budget_windows' );
		self::assertSame( 'pending', $g->admit( $command, QuoteAdmissionAttempt::generate() )->status ); self::assertSame( 'pending', $g->reconcile( $command, $attempt )->status ); self::assertSame( $before, $this->rows( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->native_count( 'operation_records' ) ); self::assertSame( 0, $this->native_count( 'delivery_quotes' ) );
	}
	public function test_different_intent_under_one_admission_namespace_refuses_without_charging(): void {
		$g = $this->gate( $this->factory() ); self::assertSame( 'capture_allowed', $g->admit( Stack::command(), QuoteAdmissionAttempt::generate() )->status ); $before = $this->rows( 'delivery_quote_budget_windows' );
		$context = QuoteFixtures::context( [ 'selection_digest' => QuoteFixtures::digest( 'different' ) ] ); $r = $g->admit( Stack::command( context: $context ), QuoteAdmissionAttempt::generate() ); self::assertSame( [ 'denied', 'intent_conflict' ], [ $r->status, $r->reason ] ); self::assertSame( $before, $this->rows( 'delivery_quote_budget_windows' ) );
	}
	public function test_actual_unsent_gate_commit_rolls_back_all_counters_and_lease(): void {
		$f = $this->factory( configure: static function( Transport $t ): void { $t->fault_commit = 1; $t->commit_fault = 'unsent'; } ); $r = $this->gate( $f )->admit( Stack::command(), QuoteAdmissionAttempt::generate() );
		self::assertSame( [ 'denied', 'commit_not_sent' ], [ $r->status, $r->reason ] ); self::assertSame( 0, $this->native_count( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->native_count( 'operation_records' ) ); self::assertSame( 0, $f->transports[0]->sent_commits );
		self::assertSame( 'capture_allowed', $this->gate( $this->factory() )->admit( Stack::command(), QuoteAdmissionAttempt::generate() )->status );
	}
	public function test_actual_committed_gate_lost_ack_reconciles_only_same_live_attempt_once(): void {
		$f = $this->factory( configure: static function( Transport $t ): void { $t->fault_commit = 1; $t->commit_fault = 'lost_ack'; } ); $attempt = QuoteAdmissionAttempt::generate(); $command = Stack::command(); $r = $this->gate( $f )->admit( $command, $attempt );
		self::assertSame( 'unconfirmed', $r->status ); self::assertTrue( $f->sessions[0]->is_retired() ); self::assertSame( 1, $f->transports[0]->sent_commits ); $before = $this->rows( 'delivery_quote_budget_windows' ); self::assertCount( 3, $before );
		$fresh = $this->worker( [ 'action' => 'inspect_admission', 'token' => 'original' ] )->finish(); self::assertSame( 'pending', $fresh['status'] ); self::assertSame( 0, $fresh['captures'] );
		$g = $this->gate( $this->factory() ); $confirmed = $g->reconcile( $command, $attempt ); self::assertSame( 'capture_allowed', $confirmed->status ); self::assertTrue( $confirmed->lease->claim_capture() ); self::assertSame( 'pending', $g->reconcile( $command, $attempt )->status ); self::assertSame( $before, $this->rows( 'delivery_quote_budget_windows' ) );
	}
	public function test_dead_lease_at_exact_sixty_seconds_is_terminated_and_never_renewed(): void {
		$command = Stack::command(); $r = $this->gate( $this->factory() )->admit( $command, QuoteAdmissionAttempt::generate() ); self::assertSame( 'capture_allowed', $r->status ); $original = $r->lease->slot()->row();
		$g = $this->gate( $this->factory( Factory::NOW + 60 ) ); self::assertSame( [ 'denied', 'lease_expired' ], [ $g->inspect( $command )->status, $g->inspect( $command )->reason ] ); self::assertSame( 'lease_terminated', $g->reconcile( $command )->reason );
		$g = $this->gate( $this->factory( Factory::NOW + 86460 ) ); self::assertSame( 'lease_terminated', $g->admit( $command, QuoteAdmissionAttempt::generate() )->reason ); $rows = $this->rows( 'delivery_quote_budget_windows' ); $lease = array_values( array_filter( $rows, static fn( array $x ): bool => 'admission' === $x['slot_kind'] ) )[0]; self::assertSame( $original['lease_expires_at'], $lease['lease_expires_at'] ); self::assertSame( 3, count( $rows ) ); self::assertSame( 0, $this->native_count( 'operation_records' ) );
	}
	public function test_two_actual_processes_at_session_limit_admit_only_twentieth_and_no_reservations(): void {
		$g = $this->gate( $this->factory() ); for ( $i = 0; $i < 19; ++$i ) { self::assertSame( 'capture_allowed', $g->admit( Stack::command( 'prior-' . $i ), QuoteAdmissionAttempt::generate() )->status ); }
		$d = $this->directory(); $left = $this->worker( [ 'action' => 'admit', 'token' => 'left', 'start_ready' => $d . '/left', 'start_release' => $d . '/go' ] ); $right = $this->worker( [ 'action' => 'admit', 'token' => 'right', 'start_ready' => $d . '/right', 'start_release' => $d . '/go' ] ); Process::wait_for( $d . '/left' ); Process::wait_for( $d . '/right' ); Barrier::signal( $d . '/go' ); $results = [ $left->finish(), $right->finish() ];
		self::assertSame( 1, count( array_filter( $results, static fn( array $x ): bool => 'capture_allowed' === $x['status'] ) ) ); self::assertSame( 1, count( array_filter( $results, static fn( array $x ): bool => 'budget_exhausted' === $x['reason'] ) ) ); self::assertNotSame( $results[0]['process_id'], $results[1]['process_id'] );
		foreach ( $this->rows( 'delivery_quote_budget_windows' ) as $row ) { if ( 'admission' !== $row['slot_kind'] ) { self::assertSame( '20', (string) $row['attempt_count'] ); } } self::assertSame( 22, $this->native_count( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->native_count( 'operation_records' ) ); self::assertSame( 0, $this->native_count( 'delivery_quotes' ) );
	}
	public function test_two_actual_processes_at_site_limit_admit_only_two_hundredth_across_sessions(): void {
		$g = $this->gate( $this->factory() ); for ( $i = 0; $i < 199; ++$i ) { self::assertSame( 'capture_allowed', $g->admit( Stack::command( 'prior-' . $i, $this->owner( (string) intdiv( $i, 20 ) ) ), QuoteAdmissionAttempt::generate() )->status ); }
		$d = $this->directory(); $left = $this->worker( [ 'action' => 'admit', 'token' => 'left', 'owner' => $this->owner( 'left' )->facts(), 'start_ready' => $d . '/left', 'start_release' => $d . '/go' ] ); $right = $this->worker( [ 'action' => 'admit', 'token' => 'right', 'owner' => $this->owner( 'right' )->facts(), 'start_ready' => $d . '/right', 'start_release' => $d . '/go' ] ); Process::wait_for( $d . '/left' ); Process::wait_for( $d . '/right' ); Barrier::signal( $d . '/go' ); $results = [ $left->finish(), $right->finish() ];
		self::assertSame( 1, count( array_filter( $results, static fn( array $x ): bool => 'capture_allowed' === $x['status'] ) ) ); self::assertSame( 1, count( array_filter( $results, static fn( array $x ): bool => 'budget_exhausted' === $x['reason'] ) ) );
		$rows = $this->rows( 'delivery_quote_budget_windows' ); $site = array_values( array_filter( $rows, static fn( array $x ): bool => 'site_minute' === $x['slot_kind'] ) ); self::assertSame( '200', (string) $site[0]['attempt_count'] ); self::assertSame( 200, count( array_filter( $rows, static fn( array $x ): bool => 'admission' === $x['slot_kind'] ) ) ); self::assertSame( 0, $this->native_count( 'operation_records' ) );
	}
	public function test_missing_control_key_fence_waits_on_same_gate_owner_before_pause_can_commit(): void {
		$d = $this->directory(); $admission = $this->worker( [ 'action' => 'admit', 'token' => 'admit-first', 'lock_kind' => 'control', 'lock_ready' => $d . '/held', 'lock_release' => $d . '/release' ] ); $blocker = $this->signal( $d . '/held', $admission );
		$opened = \CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState::absent( 1 );
		$pause = $this->worker( [ 'action' => 'control', 'token' => 'pause-after', 'payload' => EmergencyControlCommand::payload( $opened, 'checkout_suspended', 'operator_pause' ), 'lock_kind' => 'control', 'lock_dispatch' => $d . '/dispatch' ] ); $waiter = $this->signal( $d . '/dispatch', $pause ); $this->wait_for_wait( $waiter, $blocker ); Barrier::signal( $d . '/release' ); $a = $admission->finish(); $b = $pause->finish(); self::assertSame( 'capture_allowed', $a['status'] ); self::assertSame( 'accepted', $b['state'] ); self::assertSame( 3, $this->native_count( 'delivery_quote_budget_windows' ) );
	}
	public function test_current_control_lock_escapes_preopened_repeatable_read_snapshot(): void {
		$this->control( 'checkout_suspended', 'initial-pause' ); $this->control( 'enabled', 'initial-resume' ); $d = $this->directory(); $admission = $this->worker( [ 'action' => 'admit', 'token' => 'snapshot', 'lock_kind' => 'control', 'snapshot_ready' => $d . '/snapshot', 'snapshot_release' => $d . '/release' ] ); $this->signal( $d . '/snapshot', $admission ); $this->control( 'checkout_suspended', 'new-pause' ); Barrier::signal( $d . '/release' ); $r = $admission->finish(); self::assertSame( [ 'denied', 'checkout_suspended' ], [ $r['status'], $r['reason'] ] ); self::assertSame( 0, $this->native_count( 'delivery_quote_budget_windows' ) ); self::assertSame( 0, $this->native_count( 'delivery_quotes' ) );
	}
	public function test_fractional_native_timestamp_has_exact_microseconds(): void {
		$f=$this->factory();$s=$f->open();self::assertTrue($f->transports[0]->native_execute('SET timestamp=1791351300.123456')->acknowledged);self::assertTrue($s->begin());
		self::assertSame('2026-10-07 05:35:00.123456',$s->get_row('SELECT UTC_TIMESTAMP(6) AS utc')['utc']);self::assertTrue($s->rollback());self::assertTrue($s->retire());
	}
	public function test_lost_gate_ack_followed_by_committed_pause_cannot_grant_capture(): void {
		$f=$this->factory(configure:static function(Transport $t):void{$t->fault_commit=1;$t->commit_fault='lost_ack';});$a=QuoteAdmissionAttempt::generate();$c=Stack::command();self::assertSame('unconfirmed',$this->gate($f)->admit($c,$a)->status);
		$this->control('checkout_suspended','pause-after-unknown');$before=$this->rows('delivery_quote_budget_windows');$r=$this->gate($this->factory())->reconcile($c,$a);
		self::assertSame(['denied','checkout_suspended'],[$r->status,$r->reason]);self::assertNull($r->lease);self::assertSame($before,$this->rows('delivery_quote_budget_windows'));self::assertSame(0,$this->native_count('delivery_quotes'));self::assertSame(1,$this->native_count('operation_records'));
	}
	public function test_issue_original_replay_returns_one_quote_one_event_and_original_expiry(): void {
		$s=$this->stack();$r=$this->issue($s);$before=$this->rows('delivery_quotes');$expiry=$r->quote->header()->expires_at()->sql();$r2=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());$this->accept_result($r2);
		self::assertTrue($r2->attempt->replayed);self::assertSame($expiry,$r2->quote->header()->expires_at()->sql());self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame(1,$s->provider->captures);self::assertSame(1,$this->native_count('operation_records'));self::assertSame(1,$this->native_count('operation_changes'));self::assertSame('consumed',$this->rows('delivery_quote_budget_windows')[2]['lease_state']);
	}
	public function test_provider_capture_is_outside_owned_transaction_and_duplicate_stays_pending(): void {
		$d=$this->directory();$issuer=$this->worker(['action'=>'issue','token'=>'original','capture_ready'=>$d.'/capture','capture_release'=>$d.'/release','capture_counter'=>$d.'/counter']);Process::wait_for($d.'/capture');
		self::assertSame(0,$this->native_count('operation_records'));self::assertSame(0,$this->native_count('delivery_quotes'));self::assertSame(3,$this->native_count('delivery_quote_budget_windows'));
		$other=$this->worker(['action'=>'issue','token'=>'original','capture_counter'=>$d.'/counter'])->finish();self::assertSame('pending',$other['state']);self::assertSame(0,$other['captures']);self::assertSame('1',trim((string)file_get_contents($d.'/counter')));
		Barrier::signal($d.'/release');$r=$issuer->finish();self::assertSame('accepted',$r['state']);self::assertSame(1,$this->native_count('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));self::assertSame('1',trim((string)file_get_contents($d.'/counter')));
	}
	public function test_pause_after_capture_admission_is_rechecked_before_any_quote_write(): void {
		$s=$this->stack();$s->provider->before_capture=function():void{$this->control('checkout_suspended','pause-before-c03');};$r=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());
		self::assertSame('rejected',$r->attempt->outcome->state);self::assertFalse($r->attempt->outcome->mutation_accepted);self::assertSame(1,$s->provider->captures);self::assertSame(0,$this->native_count('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));self::assertSame('granted',$this->rows('delivery_quote_budget_windows')[2]['lease_state']);
	}
	public function test_two_native_acceptors_share_one_completion_and_one_event(): void {
		$r=$this->issue();$e=$this->envelope($r);$d=$this->directory();$first=$this->worker($e+['action'=>'accept','lock_kind'=>'quote','lock_ready'=>$d.'/held','lock_release'=>$d.'/release']);$this->signal($d.'/held',$first);
		$second=$this->worker($e+['action'=>'accept','lock_kind'=>'namespace_insert','namespace_hash'=>$r->quote->header()->namespace_hashes()['accept'],'lock_dispatch'=>$d.'/second']);$waiter=$this->signal($d.'/second',$second);$held=json_decode((string)file_get_contents($d.'/held'),true,4,JSON_THROW_ON_ERROR)['connection_id'];$this->wait_for_wait($waiter,$held,'operation_records');Barrier::signal($d.'/release');$a=$first->finish();$b=$second->finish();self::assertSame('accepted',$a['state']);self::assertSame('accepted',$b['state']);self::assertSame(1,(int)$a['replayed']+(int)$b['replayed']);self::assertSame(2,$this->native_count('operation_changes'));self::assertSame(2,$this->native_count('operation_records'));self::assertSame('accepted',$this->rows('delivery_quotes')[0]['state']);
	}
	public function test_accept_replay_after_101_real_later_records_in_fresh_os_process(): void {
		$r=$this->issue();$e=$this->envelope($r);$a=$this->stack()->service->accept($r->quote->header()->owner(),$r->command->reference(),$r->quote->header(),$r->quote->context(),RequestContext::create());$this->accept_result($a);
		for($i=0;$i<101;++$i){$this->control(0===$i%2?'checkout_suspended':'enabled','later-'.$i);} $before=$this->rows('delivery_quotes');$n=$this->native_count('operation_changes');$worker=$this->worker($e+['action'=>'reconcile_accept']);$out=$worker->finish();
		self::assertNotSame(getmypid(),$out['process_id']);self::assertSame('accepted',$out['state']);self::assertTrue($out['replayed']);self::assertSame(0,$out['quote_writes']);self::assertSame(0,$out['audit_appends']);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame($n,$this->native_count('operation_changes'));self::assertSame(103,$this->native_count('operation_records'));
	}
	public static function atomic_faults():array {return ['quote refusal'=>['quote'],'audit refusal'=>['audit'],'completion refusal'=>['completion'],'unsent effect commit'=>['unsent']];}
	#[DataProvider('atomic_faults')]
	public function test_issue_atomic_refusal_has_no_quote_event_or_consumed_admission(string $fault):void {
		$s=$this->stack(configure:static function(Transport $t,int $n)use($fault):void{if(2!==$n)return;match($fault){'quote'=>$t->reject_quote=true,'audit'=>$t->reject_audit=true,'completion'=>$t->reject_completion=true,'unsent'=>[$t->fault_commit=2,$t->commit_fault='unsent']};});
		$r=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());self::assertNotSame(true,$r->attempt->outcome->mutation_accepted);self::assertContains($r->attempt->outcome->state,['rejected','unconfirmed']);self::assertSame(0,$this->native_count('delivery_quotes'));self::assertSame(0,$this->native_count('operation_changes'));self::assertSame('granted',$this->rows('delivery_quote_budget_windows')[2]['lease_state']);self::assertNotSame('accepted',$this->rows('operation_records')[0]['state']);
	}
	public function test_actual_issue_effect_commit_lost_ack_is_reconciled_in_fresh_process_without_recapture():void {
		$s=$this->stack(configure:static function(Transport $t,int $n):void{if(2===$n){$t->fault_commit=2;$t->commit_fault='lost_ack';}});$r=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());self::assertSame('unconfirmed',$r->attempt->outcome->state);self::assertTrue($s->factory->sessions[1]->is_retired());self::assertSame(2,$s->factory->transports[1]->sent_commits);self::assertSame(1,$this->native_count('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));
		$before=$this->rows('delivery_quotes');$out=$this->worker(['action'=>'reconcile_issue','token'=>'original'])->finish();self::assertSame('accepted',$out['state']);self::assertTrue($out['replayed']);self::assertSame(0,$out['captures']);self::assertSame(0,$out['quote_writes']);self::assertSame(0,$out['audit_appends']);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));
	}
	public function test_failed_private_publication_reconcile_only_invalidates_original_key():void {
		$s=$this->stack();$s->publication->allow=false;$r=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());self::assertTrue($r->attempt->outcome->mutation_accepted);self::assertTrue($r->attempt->outcome->publication_pending);$before=$this->rows('delivery_quotes');self::assertSame(1,$this->native_count('operation_changes'));
		$s->publication->allow=true;$again=$s->service->reconcile($r->command,RequestContext::create());$this->accept_result($again);self::assertFalse($again->attempt->outcome->publication_pending);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));self::assertSame(1,$s->provider->captures);self::assertCount(2,$s->publication->calls);self::assertSame($s->publication->calls[0],$s->publication->calls[1]);self::assertSame(['site_id','owner_digest','quote_id'],array_keys($s->publication->calls[0]));
	}
	public function test_wrong_owner_changed_material_and_exact_expiry_do_not_accept_or_renew():void {
		$r=$this->issue();$s=$this->stack();$before=$this->rows('delivery_quotes');$wrong=$s->service->accept($this->owner('other'),$r->command->reference(),$r->quote->header(),$r->quote->context(),RequestContext::create());self::assertSame('rejected',$wrong->attempt->outcome->state);
		$changed=QuoteFixtures::context(['selection_digest'=>QuoteFixtures::digest('changed-selection')]);$bad=$s->service->accept($r->quote->header()->owner(),$r->command->reference(),$r->quote->header(),$changed,RequestContext::create());self::assertSame('rejected',$bad->attempt->outcome->state);self::assertSame($before,$this->rows('delivery_quotes'));
		$expired=$this->stack(Factory::NOW+300);$read=$expired->service->current($r->quote->header()->owner(),$r->command->reference(),$r->quote->context(),RequestContext::create());self::assertSame('quote_expired',$read->reason);$a=$expired->service->accept($r->quote->header()->owner(),$r->command->reference(),$r->quote->header(),$r->quote->context(),RequestContext::create());self::assertFalse($a->attempt->outcome->mutation_accepted);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));
	}

	private function retention(Factory $factory,?\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofReferences $refs=null,?callable $clock=null):\CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionService {
		return new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionService($factory,\CetechDeliveryEngine\Application\DeliveryQuote\QuoteOperationProfile::registry(),static fn(int $site):bool=>1===$site,$refs??new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofReferences(),new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionNativeControl(true),monotonic_clock:$clock);
	}
	private function retention_start(\CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionService $service):\CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionResult { $r=$service->start(1,RequestContext::create());self::assertSame('accepted',$r->attempt->outcome->state);self::assertNotNull($r->checkpoint);return $r; }
	public static function strip_boundaries():array{return ['one microsecond before'=>['1791351299.999999',0],'exact expires plus 30 minutes'=>['1791351300.000000',1]];}
	#[DataProvider('strip_boundaries')]
	public function test_retention_exact_boundary_strips_only_unused_body_and_keeps_receipts(string $timestamp,int $stripped):void {
		$r=$this->issue();$before=$this->rows('delivery_quotes')[0];$records=$this->rows('operation_records');$events=$this->rows('operation_changes');$budgets=$this->rows('delivery_quote_budget_windows');$f=$this->factory(Factory::NOW+2100,static function(Transport $t)use($timestamp):void{if(!$t->native_execute('SET timestamp='.$timestamp)->acknowledged)throw new \RuntimeException('Fractional retention fixture refused.');});$s=$this->retention($f);$start=$this->retention_start($s);$batch=$s->batch($start->checkpoint,RequestContext::create());self::assertSame('accepted',$batch->attempt->outcome->state);self::assertSame($stripped,$batch->checkpoint->facts()['stripped']);$after=$this->rows('delivery_quotes')[0];
		self::assertSame($before['header_json'],$after['header_json']);self::assertSame($before['body_digest'],$after['body_digest']);self::assertSame($before['expires_at'],$after['expires_at']);self::assertSame(0===$stripped?$before['private_body_json']:null,$after['private_body_json']);self::assertSame(0===$stripped?'issued':'stripped',$after['state']);self::assertSame($records[0],$this->rows('operation_records')[0]);self::assertSame($events[0],$this->rows('operation_changes')[0]);self::assertSame($budgets,$this->rows('delivery_quote_budget_windows'));
	}
	public static function protective_references():array{return ['unknown registered history'=>['unknown'],'exact history row'=>['history'],'accepted quote'=>['accepted'],'pending issue publication'=>['publication'],'unknown original admission'=>['admission']];}
	#[DataProvider('protective_references')]
	public function test_retention_protective_reference_cannot_strip_original_body(string $kind):void {
		$s=$this->stack();if('publication'===$kind)$s->publication->allow=false;$r=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());self::assertTrue($r->attempt->outcome->mutation_accepted);$q=$r->quote;
		$refs=new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofReferences();if('unknown'===$kind)$refs->mode='unknown';if('history'===$kind){$id=$this->database->real_escape_string($q->header()->id()->value());DB::execute($this->database,"INSERT INTO `{$this->prefix}quote_fixture_history_refs` (site_id,quote_uuid) VALUES(1,'{$id}')");}
		if('accepted'===$kind){$this->accept_result($s->service->accept($q->header()->owner(),$r->command->reference(),$q->header(),$q->context(),RequestContext::create()));}
		if('admission'===$kind)DB::execute($this->database,"UPDATE `{$this->prefix}delivery_engine_delivery_quote_budget_windows` SET lease_state='granted',consumed_quote_uuid=NULL,consumed_at=NULL,revision=1 WHERE slot_kind='admission'");
		$before=$this->rows('delivery_quotes');$ret=$this->retention($this->factory(Factory::NOW+2100),$refs);$start=$this->retention_start($ret);$batch=$ret->batch($start->checkpoint,RequestContext::create());self::assertSame('accepted',$batch->attempt->outcome->state);self::assertSame(0,$batch->checkpoint->facts()['stripped']);self::assertSame(1,$batch->checkpoint->facts()['protected']);self::assertSame($before,$this->rows('delivery_quotes'));
	}
	public function test_retention_page_has_fixed_ceiling_and_at_most_100_inspected_candidates():void {
		$table=$this->prefix.'delivery_engine_delivery_quotes';for($i=0;$i<101;++$i){$q=\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures::quote($i+1);$fields=array_keys($q->row());$values=array_map(fn($v):string=>null===$v?'NULL':"'".$this->database->real_escape_string((string)$v)."'",array_values($q->row()));DB::execute($this->database,"INSERT INTO `{$table}` (`".implode('`,`',$fields)."`) VALUES(".implode(',',$values).')');}
		$s=$this->retention($this->factory(Factory::NOW+2100));$start=$this->retention_start($s);self::assertSame(101,$start->checkpoint->facts()['quote_ceiling']);$later=$this->issue(token:'late',owner:$this->owner('late'));self::assertSame(102,$this->native_count('delivery_quotes'));$b=$s->batch($start->checkpoint,RequestContext::create());self::assertSame('accepted',$b->attempt->outcome->state);self::assertSame(100,$b->checkpoint->facts()['inspected']);self::assertSame(100,$b->checkpoint->facts()['quote_cursor']);self::assertSame(101,$b->checkpoint->facts()['quote_ceiling']);$c=$s->batch($b->checkpoint,RequestContext::create());self::assertSame('accepted',$c->attempt->outcome->state);self::assertSame(101,$c->checkpoint->facts()['inspected']);self::assertSame(101,$c->checkpoint->facts()['quote_cursor']);self::assertSame('issued',$this->rows('delivery_quotes')[101]['state']);
	}
	public function test_retention_refused_checkpoint_rolls_back_body_and_retries_exact_original_ceiling():void {
		$this->issue();$good=$this->retention($this->factory(Factory::NOW+2100));$start=$this->retention_start($good);$before=$this->rows('delivery_quotes');$bad=$this->retention($this->factory(Factory::NOW+2100,static function(Transport $t):void{$t->reject_completion=true;}));$failure=$bad->batch($start->checkpoint,RequestContext::create());self::assertNotSame('accepted',$failure->attempt->outcome->state);self::assertNull($failure->checkpoint);self::assertSame($before,$this->rows('delivery_quotes'));
		$this->issue(token:'late',owner:$this->owner('late'));$retry=$good->batch($start->checkpoint,RequestContext::create());self::assertSame('accepted',$retry->attempt->outcome->state);self::assertSame(1,$retry->checkpoint->facts()['quote_ceiling']);self::assertSame(1,$retry->checkpoint->facts()['stripped']);self::assertSame('issued',$this->rows('delivery_quotes')[1]['state']);
	}
	public function test_retention_soft_stop_keeps_cursor_and_does_not_claim_two_second_total():void {
		$this->issue();$ticks=[0.0,0.0,2.0];$s=$this->retention($this->factory(Factory::NOW+2100),clock:static function()use(&$ticks):float{return array_shift($ticks)??2.0;});$start=$this->retention_start($s);$before=$this->rows('delivery_quotes');$batch=$s->batch($start->checkpoint,RequestContext::create());self::assertSame('accepted',$batch->attempt->outcome->state);self::assertTrue($batch->checkpoint->facts()['soft_stopped']);self::assertSame(0,$batch->checkpoint->facts()['quote_cursor']);self::assertSame(0,$batch->checkpoint->facts()['inspected']);self::assertSame($before,$this->rows('delivery_quotes'));
	}
	public function test_retention_removes_only_old_minute_counters_not_admission_tombstone():void {
		$c=Stack::command();$gate=$this->gate($this->factory());self::assertSame('capture_allowed',$gate->admit($c,QuoteAdmissionAttempt::generate())->status);self::assertSame('lease_terminated',$this->gate($this->factory(Factory::NOW+60))->reconcile($c)->reason);$s=$this->retention($this->factory(Factory::NOW+86460));$start=$this->retention_start($s);$batch=$s->batch($start->checkpoint,RequestContext::create());self::assertSame('accepted',$batch->attempt->outcome->state);self::assertSame(2,$batch->checkpoint->facts()['counters_deleted']);self::assertSame(1,$batch->checkpoint->facts()['protected']);self::assertSame(1,$this->native_count('delivery_quote_budget_windows'));self::assertSame('terminated',$this->rows('delivery_quote_budget_windows')[0]['lease_state']);self::assertSame('lease_terminated',$this->gate($this->factory(Factory::NOW+86460))->admit($c,QuoteAdmissionAttempt::generate())->reason);self::assertSame(1,$this->native_count('delivery_quote_budget_windows'));
	}

	private function retention_envelope(\CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionResult $start):array {return ['action'=>'retention_batch','clock'=>Factory::NOW+2100,'checkpoint'=>$start->checkpoint->facts(),'checkpoint_namespace'=>$start->checkpoint->namespace_hash()];}
	public function test_accept_owner_has_no_foreign_producer_namespace_lock_before_current_quote_write():void {
		$r=$this->issue();$s=$this->stack();$a=$s->service->accept($r->quote->header()->owner(),$r->command->reference(),$r->quote->header(),$r->quote->context(),RequestContext::create());$this->accept_result($a);$issue=$r->quote->header()->namespace_hashes()['issue'];$own=$r->quote->header()->namespace_hashes()['accept'];$effect=$s->factory->transports[0]->sql;
		$foreign=array_filter($effect,static fn(string $sql):bool=>str_starts_with($sql,'SELECT ')&&str_contains($sql,'delivery_engine_operation_records')&&str_contains($sql,$issue));self::assertNotEmpty($foreign);foreach($foreign as $sql)self::assertStringNotContainsString('FOR UPDATE',$sql);
		$own_locks=array_filter($effect,static fn(string $sql):bool=>str_starts_with($sql,'SELECT ')&&str_contains($sql,'FOR UPDATE')&&str_contains($sql,'delivery_engine_operation_records')&&str_contains($sql,$own));self::assertNotEmpty($own_locks);
	}
	public function test_retention_waits_for_inflight_accept_namespace_and_keeps_accepted_body():void {
		$r=$this->issue();$ret=$this->retention($this->factory(Factory::NOW+2100));$start=$this->retention_start($ret);$d=$this->directory();
		$collect=$this->worker($this->retention_envelope($start)+['phase_ready'=>$d.'/reserved','phase_release'=>$d.'/begin','lock_kind'=>'producer','namespace_hashes'=>array_values($r->quote->header()->namespace_hashes()),'lock_dispatch'=>$d.'/collector']);Process::wait_for($d.'/reserved');
		$accept=$this->worker($this->envelope($r)+['action'=>'accept','lock_kind'=>'quote','lock_ready'=>$d.'/accept-held','lock_release'=>$d.'/accept-release']);$blocker=$this->signal($d.'/accept-held',$accept);Barrier::signal($d.'/begin');
		try{$waiter=$this->signal($d.'/collector',$collect);}catch(\RuntimeException $error){Barrier::signal($d.'/accept-release');$a=$accept->finish();$b=$collect->finish();self::fail(json_encode(['accept'=>$a,'collector'=>$b],JSON_THROW_ON_ERROR));}$this->wait_for_wait($waiter,$blocker,'operation_records');Barrier::signal($d.'/accept-release');$a=$accept->finish();$b=$collect->finish();self::assertSame('accepted',$a['state'],json_encode(['accept'=>$a,'collector'=>$b],JSON_THROW_ON_ERROR));self::assertSame('accepted',$b['state'],json_encode(['accept'=>$a,'collector'=>$b],JSON_THROW_ON_ERROR));self::assertSame(0,$b['checkpoint']['stripped']);self::assertSame('accepted',$this->rows('delivery_quotes')[0]['state']);self::assertNotNull($this->rows('delivery_quotes')[0]['private_body_json']);self::assertSame(0,$a['lock_timeouts']+$b['lock_timeouts']);self::assertSame(0,$a['deadlocks']+$b['deadlocks']);
	}
	public function test_retention_missing_accept_namespace_fence_blocks_new_acceptor_without_deadlock():void {
		$r=$this->issue();$ret=$this->retention($this->factory(Factory::NOW+2100));$start=$this->retention_start($ret);$d=$this->directory();$collect=$this->worker($this->retention_envelope($start)+['lock_kind'=>'namespace','namespace_hash'=>$r->quote->header()->namespace_hashes()['accept'],'lock_ready'=>$d.'/collector-held','lock_release'=>$d.'/collector-release']);$blocker=$this->signal($d.'/collector-held',$collect);
		$accept=$this->worker($this->envelope($r)+['action'=>'accept','lock_kind'=>'namespace_insert','namespace_hash'=>$r->quote->header()->namespace_hashes()['accept'],'lock_dispatch'=>$d.'/accept']);$waiter=$this->signal($d.'/accept',$accept);$this->wait_for_wait($waiter,$blocker,'operation_records');Barrier::signal($d.'/collector-release');$b=$collect->finish();$a=$accept->finish();self::assertSame('accepted',$b['state']);self::assertSame(1,$b['checkpoint']['stripped']);self::assertFalse($a['accepted']);self::assertSame('stripped',$this->rows('delivery_quotes')[0]['state']);self::assertSame(0,$a['lock_timeouts']+$b['lock_timeouts']);self::assertSame(0,$a['deadlocks']+$b['deadlocks']);
	}
	public function test_retention_exact_history_absence_holds_quote_until_late_reference_writer_refuses():void {
		$r=$this->issue();$ret=$this->retention($this->factory(Factory::NOW+2100));$start=$this->retention_start($ret);$d=$this->directory();$collect=$this->worker($this->retention_envelope($start)+['lock_kind'=>'history','lock_ready'=>$d.'/history-held','lock_release'=>$d.'/history-release']);$blocker=$this->signal($d.'/history-held',$collect);
		$writer=$this->worker(['action'=>'history_writer','quote_id'=>$r->quote->header()->id()->value(),'lock_kind'=>'quote','lock_dispatch'=>$d.'/writer']);$waiter=$this->signal($d.'/writer',$writer);$this->wait_for_wait($waiter,$blocker,'delivery_quotes');Barrier::signal($d.'/history-release');$c=$collect->finish();$w=$writer->finish();self::assertSame('accepted',$c['state']);self::assertSame(1,$c['checkpoint']['stripped']);self::assertSame('body_unavailable',$w['state']);self::assertSame(0,(int)DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}quote_fixture_history_refs`"));
	}
	public function test_retention_lost_commit_ack_replays_checkpoint_without_second_mutation_in_fresh_process():void {
		$this->issue();$start=$this->retention_start($this->retention($this->factory(Factory::NOW+2100)));$f=$this->factory(Factory::NOW+2100,static function(Transport $t):void{$t->fault_commit=2;$t->commit_fault='lost_ack';});$r=$this->retention($f)->batch($start->checkpoint,RequestContext::create());self::assertSame('unconfirmed',$r->attempt->outcome->state);self::assertNull($r->checkpoint);self::assertSame('stripped',$this->rows('delivery_quotes')[0]['state']);$before=$this->rows('delivery_quotes');$n=$this->native_count('operation_changes');$out=$this->worker(['action'=>'retention_reconcile','clock'=>Factory::NOW+2100,'retention_request'=>$r->request->to_private_array()])->finish();self::assertSame('accepted',$out['state']);self::assertTrue($out['replayed']);self::assertSame(1,$out['checkpoint']['stripped']);self::assertSame(0,$out['quote_writes']);self::assertSame(0,$out['audit_appends']);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame($n,$this->native_count('operation_changes'));
	}

	public function test_actual_binding_prepare_seal_and_original_replays_write_exactly_once():void {
		$r=$this->issue();$s=$this->stack();$a=$s->service->accept($r->quote->header()->owner(),$r->command->reference(),$r->quote->header(),$r->quote->context(),RequestContext::create());$this->accept_result($a);$q=$a->quote;$b=\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures::binding($q);$names=QuoteDurableCommand::binding_namespaces($q->header()->owner(),$q->header(),$b->row()['placement_uuid']);$b=\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row(array_replace($b->row(),['bind_namespace_hash'=>$names['bind'],'seal_namespace_hash'=>$names['seal']]),$q);
		$bound=$s->service->bind($q->header()->owner(),$r->command->reference(),$q->header(),$b,RequestContext::create(),$q->context());$this->accept_result($bound);self::assertSame('prepared',$bound->binding->state());$verified=\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row(array_replace($bound->binding->row(),['revision'=>2,'snapshot_digest'=>QuoteFixtures::digest('physical-fixture-snapshot'),'context_digest'=>$q->header()->material_digest(),'verified_at'=>$q->accepted_at()->sql()]),$q);
		$sealed=$s->service->seal($q->header()->owner(),$r->command->reference(),$q->header(),$verified,RequestContext::create(),$q->context());$this->accept_result($sealed);self::assertSame('sealed',$sealed->binding->state());self::assertSame(3,$sealed->binding->revision());$before=$this->rows('delivery_quote_bindings');$n=$this->native_count('operation_changes');$again=$s->service->reconcile($sealed->command,RequestContext::create());$this->accept_result($again);self::assertTrue($again->attempt->replayed);self::assertSame($before,$this->rows('delivery_quote_bindings'));self::assertSame($n,$this->native_count('operation_changes'));self::assertSame(4,$n);self::assertSame(0,$s->provider->captures);
	}
	public function test_actual_accept_cas_transport_refusal_rolls_back_and_keeps_opened_generation():void {
		$r=$this->issue();$before=$this->rows('delivery_quotes');$s=$this->stack(configure:static function(Transport $t):void{$t->miss_quote_cas=true;});$a=$s->service->accept($r->quote->header()->owner(),$r->command->reference(),$r->quote->header(),$r->quote->context(),RequestContext::create());self::assertNotSame(true,$a->attempt->outcome->mutation_accepted);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame(1,$this->native_count('operation_changes'));self::assertSame(0,array_sum(array_column($s->factory->transports,'quote_writes')));
	}
	public function test_refused_rollback_and_close_leave_unknown_until_fresh_native_reconciliation():void {
		$s=$this->stack(configure:static function(Transport $t,int $n):void{if(2===$n){$t->reject_audit=true;$t->reject_rollback=true;$t->reject_close=true;}});$r=$s->service->issue(Stack::command(),QuoteAdmissionAttempt::generate(),RequestContext::create());self::assertSame('unconfirmed',$r->attempt->outcome->state);self::assertNull($r->attempt->outcome->mutation_accepted);self::assertTrue($s->factory->sessions[1]->is_retired());$s->factory->close_all();self::assertSame(0,$this->native_count('delivery_quotes'));self::assertSame(0,$this->native_count('operation_changes'));self::assertSame('granted',$this->rows('delivery_quote_budget_windows')[2]['lease_state']);$fresh=$this->worker(['action'=>'reconcile_issue','token'=>'original'])->finish();self::assertSame('rejected',$fresh['state']);self::assertSame(0,$fresh['captures']);self::assertSame(0,$fresh['quote_writes']);self::assertSame(0,$fresh['audit_appends']);self::assertSame(0,$this->native_count('delivery_quotes'));
	}
	public function test_prewarmed_reader_never_returns_issued_fact_after_committed_invalidation():void {
		$r=$this->issue();$s=$this->stack();$owner=$r->quote->header()->owner();$reference=$r->command->reference();$warm=$s->service->current($owner,$reference,$r->quote->context(),RequestContext::create());self::assertSame('ready',$warm->status);self::assertNull($warm->reason);$changed=QuoteFixtures::context(['selection_digest'=>QuoteFixtures::digest('later-cart')]);$in=$this->stack()->service->invalidate($owner,$reference,$r->quote->header(),$changed,RequestContext::create());$this->accept_result($in);self::assertSame('invalidated',$in->quote->state());$after=$s->service->current($owner,$reference,$changed,RequestContext::create());self::assertSame('ready',$after->status);self::assertSame('quote_invalidated',$after->reason);self::assertSame('invalidated',$after->quote->state());self::assertSame(2,$this->native_count('operation_changes'));
	}

	public static function opened_candidate_changes():array {return ['disappeared'=>['disappeared'],'changed exact generation'=>['changed']];}
	#[DataProvider('opened_candidate_changes')]
	public function test_retention_never_mutates_candidate_removed_or_changed_after_real_membership_read(string $change):void {
		$r=$this->issue();$ret=$this->retention($this->factory(Factory::NOW+2100));$start=$this->retention_start($ret);$d=$this->directory();$collect=$this->worker($this->retention_envelope($start)+['lock_kind'=>'quote_page','lock_ready'=>$d.'/opened','lock_release'=>$d.'/release']);$id=$this->signal($d.'/opened',$collect);self::assertGreaterThan(0,$id);$table=$this->prefix.'delivery_engine_delivery_quotes';
		if('disappeared'===$change){DB::execute($this->database,"DELETE FROM `{$table}` WHERE id=1");$before=[];}else{DB::execute($this->database,"UPDATE `{$table}` SET state='invalidated',revision=2,transition_at='2026-10-07 05:35:00.000000' WHERE id=1");$before=$this->rows('delivery_quotes');}
		Barrier::signal($d.'/release');$out=$collect->finish();self::assertSame('accepted',$out['state']);self::assertSame(0,$out['checkpoint']['stripped']);self::assertSame(1,$out['checkpoint'][$change]);self::assertSame($before,$this->rows('delivery_quotes'));self::assertSame(0,$out['quote_writes']);
	}

}
