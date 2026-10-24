<?php
/** R22 owned native administration fixture. No production action is replaced. */
declare(strict_types=1);

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyContent,RefundPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent,RuleLifecycleCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{TableNames,WpdbRefundPolicyAdminIntent};

// This same exact file may be copied as the sole, temporary MU observer. It
// serves only a possession-bound listener probe and suppresses async runners.
if ('1' !== getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION') || '1' !== getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION')) { return; }
if (defined('WP_CLI')&&WP_CLI&&'cetech-refund-policy-admin-fixture.php'===basename(__FILE__)) {
    add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);
    return;
}
if (!(defined('WP_CLI') && WP_CLI)) {
    $site=getenv('CETECH_DE_HTTP_FIXTURE_SITE');
    if (!is_string($site)||!defined('ABSPATH')||realpath($site)!==realpath(ABSPATH)||!defined('DB_HOST')||!preg_match('/\A127\.0\.0\.1(?::[0-9]+)?\z/D',DB_HOST)||!defined('DB_NAME')||!preg_match('/\Acetech_wp_opening_qualification_[a-z0-9]+\z/D',DB_NAME)) { throw new RuntimeException('R22 unowned listener refused.'); }
    if ('1'!==(string)get_option('cetech_opening_qualification_disposable')||!defined('DISABLE_WP_CRON')||!DISABLE_WP_CRON||'http://127.0.0.1:8085'!==get_option('home')||'http://127.0.0.1:8085'!==get_option('siteurl')) { throw new RuntimeException('R22 listener marker refused.'); }
    add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);
    if (isset($_GET['cetech_refund_policy_probe'])) {
        $token=getenv('CETECH_DE_HTTP_PROBE_TOKEN');$received=$_SERVER['HTTP_X_CETECH_OPENING_PROBE']??null;
        if ('1'!==$_GET['cetech_refund_policy_probe']||!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token)||!is_string($received)||!hash_equals($token,$received)) { http_response_code(403);exit; }
        header('Content-Type: application/json');header('Cache-Control: no-store');
        echo json_encode(['format'=>'cetech-r22-owned-listener-v1','probe_sha256'=>hash('sha256',$token),'site_path_sha256'=>hash('sha256',realpath(ABSPATH)),'database_name_sha256'=>hash('sha256',DB_NAME),'source_head'=>getenv('CETECH_DE_QUALIFICATION_HEAD'),'candidate_head'=>getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'),'source_tree'=>getenv('CETECH_DE_QUALIFICATION_TREE'),'php'=>PHP_VERSION,'sapi'=>PHP_SAPI],JSON_THROW_ON_ERROR);exit;
    }
    return;
}
if (!isset($args)||count($args)!==3) { throw new RuntimeException('R22 expects MODE PRIVATE_STATE OUTPUT.'); }
require_once __DIR__.'/opening-http-fixture-common.php';
opening_http_fixture_guard();
[$mode,$state_path,$output_path]=$args;
if (!in_array($mode,['prepare','register','inspect','revoke','cleanup'],true)) { throw new RuntimeException('R22 mode refused.'); }

// Conditional declarations execute only after the copied MU has returned.
// Unconditional PHP function declarations would register at compilation even
// before that return and collide with the later original eval-file include.
if (isset($args)&&count($args)===3) {
function cetech_r22_rows(string $sql):array { global $wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);if (!is_array($rows)||''!==$wpdb->last_error)throw new RuntimeException('R22 physical read failed.');return $rows; }
function cetech_r22_hash(array $value):string { return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function cetech_r22_protected():array {
    global $wpdb;$out=[];
    foreach(DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix) {
        if(in_array($suffix,['operation_records','operation_changes','rule_family_guards','logical_rules','rule_versions'],true))continue;
        $out[$suffix]=cetech_r22_hash(cetech_r22_rows('SELECT * FROM `'.TableNames::for($suffix).'` ORDER BY id'));
    }
    foreach(['posts','postmeta','wc_orders','wc_orders_meta','wc_order_addresses','wc_order_operational_data','woocommerce_order_items','woocommerce_order_itemmeta']as$suffix) {
        $table=$wpdb->prefix.$suffix;$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        if($exists===$table)$out['native.'.$suffix]=cetech_r22_hash(cetech_r22_rows('SELECT * FROM `'.$table.'` ORDER BY 1'));
    }
    // Return Policy remains a separate aggregate and journal, even when no rows
    // currently exist. These are preservation observations, never Refund grants.
    $out['return_policy']=cetech_r22_return_policy();
    $out['adoption']=cetech_r22_rows($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name IN (%s,%s) ORDER BY option_name','cetech_de_quote_placement_adoption','cetech_de_service_promise_adoption'));
    return $out;
}
function cetech_r22_return_policy():array {
    global $wpdb;
    $guards=TableNames::for('rule_family_guards');$logicals=TableNames::for('logical_rules');$versions=TableNames::for('rule_versions');
    $family=\CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent::FAMILY;
    return [
        'guards'=>cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.$guards.'` WHERE family_code=%s ORDER BY id',$family)),
        'logicals'=>cetech_r22_rows($wpdb->prepare('SELECT l.* FROM `'.$logicals.'` l INNER JOIN `'.$guards.'` g ON g.id=l.family_guard_id WHERE g.family_code=%s ORDER BY l.id',$family)),
        'versions'=>cetech_r22_rows($wpdb->prepare('SELECT v.* FROM `'.$versions.'` v INNER JOIN `'.$logicals.'` l ON l.id=v.logical_rule_id INNER JOIN `'.$guards.'` g ON g.id=l.family_guard_id WHERE g.family_code=%s ORDER BY v.id',$family)),
        'journals'=>cetech_r22_rows($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name LIKE %s ORDER BY option_name',$wpdb->esc_like('cetech_de_return_intent_v1_').'%')),
    ];
}
function cetech_r22_original():array { $out=[];foreach(['operation_records','operation_changes','rule_family_guards','logical_rules','rule_versions']as$suffix)$out[$suffix]=cetech_r22_rows('SELECT * FROM `'.TableNames::for($suffix).'` ORDER BY id');return $out; }
function cetech_r22_adoption_off(array $rows):bool {
    $values=array_column($rows,'option_value','option_name');
    return false===CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementActivation::decode($values['cetech_de_quote_placement_adoption']??null)['enabled']
        &&false===CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation::decode($values['cetech_de_service_promise_adoption']??null)['enabled'];
}
function cetech_r22_journal(array $state,?string &$bytes=null):?array {
    $bytes=null;
    global $wpdb;$name=WpdbRefundPolicyAdminIntent::name($state['site_id'],$state['admin']['id']);
    $rows=cetech_r22_rows($wpdb->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s',$name));
    if(count($rows)>1)throw new RuntimeException('R22 journal ambiguous.');
    if(!$rows)return null;$bytes=$rows[0]['option_value'];$record=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($record)||($record['site_id']??null)!==$state['site_id']||($record['owner_id']??null)!==$state['admin']['id'])throw new RuntimeException('R22 journal owner differs.');
    return $record;
}
function cetech_r22_register(array &$state):array {
    $record=cetech_r22_journal($state);
    if(null===$record||($record['state']??null)!=='review'||!is_array($record['opened']['base_payload']??null))throw new RuntimeException('R22 requires original server review before dispatch.');
    $payload=$record['opened']['base_payload'];$uuid=$payload['logical_uuid']??null;
    if(!is_string($uuid)||1!==preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',$uuid)||($payload['scope']??null)!==['policy_uuid'=>$uuid]||($payload['family']??null)!==RefundPolicyContent::FAMILY)throw new RuntimeException('R22 original review scope refused.');
    $profile=new RefundPolicyFamily(static fn():bool=>false,static fn():bool=>false,static fn():bool=>false);
    $target=RuleLifecycleCommand::make_target_key(RefundPolicyContent::FAMILY,RuleContent::scope_hash($profile,$payload['scope']),$uuid);
    $state['owned'][$uuid]=['target_hash'=>hash('sha256','cetech-operation-target-v1:'.$target)];
    opening_http_write_json($state['state_path'],$state,true);
    return ['ready'=>true,'policy_uuid'=>$uuid,'version_uuid'=>$payload['version_uuid'],'ticket'=>$record['ticket']];
}
function cetech_r22_inspect(array $state):array {
    global $wpdb;$rows=[];$logicals=[];
    $guards=cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND family_code=%s ORDER BY id',$state['site_id'],RefundPolicyContent::FAMILY));
    if(count($guards)>1)throw new RuntimeException('R22 family ambiguous.');
    if($guards)$logicals=cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('logical_rules').'` WHERE site_id=%d AND family_guard_id=%d ORDER BY id',$state['site_id'],(int)$guards[0]['id']));
    foreach($logicals as$logical) {
        if(!isset($state['owned'][$logical['logical_uuid']])||json_decode($logical['scope_json'],true)!==['policy_uuid'=>$logical['logical_uuid']])throw new RuntimeException('R22 refuses an undeclared policy.');
        foreach(cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_versions').'` WHERE site_id=%d AND logical_rule_id=%d ORDER BY id',$state['site_id'],(int)$logical['id']))as$row) {
            if((int)$row['author_user_id']!==$state['admin']['id'])throw new RuntimeException('R22 foreign version author.');
            $fields=RefundPolicyContent::from_rule_payload(json_decode($row['payload_json'],true,32,JSON_THROW_ON_ERROR))->facts();
            $rows[]=['policy_uuid'=>$logical['logical_uuid'],'version_uuid'=>$row['version_uuid'],'state'=>$row['state'],'author'=>(int)$row['author_user_id'],'reason'=>$row['change_reason'],'fields'=>$fields,'body_sha256'=>hash('sha256',$row['payload_json'])];
        }
    }
    $operations=[];foreach($state['owned']as$owned)foreach(cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND target_hash=%s ORDER BY id',$state['site_id'],$owned['target_hash']))as$row) {
        if(1!==(int)$row['operation_version']||!in_array($row['operation'],['rule.draft.create','rule.draft.edit','rule.publish','rule.retire'],true))throw new RuntimeException('R22 foreign operation.');$operations[]=$row;
    }
    $journal_bytes=null;$record=cetech_r22_journal($state,$journal_bytes);
    $protected=cetech_r22_protected();
    return ['versions'=>$rows,'operation_count'=>count($operations),'operations_sha256'=>cetech_r22_hash($operations),'journal_state'=>$record['state']??null,'journal_sha256'=>null===$journal_bytes?null:hash('sha256',$journal_bytes),'protected_unchanged'=>$state['protected']===$protected,'return_policy_unchanged'=>$state['protected']['return_policy']===$protected['return_policy'],'adoption_unchanged'=>$state['protected']['adoption']===$protected['adoption'],'adoption_off'=>cetech_r22_adoption_off($protected['adoption']),'schema'=>(string)get_option('cetech_de_db_version')];
}
}

if('prepare'===$mode) {
    if(file_exists($state_path)||is_link($state_path))throw new RuntimeException('R22 existing private allocation refused.');
    $identity=opening_http_identity();global $wpdb;
    if(cetech_r22_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND family_code=%s',get_current_blog_id(),RefundPolicyContent::FAMILY)))throw new RuntimeException('R22 requires unused disposable Refund family.');
    $state=['state_path'=>$state_path,'site_path'=>realpath(ABSPATH),'database_name'=>DB_NAME,'site_id'=>get_current_blog_id(),'base_url'=>'http://127.0.0.1:8085','probe_token'=>getenv('CETECH_DE_HTTP_PROBE_TOKEN'),'identity'=>$identity,'owned'=>[],'protected'=>cetech_r22_protected(),'original'=>cetech_r22_original(),'cleanup_done'=>false];
    if(!cetech_r22_adoption_off($state['protected']['adoption']))throw new RuntimeException('R22 requires existing adoption OFF; it never changes adoption.');
    opening_http_write_json($state_path,$state,true);
    foreach(['admin','denied']as$kind) {
        $login='r22_'.$kind.'_'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));
        $id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','role'=>'admin'===$kind?'administrator':'subscriber']);
        if(!is_int($id)||$id<1)throw new RuntimeException('R22 native actor creation refused.');
        $state[$kind]=['id'=>$id,'login'=>$login,'password'=>$password];opening_http_write_json($state_path,$state,true);
    }
    opening_http_write_json($output_path,['ready'=>true]);return;
}
$state=opening_http_read_state($state_path);
if('cleanup'===$mode) {
    if(true===($state['cleanup_done']??false)){opening_http_write_json($output_path,$state['cleanup']);return;}
    global$wpdb;
    // Deletion authority is the server review target registered BEFORE dispatch,
    // together with the exclusively allocated actor/family, never an appeared-ID diff.
    $inspection=isset($state['admin'])?cetech_r22_inspect($state):null;
    foreach($state['owned']as$uuid=>$owned) {
        foreach(cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND target_hash=%s ORDER BY id',$state['site_id'],$owned['target_hash']))as$row) {
            if(1!==(int)$row['operation_version']||!in_array($row['operation'],['rule.draft.create','rule.draft.edit','rule.publish','rule.retire'],true))throw new RuntimeException('R22 cleanup operation refused.');
            if(false===$wpdb->delete(TableNames::for('operation_changes'),['site_id'=>$state['site_id'],'operation_id'=>(int)$row['id']])||false===$wpdb->delete(TableNames::for('operation_records'),['site_id'=>$state['site_id'],'id'=>(int)$row['id']]))throw new RuntimeException('R22 cleanup operation unacknowledged.');
        }
        foreach(cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('logical_rules').'` WHERE site_id=%d AND logical_uuid=%s',$state['site_id'],$uuid))as$logical) {
            if(false===$wpdb->delete(TableNames::for('rule_versions'),['site_id'=>$state['site_id'],'logical_rule_id'=>(int)$logical['id']])||false===$wpdb->delete(TableNames::for('logical_rules'),['site_id'=>$state['site_id'],'id'=>(int)$logical['id']]))throw new RuntimeException('R22 cleanup source unacknowledged.');
        }
    }
    foreach(cetech_r22_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND family_code=%s',$state['site_id'],RefundPolicyContent::FAMILY))as$guard) {
        if(cetech_r22_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('logical_rules').'` WHERE family_guard_id=%d',(int)$guard['id']))||false===$wpdb->delete(TableNames::for('rule_family_guards'),['site_id'=>$state['site_id'],'id'=>(int)$guard['id']]))throw new RuntimeException('R22 cleanup family refused.');
    }
    if(isset($state['admin'])) {
        $name=WpdbRefundPolicyAdminIntent::name($state['site_id'],$state['admin']['id']);$record=cetech_r22_journal($state);
        if(null!==$record){$bytes=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s',$name));if(false===$wpdb->query($wpdb->prepare('DELETE FROM `'.$wpdb->options.'` WHERE option_name=%s AND BINARY option_value=BINARY %s',$name,$bytes)))throw new RuntimeException('R22 cleanup journal unacknowledged.');}
        if(cetech_r22_journal($state)!==null)throw new RuntimeException('R22 cleanup journal remains.');
    }
    $users=true;if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(['admin','denied']as$kind)if(isset($state[$kind])){$owned=$state[$kind];$user=get_userdata($owned['id']);if(!$user||$user->user_login!==$owned['login']||!str_starts_with($owned['login'],'r22_'.$kind.'_'))throw new RuntimeException('R22 cleanup actor differs.');$users=wp_delete_user($owned['id'])&&false===get_userdata($owned['id'])&&$users;}
    $restored=$state['original']===cetech_r22_original();$protected=$state['protected']===cetech_r22_protected();
    $state['cleanup']=['original_c01_rows_restored'=>$restored,'protected_domain_and_native_rows_preserved'=>$protected,'owned_users_removed'=>$users,'owned_journal_removed'=>true];$state['cleanup_done']=true;opening_http_write_json($state_path,$state,true);opening_http_write_json($output_path,$state['cleanup']);return;
}
if(true===($state['cleanup_done']??false))throw new RuntimeException('R22 fixture already closed.');
if('register'===$mode){opening_http_write_json($output_path,cetech_r22_register($state));return;}
if('revoke'===$mode){$user=get_userdata($state['admin']['id']);if(!$user||$user->user_login!==$state['admin']['login'])throw new RuntimeException('R22 revoke owner differs.');$user->add_cap('manage_site_wide_defaults',false);clean_user_cache($user->ID);opening_http_write_json($output_path,['revoked'=>!user_can($user,'manage_site_wide_defaults')]);return;}
opening_http_write_json($output_path,cetech_r22_inspect($state));
