<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Persistence;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseLifecycleOperationProfile;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\{OperationCompletion, OperationMaterialEvent};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseLifecycleRecordedProfile, PromiseSiteBinding, PromiseSourceReceipt, PromiseStoredObject, PromiseVersionCommand};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromiseRecordedReceiptTest extends TestCase {
	public function test_physical_original_creation_ack_has_reference_only_bounded_facts(): void {
		[$operation,$event,$receipt,$row] = self::packet(); PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$row);
		self::assertLessThan(16384, strlen($operation['completion_json'])); self::assertLessThan(16384, strlen($event['event_json']));
		self::assertStringNotContainsString('Private synthetic reason',$operation['completion_json']); self::assertStringNotContainsString('weekly_openings',$event['event_json']);
		self::assertSame(31,count(json_decode($operation['completion_json'],true)['result']));
		// Creation receipt remains its original proof after a later scheduling command.
		$row['schedule_expected_object_revision']=6; $row['schedule_expected_published_version_id']=null;
		PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$row); self::assertTrue(true);
	}
	#[DataProvider('corruptions')]
	public function test_unacknowledged_or_foreign_physical_facts_never_become_publication_authority(string $case): void {
		[$operation,$event,$receipt,$row] = self::packet();
		switch($case) {
			case 'namespace':$operation['namespace_hash']=str_repeat('b',64);break;
			case 'intent':$operation['intent_hash']=str_repeat('b',64);break;
			case 'native_site':$operation['site_id']=8;break;
			case 'event_link':$event['operation_id']=2;break;
			case 'target_digest':$operation['target_hash']=str_repeat('b',64);break;
			case 'body_digest':$row['body_digest']=str_repeat('b',64);break;
			case 'row_id':$row['id']=2;break;
			case 'uuid':$row['version_uuid']='22222222-2222-4222-8222-222222222222';break;
			case 'domain_version':$row['domain_version']=2;break;
			case 'logical_id':$row['logical_id']='other';break;
			case 'opaque_site':$row['site_key']='other-site';break;
			case 'parent':$row['object_id']=2;break;
			case 'declared_from':$row['declared_from']='2026-10-02 00:00:00.000000';break;
			case 'predecessor_guard':$row['predecessor_version_id']=23;break;
			case 'receipt_actor':$f=$receipt->private_facts();$f['author_user_id']=22;$receipt=PromiseSourceReceipt::from_array($f);break;
			case 'receipt_reason':$f=$receipt->private_facts();$f['reason']='different';$receipt=PromiseSourceReceipt::from_array($f);break;
		}
		$this->expectException(\InvalidArgumentException::class); PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$row);
	}
	public static function corruptions():array {return array_map(static fn($v)=>[$v],['namespace','intent','native_site','event_link','target_digest','body_digest','row_id','uuid','domain_version','logical_id','opaque_site','parent','declared_from','predecessor_guard','receipt_actor','receipt_reason']);}
	public function test_later_phase_actor_is_bound_to_its_ack_not_creation_actor():void {
		[$operation,$event,$receipt,$row]=self::packet('promise.version.seal'); $row['author_user_id']=99; $row['reason']='Original creation reason';
		PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$row);self::assertTrue(true);
	}
	public function test_schedule_guard_is_exact_even_if_body_and_receipt_hash_are_unchanged():void {
		[$operation,$event,$receipt,$row]=self::packet('promise.version.schedule'); PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$row);
		$row['schedule_expected_object_revision']++;
		$this->expectException(\InvalidArgumentException::class);PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$row);
	}
	public function test_mutable_object_head_requires_its_exact_latest_original_ack(): void {
		[$operation,$event,$receipt,$version]=self::packet();
		$head=['id'=>1,'site_id'=>'7','site_key'=>'site-1','kind'=>'calendar','logical_id'=>'picking','revision'=>'2','last_sequence'=>'1','draft_version_id'=>'1','scheduled_version_id'=>null,'published_version_id'=>null,'latest_version_id'=>'1','latest_source_receipt_digest'=>$receipt->digest(),'updated_at'=>$receipt->accepted_at()->sql()];
		PromiseLifecycleRecordedProfile::assert_object_head($operation,$event,$receipt,$head,$version);
		$head['revision']='9';
		$this->expectException(\InvalidArgumentException::class);PromiseLifecycleRecordedProfile::assert_object_head($operation,$event,$receipt,$head,$version);
	}
	public function test_atomic_successor_ack_truthfully_links_retired_predecessor_without_rewriting_original_body(): void {
		[$operation,$event,$original,$oldrow]=self::packet();$old=$original->private_facts();$old['operation']='promise.version.publish';$old['role']='superseded';$old['state']='retired';$old['before_revision']=7;$old['after_revision']=8;$old['version_before_revision']=3;$old['version_after_revision']=4;
		$receipt=PromiseSourceReceipt::from_array($old);$profile=PromiseLifecycleOperationProfile::receipt_profile('promise.version.publish');
		$f=json_decode($operation['completion_json'],true)['result'];$f['object_before_revision']=7;$f['object_revision']=8;$f['version_id']=2;$f['version_uuid']='22222222-2222-4222-8222-222222222222';$f['domain_version']=2;$f['version_before_revision']=2;$f['version_revision']=3;$f['state']='published';$f['source_receipt_hash']=str_repeat('a',64);$f['has_published']=true;$f['published_at']=$f['accepted_at'];$f['has_predecessor']=true;$f['predecessor_version_id']=1;$f['predecessor_version_uuid']=$old['version_uuid'];$f['predecessor_domain_version']=1;$f['predecessor_version_before_revision']=3;$f['predecessor_version_revision']=4;$f['predecessor_body_digest']=$old['content_digest'];$f['predecessor_source_receipt_hash']=$receipt->digest();$f['version_guards']['predecessor_version_id']=1;$f['object_head']=['revision'=>8,'last_sequence'=>2,'draft_version_id'=>0,'scheduled_version_id'=>0,'published_version_id'=>2,'latest_version_id'=>2];
		$identity=PromiseCommandsTest::identity(PromiseSiteBinding::bind(7,'site-1'),'promise.version.publish');$operation['operation']='promise.version.publish';$completion=OperationCompletion::accepted($profile,$f);$operation['completion_json']=$completion->to_json();
		$e=OperationMaterialEvent::from_mutation($profile,$identity,RequestContext::create(),['authority_hash'=>$old['authority_hash'],'principal_hash'=>$old['principal_hash'],'author_user_id'=>11],$f,'promise_published',7,8,['publication','retirement','head']);$event['event_json']=$e->to_json();
		PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$oldrow);self::assertSame($original->private_facts()['content_digest'],$oldrow['body_digest']);
		$oldrow['body_digest']=str_repeat('c',64);$this->expectException(\InvalidArgumentException::class);PromiseLifecycleRecordedProfile::assert_receipt($operation,$event,$receipt,$oldrow);
	}

	public static function packet(string $op='promise.version.create'):array {
		$b=PromiseSiteBinding::bind(7,'site-1');$payload=PromiseCommandsTest::payload();$identity=PromiseCommandsTest::identity($b,$op);$create='promise.version.create'===$op;$schedule='promise.version.schedule'===$op;
		$payload['body_json']=$create?$payload['body_json']:null;$payload['preconditions']=$create?$payload['preconditions']:['object_revision'=>3,'version_revision'=>2,'published_version_id'=>0];if($schedule){$payload['declared_from']='2026-10-10 00:00:00.000000';}
		$command=PromiseVersionCommand::from_array($identity,$b,$payload);$at=RuleTime::parse('2026-10-09 10:00:00.000000');$state=$create?'draft':($schedule?'scheduled':'sealed');$before=$create?1:3;$after=$before+1;$vb=$create?0:2;
		$receipt=PromiseSourceReceipt::from_array(['format_version'=>1,'kind'=>'version','role'=>'primary','site_id'=>7,'site_key'=>'site-1','namespace_hash'=>$identity->namespace_digest(),'intent_hash'=>$command->intent()->fingerprint(),'operation'=>$op,'target_digest'=>hash('sha256','cetech-operation-target-v1:'.$identity->target_key),'accepted_at'=>$at->sql(),'author_user_id'=>11,'authority_hash'=>hash('sha256','manager'),'principal_hash'=>hash('sha256','user:11'),'reason'=>$payload['reason'],'before_revision'=>$before,'after_revision'=>$after,'object_id'=>1,'version_uuid'=>$payload['version_uuid'],'domain_version'=>1,'content_digest'=>$payload['body_digest'],'logical_digest'=>PromiseStoredObject::identity_digest(7,'site-1','calendar','picking'),'version_before_revision'=>$vb,'version_after_revision'=>$vb+1,'state'=>$state,'declared_from'=>$payload['declared_from'],'declared_until'=>null,'calendar_publications'=>[]]);
		$f=['kind'=>'calendar','site_digest'=>hash('sha256','site-1'),'logical_digest'=>PromiseStoredObject::identity_digest(7,'site-1','calendar','picking'),'object_id'=>1,'object_before_revision'=>$before,'object_revision'=>$after,'version_id'=>1,'version_uuid'=>$payload['version_uuid'],'domain_version'=>1,'version_before_revision'=>$vb,'version_revision'=>$vb+1,'state'=>$state,'body_digest'=>$payload['body_digest'],'source_receipt_hash'=>$receipt->digest(),'accepted_at'=>$at->epoch_microseconds(),'declared_from'=>RuleTime::parse($payload['declared_from'])->epoch_microseconds(),'has_until'=>false,'declared_until'=>0,'has_published'=>false,'published_at'=>0,'author_user_id'=>11,'has_predecessor'=>false,'predecessor_version_id'=>0,'predecessor_version_uuid'=>'00000000-0000-4000-8000-000000000000','predecessor_domain_version'=>0,'predecessor_version_before_revision'=>0,'predecessor_version_revision'=>0,'predecessor_body_digest'=>hash('sha256',''),'predecessor_source_receipt_hash'=>hash('sha256',''),'object_head'=>['revision'=>$after,'last_sequence'=>1,'draft_version_id'=>$schedule?0:1,'scheduled_version_id'=>$schedule?1:0,'published_version_id'=>0,'latest_version_id'=>1],'version_guards'=>['predecessor_version_id'=>0,'schedule_expected_object_revision'=>$schedule?$after:0,'schedule_expected_published_version_id'=>0]];
		$profile=PromiseLifecycleOperationProfile::receipt_profile($op);$completion=OperationCompletion::accepted($profile,$f);$event=OperationMaterialEvent::from_mutation($profile,$identity,RequestContext::create(),['authority_hash'=>hash('sha256','manager'),'principal_hash'=>hash('sha256','user:11'),'author_user_id'=>11],$f,$create?'promise_created':($schedule?'promise_scheduled':'promise_sealed'),$before,$after,$create?['immutable_version','head']:($schedule?['schedule','head']:['seal']));
		$operation=['id'=>1,'site_id'=>7,'namespace_hash'=>$identity->namespace_digest(),'intent_hash'=>$command->intent()->fingerprint(),'namespace_format'=>1,'intent_format'=>1,'record_format'=>1,'operation'=>$op,'operation_version'=>1,'target_hash'=>hash('sha256','cetech-operation-target-v1:'.$identity->target_key),'state'=>'accepted','publication_state'=>'none','completion_json'=>$completion->to_json(),'audit_id'=>1,'row_version'=>2,'created_at'=>$at->sql(),'updated_at'=>$at->sql(),'completed_at'=>$at->sql()];
		$eventrow=['id'=>1,'site_id'=>7,'operation_id'=>1,'event_format'=>1,'event_json'=>$event->to_json(),'created_at'=>$at->sql()];
		$row=['id'=>1,'site_id'=>7,'site_key'=>'site-1','object_id'=>1,'kind'=>'calendar','logical_id'=>'picking','version_uuid'=>$payload['version_uuid'],'domain_version'=>1,'body_digest'=>$payload['body_digest'],'declared_from'=>$payload['declared_from'],'declared_until'=>null,'author_user_id'=>11,'reason'=>$payload['reason'],'predecessor_version_id'=>null,'schedule_expected_object_revision'=>$schedule?$after:null,'schedule_expected_published_version_id'=>null];
		return[$operation,$eventrow,$receipt,$row];
	}
}
