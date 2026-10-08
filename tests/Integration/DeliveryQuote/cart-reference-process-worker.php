<?php
declare(strict_types=1);
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteCartProofStack,QuoteCartProofFixtures,QuoteLifecycleProofTransport};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;
use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteSessionEnvelope;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
require dirname(__DIR__,2).'/bootstrap.php';
try {
 $a=json_decode($argv[1]??'',true,32,JSON_THROW_ON_ERROR);DB::validate_prefix($a['prefix']);$owner=isset($a['owner'])?QuoteOwner::from_array($a['owner']):QuoteCartProofFixtures::owner();
 $signal=static function(string $path,int $id):void { $json=json_encode(['connection_id'=>$id,'process_id'=>getmypid()],JSON_THROW_ON_ERROR);if(false===file_put_contents($path.'.pending',$json,LOCK_EX)||!rename($path.'.pending',$path))throw new RuntimeException(); };
 $done=false;$configure=static function(QuoteLifecycleProofTransport $t)use($a,$signal,&$done):void {
  $match=static fn(string $sql):bool=>str_starts_with($sql,'SELECT ')&&str_contains($sql,'woocommerce_sessions')&&str_contains($sql,'FOR UPDATE');
  $t->before=static function(string $sql,QuoteLifecycleProofTransport $transport)use($a,$signal,$match,&$done):void {if($done||!$match($sql)||!isset($a['dispatch_ready']))return;$done=true;$signal($a['dispatch_ready'],$transport->connection_id());if(isset($a['dispatch_release']))OperationProofProcess::wait_for($a['dispatch_release'],12.0);};
  $t->after=static function(string $sql,QuoteLifecycleProofTransport $transport,$result)use($a,$signal,$match,&$done):void {if($done||!$result->acknowledged||!$match($sql)||!isset($a['lock_ready']))return;$done=true;$signal($a['lock_ready'],$transport->connection_id());OperationProofProcess::wait_for($a['lock_release'],12.0);};
 };
 $s=new QuoteCartProofStack($a['prefix'],$owner,$configure);if(isset($a['clock']))$s->factory->clock=$a['clock'];
 if(isset($a['prepare_ready']))$s->environment->before_prepare=static function()use($a,$signal):void { $db=DB::connect();try{$signal($a['prepare_ready'],$db->thread_id);OperationProofProcess::wait_for($a['prepare_release'],12.0);}finally{$db->close();} };
 if('cas'===$a['action']) { $expected=null===($a['expected']??null)?null:CartQuoteSessionEnvelope::from_private_json($a['expected']);$next=CartQuoteSessionEnvelope::from_private_json($a['next']);$out=['cas'=>$s->sessions->compare_and_swap($owner,$expected,$next)]; }
 else {$r=match($a['action']){'refresh'=>$s->service->refresh($a['token'],$a['generation'],RequestContext::create()),'retry'=>$s->service->retry($a['generation'],RequestContext::create()),'current'=>$s->service->current(RequestContext::create()),default=>throw new RuntimeException()};$out=['status'=>$r->shopper_facts()['status'],'generation'=>$r->shopper_facts()['generation']];}
 $out+=['process_id'=>getmypid(),'prepares'=>$s->environment->prepares,'source_captures'=>$s->environment->source_captures(),'native_reads'=>$s->environment->native_reads(),'lock_timeouts'=>array_sum(array_column($s->factory->transports,'lock_timeouts')),'deadlocks'=>array_sum(array_column($s->factory->transports,'deadlocks'))];$s->close_all();echo json_encode($out,JSON_THROW_ON_ERROR);
} catch(Throwable){fwrite(STDERR,'Cart reference proof worker refused.');exit(1);}
