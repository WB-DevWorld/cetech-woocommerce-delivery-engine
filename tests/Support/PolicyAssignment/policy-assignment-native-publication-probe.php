<?php
declare(strict_types=1);

/** Native cache entrypoint fault in an isolated owned PHP unit process, not WordPress. */
function wp_cache_delete(mixed $key,string $group=''):bool {
	if (($GLOBALS['assignment_probe_cache_failure']??false) && is_string($key) && str_starts_with($key,'cetech_de_policy_assignment_v1_')) {
		++$GLOBALS['assignment_probe_cache_throws']; throw new RuntimeException('Owned publication sink failure.');
	}
	return true;
}
require dirname(__DIR__,2).'/bootstrap.php';
require __DIR__.'/PolicyAssignmentAdminFixture.php';

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentAdminFixture;
use CetechDeliveryEngine\Presentation\Admin\{AdminActionHandler,AdminNoticeService,PolicyAssignmentPage};

$GLOBALS['blog_id']=1;$GLOBALS['cetech_de_test_is_admin']=true;$GLOBALS['cetech_de_test_user_id']=9;
$GLOBALS['cetech_de_test_caps']=['manage_delivery_settings'=>true,'manage_site_wide_defaults'=>true];
$f=new PolicyAssignmentAdminFixture();
try {
	$f->seed();$w=$f->workflow();$r=$w->open('global',0,7);$GLOBALS['assignment_probe_cache_failure']=true;$GLOBALS['assignment_probe_cache_throws']=0;
	$result=$w->submit($r['ticket'],$f->fields(),RequestContext::create());$original=$f->journal();
	$pending=$result->outcome->mutation_accepted===true&&$result->outcome->publication_pending&&$result->completion?->state==='accepted'&&$original['state']==='uncertain';
	$blocked=$f->workflow()->view()['blocked'];$payload=$original['payload'];$identity=$original['identity'];
	$p=$f->core->payload(null,8,['return_policy_v1'=>['mode'=>'disabled']]);$later=$f->core->attempt($p,'later-during-pending');$advanced=$f->core->state()->original_bytes();$retained=$f->journal();
	$changes=$f->core->count('operation_changes');$before=count($f->core->factory->sql);$GLOBALS['assignment_probe_cache_failure']=false;
	$resolved=$f->workflow()->recover('reconcile',RequestContext::create());$history=true;
	foreach(array_slice($f->core->factory->sql,$before)as$sql){if(preg_match('/\ASELECT.+(?:logical_rules|rule_versions).+FOR UPDATE/s',$sql))$history=false;}
	$out=['pending_preserves_acknowledged_effect'=>$pending,'original_journal_blocks_fresh_changes'=>$blocked,'actual_native_cache_fault_observed'=>$GLOBALS['assignment_probe_cache_throws']>0,
		'later_assignment_accepted'=>$later->outcome->state==='accepted','original_payload_and_identity_retained'=>$retained['payload']===$payload&&$retained['identity']===$identity,
		'history_reconcile_completes_publication'=>$resolved->outcome->state==='accepted'&&!$resolved->outcome->publication_pending,
		'advanced_map_bytes_not_republished'=>$advanced===$f->core->state()->original_bytes(),'no_second_material_audit'=>$changes===$f->core->count('operation_changes'),
		'no_current_policy_reload'=>$history,'original_journal_removed_after_resolution'=>$f->options->rows===[]];
	$f2=new PolicyAssignmentAdminFixture();
	try {
		$f2->seed();$w2=$f2->workflow();$review=$w2->open('global',0,7);$page=new PolicyAssignmentPage($w2,new AdminActionHandler(new AdminNoticeService()));
		$action=PolicyAssignmentPage::ACTION_PREFIX.'save';$_SERVER['REQUEST_METHOD']='POST';$_POST=['cetech_de_action'=>$action,'cetech_de_nonce'=>'test-nonce-'.$action,'ticket'=>$review['ticket'],...$f2->fields()];$GLOBALS['assignment_probe_cache_failure']=true;
		$page->handle_actions();ob_start();try{$page->render();$html=ob_get_contents();}finally{ob_end_clean();}
		$out['native_page_reports_saved_effect_pending_confirmation']=str_contains($html,'The assignments were saved. Finish confirmation below before another change.');
		$out['pending_page_offers_recovery_and_suppresses_fresh_changes']=str_contains($html,PolicyAssignmentPage::ACTION_PREFIX.'reconcile')&&!str_contains($html,PolicyAssignmentPage::ACTION_PREFIX.'save')&&$f2->workflow()->view()['blocked'];
	} finally {$f2->close();$GLOBALS['assignment_probe_cache_failure']=false;}
	echo json_encode($out,JSON_THROW_ON_ERROR);
} finally {$f->close();}
