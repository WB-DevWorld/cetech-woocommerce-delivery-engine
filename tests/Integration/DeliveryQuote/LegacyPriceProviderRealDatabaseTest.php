<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Integration\DeliveryQuote;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteProviderProofFactory,QuoteStorageProofWpdb};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteProviderProofDatabase as SourceDB,QuoteProviderProofFixtures as SourceF,QuoteFixtures};
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteEngineCapture;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier,OperationProofProcess};
use PHPUnit\Framework\Attributes\{DataProvider,Group};
use PHPUnit\Framework\TestCase;

/** Required real native Q04 SQL proofs; fixture money receipts do not claim Woo execution. */
#[Group('quote-provider-real-db')]
final class LegacyPriceProviderRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories=[];
	private array $processes=[];
	private array $directories=[];
	private array $saved_globals=[];
	protected function setUp():void {
		foreach(['wpdb','blog_id'] as $key){$this->saved_globals[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];}
		$this->database=DB::connect();$this->prefix=DB::prefix();DB::install($this->database,$this->prefix);
		$GLOBALS['wpdb']=new QuoteStorageProofWpdb($this->database,$this->prefix);$GLOBALS['blog_id']=1;
	}
	protected function tearDown():void {
		foreach($this->processes as $p){$p->kill();}foreach($this->factories as $f){$f->close_all();}foreach($this->directories as $d){OperationProofBarrier::cleanup($d);}
		if(isset($this->database,$this->prefix)){DB::cleanup($this->database,$this->prefix);$this->database->close();}
		foreach($this->saved_globals as $key=>[$exists,$value]){if($exists){$GLOBALS[$key]=$value;}else{unset($GLOBALS[$key]);}}
	}
	private function factory(?callable $configure=null):QuoteProviderProofFactory{$f=new QuoteProviderProofFactory($this->prefix);$f->configure=null===$configure?null:\Closure::fromCallable($configure);$this->factories[]=$f;return $f;}
	public static function retained_fixed_amounts():array{return ['fixed shipment'=>['fixed_per_shipment','12.5000','12.5000'],'fixed item'=>['fixed_per_item','12.5000','25.0000'],'configured zero'=>['fixed_per_shipment','0.0000','0.0000']];}
	#[DataProvider('retained_fixed_amounts')]
	public function test_actual_retained_rate_engine_capture_preserves_fixed_types_and_explicit_zero(string $type,string $base,string $expected):void {
		$id=\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteProviderProofDatabase::rate($this->database,$this->prefix,['charge_type'=>$type,'base_amount'=>$base]);
		$groups=\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::context()->private_facts()['groups'];$groups[0]['service_id']=$groups[0]['offer_id'];$context=\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::context(['groups'=>$groups]);
		$repository=new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbRateCardRepository();$engine=new \CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine($repository);$old=$engine->quote(new \CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest(20,50,2,new \CetechDeliveryEngine\Domain\ValueObject\CurrencyCode('GHS'),origin_id:40));self::assertTrue($old->success);self::assertSame($type,$old->charge_type);self::assertSame($expected,$old->amount->amount());
		$captured=(new \CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteEngineCapture())->capture($context,$repository);self::assertCount(1,$captured);self::assertSame($expected,$captured[0]['expected']->facts()['amount']);self::assertSame($type,$captured[0]['charge_type']);self::assertSame($id,$captured[0]['rate_card_id']);
	}
	public static function positive_scopes():array{return ['origin absent'=>['origin','origin_id','absent'],'origin unknown'=>['origin','origin_id','unknown'],'supplier absent'=>['supplier','supplier_id','absent'],'supplier unknown'=>['supplier','supplier_id','unknown'],'profile absent'=>['profile','logistics_profile_id','absent'],'profile unknown'=>['profile','logistics_profile_id','unknown']];}
	#[DataProvider('positive_scopes')]
	public function test_physical_positive_scoped_price_does_not_become_a_wildcard_price(string $dimension,string $column,string $state):void {
		\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteProviderProofDatabase::rate($this->database,$this->prefix,[$column=>40]);
		$repository=new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbRateCardRepository();
		$legacy=(new \CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine($repository))->quote(new \CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest(20,50,2,new \CetechDeliveryEngine\Domain\ValueObject\CurrencyCode('GHS')));
		self::assertTrue($legacy->success,'The retained wildcard matcher establishes this regression premise.');
		$data=\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::context()->private_facts();$data['groups'][0]['service_id']=20;$data['groups'][0][$dimension]=['state'=>$state,'id'=>null];
		$this->expectException(\InvalidArgumentException::class);(new \CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteEngineCapture())->capture(\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext::from_array($data),$repository);
	}
	public function test_retained_effective_to_is_inclusive_for_an_actual_stored_card():void {
		\CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteProviderProofDatabase::rate($this->database,$this->prefix,['effective_from'=>'2026-10-07 05:00:00','effective_to'=>'2026-10-07 05:01:00']);
		$repository=new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbRateCardRepository();$cards=$repository->listActiveForQuoteMatch(20,50,'GHS');self::assertCount(1,$cards);
		$engine=new \CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine($repository);$method=new \ReflectionMethod($engine,'is_effective');
		self::assertFalse($method->invoke($engine,$cards[0],'2026-10-07 04:59:59'));
		self::assertTrue($method->invoke($engine,$cards[0],'2026-10-07 05:00:00'));
		self::assertTrue($method->invoke($engine,$cards[0],'2026-10-07 05:01:00'));
		self::assertFalse($method->invoke($engine,$cards[0],'2026-10-07 05:01:01'));
	}
	private function seeded():void{SourceF::seed($this->database,$this->prefix);SourceDB::rate($this->database,$this->prefix);}
	private function verifies(\CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot $snapshot,?QuoteProviderProofFactory $factory=null):bool{$s=($factory??$this->factory())->open();self::assertTrue($s->begin());try{return $snapshot->guard()->verify($s,QuoteFixtures::owner(),$snapshot->context());}finally{self::assertTrue($s->rollback());self::assertTrue($s->retire());}}
	public function test_native_capture_and_current_guard_retire_with_exact_bounded_source_queries():void {
		$this->seeded();$f=$this->factory();$snapshot=SourceF::bind(SourceF::capture($f));
		self::assertSame(1,$snapshot->candidate_count(20,50,'GHS'));self::assertCount(1,$snapshot->rows_for('scope_fields'));self::assertCount(1,$snapshot->rows_for('scope_collections'));self::assertSame('12.5000',(new LegacyQuoteEngineCapture())->capture($snapshot->context(),$snapshot->active_repository())[0]['expected']->amount());
		self::assertTrue($f->sessions[0]->is_retired());self::assertFalse($f->sessions[0]->in_transaction());self::assertLessThanOrEqual(16,count($snapshot->plan()->tables($f->sessions[0])));
		$rate_queries=array_values(array_filter($f->transports[0]->sql,static fn(string $sql):bool=>str_contains($sql,' FORCE INDEX ')));self::assertCount(1,$rate_queries);self::assertStringContainsString('FORCE INDEX (`quote_candidate_range`)',$rate_queries[0]);self::assertStringContainsString(' LIMIT 1001 FOR UPDATE',$rate_queries[0]);self::assertStringNotContainsString('status=',$rate_queries[0]);self::assertTrue($this->verifies($snapshot));
		self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_delivery_quotes`"));self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_records`"));
	}
	public function test_actual_complete_thousand_candidate_range_uses_named_index_and_retained_winner():void {
		SourceF::seed($this->database,$this->prefix);for($id=1;$id<=1000;++$id){SourceDB::rate($this->database,$this->prefix,['internal_code'=>'rate_'.$id,'priority'=>$id,'base_amount'=>1===$id?'7.0000':'12.5000']);}
		$f=$this->factory();$snapshot=SourceF::bind(SourceF::capture($f));self::assertSame(1000,$snapshot->candidate_count(20,50,'GHS'));$charges=(new LegacyQuoteEngineCapture())->capture($snapshot->context(),$snapshot->active_repository());self::assertSame('7.0000',$charges[0]['expected']->amount());self::assertSame(1,$charges[0]['rate_card_id']);
		$queries=array_values(array_filter($f->transports[0]->sql,static fn(string $sql):bool=>str_contains($sql,' FORCE INDEX ')));self::assertCount(1,$queries);$plan=$this->database->query('EXPLAIN '.str_replace(' FOR UPDATE','',$queries[0]));self::assertInstanceOf(\mysqli_result::class,$plan);$row=$plan->fetch_assoc();self::assertSame('quote_candidate_range',$row['key']);self::assertContains($row['type'],['ref','range']);self::assertLessThanOrEqual(1001,max($f->transports[0]->row_counts));
	}
	public function test_actual_thousand_plus_one_candidate_range_refuses_before_a_partial_winner():void {
		SourceF::seed($this->database,$this->prefix);for($id=1;$id<=1001;++$id){SourceDB::rate($this->database,$this->prefix,['internal_code'=>'rate_'.$id,'priority'=>$id]);}
		$f=$this->factory();$snapshot=null;try{$snapshot=SourceF::capture($f);self::fail('The sentinel must refuse the complete range.');}catch(\CetechDeliveryEngine\Application\Operation\OperationStorageException){self::assertNull($snapshot);}self::assertTrue($f->sessions[0]->is_retired());self::assertContains(1001,$f->transports[0]->row_counts);self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_records`"));
	}
	public static function source_changes():array{return ['same-second rate edit'=>['rate'],'higher precedence insertion'=>['rate_insert'],'scope field without version change'=>['scope_field'],'scope collection without version change'=>['scope_collection'],'warm stock metadata'=>['stock'],'route flag'=>['option'],'offer service'=>['offer'],'origin status'=>['origin'],'zone priority'=>['zone'],'destination rule insertion'=>['zone_rule'],'product eligibility'=>['product']];}
	private function mutate(string $change):void {
		$sql=match($change){'rate'=>"UPDATE `{$this->prefix}delivery_engine_rate_cards` SET base_amount='99.0000' WHERE id=1",'scope_field'=>"UPDATE `{$this->prefix}delivery_engine_configuration_fields` SET value_text='41' WHERE id=1",'scope_collection'=>"UPDATE `{$this->prefix}delivery_engine_configuration_collections` SET members_json='[]' WHERE id=1",'stock'=>"UPDATE `{$this->prefix}postmeta` SET meta_value='outofstock' WHERE meta_key='_stock_status'",'option'=>"UPDATE `{$this->prefix}options` SET option_value='0' WHERE option_name='cetech_de_enable_effective_configuration_runtime'",'offer'=>"UPDATE `{$this->prefix}delivery_engine_delivery_offers` SET service_level='express' WHERE id=20",'origin'=>"UPDATE `{$this->prefix}delivery_engine_origins` SET status='inactive' WHERE id=40",'zone'=>"UPDATE `{$this->prefix}delivery_engine_destination_zones` SET priority=1 WHERE id=50",'product'=>"UPDATE `{$this->prefix}posts` SET post_status='draft' WHERE ID=10",default=>null};
		if(null!==$sql){DB::execute($this->database,$sql);}elseif('rate_insert'===$change){SourceDB::rate($this->database,$this->prefix,['internal_code'=>'later_priority','priority'=>0,'base_amount'=>'1.0000']);}elseif('zone_rule'===$change){SourceDB::insert($this->database,$this->prefix,'destination_rules',['zone_id'=>50,'rule_type'=>'country','rule_value'=>'GH']);}else{throw new \InvalidArgumentException('Unknown source mutation.');}
	}
	#[DataProvider('source_changes')]
	public function test_prepared_source_and_warm_guard_refuse_each_independent_physical_change(string $change):void {
		$this->seeded();$snapshot=SourceF::bind(SourceF::capture($this->factory()));self::assertTrue($this->verifies($snapshot));self::assertTrue($this->verifies($snapshot));$opened=(string)DB::scalar($this->database,"SELECT updated_at FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1");$this->mutate($change);self::assertFalse($this->verifies($snapshot));self::assertSame($opened,(string)DB::scalar($this->database,"SELECT updated_at FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1"));self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_changes`"));
	}
	public function test_preopened_rr_snapshot_cannot_hide_a_later_committed_rate_edit():void {
		$this->seeded();$snapshot=SourceF::bind(SourceF::capture($this->factory()));$f=$this->factory();$s=$f->open();self::assertTrue($s->begin());$old=$s->get_row("SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1");self::assertSame('12.5000',$old['base_amount']);$this->mutate('rate');self::assertSame('12.5000',$s->get_row("SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1")['base_amount']);self::assertFalse($snapshot->guard()->verify($s,QuoteFixtures::owner(),$snapshot->context()));self::assertTrue($s->rollback());self::assertTrue($s->retire());self::assertSame('99.0000',DB::scalar($this->database,"SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1"));
	}
	private function directory():string{$d=OperationProofBarrier::directory();$this->directories[]=$d;return $d;}
	private function worker(array $args):OperationProofProcess{$p=new OperationProofProcess([__DIR__.'/provider-process-worker.php',json_encode(['prefix'=>$this->prefix]+$args,JSON_THROW_ON_ERROR)]);$this->processes[]=$p;return $p;}
	private function connection_signal(string $path,OperationProofProcess $process):int{OperationProofProcess::wait_for($path);$r=json_decode((string)file_get_contents($path),true,4,JSON_THROW_ON_ERROR);self::assertSame($process->pid(),$r['process_id']);self::assertGreaterThan(0,$r['connection_id']);return $r['connection_id'];}
	private function wait_for_rate_wait(int $waiter,int $blocker):void {
		self::assertNotSame($waiter,$blocker);$table=$this->prefix.'delivery_engine_rate_cards';$sql="SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";$end=microtime(true)+1.5;
		do{if((int)DB::scalar($this->database,$sql)>0){self::assertTrue(true);return;}$remaining=$end-microtime(true);if($remaining<=0){break;}usleep((int)min(120000,ceil($remaining*1000000)));}while(microtime(true)<$end);self::fail('The exact source writer did not enter the held native rate-range lock.');
	}
	public static function rate_writes():array{return ['same-second existing row'=>['edit'],'higher-precedence insert into captured range'=>['insert']];}
	#[DataProvider('rate_writes')]
	public function test_current_range_fence_holds_edit_and_insertion_until_owned_followup_commit(string $mutation):void {
		$this->seeded();$d=$this->directory();$holder=$this->worker(['action'=>'guard_hold','ready'=>$d.'/held','release'=>$d.'/release']);$blocker=$this->connection_signal($d.'/held',$holder);$writer=$this->worker(['action'=>'write_rate','mutation'=>$mutation,'ready'=>$d.'/writer']);$waiter=$this->connection_signal($d.'/writer',$writer);
		try{$this->wait_for_rate_wait($waiter,$blocker);self::assertSame('12.5000',DB::scalar($this->database,"SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1"));self::assertSame('0',DB::scalar($this->database,"SELECT value FROM `{$this->prefix}operation_fixture_counter` WHERE id=1"));}finally{OperationProofBarrier::signal($d.'/release');}
		self::assertSame(['valid'=>true,'write'=>true,'commit'=>'acknowledged','retired'=>true,'timeouts'=>0,'deadlocks'=>0],$holder->finish());self::assertSame(['written'=>true,'base_amount'=>'99.0000'],$writer->finish());self::assertSame('1',DB::scalar($this->database,"SELECT value FROM `{$this->prefix}operation_fixture_counter` WHERE id=1"));self::assertSame('99.0000',DB::scalar($this->database,"SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` ORDER BY id DESC LIMIT 1"));
	}
	public function test_actual_blocked_source_guard_refuses_at_the_existing_two_second_query_budget():void {
		$this->seeded();$snapshot=SourceF::bind(SourceF::capture($this->factory()));DB::execute($this->database,'START TRANSACTION');DB::execute($this->database,"UPDATE `{$this->prefix}delivery_engine_rate_cards` SET base_amount='88.0000' WHERE id=1");$f=$this->factory();$s=$f->open();self::assertTrue($s->begin());self::assertSame('2',$s->get_row('SELECT @@SESSION.innodb_lock_wait_timeout AS bound')['bound']);$at=microtime(true);
		try{self::assertFalse($snapshot->guard()->verify($s,QuoteFixtures::owner(),$snapshot->context()));$elapsed=microtime(true)-$at;self::assertGreaterThanOrEqual(1.5,$elapsed);self::assertLessThan(4.0,$elapsed);self::assertSame(1,$f->transports[0]->lock_timeouts);self::assertSame(0,$f->transports[0]->deadlocks);}finally{self::assertTrue($s->rollback());self::assertTrue($s->retire());DB::execute($this->database,'ROLLBACK');}
		self::assertSame('12.5000',DB::scalar($this->database,"SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1"));self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_records`"));
	}
	public static function variation_changes():array{return ['variation reparented'=>['reparent'],'variation disappears'=>['remove']];}
	#[DataProvider('variation_changes')]
	public function test_exact_native_variation_parent_identity_is_fenced(string $change):void {
		$this->seeded();SourceDB::product($this->database,$this->prefix,11,'product_variation',10);$facts=SourceF::context()->private_facts();$facts['lines'][0]['variation_id']=11;$facts['lines'][0]['parent_id']=10;$context=QuoteContext::from_array($facts);$snapshot=SourceF::bind(SourceF::capture($this->factory(),$context));self::assertTrue($this->verifies($snapshot));
		DB::execute($this->database,'reparent'===$change?"UPDATE `{$this->prefix}posts` SET post_parent=12 WHERE ID=11":"DELETE FROM `{$this->prefix}posts` WHERE ID=11");self::assertFalse($this->verifies($snapshot));self::assertSame(11,$snapshot->context()->private_facts()['lines'][0]['variation_id']);self::assertSame(10,$snapshot->context()->private_facts()['lines'][0]['parent_id']);
	}
	public static function zone_bounds():array{return ['exact two hundred'=>[200,true],'two hundred plus sentinel'=>[201,false]];}
	#[DataProvider('zone_bounds')]
	public function test_actual_complete_zone_universe_refuses_only_the_sentinel(int $count,bool $allowed):void {
		$this->seeded();for($n=1;$n<$count;++$n){SourceDB::zone($this->database,$this->prefix,['id'=>50+$n,'internal_code'=>'zone_'.$n]);}$f=$this->factory();$snapshot=null;try{$snapshot=SourceF::capture($f);}catch(\CetechDeliveryEngine\Application\Operation\OperationStorageException){self::assertFalse($allowed);}if($allowed){self::assertNotNull($snapshot);self::assertCount(200,$snapshot->rows_for('zones'));}else{self::assertNull($snapshot);self::assertContains(201,$f->transports[0]->row_counts);}self::assertTrue($f->sessions[0]->is_retired());
	}
	public function test_oversized_actual_source_text_refuses_in_sql_before_private_materialization():void {
		$this->seeded();DB::execute($this->database,"UPDATE `{$this->prefix}delivery_engine_configuration_fields` SET value_text='".str_repeat('x',8193)."' WHERE id=1");$f=$this->factory();try{SourceF::capture($f);self::fail('Oversized source evidence must refuse.');}catch(\CetechDeliveryEngine\Application\Operation\OperationStorageException){self::assertTrue($f->sessions[0]->is_retired());}$queries=array_values(array_filter($f->transports[0]->sql,static fn(string $sql):bool=>str_contains($sql,' AS source_oversized')));self::assertNotEmpty($queries);self::assertStringContainsString('OCTET_LENGTH(`value_text`)<=8192',implode("\n",$queries));self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_delivery_quotes`"));
	}
	public static function mixed_member_dimensions():array{return ['origin first order'=>['origin','origin_id',false],'origin reverse order'=>['origin','origin_id',true],'supplier first order'=>['supplier','supplier_id',false],'supplier reverse order'=>['supplier','supplier_id',true],'profile first order'=>['profile','logistics_profile_id',false],'profile reverse order'=>['profile','logistics_profile_id',true]];}
	#[DataProvider('mixed_member_dimensions')]
	public function test_plan_refuses_two_physical_scoped_members_with_mixed_identity_in_both_orders(string $dimension,string $field,bool $reverse):void {
		$this->seeded();SourceDB::product($this->database,$this->prefix,11);SourceDB::insert($this->database,$this->prefix,'configuration_scopes',['id'=>2,'scope_type'=>'product','scope_id'=>11,'slice_key'=>'fulfilment','status'=>'active','config_version'=>3,'source'=>'native']);SourceDB::insert($this->database,$this->prefix,'configuration_fields',['id'=>2,'scope_row_id'=>2,'field_key'=>$field,'mode'=>'set','value_type'=>'integer','value_text'=>'41']);
		$facts=SourceF::context()->private_facts();$second=$facts['lines'][0];$second['line_key']='line_two';$second['product_id']=11;$facts['lines'][]=$second;$facts['groups'][0]['line_keys'][]='line_two';$context=QuoteContext::from_array($facts);$plan=SourceF::plan($context);$members=$plan->member_proofs();
		$actual=DB::row($this->database,"SELECT value_text FROM `{$this->prefix}delivery_engine_configuration_fields` WHERE scope_row_id=2");self::assertSame('41',$actual['value_text']);self::assertSame('2',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_configuration_scopes`"));$members[1][$dimension]=['state'=>'known','id'=>(int)$actual['value_text']];if($reverse){$members=array_reverse($members);}
		$this->expectException(\InvalidArgumentException::class);\CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourcePlan::create(QuoteFixtures::owner(),$context,$members,$plan->rate_ranges(),$plan->fences());
	}
	public function test_real_source_owner_is_retired_before_synthetic_native_receipt_capture():void {
		$this->seeded();$f=$this->factory();$native=new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteProviderProofNativeSource($f);$stack=new \CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteProviderStack($f,new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeReceiptCapture($native));$context=SourceF::context();$component=$context->private_facts()['groups'][0]['component_key'];$prepared=$stack->prepare(QuoteFixtures::owner(),$context,SourceF::plan($context),[$component=>'physical_fixture|delivery|20']);
		self::assertTrue($native->all_prior_owners_retired);self::assertSame(1,$native->reads);self::assertCount(2,$f->sessions);foreach($f->sessions as $s){self::assertTrue($s->is_retired());self::assertFalse($s->in_transaction());}self::assertSame('12.5000',$prepared->terms()->private_facts()['groups'][0]['final']['amount']);self::assertSame(['reason'=>'cost_provider_unavailable','state'=>'unavailable'],$prepared->terms()->private_facts()['groups'][0]['cost']);$provider=$prepared->provider();$registry=$prepared->registry();self::assertSame($prepared->terms()->to_private_json(),$registry->capture($provider->code(),1,$provider->profile(),1,$prepared->context())->to_private_json());self::assertSame(1,$native->reads);self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_records`"));
	}
	public function test_mutation_during_synthetic_native_capture_is_refused_by_fresh_post_capture_source_owner():void {
		$this->seeded();$f=$this->factory();$native=new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteProviderProofNativeSource($f);$native->during_capture=fn()=> $this->mutate('rate');$stack=new \CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteProviderStack($f,new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeReceiptCapture($native));$context=SourceF::context();$component=$context->private_facts()['groups'][0]['component_key'];
		try{$stack->prepare(QuoteFixtures::owner(),$context,SourceF::plan($context),[$component=>'physical_fixture|delivery|20']);self::fail('A changed physical source cannot disclose prepared terms.');}catch(\RuntimeException){self::assertTrue($native->all_prior_owners_retired);self::assertSame(1,$native->reads);self::assertCount(2,$f->sessions);foreach($f->sessions as $s){self::assertTrue($s->is_retired());}self::assertSame('99.0000',DB::scalar($this->database,"SELECT base_amount FROM `{$this->prefix}delivery_engine_rate_cards` WHERE id=1"));self::assertSame('0',DB::scalar($this->database,"SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_operation_records`"));}
	}

}
