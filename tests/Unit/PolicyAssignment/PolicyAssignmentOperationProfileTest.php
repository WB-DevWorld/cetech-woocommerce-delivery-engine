<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\PolicyAssignment\{PolicyAssignmentOperationProfile,PolicyAssignmentService,PolicyCurrentCatalog};
use CetechDeliveryEngine\Integrations\PolicyAssignment\NativePolicyAssignmentAuthority;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentUnitFactory,PolicyAssignmentUnitReadiness};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion,OperationMaterialEvent};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/Support/PolicyAssignment/PolicyAssignmentFixture.php';
require_once dirname(__DIR__,2).'/Support/PolicyAssignment/PolicyAssignmentAdminNativeFixtures.php';

final class PolicyAssignmentOperationProfileTest extends TestCase {
 use RestoresWordPressFixtureGlobals;
 private PolicyAssignmentFixture $f;
 protected function setUp():void{$this->f=new PolicyAssignmentFixture();}
 protected function tearDown():void{$this->f->close();}
 public function test_original_event_and_completion_validate_without_any_current_policy_read():void{
  [$profile,$completion,$event]=$this->accepted();$sql=count($this->f->factory->sql);self::assertTrue($profile->validate_accepted_facts($completion,$event));self::assertSame($sql,count($this->f->factory->sql));
 }
 #[DataProvider('result_corruptions')]
 public function test_each_independent_result_link_must_match_original_command_and_event(string $key):void{
  [$profile,$completion,$event]=$this->accepted();$facts=$completion->result;$facts[$key]=is_int($facts[$key])?$facts[$key]+1:str_repeat('0',64);$bad=OperationCompletion::accepted($profile,$facts,$completion->publication);self::assertFalse($profile->validate_accepted_facts($bad,$event));
 }
 public static function result_corruptions():array{return array_map(static fn(string $key):array=>[$key],['site_id','coordinate_hash','offer_id','row_id','revision','generation_hash','state_hash','bytes_hash','return_map_hash','refund_map_hash','actor_user_id','reason_hash','changed_at','reviewed_hash','intent_hash']);}
 #[DataProvider('event_corruptions')]
 public function test_each_original_event_link_is_authenticated_before_replay(string $section,string $key):void{
  [$profile,$completion,$event]=$this->accepted();$facts=json_decode($event->to_json(),true,16,JSON_THROW_ON_ERROR);$facts[$section][$key]=is_int($facts[$section][$key])?$facts[$section][$key]+1:str_repeat('0',64);$bad=OperationMaterialEvent::from_json(json_encode($facts,JSON_THROW_ON_ERROR),$profile);self::assertFalse($profile->validate_accepted_facts($completion,$bad));
 }
 public static function event_corruptions():array{
  $cases=[];foreach(['actor_user_id','authority_hash','principal_hash'] as $key){$cases[]=['actor',$key];}foreach(['site_id','coordinate_hash','offer_id','before_row_id','after_row_id','before_revision','after_revision','before_state_hash','after_state_hash','before_bytes_hash','after_bytes_hash','generation_hash','reason_hash','reviewed_hash','intent_hash','changed_at'] as $key){$cases[]=['target',$key];}return $cases;
 }
 public function test_publication_cannot_point_to_another_scope_or_native_option():void{
  [$profile,$completion,$event]=$this->accepted();foreach(['coordinate_hash','option_hash'] as $key){$p=$completion->publication;$p[$key]=str_repeat('0',64);$bad=OperationCompletion::accepted($profile,$completion->result,$p);self::assertFalse($profile->validate_accepted_facts($bad,$event));}
 }
 public function test_corrupt_accepted_receipt_is_quarantined_without_rewriting_original_state_or_audit():void{
  $f=$this->f;[$profile,$completion,$event,$p]=$this->accepted();$before=$f->rows('options');$audits=$f->rows('operation_changes');$record=$f->rows('operation_records')[0];$body=json_decode($record['completion_json'],true,16,JSON_THROW_ON_ERROR);$body['result']['state_hash']=str_repeat('0',64);$db=$f->factory->inspection();$db->exec('UPDATE '.$f->table('operation_records').' SET completion_json='.$db->quote(json_encode($body,JSON_THROW_ON_ERROR)).' WHERE id='.(int)$record['id']);
  $identity=$f->identity($p);$r=$f->service($identity)->reconcile($identity,$p,RequestContext::create());self::assertSame('unconfirmed',$r->outcome->state);self::assertSame($before,$f->rows('options'));self::assertSame($audits,$f->rows('operation_changes'));
 }
 public function test_no_change_receipt_is_checked_against_original_physical_frame_not_latest_rows():void{
  $f=$this->f;$p=$f->payload();$identity=$f->identity($p);$r=$f->attempt($p);self::assertSame('not_applicable',$r->outcome->state);$profile=new PolicyAssignmentOperationProfile([$f,'authorize'],$f->store,$f->catalog($identity));$profile->validate_command($identity,$p);self::assertTrue($profile->validate_no_change_facts($r->completion));$bad=$r->completion->result;$bad['revision']=1;self::assertFalse($profile->validate_no_change_facts(OperationCompletion::not_applicable($profile,$bad)));
 }

 public function test_genuine_native_variation_grant_rejects_forged_parent_before_opening_sql():void{
  $this->remember_fixture_globals();$old_blog=$GLOBALS['blog_id']??null;$f=$this->f;
  try{
   $GLOBALS['blog_id']=1;$GLOBALS['cetech_de_test_is_admin']=true;$GLOBALS['cetech_de_test_user_id']=9;$GLOBALS['cetech_de_test_caps']=['manage_delivery_settings'=>true,'manage_site_wide_defaults'=>true];$GLOBALS['cetech_de_test_edit_posts']=[33=>true,44=>true,55=>true];AdminPageAccess::bind(null);
   $authority=new NativePolicyAssignmentAuthority(null,static fn(int $id):array=>['parent_id'=>44===$id?33:0,'is_variation'=>44===$id,'label'=>'Owned target'],static fn():array=>['site_id'=>1,'prefix'=>PolicyAssignmentUnitFactory::PREFIX]);
   $coordinate=$authority->target('variation',44);self::assertSame(33,$coordinate['parent_id']);$identity=$authority->identity($authority->identity_data('policy.assignment.set',$coordinate,0,'parent-fence'));$grant=$authority->capture($identity,$coordinate,0);
   $wrong=$coordinate;$wrong['parent_id']=55;$payload=$f->payload($wrong,instructions:['return_policy_v1'=>['mode'=>'disabled'],'refund_policy_v1'=>['mode'=>'disabled']]);
   $service=new PolicyAssignmentService($f->factory,$f->store,new PolicyCurrentCatalog($grant['return_family'],$grant['refund_family'],$identity),$grant['authorize'],new PolicyAssignmentUnitReadiness());$opens=$f->factory->opens;
   $result=$service->attempt($identity,$payload,RequestContext::create());self::assertSame('rejected',$result->outcome->state);self::assertSame('not_authorized',$result->outcome->error?->code);self::assertSame($opens,$f->factory->opens);self::assertSame(0,$f->count('options'));self::assertSame(0,$f->count('operation_records'));self::assertSame(0,$f->count('operation_changes'));
  }finally{AdminPageAccess::bind(null);$this->restore_fixture_globals();if(null===$old_blog){unset($GLOBALS['blog_id']);}else{$GLOBALS['blog_id']=$old_blog;}}
 }
 private function accepted():array{
  $f=$this->f;$p=$f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled'],'refund_policy_v1'=>['mode'=>'disabled']]);$identity=$f->identity($p);$r=$f->attempt($p);self::assertSame('accepted',$r->outcome->state);$profile=new PolicyAssignmentOperationProfile([$f,'authorize'],$f->store,$f->catalog($identity));$profile->validate_command($identity,$p);$event=OperationMaterialEvent::from_json($f->rows('operation_changes')[0]['event_json'],$profile);return [$profile,$r->completion,$event,$p];
 }
}
