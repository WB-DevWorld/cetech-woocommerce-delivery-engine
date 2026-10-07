<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\{OperationCommand,OperationCompletion,OperationMaterialEvent,OperationMutation,OperationProfile,OperationProfileRegistry,OperationRefusal,OperationSchema,OperationSession,OperationTarget};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository,DeliveryQuoteSchema,QuoteRetentionStore};
/** Explicit C06-adopter policy, separate from preservation/uninstall. No standard profile registration. */
final class QuoteRetentionProfile implements OperationProfile {
 public const POLICY_VERSION=1; public const INSPECTION_LIMIT=100; public const MUTATION_LIMIT=100; public const SOFT_SECONDS=2.0;
 private \Closure $authorize; private \Closure $clock; private array $page=[];private array $quotes=[];private array $safe=[];private float $started=0;private ?QuoteTime $current_time=null;private ?array $initial=null;
 public function __construct(private string $kind,private string $policy,callable $authorizer,private QuoteRetentionReferenceInspector $references,private QuoteRetentionControl $control,private OperationProfileRegistry $producers,?callable $monotonic_clock=null) { if(!in_array($kind,['start','batch'],true)) { throw new \InvalidArgumentException('Unknown quote retention operation.'); }$this->authorize=\Closure::fromCallable($authorizer);$this->clock=null===$monotonic_clock?static fn():float=>hrtime(true)/1000000000:\Closure::fromCallable($monotonic_clock); }
 public static function policy_digest(QuoteRetentionReferenceInspector $references):string { $digest=$references->policy_digest();if(1!==preg_match('/\A[0-9a-f]{64}\z/D',$digest)) { throw new \InvalidArgumentException('Invalid quote reference policy.'); }return hash('sha256','cetech-c06-delivery-quote-retention-v1:1800:86400:100:2:'.$digest); }
 public function operation():string { return 'delivery_quote.retention.'.$this->kind; } public function version():int { return 1; }
 public function authorize(OperationIdentity $identity):bool { return $identity->authority==='delivery_quote_retention'&&$identity->principal==='quote-retention'&&$identity->operation===$this->operation()&&$identity->operation_version===1&&true===($this->authorize)($identity->site_id); }
 public function validate_command(OperationIdentity $identity,mixed $payload):OperationCommand { if(!$payload instanceof QuoteRetentionRequest||$payload->identity->namespace_digest()!==$identity->namespace_digest()||!hash_equals($this->policy,$payload->policy)||($this->kind==='start')!==(null===$payload->previous)) { throw new OperationRefusal('invalid_input','reload_and_submit'); }return $payload; }
 public function transactional_tables(OperationSession $session):array { $extra=$this->references->transactional_tables($session);if(!array_is_list($extra)||count($extra)>16) { throw new \InvalidArgumentException('Invalid quote reference participant.'); }foreach($extra as $table) { if(!is_string($table)||strlen($table)>64||!str_starts_with($table,$session->table_prefix())||1!==preg_match('/\A[a-zA-Z0-9_]+\z/D',$table)) { throw new \InvalidArgumentException('Invalid quote reference participant.'); } }sort($extra,SORT_STRING);return array_values(array_unique([...DeliveryQuoteSchema::tables($session->table_prefix()),...$this->control->transactional_tables($session),...$extra])); }
 public function lock_target(OperationSession $session,OperationIdentity $identity,OperationCommand $command):OperationTarget {
  if(!$command instanceof QuoteRetentionRequest) { throw new OperationRefusal('invalid_input','reload_and_submit'); }$this->started=$this->monotonic();$store=new QuoteRetentionStore($session);$store->assert_ready();
  if(null===$command->previous) {
   $this->control->assert_enabled($session);$now=$store->now();$run=substr($identity->target_key,strlen('retention:'));$cp=QuoteRetentionCheckpoint::initial($identity->site_id,$run,$this->policy,$now->epoch_microseconds(),$store->ceiling('delivery_quotes'),$store->ceiling('delivery_quote_budget_windows'),$identity->namespace_digest());$this->initial=$cp->facts();
   return new OperationTarget($this->target_schema(),['run_id'=>$run,'manifest_digest'=>$this->initial['manifest_digest'],'sequence'=>0,'effect_count'=>0,'effects_digest'=>hash('sha256','[]')]);
  }
  $previous=$command->previous;$data=$previous->facts();if($data['site_id']!==$identity->site_id||!hash_equals($data['policy_digest'],$this->policy)||$previous->complete()) { throw new OperationRefusal('stale_revision','reload_and_submit'); }
  $profiles=new OperationProfileRegistry([$this,new self('start',$this->policy,$this->authorize,$this->references,$this->control,$this->producers,$this->clock)]);$prior=$store->prior($previous->namespace_hash(),$profiles);
  if('accepted'!==$prior->state||'none'!==$prior->publication_state||$prior->completion?->result!==$data) { throw new OperationRefusal('stale_revision','reload_and_submit'); }
  $suffix=$data['phase']==='quotes'?'delivery_quotes':'delivery_quote_budget_windows';$this->page=$store->page($suffix,$data[$data['phase']==='quotes'?'quote_cursor':'counter_cursor'],$data[$data['phase']==='quotes'?'quote_ceiling':'counter_ceiling'],100);
  if($data['phase']==='quotes') { foreach($this->page as $raw) { $quote=$store->quote($raw);if(null!==$quote) { $this->quotes[$quote->id()]=$quote; } }$fences=$store->producer_fences(array_values($this->quotes));
   foreach($this->quotes as $id=>$quote) { $this->safe[$id]=$store->producer_safe($quote,$fences,$this->producers); }
  }
  $this->control->assert_enabled($session);$this->current_time=$store->now();if($this->current_time->epoch_microseconds()<$data['cutoff_us']) { throw new OperationRefusal('temporarily_unavailable','retry_original_request'); }
  // Budgets precede every quote lock. Unknown/missing admission evidence protects the body.
  foreach($this->quotes as $id=>$quote) { $this->safe[$id]=$this->safe[$id]&&$store->admission_safe($quote); }
  return new OperationTarget($this->target_schema(),['run_id'=>$data['run_id'],'manifest_digest'=>$data['manifest_digest'],'sequence'=>$data['sequence']+1,'effect_count'=>0,'effects_digest'=>hash('sha256','[]')]);
 }
 public function mutate(OperationSession $session,OperationIdentity $identity,OperationCommand $command,OperationTarget $target,RequestContext $context):OperationMutation {
  if(!$command instanceof QuoteRetentionRequest) { throw new OperationRefusal('invalid_input','reload_and_submit'); }$this->assert_authorized($identity);$this->control->assert_enabled($session);
  if(null===$command->previous) { $data=$this->initial;if(null===$data) { throw new \LogicException('Retention target was not captured.'); } }
  else { $data=$command->previous->facts();$data['sequence']++;$data['soft_stopped']=false;$data['effect_count']=0;$effects=[];$store=new QuoteRetentionStore($session);$phase=$data['phase'];$cursor=$phase==='quotes'?'quote_cursor':'counter_cursor';$ceiling=$phase==='quotes'?'quote_ceiling':'counter_ceiling';$cutoff=QuoteTime::from_epoch_microseconds($data['cutoff_us']);$walked=0;
   foreach($this->page as $raw) { if($this->monotonic()-$this->started>=self::SOFT_SECONDS) { $data['soft_stopped']=true;break; }$this->assert_authorized($identity);$id=(int)$raw['id'];$result=$phase==='quotes'?$this->strip($session,$store,$raw,$cutoff):$store->delete_counter($raw,$cutoff);$data['inspected']++;$data[$result]++;if(in_array($result,['stripped','counters_deleted'],true)) { $data['effect_count']++;$effects[]=['kind'=>$result,'id'=>$id,'opened_hash'=>hash('sha256',json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))]; }$data[$cursor]=$id;$walked++; }
   if(!$data['soft_stopped']&&$walked===count($this->page)&&count($this->page)<100) { $data[$cursor]=$data[$ceiling]; }
   $data['effects_digest']=hash('sha256',json_encode($effects,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));if($data[$cursor]===$data[$ceiling]) { $data['phase']=$phase==='quotes'&&$data['counter_cursor']<$data['counter_ceiling']?'counters':'complete'; }
  }
  $this->assert_authorized($identity);$this->control->assert_enabled($session);$cp=QuoteRetentionCheckpoint::from_completion($data,$identity->namespace_digest());$completion=OperationCompletion::accepted($this,$cp->facts());$fields=['checkpoint'];if(($data['effect_count']??0)>0) { $fields[]=$command->previous?->facts()['phase']==='quotes'?'private_body':'minute_counters'; }$event_target=$target->facts;$event_target['effect_count']=$data['effect_count'];$event_target['effects_digest']=$data['effects_digest'];$event=OperationMaterialEvent::from_mutation($this,$identity,$context,['authority'=> 'quote_retention'], $event_target, 'retention_checkpoint',$data['sequence']+1,$data['sequence']+2,$fields);return OperationMutation::changed($completion,$event);
 }
 private function strip(OperationSession $session,QuoteRetentionStore $store,array $raw,QuoteTime $cutoff):string {
  $opened=$this->quotes[(int)$raw['id']]??null;if(null===$opened) { return 'invalid'; }
  if(null!==$opened->accepted_at()||'stripped'===$opened->state()||$opened->header()->expires_at()->plus_seconds(1800)->compare($cutoff)>0||!($this->safe[$opened->id()]??false)) { return 'protected'; }
  if(true!==$this->references->authorize_owner($opened->header()->owner())) { return 'protected'; }
  $current_raw=$store->current_quote($opened->id());if(null===$current_raw) { return 'disappeared'; }$current=$store->quote($current_raw);if(null===$current) { return 'invalid'; }if($current->row()!==$opened->row()) { return 'changed'; }
  if(!$store->binding_absent($current)) { return 'protected'; }$reference=$this->references->inspect($session,$current);if(!in_array($reference,['absent','protected','unknown'],true)||$reference!=='absent'||true!==$this->references->authorize_owner($current->header()->owner())) { return 'protected'; }
  $next=$current->row();$next['private_body_json']=null;$next['state']='stripped';$next['revision']++;$next['retention_revision']++;$next['transition_at']=$this->current_time->sql();if(!(new DeliveryQuoteRepository($session))->replace_quote($current,QuoteStoredRow::from_row($next))) { throw new OperationRefusal('stale_revision','reload_and_submit'); }return 'stripped';
 }
 public function publish(OperationIdentity $identity,OperationCompletion $completion):bool { return true; }
 public function result_schema():OperationSchema { return QuoteRetentionCheckpoint::schema(); }
 public function publication_schema():?OperationSchema { return null; }
 public function actor_schema():OperationSchema { return new OperationSchema(['authority'=>['enum'=>['quote_retention']]]); }
 public function target_schema():OperationSchema { return new OperationSchema(['run_id'=>'uuid','manifest_digest'=>'sha256','sequence'=>'nonnegative_int','effect_count'=>'nonnegative_int','effects_digest'=>'sha256']); }
 public function reason_codes():array { return ['retention_checkpoint']; }
 public function changed_fields():array { return ['checkpoint','private_body','minute_counters']; }
 public function validate_accepted_facts(OperationCompletion $completion,OperationMaterialEvent $event):bool { try { $r=$completion->result;if(null===$r||$completion->state!=='accepted'||$completion->publication!==null) { return false; }QuoteRetentionCheckpoint::from_completion($r,str_repeat('0',64));return $event->actor===['authority'=>'quote_retention']&&$event->target===['run_id'=>$r['run_id'],'manifest_digest'=>$r['manifest_digest'],'sequence'=>$r['sequence'],'effect_count'=>$r['effect_count'],'effects_digest'=>$r['effects_digest']]&&$event->before_revision===$r['sequence']+1&&$event->after_revision===$r['sequence']+2&&$event->reason_code==='retention_checkpoint'&&in_array($event->changed_fields,[['checkpoint'],['checkpoint','private_body'],['checkpoint','minute_counters']],true)&&($r['effect_count']===0)===(['checkpoint']===$event->changed_fields); }catch(\Throwable) { return false; } }
 private function assert_authorized(OperationIdentity $identity):void { if(!$this->authorize($identity)||!hash_equals($this->policy,self::policy_digest($this->references))) { throw new OperationRefusal('not_authorized','contact_support'); } }
 private function monotonic():float { $value=($this->clock)();if(!is_int($value)&&!is_float($value)||!is_finite((float)$value)) { throw new \LogicException('Invalid retention clock.'); }return (float)$value; }
}
