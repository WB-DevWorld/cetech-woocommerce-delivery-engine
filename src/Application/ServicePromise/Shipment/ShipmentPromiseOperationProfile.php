<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Shipment;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity,RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand,OperationCompletion,OperationMaterialEvent,OperationMutation,OperationProfile,OperationRefusal,OperationSchema,OperationSession,OperationTarget};
use CetechDeliveryEngine\Domain\ServicePromise\Shipment\{ShipmentPromiseCommand,ShipmentPromiseStored,ShipmentPromiseGrant};
use CetechDeliveryEngine\Infrastructure\Persistence\{ShipmentPromiseSchema,ShipmentSchema,WpdbShipmentPromiseRepository,WpdbOperationRecordRepository,EmergencyControlStore};

/** C03 exact original/current shipment adopter; no host callback executes while its SQL owner is open. */
final class ShipmentPromiseOperationProfile implements OperationProfile {
 private ?WpdbShipmentPromiseRepository $repository=null;private ?ShipmentPromiseStored $locked=null;private ?OperationSession $owner=null;
 private function __construct(private string $action,private ?ShipmentPromiseCommand $command,private ?ShipmentPromiseGrant $grant){}
 public static function for_command(ShipmentPromiseCommand $command,ShipmentPromiseGrant $grant):self{return new self($command->identity->operation,$command,$grant);}
 public static function receipt_profile(string $operation):self{if(!in_array($operation,[ShipmentPromiseCommand::CREATE,ShipmentPromiseCommand::UPDATE],true)){throw new \InvalidArgumentException('Unknown shipment promise operation.');}return new self($operation,null,null);}
 public function operation():string{return $this->action;}public function version():int{return 1;}
 public function authorize(OperationIdentity $identity):bool{return null!==$this->command&&null!==$this->grant&&$this->grant->permits($identity,$this->command);}
 public function validate_command(OperationIdentity $identity,mixed $command):OperationCommand{if($command!==$this->command||!$this->authorize($identity)){self::refuse('not_authorized');}return $command;}
 public function transactional_tables(OperationSession $session):array{$tables=[...ShipmentPromiseSchema::tables($session->table_prefix()),...(array_map(fn(string $suffix):string=>WpdbOperationRecordRepository::table_name($session,$suffix),ShipmentSchema::SUFFIXES)),(new EmergencyControlStore())->options_table($session)];if(null!==$this->grant){$tables=[...$tables,...$this->grant->tables($session)];}if(null!==$this->command?->paid){$tables=[...$tables,...$this->command->paid->tables($session)];}return array_values(array_unique($tables));}
 public function lock_target(OperationSession $session,OperationIdentity $identity,OperationCommand $command):OperationTarget {
  if($command!==$this->command||!$this->authorize($identity)){self::refuse('not_authorized');}$this->command->binding->assert_session($session);$control=new EmergencyControlStore();$control->assert_ready($session,$identity->site_id);if(ShipmentPromiseCommand::UPDATE===$this->action&&!$control->current($session)->enabled()){self::refuse('not_authorized');}
  if(!$this->grant->verify($session)){self::refuse('stale_revision');}if(null!==$this->command->paid&&!$this->command->paid->verify($session)){self::refuse('not_authorized');}$repo=new WpdbShipmentPromiseRepository($session,$this->command->binding);$this->repository=$repo;$this->owner=$session;
  if(ShipmentPromiseCommand::UPDATE===$this->action){$this->locked=$repo->load($this->command->shipment->id,true);if(null===$this->locked){self::refuse('stale_revision');}$repo->assert_ack($this->locked);$row=$this->locked->row();if($row['revision']!==$this->command->expected_revision||$row['order_id']!==$this->command->shipment->order_id||$row['delivery_group_id']!==$this->command->shipment->delivery_group_id){self::refuse('stale_revision');}}
  return new OperationTarget($this->target_schema(),['order_id'=>$this->command->shipment->order_id,'group_hash'=>hash('sha256',$this->command->shipment->delivery_group_id),'revision'=>$this->locked?->row()['revision']??1]);
 }
 public function mutate(OperationSession $session,OperationIdentity $identity,OperationCommand $command,OperationTarget $target,RequestContext $context):OperationMutation {
  if($session!==$this->owner||$command!==$this->command||!$this->authorize($identity)||null===$this->repository){self::refuse('not_authorized');}
  if(ShipmentPromiseCommand::CREATE===$this->action){$write=$this->repository->create($this->command);$stored=$write['stored'];$changed=$write['changed'];$before=1;}
  else{$stored=$this->repository->update($this->locked,$this->command);$changed=true;$before=$this->locked->row()['revision'];}
  $row=$stored->row();$result=['shipment_id'=>$row['shipment_id'],'order_id'=>$row['order_id'],'revision'=>$row['revision'],'original_digest'=>$row['original_digest'],'packet_digest'=>$row['packet_digest'],'current_digest'=>$row['current_digest']??str_repeat('0',64),'accepted_at'=>\CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse($row['updated_at'])->epoch_microseconds()];
  if(!$changed){return OperationMutation::unchanged(OperationCompletion::not_applicable($this,$result));}
  $reason=ShipmentPromiseCommand::CREATE===$this->action?'original_attached':$this->command->current->private_facts()['source'];$fields=ShipmentPromiseCommand::CREATE===$this->action?['shipment','original_promise']:['current_prediction','current_event'];$event=OperationMaterialEvent::from_mutation($this,$identity,$context,['kind'=>null===$this->command->actor_user_id?'system':'staff','user_id'=>$this->command->actor_user_id??0],['order_id'=>$row['order_id'],'group_hash'=>hash('sha256',$row['delivery_group_id']),'revision'=>$before],$reason,$before,$row['revision'],$fields);
  return OperationMutation::changed(OperationCompletion::accepted($this,$result),$event);
 }
 public function publish(OperationIdentity $identity,OperationCompletion $completion):bool{return false;}
 public function result_schema():OperationSchema{return new OperationSchema(['shipment_id'=>'positive_int','order_id'=>'positive_int','revision'=>'positive_int','original_digest'=>'sha256','packet_digest'=>'sha256','current_digest'=>'sha256','accepted_at'=>'positive_int']);}
 public function publication_schema():?OperationSchema{return null;}
 public function actor_schema():OperationSchema{return new OperationSchema(['kind'=>['enum'=>['system','staff']],'user_id'=>'nonnegative_int']);}
 public function target_schema():OperationSchema{return new OperationSchema(['order_id'=>'positive_int','group_hash'=>'sha256','revision'=>'positive_int']);}
 public function reason_codes():array{return ['original_attached','payment_confirmed','staff_revision'];}
 public function changed_fields():array{return ['shipment','original_promise','current_prediction','current_event'];}
 public function validate_accepted_facts(OperationCompletion $completion,OperationMaterialEvent $event):bool {
  $result=$completion->result;$create=ShipmentPromiseCommand::CREATE===$this->action;return 'accepted'===$completion->state&&null===$completion->publication&&$result['order_id']===$event->target['order_id']&&$result['revision']===$event->after_revision&&$event->before_revision===$event->target['revision']&&$event->after_revision===$event->before_revision+1&&($create?(1===$event->before_revision&&2===$event->after_revision&&'original_attached'===$event->reason_code&&['shipment','original_promise']===$event->changed_fields&&str_repeat('0',64)===$result['current_digest']):($event->before_revision>=2&&in_array($event->reason_code,['payment_confirmed','staff_revision'],true)&&['current_prediction','current_event']===$event->changed_fields))&&('system'===$event->actor['kind']?0===$event->actor['user_id']:$event->actor['user_id']>0)&&($create||('payment_confirmed'===$event->reason_code?('system'===$event->actor['kind']&&0===$event->actor['user_id']):('staff'===$event->actor['kind']&&$event->actor['user_id']>0)));
 }
 private static function refuse(string $code):never{throw new OperationRefusal($code,'not_authorized'===$code?'contact_support':'reload_and_submit');}
}
