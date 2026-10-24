<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\PolicyAssignment\PolicyAssignmentService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand,PolicyAssignmentState};
use CetechDeliveryEngine\Infrastructure\Persistence\PolicyAssignmentStore;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentFixture,PolicyAssignmentUnitReadiness,PolicyAssignmentUnitFactory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/Support/PolicyAssignment/PolicyAssignmentFixture.php';

/** Real C03 plus in-memory C01/options; native concurrency is separately qualified. */
final class PolicyAssignmentServiceTest extends TestCase {
 private PolicyAssignmentFixture $f;
 protected function setUp():void{$this->f=new PolicyAssignmentFixture();}
 protected function tearDown():void{$this->f->close();}
 public function test_two_family_first_save_is_one_state_event_and_completion_with_explicit_revision_offset():void{
  $f=$this->f;$f->publish('return_policy_v1',101,1);$f->publish('refund_policy_v1',102,2);$audits=$f->count('operation_changes');$records=$f->count('operation_records');
  $p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'assigned','policy_uuid'=>$f::uuid(101)],'refund_policy_v1'=>['mode'=>'assigned','policy_uuid'=>$f::uuid(102)]]);
  $r=$f->attempt($p);self::assertSame('accepted',$r->outcome->state);self::assertSame($audits+1,$f->count('operation_changes'));self::assertSame($records+1,$f->count('operation_records'));self::assertSame(1,$f->count('options'));self::assertSame(1,$f->state()->revision());
  $event=json_decode($f->rows('operation_changes')[array_key_last($f->rows('operation_changes'))]['event_json'],true,16,JSON_THROW_ON_ERROR);
  self::assertSame([1,2,0,1],[$event['before_revision'],$event['after_revision'],$event['target']['before_revision'],$event['target']['after_revision']]);
  self::assertSame('assigned',$f->state()->map('return_policy_v1')->instruction(1)['instruction']['mode']);self::assertSame('assigned',$f->state()->map('refund_policy_v1')->instruction(1)['instruction']['mode']);
 }
 public function test_default_no_change_does_not_create_assignment_guard_state_or_material_event():void{
  $r=$this->f->attempt($this->f->payload());self::assertSame('not_applicable',$r->outcome->state);self::assertSame(0,$this->f->count('options'));self::assertSame(0,$this->f->count('rule_family_guards'));self::assertSame(0,$this->f->count('operation_changes'));self::assertSame(1,$this->f->count('operation_records'));
 }
 public function test_selected_offer_save_preserves_default_and_every_other_family_slot():void{
  $f=$this->f;$first=$f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled'],'refund_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$f->attempt($first)->outcome->state);
  $offer=$f->payload(offer:7,instructions:['return_policy_v1'=>['mode'=>'disabled'],'refund_policy_v1'=>['mode'=>'inherit']]);self::assertSame('accepted',$f->attempt($offer,'offer7')->outcome->state);
  $prior=$f->state();$other=$f->payload(offer:9,instructions:['return_policy_v1'=>['mode'=>'inherit'],'refund_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$f->attempt($other,'offer9')->outcome->state);$now=$f->state();
  self::assertSame(['mode'=>'disabled'],$now->map('return_policy_v1')->facts()['default']);self::assertSame(['mode'=>'disabled'],$now->map('refund_policy_v1')->facts()['default']);
  foreach(['return_policy_v1','refund_policy_v1'] as $family){self::assertSame($prior->map($family)->facts()['offers']->{'7'},$now->map($family)->facts()['offers']->{'7'});}self::assertSame(3,$now->revision());
 }
 public function test_stale_opened_same_scope_is_rejected_without_replacing_accepted_settings():void{
  $p=$this->f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$this->f->attempt($p)->outcome->state);$before=$this->f->state()->original_bytes();$events=$this->f->count('operation_changes');
  $r=$this->f->attempt($p,'second-token');self::assertSame('rejected',$r->outcome->state);self::assertSame('stale_revision',$r->outcome->error?->code);self::assertSame($before,$this->f->state()->original_bytes());self::assertSame($events,$this->f->count('operation_changes'));
 }
 public function test_original_replay_after_later_save_returns_original_receipt_without_reapplying_old_maps():void{
  $f=$this->f;$p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]);$first=$f->attempt($p);self::assertSame('accepted',$first->outcome->state);
  $later=$f->payload(instructions:['return_policy_v1'=>['mode'=>'inherit'],'refund_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$f->attempt($later,'later')->outcome->state);$before=$f->state()->original_bytes();$events=$f->count('operation_changes');
  $again=$f->attempt($p);self::assertSame('accepted',$again->outcome->state);self::assertTrue($again->replayed);self::assertSame($first->completion?->to_json(),$again->completion?->to_json());self::assertSame($before,$f->state()->original_bytes());self::assertSame($events,$f->count('operation_changes'));
 }
 public function test_changed_semantic_same_token_stays_fail_closed_and_preserves_original_effect():void{
  $p=$this->f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$this->f->attempt($p)->outcome->state);$before=$this->f->rows('options');$events=$this->f->count('operation_changes');$p['reason']='Different original reason';
  $r=$this->f->attempt($p);self::assertContains($r->outcome->state,['rejected','unconfirmed']);self::assertSame($before,$this->f->rows('options'));self::assertSame($events,$this->f->count('operation_changes'));
 }
 #[DataProvider('rollback_faults')]
 public function test_first_effect_audit_or_completion_failure_rolls_back_guard_and_map(string $fault):void{
  $f=$this->f;$f->factory->{$fault}=true;$r=$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]));self::assertContains($r->outcome->state,['rejected','unconfirmed']);self::assertSame(0,$f->count('options'));self::assertSame(0,$f->count('rule_family_guards'));self::assertSame(0,$f->count('operation_changes'));
 }
 public static function rollback_faults():array{return [['reject_audit'],['reject_completion']];}
 public function test_effect_commit_not_sent_is_no_effect_and_exact_original_can_retry():void{
  $f=$this->f;$f->factory->not_sent_at=2;$p=$f->payload(instructions:['refund_policy_v1'=>['mode'=>'disabled']]);$r=$f->attempt($p);self::assertSame('rejected',$r->outcome->state);self::assertSame(0,$f->count('options'));self::assertSame(0,$f->count('operation_changes'));self::assertSame('accepted',$f->attempt($p)->outcome->state);self::assertSame(1,$f->count('operation_changes'));
 }
 public function test_lost_commit_ack_reconciles_original_after_policy_is_retired_without_current_source_queries():void{
  $f=$this->f;$f->publish('return_policy_v1',101,1);$p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'assigned','policy_uuid'=>$f::uuid(101)]]);$f->factory->lost_ack_at=$f->factory->commits+2;$lost=$f->attempt($p);self::assertSame('unconfirmed',$lost->outcome->state);$events=$f->count('operation_changes');$bytes=$f->state()->original_bytes();
  $f->factory->inspection()->exec("UPDATE ".$f->table('logical_rules')." SET current_published_version_id=NULL");$sql=count($f->factory->sql);$identity=$f->identity($p);$r=$f->service($identity)->reconcile($identity,$p,RequestContext::create());self::assertSame('accepted',$r->outcome->state);self::assertSame($bytes,$f->state()->original_bytes());self::assertSame($events,$f->count('operation_changes'));
  $queries=implode("\n",array_slice($f->factory->sql,$sql));self::assertStringNotContainsString('SELECT * FROM `'.$f->table('rule_versions').'`',$queries);self::assertStringNotContainsString('SELECT * FROM `'.$f->table('logical_rules').'`',$queries);
 }
 public function test_cache_publication_occurs_only_after_all_prior_sql_owners_acknowledge_retirement():void{
  $f=$this->f;$seen=[];$store=new PolicyAssignmentStore(1,PolicyAssignmentUnitFactory::PREFIX,static function(string $name,string $group)use($f,&$seen):void{foreach($f->factory->sessions as $session){self::assertTrue($session->is_retired());self::assertFalse($session->in_transaction());}$seen[]=[$name,$group];});
  $p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]);$identity=$f->identity($p);$service=new PolicyAssignmentService($f->factory,$store,$f->catalog($identity),[$f,'authorize'],new PolicyAssignmentUnitReadiness());self::assertSame('accepted',$service->attempt($identity,$p,RequestContext::create())->outcome->state);self::assertCount(3,$seen);self::assertSame([[$store->name($p['coordinate']),'options'],['alloptions','options'],['notoptions','options']],$seen);
 }
 public function test_failed_cache_publication_recovers_original_after_later_assignment_without_old_value_republish():void{
  $f=$this->f;$cache=false;$store=new PolicyAssignmentStore(1,PolicyAssignmentUnitFactory::PREFIX,static function()use(&$cache):void{if(!$cache){throw new \RuntimeException('Owned injected cache refusal');}});
  $p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]);$identity=$f->identity($p);$service=new PolicyAssignmentService($f->factory,$store,$f->catalog($identity),[$f,'authorize'],new PolicyAssignmentUnitReadiness());$r=$service->attempt($identity,$p,RequestContext::create());self::assertTrue($r->outcome->mutation_accepted);self::assertTrue($r->outcome->publication_pending);
  self::assertSame('accepted',$f->attempt($f->payload(instructions:['refund_policy_v1'=>['mode'=>'disabled']]),'later')->outcome->state);$before=$f->state()->original_bytes();$cache=true;
  self::assertSame('accepted',$service->reconcile($identity,$p,RequestContext::create())->outcome->state);self::assertSame($before,$f->state()->original_bytes());
 }
 public function test_live_actor_binding_refuses_a_forged_command_actor_before_any_sql_reservation():void{
  $p=$this->f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']],changes:['actor_user_id'=>10]);$r=$this->f->attempt($p);self::assertSame('rejected',$r->outcome->state);self::assertSame('not_authorized',$r->outcome->error?->code);self::assertSame(0,$this->f->factory->opens);self::assertSame(0,$this->f->count('operation_records'));
 }
 public function test_native_grant_loss_after_acknowledged_effect_hides_success_without_claiming_rollback():void{
  $f=$this->f;$f->factory->on_commit=static function($factory)use($f):void{if(2===$factory->commits){$f->allowed=false;}};$r=$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]));self::assertSame('unconfirmed',$r->outcome->state);self::assertSame(1,$f->count('options'));self::assertSame(1,$f->count('operation_changes'));
 }
 public function test_repeat_unchanged_maps_keeps_original_actor_reason_and_changed_at_without_new_audit():void{
  $f=$this->f;self::assertSame('accepted',$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]))->outcome->state);$before=$f->state()->original_bytes();$events=$f->count('operation_changes');$f->factory->clock='2026-10-24 10:10:00.000000';
  $r=$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']],changes:['reason'=>'New unused reason']),'unchanged');self::assertSame('not_applicable',$r->outcome->state);self::assertSame($before,$f->state()->original_bytes());self::assertSame($events,$f->count('operation_changes'));
 }
 #[DataProvider('metadata_faults')]
 public function test_all_tracked_owners_get_cleanup_attempt_even_when_metadata_throws_after_marker_commit(string $flag):void{
  $f=$this->f;$f->factory->on_commit=static function($factory)use($flag):void{if(3===$factory->commits){$factory->{$flag}=true;}};
  $r=$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]));self::assertSame('unconfirmed',$r->outcome->state);self::assertSame(2,$f->factory->retirements);self::assertSame(1,$f->count('options'));self::assertSame(1,$f->count('operation_changes'));
 }
 public static function metadata_faults():array{return [['throw_retired_metadata'],['throw_transaction_metadata']];}
 public function test_lost_owner_retirement_ack_keeps_result_unknown_and_never_calls_cache_publisher():void{
  $f=$this->f;$f->factory->unconfirmed_retirement=1;$cache_calls=0;$store=new PolicyAssignmentStore(1,PolicyAssignmentUnitFactory::PREFIX,static function()use(&$cache_calls):void{++$cache_calls;});$p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]);$identity=$f->identity($p);$service=new PolicyAssignmentService($f->factory,$store,$f->catalog($identity),[$f,'authorize'],new PolicyAssignmentUnitReadiness());
  $r=$service->attempt($identity,$p,RequestContext::create());self::assertSame('unconfirmed',$r->outcome->state);self::assertSame(0,$cache_calls);self::assertSame(1,$f->count('options'));self::assertSame(1,$f->count('operation_changes'));
 }

}
