<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Application\DataLifecycle\ManagedGeographyCache;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope as Envelope;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity as Identity;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheTicket;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagedGeographyCacheTest extends TestCase {
	#[DataProvider('responses')]
	public function test_three_response_schemas_are_preserved_and_search_token_is_never_stored( string $kind, array $response ): void {
		$fixture = new ManagedCacheFixture(); $cache = $fixture->cache(); $identity = $fixture->identity($cache,$kind);
		$ticket = $cache->lookup($identity); self::assertNull($ticket->payload()); self::assertTrue($cache->publish($ticket,$response));
		$expected = $response; unset($expected['request_token']);
		self::assertSame($expected,$cache->lookup($identity)->payload());
		$row = $fixture->database->rows[$identity->option_name()]; self::assertSame('off',$row['autoload']);
		self::assertStringNotContainsString('CLIENT_TOKEN_PRIVATE',$row['option_value']); self::assertStringNotContainsString('request_token',$row['option_value']);
		self::assertSame($fixture->now+120,Envelope::from_json($row['option_value'])->expires_at());
		self::assertSame(1,$fixture->database->writes);
	}
	public static function responses(): array { return [ ['children', self::children()], ['search', self::search()], ['postcode',['required'=>true,'visible'=>true]] ]; }
	public function test_purpose_site_locale_revision_and_delimiter_tuple_identities_are_distinct(): void {
		$base = [1,'search','GH','parent','search','1',1,'opaque-ready-7','en_US','trusted salt'];
		$identity = Identity::create(...$base); $variants = [];
		foreach ([0=>2,1=>'children',4=>'other search',7=>'opaque-ready-8',8=>'fr_FR'] as $position=>$value) { $tuple=$base; $tuple[$position]=$value; $variants[]=Identity::create(...$tuple); }
		foreach($variants as $other) { self::assertNotSame($identity->digest(),$other->digest()); }
		$left=$base;$left[3]='a|b';$left[4]='c';$right=$base;$right[3]='a';$right[4]='b|c';self::assertNotSame(Identity::create(...$left)->digest(),Identity::create(...$right)->digest());
		self::assertMatchesRegularExpression('/\Acetech_de_gc_geo_v1_[0-9a-f]{64}\z/D',$identity->option_name()); self::assertStringNotContainsString('search',$identity->option_name());
	}
	public function test_read_does_not_slide_expiry_and_delayed_queries_do_not_gain_a_new_120_seconds(): void {
		$f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);$ticket=$c->lookup($id);$f->now+=119;
		self::assertTrue($c->publish($ticket,self::children()));self::assertSame(1,$f->last_ttl);
		self::assertNotNull($c->lookup($id)->payload());$f->now+=1;self::assertNull($c->lookup($id)->payload());
		$old=$c->lookup($id);$f->now+=120;self::assertFalse($c->publish($old,self::children()));self::assertSame(1,$f->database->writes);
	}
	public function test_same_key_newer_generation_blocks_old_renewal_and_different_physical_identity(): void {
		$f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);self::assertTrue($c->publish($c->lookup($id),self::children()));
		$old=$c->lookup($id);$new=$c->lookup($id);self::assertTrue($c->publish($new,self::children('new value')));self::assertFalse($c->publish($old,self::children('old value')));self::assertSame('new value',$c->lookup($id)->payload()['items'][0]['name']);
		$before=$c->lookup($id);$row=$f->database->rows[$id->option_name()];$row['option_id']=++$f->database->next_id;$f->database->rows[$id->option_name()]=$row;
		self::assertFalse($c->publish($before,self::children('recreated overwrite')));self::assertSame('new value',$c->lookup($id)->payload()['items'][0]['name']);
	}
	public function test_two_observed_absences_insert_once_and_absence_is_not_a_history_fence(): void {
		$f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);$a=$c->lookup($id);$b=$c->lookup($id);
		self::assertTrue($c->publish($b,self::children('newer')));self::assertFalse($c->publish($a,self::children('older')));
		unset($f->database->rows[$id->option_name()]);self::assertTrue($c->publish($a,self::children('current absence')));
		self::assertSame('current absence',$c->lookup($id)->payload()['items'][0]['name']);self::assertSame(2,$f->database->writes);
	}
	public function test_delayed_old_object_cache_publication_cannot_override_authoritative_new_generation(): void {
		$f=new ManagedCacheFixture();$old=$f->cache();$id=$f->identity($old);$ticket=$old->lookup($id);
		$f->before_advisory_set=function()use($f,$id):void { $f->before_advisory_set=null; $new=$f->cache(); self::assertTrue($new->publish($new->lookup($id),self::children('new generation'))); };
		self::assertTrue($old->publish($ticket,self::children('delayed old generation')));
		self::assertSame('delayed old generation',$f->advisory[$id->digest()]['envelope']['payload']['items'][0]['name']);
		self::assertSame('new generation',$old->lookup($id)->payload()['items'][0]['name']);self::assertSame(2,$f->database->writes);
	}
	public function test_tampered_advisory_payload_with_same_identity_and_generation_never_replaces_current_sql_facts(): void {
		$f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);self::assertTrue($c->publish($c->lookup($id),self::children('SQL truth')));
		$f->advisory[$id->digest()]['envelope']['payload']['items'][0]['name']='poisoned';
		self::assertSame('SQL truth',$c->lookup($id)->payload()['items'][0]['name']);
		$f->advisory[$id->digest()]['option_id']+=1;self::assertSame('SQL truth',$c->lookup($id)->payload()['items'][0]['name']);
	}
	public function test_unknown_or_malformed_storage_is_a_miss_and_not_rewritten(): void {
		$f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);
		foreach(['{"format":2,"private":"DO_NOT_ECHO"}',str_repeat('x',67585),'not JSON'] as $raw) { $f->database->rows[$id->option_name()]=['option_id'=>1,'option_name'=>$id->option_name(),'option_value'=>$raw,'autoload'=>'off'];$ticket=$c->lookup($id);self::assertNull($ticket->payload());self::assertFalse($c->publish($ticket,self::children()));self::assertSame($raw,$f->database->rows[$id->option_name()]['option_value']);self::assertStringNotContainsString('DO_NOT_ECHO',json_encode($c->diagnostics())); }
		self::assertSame(0,$f->database->writes);
	}
	public function test_changed_pack_revision_refuses_old_key_read_and_publication(): void {
		$f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);$ticket=$c->lookup($id);
		$f->database->rows[DataLifecycleOptionsStore::REVISION_OPTION]=['option_id'=>20,'option_name'=>DataLifecycleOptionsStore::REVISION_OPTION,'option_value'=>'new-opaque-revision','autoload'=>'off'];
		self::assertFalse($c->publish($ticket,self::children()));self::assertNull($c->lookup($id)->payload());self::assertSame(0,$f->database->writes);
	}
	public function test_commit_outcome_and_advisory_failure_never_reissue_a_database_write(): void {
		foreach([OperationCommitResult::NotSent,OperationCommitResult::Unconfirmed] as $mode) { $f=new ManagedCacheFixture();$c=$f->cache();$id=$f->identity($c);$ticket=$c->lookup($id);$f->database->next_commit=$mode;self::assertFalse($c->publish($ticket,self::children()));self::assertSame(1,$f->database->writes);self::assertSame([], $f->advisory);self::assertSame(OperationCommitResult::Unconfirmed===$mode,isset($f->database->rows[$id->option_name()])); }
		$f=new ManagedCacheFixture();$f->reject_cache_set=true;$c=$f->cache();$id=$f->identity($c);self::assertTrue($c->publish($c->lookup($id),self::children()));self::assertSame(['status'=>'publication_pending'],$c->diagnostics());self::assertSame(1,$f->database->writes);self::assertNotNull($c->lookup($id)->payload());
	}
	public function test_unsupported_readiness_and_cache_service_failure_preserve_fresh_fallback(): void {
		$f=new ManagedCacheFixture();$f->database->ready=false;$c=$f->cache();$id=$f->identity($c);$ticket=$c->lookup($id);self::assertNull($ticket->payload());self::assertFalse($c->publish($ticket,self::children()));self::assertSame(0,$f->database->writes);
		$f->database->ready=true;self::assertTrue($c->publish($c->lookup($id),self::children()));$f->reject_cache_get=true;self::assertNull($c->lookup($id)->payload());self::assertSame(['status'=>'cache_unavailable'],$c->diagnostics());
	}
	public function test_public_projection_rejects_unknown_private_nested_keys_and_keeps_input_arrays_detached(): void {
		$id=Identity::create(1,'children','GH','','administrative','1',1,'0','en_US','salt');$payload=self::children();$name='original';$payload['items'][0]['name']=&$name;
		$e=Envelope::create($id,$payload,1000,str_repeat('a',32));$name='changed input';$copy=$e->payload();$copy['items'][0]['name']='changed output';self::assertSame('original',$e->payload()['items'][0]['name']);
		foreach(['unexpected_email','private','internal']as$key){$bad=self::children();$bad['items'][0][$key]=['secret'=>'PRIVATE_SQL'];try{Envelope::create($id,$bad,1000);self::fail('Unknown item fields accepted.');}catch(\InvalidArgumentException $error){self::assertSame('Invalid managed geography cache envelope.',$error->getMessage());}}
	}
	public function test_payload_list_byte_depth_and_exact_scalar_budgets_refuse_without_truncation(): void {
		$id=Identity::create(1,'children','GH','','administrative','1',1,'0','en_US','salt');
		$cases=[];$count=self::children();$count['items']=[];for($i=0;$i<51;++$i){$item=self::children()['items'][0];$item['key']='key-'.$i;$count['items'][]=$item;}$cases[]=$count;
		$big=self::children();$big['items']=[];for($i=0;$i<10;++$i){$item=self::children()['items'][0];$item['key']='key-'.$i;$item['label']=str_repeat('x',7000);$big['items'][]=$item;}$cases[]=$big;
		foreach(['page'=>'1','total'=>1.0,'has_more'=>0,'skip_admin'=>'false']as$key=>$value){$bad=self::children();$bad[$key]=$value;$cases[]=$bad;}
		foreach($cases as $bad){try{Envelope::create($id,$bad,1000);self::fail('Invalid budget/type accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}}
		try{Envelope::from_json(str_repeat('x',67585));self::fail('Oversize envelope accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}
	}
	public function test_canonical_envelope_rejects_duplicate_fields_and_generic_serialization(): void {
		$id=Identity::create(1,'children','GH','','administrative','1',1,'0','en_US','salt');$e=Envelope::create($id,self::children(),1000,str_repeat('a',32));self::assertSame($e->payload(),Envelope::from_json($e->to_json())->payload());
		try{Envelope::from_json(str_replace('"format":1,','"format":1,"format":1,',$e->to_json()));self::fail('Duplicate fields accepted.');}catch(\InvalidArgumentException){self::assertTrue(true);}
		foreach([$id,$e,new ManagedGeographyCacheTicket($id,1000)] as$value){try{json_encode($value,JSON_THROW_ON_ERROR);self::fail('Implicit serialization accepted.');}catch(\LogicException$error){self::assertStringContainsString('explicit projection',$error->getMessage());}}
	}
	private static function children(string $name='Region'):array{return ['items'=>[['key'=>'public-key','name'=>$name,'type'=>'administrative','label'=>$name.' — Country','breadcrumb'=>'Country','code'=>'GA']],'page'=>1,'total'=>1,'has_more'=>false,'label'=>'Region','skip_admin'=>false];}
	private static function search():array{$payload=self::children('Locality');$payload['items'][0]['type']='locality';unset($payload['label'],$payload['skip_admin']);$payload['has_pack']=true;$payload['request_token']='CLIENT_TOKEN_PRIVATE';return $payload;}
}

final class ManagedCacheDatabase {public array $rows=[];public int $next_id=0;public int $writes=0;public bool $ready=true;public OperationCommitResult $next_commit=OperationCommitResult::Acknowledged;}
final class ManagedCacheFixture {
	public ManagedCacheDatabase $database;public int $now=1000;public array $advisory=[];public int $last_ttl=0;public bool $reject_cache_set=false;public bool $reject_cache_get=false;public ?\Closure $before_advisory_set=null;
	public function __construct(){$this->database=new ManagedCacheDatabase();}
	public function cache():ManagedGeographyCache{return new ManagedGeographyCache(new ManagedCacheFactory($this->database),new ManagedCacheStore(),fn()=>$this->now,fn()=>'trusted test salt',function($key){if($this->reject_cache_get){throw new \RuntimeException('PRIVATE_NETWORK_ERROR');}return $this->advisory[$key]??false;},function($key,$value,$group,$ttl){$this->last_ttl=$ttl;if(null!==$this->before_advisory_set){($this->before_advisory_set)();}if($this->reject_cache_set){return false;}$this->advisory[$key]=$value;return true;},function($key){unset($this->advisory[$key]);return true;});}
	public function identity(ManagedGeographyCache $cache,string $kind='children'):Identity{return $cache->identity(1,$kind,'GH','','administrative','1',1,'0','en_US');}
}
final class ManagedCacheFactory implements OperationConnectionFactory {public function __construct(private ManagedCacheDatabase $database){}public function open():OperationSession{return new ManagedCacheSession($this->database);}}
final class ManagedCacheSession implements OperationSession {
	public array $rows=[];private bool $owner=false;private bool $retired=false;public function __construct(public ManagedCacheDatabase $database){}public function site_id():int{return 1;}public function table_prefix():string{return 'cache_';}public function charset_collate():string{return 'DEFAULT CHARACTER SET utf8mb4';}public function begin():bool{$this->owner=true;$this->rows=$this->database->rows;return true;}public function commit():OperationCommitResult{$result=$this->database->next_commit;$this->database->next_commit=OperationCommitResult::Acknowledged;if(OperationCommitResult::NotSent!==$result){$this->database->rows=$this->rows;$this->owner=false;}if(OperationCommitResult::Unconfirmed===$result){$this->retired=true;}return $result;}public function rollback():bool{$this->owner=false;return true;}public function retire():bool{$this->owner=false;$this->retired=true;return true;}public function is_retired():bool{return $this->retired;}public function in_transaction():bool{return $this->owner;}public function validate_tables(array $names):bool{return $this->database->ready;}public function query(string $sql):int|false{return false;}public function get_row(string $sql):array|null|false{return false;}public function get_results(string $sql):array|false{return false;}public function prepare(string $sql,mixed...$args):string{return $sql;}public function errno():int{return 0;}public function insert_id():int{return $this->database->next_id;}
}
final class ManagedCacheStore extends DataLifecycleOptionsStore {
	public function assert_ready(OperationSession $session,int $site):void{if(!$session instanceof ManagedCacheSession||!$session->in_transaction()||!$session->database->ready){throw new \RuntimeException('PRIVATE_STORAGE_ERROR');}}
	public function current_by_name(OperationSession $session,string $name,int $max_bytes=67584):?array{$row=$session->rows[$name]??null;if(null===$row){return null;}$row['byte_length']=strlen($row['option_value']);if($row['byte_length']>$max_bytes){$row['option_value']=null;}return $row;}
	public function insert(OperationSession $session,string $name,string $value):int{++$session->database->writes;$id=++$session->database->next_id;$session->rows[$name]=['option_id'=>$id,'option_name'=>$name,'option_value'=>$value,'autoload'=>'off'];return $id;}
	public function replace(OperationSession $session,int $id,string $name,string $old,string $new):bool{++$session->database->writes;if(($session->rows[$name]['option_value']??null)!==$old){return false;}$session->rows[$name]['option_value']=$new;return true;}
	public function invalidate(array $names):bool{return true;}
}
