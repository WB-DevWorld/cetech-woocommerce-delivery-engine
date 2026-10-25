<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap,PolicyAssignmentState};
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentAdminFixture,PolicyAssignmentFixture};
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/Support/PolicyAssignment/PolicyAssignmentAdminFixture.php';

final class PolicyAssignmentAdminWorkflowTest extends TestCase {
	use RestoresWordPressFixtureGlobals;private PolicyAssignmentAdminFixture $f;private mixed $old_blog;
	protected function setUp():void {$this->remember_fixture_globals();$this->old_blog=$GLOBALS['blog_id']??null;$GLOBALS['blog_id']=1;$GLOBALS['cetech_de_test_is_admin']=true;$GLOBALS['cetech_de_test_user_id']=9;$this->caps();$GLOBALS['cetech_de_test_edit_posts']=[10=>true,11=>true,20=>true];AdminPageAccess::bind(null);$this->f=new PolicyAssignmentAdminFixture();$this->f->seed();}
	protected function tearDown():void {$this->f->close();AdminPageAccess::bind(null);$this->restore_fixture_globals();if(null===$this->old_blog){unset($GLOBALS['blog_id']);}else{$GLOBALS['blog_id']=$this->old_blog;}}
	private function caps():void {$GLOBALS['cetech_de_test_caps']=['manage_delivery_settings'=>true,'manage_site_wide_defaults'=>true];}
	private function refusal(callable $f):void {try{$f();self::fail('The original assignment context must refuse.');}catch(\RuntimeException){self::assertTrue(true);}}

	public function test_native_open_save_both_policies_and_fresh_saved_preview():void {
		$f=$this->f;$workflow=$f->workflow();$review=$workflow->open('global',0);self::assertSame('inherit',$review['fields']['return_mode']);self::assertSame('inherit',$review['fields']['refund_mode']);
		self::assertSame(['Return café'],array_column($review['choices']['return_policy_v1'],'label'));
		$result=$workflow->submit($review['ticket'],$f->fields(),RequestContext::create());self::assertSame('accepted',$result->outcome->state,$result->outcome->error?->code??'');
		$state=$f->core->state();self::assertSame(1,$state->revision());self::assertSame(PolicyAssignmentFixture::uuid(101),$state->map('return_policy_v1')->facts()['default']['policy_uuid']);self::assertSame(PolicyAssignmentFixture::uuid(102),$state->map('refund_policy_v1')->facts()['default']['policy_uuid']);self::assertSame([],$f->options->rows);
		$workflow=$f->workflow();$opened=$workflow->open('global',0,7);$before=$state->original_bytes();$ops=$f->core->count('operation_records');
		$preview=$workflow->preview($opened['ticket'],RequestContext::create());self::assertSame('assigned',$preview['selections']['return_policy_v1']->disposition());self::assertSame('Return <b>literal</b>',$preview['selections']['return_policy_v1']->version()->content()->facts()['customer_title']);self::assertSame('assigned',$preview['selections']['refund_policy_v1']->disposition());self::assertSame($before,$f->core->state()->original_bytes());self::assertSame($ops,$f->core->count('operation_records'));self::assertSame(0,$f->native_during_sql);
	}

	public function test_both_selected_slots_change_atomically_and_other_offer_slots_survive():void {
		$f=$this->f;$w=$f->workflow();$a=$w->open('global',0,8);$fields=$f->fields(['return_mode'=>'disabled','return_policy_uuid'=>'','refund_mode'=>'inherit','refund_policy_uuid'=>'']);self::assertSame('accepted',$w->submit($a['ticket'],$fields,RequestContext::create())->outcome->state);
		$a=$w->open('global',0,7);self::assertSame('accepted',$w->submit($a['ticket'],$f->fields(),RequestContext::create())->outcome->state);$s=$f->core->state();
		self::assertSame(['mode'=>'disabled'],$s->map('return_policy_v1')->facts()['offers']->{'8'});self::assertSame(['mode'=>'inherit'],$s->map('refund_policy_v1')->facts()['offers']->{'8'});self::assertSame(PolicyAssignmentFixture::uuid(101),$s->map('return_policy_v1')->facts()['offers']->{'7'}['policy_uuid']);self::assertSame(PolicyAssignmentFixture::uuid(102),$s->map('refund_policy_v1')->facts()['offers']->{'7'}['policy_uuid']);self::assertSame(2,$s->revision());
	}
	public function test_same_map_save_is_authenticated_no_change_and_keeps_original_body():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);self::assertSame('accepted',$w->submit($r['ticket'],$f->fields(),RequestContext::create())->outcome->state);$before=$f->core->state()->original_bytes();$changes=$f->core->count('operation_changes');
		$r=$w->open('global',0,7);$result=$w->submit($r['ticket'],$f->fields(['reason'=>'Another reviewed no-change reason']),RequestContext::create());self::assertSame('not_applicable',$result->outcome->state);self::assertNotNull($result->completion);self::assertSame($before,$f->core->state()->original_bytes());self::assertSame($changes,$f->core->count('operation_changes'));self::assertSame([],$f->options->rows);
	}
	public function test_old_ticket_or_other_native_owner_cannot_replace_the_current_review():void {
		$f=$this->f;$w=$f->workflow();$old=$w->open('global',0,7);$fresh=$w->open('product',10,8);$bytes=$f->options->rows;$opens=$f->core->factory->opens;
		self::assertSame('stale_revision',$w->submit($old['ticket'],$f->fields(),RequestContext::create())->outcome->error?->code);self::assertSame($bytes,$f->options->rows);self::assertSame($opens,$f->core->factory->opens);
		$GLOBALS['cetech_de_test_user_id']=10;self::assertSame('stale_revision',$f->workflow()->submit($fresh['ticket'],$f->fields(),RequestContext::create())->outcome->error?->code);self::assertSame($bytes,$f->options->rows);
	}
	#[DataProvider('invalid_fields')]
	public function test_forged_ambiguous_or_malformed_fields_never_dispatch(array $changes):void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$before=$f->options->rows;$opens=$f->core->factory->opens;
		$result=$w->submit($r['ticket'],array_replace($f->fields(),$changes),RequestContext::create());self::assertSame('rejected',$result->outcome->state);self::assertSame($before,$f->options->rows);self::assertSame($opens,$f->core->factory->opens);self::assertSame(0,$f->core->state()->row_id());
	}
	public static function invalid_fields():array {return [
		'forged actor'=>[['actor_user_id'=>10]],'forged target'=>[['coordinate'=>['scope_type'=>'global']]],'forged CAS'=>[['row_id'=>99]],'new identity'=>[['identity'=>[]]],
		'wrong mode'=>[['return_mode'=>'activate']],'array uuid'=>[['return_policy_uuid'=>[]]],'foreign family uuid'=>[['return_policy_uuid'=>PolicyAssignmentFixture::uuid(102)]],
		'unknown policy'=>[['refund_policy_uuid'=>PolicyAssignmentFixture::uuid(999)]],'dangling disabled selection'=>[['return_mode'=>'disabled']],
		'empty audit reason'=>[['reason'=>'']],'oversize audit reason'=>[['reason'=>str_repeat('x',1001)]],'malformed reason'=>[['reason'=>"bad\x00reason"]],
	];}
	public function test_opened_cas_never_refreshes_after_another_assignment_changes_the_scope():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$p=$f->core->payload(null,8,['return_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$f->core->attempt($p,'another-actor-request')->outcome->state);$before=$f->core->state()->original_bytes();
		$result=$w->submit($r['ticket'],$f->fields(),RequestContext::create());self::assertSame('rejected',$result->outcome->state);self::assertSame('stale_revision',$result->outcome->error?->code);self::assertSame($before,$f->core->state()->original_bytes());self::assertSame([],$f->options->rows);
	}
	public function test_changed_reviewed_publication_is_refused_without_assignment_effect():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$f->core->publish('return_policy_v1',103,3,['name'=>'Another current policy']);$result=$w->submit($r['ticket'],$f->fields(),RequestContext::create());self::assertSame('rejected',$result->outcome->state);self::assertSame('stale_revision',$result->outcome->error?->code);self::assertSame(0,$f->core->state()->row_id());self::assertSame([],$f->options->rows);
	}
	public function test_unknown_original_survives_new_request_and_reconciles_without_current_source_reads_or_reapplying():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$f->core->factory->on_commit=static function():void{$GLOBALS['cetech_de_test_caps']=[];};
		$result=$w->submit($r['ticket'],$f->fields(),RequestContext::create());self::assertSame('unconfirmed',$result->outcome->state);self::assertSame(1,$f->core->state()->revision());$original=$f->journal();self::assertSame('uncertain',$original['state']);self::assertSame(9,$original['payload']['actor_user_id']);self::assertSame('user:9',$original['identity']['principal']);self::assertCount(7,$original['identity']);
		$f->core->factory->on_commit=null;$this->caps();self::assertTrue($f->workflow()->view()['blocked']);$this->refusal(fn()=>$f->workflow()->open('product',10,8));
		$p=$f->core->payload(null,8,['return_policy_v1'=>['mode'=>'disabled']]);self::assertSame('accepted',$f->core->attempt($p,'later-other-namespace')->outcome->state);$advanced=$f->core->state()->original_bytes();$before=count($f->core->factory->sql);
		$result=$f->workflow()->recover('reconcile',RequestContext::create());self::assertSame('accepted',$result->outcome->state);self::assertSame($advanced,$f->core->state()->original_bytes());self::assertSame([],$f->options->rows);
		$queries=array_slice($f->core->factory->sql,$before);foreach($queries as $sql){self::assertDoesNotMatchRegularExpression('/\ASELECT.+(?:logical_rules|rule_versions).+FOR UPDATE/s',$sql);}self::assertSame(0,$f->native_during_sql);
	}
	public function test_journal_ack_hooks_are_before_fresh_native_dispatch_grant():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$opens=$f->core->factory->opens;$f->options->after_write=static function():void{$GLOBALS['cetech_de_test_caps']=[];};
		self::assertSame('unconfirmed',$w->submit($r['ticket'],$f->fields(),RequestContext::create())->outcome->state);self::assertSame($opens,$f->core->factory->opens);self::assertSame(0,$f->core->state()->row_id());$original=$f->journal();self::assertSame('ready',$original['state']);
		$this->caps();self::assertTrue($f->workflow()->view()['blocked']);self::assertSame('accepted',$f->workflow()->recover('retry',RequestContext::create())->outcome->state);self::assertSame(1,$f->core->state()->revision());self::assertSame([],$f->options->rows);
	}
	public function test_lost_ready_readback_retains_original_without_dispatch_and_retry_is_exact():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$opens=$f->core->factory->opens;$f->options->after_write=function()use($f):void{$f->options->fail_next_read=true;};
		self::assertSame('unconfirmed',$w->submit($r['ticket'],$f->fields(),RequestContext::create())->outcome->state);self::assertSame($opens,$f->core->factory->opens);$original=$f->journal();self::assertSame('ready',$original['state']);self::assertSame('accepted',$f->workflow()->recover('retry',RequestContext::create())->outcome->state);self::assertSame([],$f->options->rows);
	}
	public function test_malformed_retained_original_is_never_cleared_as_known_rejection():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$f->options->after_write=static function():void{$GLOBALS['cetech_de_test_caps']=[];};$w->submit($r['ticket'],$f->fields(),RequestContext::create());$this->caps();$raw=$f->journal();$raw['identity']['principal']='user:10';$f->options->rows[$f->journal_name()]=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$before=$f->options->rows;$opens=$f->core->factory->opens;
		self::assertSame('unconfirmed',$f->workflow()->recover('reconcile',RequestContext::create())->outcome->state);self::assertSame($before,$f->options->rows);self::assertSame($opens,$f->core->factory->opens);
	}
	public function test_default_slot_cannot_be_presented_as_a_real_delivery_offer_preview():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0);$before=$f->options->rows;$opens=$f->core->factory->opens;$this->refusal(fn()=>$w->preview($r['ticket'],RequestContext::create()));self::assertSame($before,$f->options->rows);self::assertSame($opens,$f->core->factory->opens);
	}
	public function test_saved_preview_ignores_unsaved_editor_choices_and_keeps_journal_bytes():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);$before=$f->options->rows;$p=$w->preview($r['ticket'],RequestContext::create());self::assertSame('not_assigned',$p['selections']['return_policy_v1']->disposition());self::assertSame('not_assigned',$p['selections']['refund_policy_v1']->disposition());self::assertSame($before,$f->options->rows);self::assertSame(0,$f->core->state()->row_id());
	}
	public function test_undispatched_review_can_be_replaced_when_original_variation_is_reparented():void {
		$f=$this->f;$w=$f->workflow();$w->open('variation',11,7);$f->products[11]['parent_id']=20;$view=$f->workflow()->view();self::assertFalse($view['blocked']);self::assertNull($view['review']);$new=$f->workflow()->open('global',0,8);self::assertSame('global',$new['scope_type']);self::assertSame(0,$f->core->state()->row_id());
	}
	public function test_unconfirmed_original_stays_blocked_after_original_variation_is_reparented():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('variation',11,7);$f->core->factory->on_commit=static function():void{$GLOBALS['cetech_de_test_caps']=[];};self::assertSame('unconfirmed',$w->submit($r['ticket'],$f->fields(),RequestContext::create())->outcome->state);$f->core->factory->on_commit=null;$this->caps();$f->products[11]['parent_id']=20;$before=$f->options->rows;
		self::assertSame('unconfirmed',$f->workflow()->recover('reconcile',RequestContext::create())->outcome->state);$this->refusal(fn()=>$f->workflow()->open('global',0,8));self::assertSame($before,$f->options->rows);
	}
	public function test_completed_ticket_replay_cannot_apply_a_second_effect():void {
		$f=$this->f;$w=$f->workflow();$r=$w->open('global',0,7);self::assertSame('accepted',$w->submit($r['ticket'],$f->fields(),RequestContext::create())->outcome->state);$before=$f->core->state()->original_bytes();$ops=$f->core->count('operation_records');self::assertSame('stale_revision',$w->submit($r['ticket'],$f->fields(),RequestContext::create())->outcome->error?->code);self::assertSame($before,$f->core->state()->original_bytes());self::assertSame($ops,$f->core->count('operation_records'));
	}
	public function test_display_label_shortening_never_changes_supplied_policy_content():void {
		$f=$this->f;$name=str_repeat('é',101);$f->core->publish('return_policy_v1',103,3,['name'=>$name]);$r=$f->workflow()->open('global',0,7);$choice=array_values(array_filter($r['choices']['return_policy_v1'],static fn(array $row):bool=>PolicyAssignmentFixture::uuid(103)===$row['policy_uuid']))[0];self::assertSame(str_repeat('é',100),$choice['label']);$db=$f->core->factory->inspection();$p=$db->query('SELECT payload_json FROM '.$f->core->table('rule_versions').' WHERE version_uuid='.$db->quote(PolicyAssignmentFixture::uuid(3)))->fetchColumn();$content=json_decode(json_decode($p,true,8,JSON_THROW_ON_ERROR)['content_json'],true,8,JSON_THROW_ON_ERROR);self::assertSame($name,$content['name']);
	}
	public function test_catalog_choice_cap_refuses_incomplete_editor_without_replacing_original_review():void {
		$f=$this->f;for($i=0;$i<63;++$i){$f->core->publish('return_policy_v1',200+$i,1000+$i,['name'=>'Choice '.$i]);}$w=$f->workflow();$r=$w->open('global',0,7);self::assertCount(64,$r['choices']['return_policy_v1']);$before=$f->options->rows;$f->core->publish('return_policy_v1',300,2000,['name'=>'Over-cap choice']);$this->refusal(fn()=>$f->workflow()->open('global',0,8));self::assertSame($before,$f->options->rows);self::assertSame(0,$f->core->state()->row_id());
	}
	public function test_native_cache_failure_preserves_accepted_pending_original_until_publication_reconciles():void {
		$file=dirname(__DIR__,2).'/Support/PolicyAssignment/policy-assignment-native-publication-probe.php';$process=proc_open([PHP_BINARY,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err);$facts=json_decode($out,true,8,JSON_THROW_ON_ERROR);self::assertCount(12,$facts);foreach($facts as$key=>$value){self::assertTrue($value,$key);}
	}
}
