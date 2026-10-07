<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteProviderProofFactory as Factory,QuoteProviderProofFixtures as F,QuoteProviderProofDatabase as SourceDB,QuoteFixtures};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier as Barrier,OperationProofProcess as Process};
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
$args=json_decode($argv[1]??'',true,8,JSON_THROW_ON_ERROR);$prefix=$args['prefix'];DB::validate_prefix($prefix);$factory=null;$db=null;
try{
	if('guard_hold'===$args['action']){
		$factory=new Factory($prefix);$snapshot=F::bind(F::capture($factory));
		$factory->configure=static function($t,int $number)use($args):void{if(2!==$number){return;}$t->after=static function(string $sql,$t,OperationConnectionResult $r)use($args):void{if('SELECT UTC_TIMESTAMP(6) AS utc'===$sql){file_put_contents($args['ready'],json_encode(['process_id'=>getmypid(),'connection_id'=>$t->connection_id()],JSON_THROW_ON_ERROR),LOCK_EX);Process::wait_for($args['release'],5.0);}};};
		$s=$factory->open();if(!$s->begin()||!$s->validate_tables([$prefix.'operation_fixture_counter'])){throw new RuntimeException('Native guard refused.');}$valid=$snapshot->guard()->verify($s,QuoteFixtures::owner(),$snapshot->context());$write=false;if($valid){$write=1===$s->query("UPDATE `{$prefix}operation_fixture_counter` SET value=value+1 WHERE id=1");}$commit=$s->commit();$retired=$s->retire();$t=$factory->transports[1];echo json_encode(['valid'=>$valid,'write'=>$write,'commit'=>$commit->value,'retired'=>$retired,'timeouts'=>$t->lock_timeouts,'deadlocks'=>$t->deadlocks],JSON_THROW_ON_ERROR).PHP_EOL;
	}elseif('write_rate'===$args['action']){
		$db=DB::connect();DB::execute($db,'SET SESSION innodb_lock_wait_timeout=2');$connection_id=(int)DB::scalar($db,'SELECT CONNECTION_ID()');file_put_contents($args['ready'],json_encode(['process_id'=>getmypid(),'connection_id'=>$connection_id],JSON_THROW_ON_ERROR),LOCK_EX);
		if('insert'===$args['mutation']){SourceDB::rate($db,$prefix,['internal_code'=>'concurrent_new','priority'=>0,'base_amount'=>'99.0000']);}else{DB::execute($db,"UPDATE `{$prefix}delivery_engine_rate_cards` SET base_amount='99.0000' WHERE id=1");}
		echo json_encode(['written'=>true,'base_amount'=>(string)DB::scalar($db,"SELECT base_amount FROM `{$prefix}delivery_engine_rate_cards` ORDER BY id DESC LIMIT 1")],JSON_THROW_ON_ERROR).PHP_EOL;
	}else{throw new InvalidArgumentException('Unknown finite provider worker action.');}
}catch(Throwable $error){fwrite(STDERR,'Native provider proof worker failed: '.get_class($error).PHP_EOL);exit(1);}finally{$factory?->close_all();$db?->close();}
