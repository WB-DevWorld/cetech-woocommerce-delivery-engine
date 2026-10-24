<?php
/** R23 owned policy-assignment fixture. Native production actions are never replaced. */
declare(strict_types=1);

use CetechDeliveryEngine\Application\PolicyAssignment\{PolicyAssignmentCurrentService,PolicyCurrentCatalog};
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Bootstrap\{DataLifecycleManifest,Plugin};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity,RequestContext};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand,PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent,RuleLifecycleCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore,TableNames,WpdbDeliveryOfferRepository,WpdbPolicyAssignmentAdminIntent};
use CetechDeliveryEngine\Integrations\PolicyAssignment\NativePolicyAssignmentAuthority;

if ('1'!==getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION')||'1'!==getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION')) { return; }
if (defined('WP_CLI')&&WP_CLI&&'cetech-policy-assignment-admin-fixture.php'===basename(__FILE__)) {
    add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);return;
}
if (!(defined('WP_CLI')&&WP_CLI)) {
    $site=getenv('CETECH_DE_HTTP_FIXTURE_SITE');
    if (!is_string($site)||!defined('ABSPATH')||realpath($site)!==realpath(ABSPATH)||!defined('DB_HOST')||!preg_match('/\A127\.0\.0\.1(?::[0-9]+)?\z/D',DB_HOST)||!defined('DB_NAME')||!preg_match('/\Acetech_wp_opening_qualification_[a-z0-9]+\z/D',DB_NAME)) { throw new RuntimeException('R23 unowned listener refused.'); }
    if ('1'!==(string)get_option('cetech_opening_qualification_disposable')||!defined('DISABLE_WP_CRON')||!DISABLE_WP_CRON||'http://127.0.0.1:8085'!==get_option('home')||'http://127.0.0.1:8085'!==get_option('siteurl')) { throw new RuntimeException('R23 listener marker refused.'); }
    add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);
    if (isset($_GET['cetech_policy_assignment_probe'])) {
        $token=getenv('CETECH_DE_HTTP_PROBE_TOKEN');$received=$_SERVER['HTTP_X_CETECH_OPENING_PROBE']??null;
        if ('1'!==$_GET['cetech_policy_assignment_probe']||!is_string($token)||!preg_match('/\A[a-f0-9]{48}\z/D',$token)||!is_string($received)||!hash_equals($token,$received)) { http_response_code(403);exit; }
        header('Content-Type: application/json');header('Cache-Control: no-store');
        echo json_encode(['format'=>'cetech-r23-owned-listener-v1','probe_sha256'=>hash('sha256',$token),'site_path_sha256'=>hash('sha256',realpath(ABSPATH)),'database_name_sha256'=>hash('sha256',DB_NAME),'source_head'=>getenv('CETECH_DE_QUALIFICATION_HEAD'),'candidate_head'=>getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'),'source_tree'=>getenv('CETECH_DE_QUALIFICATION_TREE'),'php'=>PHP_VERSION,'sapi'=>PHP_SAPI],JSON_THROW_ON_ERROR);exit;
    }
    return;
}
if (!isset($args)||count($args)!==3) { throw new RuntimeException('R23 expects MODE PRIVATE_STATE OUTPUT.'); }
require_once __DIR__.'/opening-http-fixture-common.php';
opening_http_fixture_guard();[$mode,$state_path,$output_path]=$args;
$modes=['prepare','inspect','replace','revoke','cleanup','capture-global-a','capture-global-b','capture-product-a','capture-product-b','capture-variation-a','capture-variation-b'];
if (!in_array($mode,$modes,true)) { throw new RuntimeException('R23 mode refused.'); }

// Runtime declarations are below the copied-MU return guard: CLI bridges may
// include both the exact copied MU and this original without redeclaration.
if (isset($args)&&count($args)===3) {
function cetech_r23_rows(string $sql):array { global $wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||''!==$wpdb->last_error||count($rows)>100000)throw new RuntimeException('R23 physical read refused.');return $rows; }
function cetech_r23_hash(array $value):string { return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function cetech_r23_save(array $state):void { opening_http_write_json($state['state_path'],$state,true); }
function cetech_r23_c01():array { $out=[];foreach(['operation_records','operation_changes','rule_family_guards','logical_rules','rule_versions']as$suffix)$out[$suffix]=cetech_r23_rows('SELECT * FROM `'.TableNames::for($suffix).'` ORDER BY id');return $out; }
function cetech_r23_ids(array $state,string $kind):array { $ids=[];foreach($state[$kind]??[]as$row)if(($row['id']??0)>0)$ids[]=(int)$row['id'];return $ids; }
function cetech_r23_not_in(array $ids,string $column):string { return $ids?' WHERE '.$column.' NOT IN ('.implode(',',array_map('intval',$ids)).')':''; }
function cetech_r23_configuration():array { global $wpdb;$out=[];foreach(['configuration_scopes','configuration_fields','configuration_collections']as$suffix)$out[$suffix]=cetech_r23_rows('SELECT * FROM `'.TableNames::for($suffix).'` ORDER BY id');$out['native_defaults_options']=cetech_r23_rows($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name IN (%s,%s) ORDER BY option_name','cetech_de_sitewide_defaults','cetech_de_global_configuration_version'));return $out; }
function cetech_r23_protected(array $state):array {
    global $wpdb;$out=[];$offers=cetech_r23_ids($state,'offers');$products=cetech_r23_ids($state,'products');$users=cetech_r23_ids($state,'actors');
    foreach(DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as$suffix) {
        if(in_array($suffix,['operation_records','operation_changes','rule_family_guards','logical_rules','rule_versions'],true))continue;
        $where='delivery_offers'===$suffix?cetech_r23_not_in($offers,'id'):'';
        $out[$suffix]=cetech_r23_hash(cetech_r23_rows('SELECT * FROM `'.TableNames::for($suffix).'`'.$where.' ORDER BY id'));
    }
    foreach(['posts'=>'ID','postmeta'=>'post_id','wc_product_meta_lookup'=>'product_id','users'=>'ID','usermeta'=>'user_id','wc_orders'=>'id','wc_orders_meta'=>'order_id','wc_order_addresses'=>'order_id','wc_order_operational_data'=>'order_id','woocommerce_order_items'=>'order_id','woocommerce_order_itemmeta'=>'order_item_id']as$suffix=>$column) {
        $table=$wpdb->prefix.$suffix;$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));if($exists!==$table)continue;
        $exclude=in_array($suffix,['posts','postmeta','wc_product_meta_lookup'],true)?$products:(in_array($suffix,['users','usermeta'],true)?$users:[]);
        $out['native.'.$suffix]=cetech_r23_hash(cetech_r23_rows('SELECT * FROM `'.$table.'`'.cetech_r23_not_in($exclude,$column).' ORDER BY 1'));
    }
    $out['adoption']=cetech_r23_rows($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name IN (%s,%s) ORDER BY option_name','cetech_de_quote_placement_adoption','cetech_de_service_promise_adoption'));
    $preserved=[...DataLifecycleManifest::FEATURE_FLAG_OPTIONS,DataLifecycleManifest::CHECKOUT_CONTROL_OPTION];$out['feature_flags_and_control']=cetech_r23_rows($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name IN ('.implode(',',array_fill(0,count($preserved),'%s')).') ORDER BY option_name',...$preserved));
    $out['editor_journals']=cetech_r23_rows($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name',$wpdb->esc_like('cetech_de_return_intent_v1_').'%',$wpdb->esc_like('cetech_de_refund_intent_v1_').'%'));
    return $out;
}
function cetech_r23_adoption_off(array $rows):bool {
    $v=array_column($rows,'option_value','option_name');
    return false===CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementActivation::decode($v['cetech_de_quote_placement_adoption']??null)['enabled']&&false===CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation::decode($v['cetech_de_service_promise_adoption']??null)['enabled'];
}
function cetech_r23_coordinate(array $state,string $kind):array {
    return match($kind){'global'=>PolicyAssignmentState::make_coordinate($state['site_id'],'global',0),'product'=>PolicyAssignmentState::make_coordinate($state['site_id'],'product',$state['products']['parent']['id']),'variation'=>PolicyAssignmentState::make_coordinate($state['site_id'],'variation',$state['products']['variation']['id'],$state['products']['parent']['id']),default=>throw new RuntimeException('R23 coordinate refused.')};
}
function cetech_r23_store(array $state):PolicyAssignmentStore { return new PolicyAssignmentStore($state['site_id'],$state['prefix']); }
function cetech_r23_options(array $state):array {
    global $wpdb;$rows=cetech_r23_rows($wpdb->prepare('SELECT option_id,option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name LIKE %s ORDER BY option_name',$wpdb->esc_like(PolicyAssignmentStore::PREFIX).'%'));$out=[];$store=cetech_r23_store($state);
    foreach($rows as$row){$key=array_search($row['option_name'],$state['option_names'],true);if(false===$key)throw new RuntimeException('R23 undeclared assignment option.');$c=cetech_r23_coordinate($state,$key);$parsed=PolicyAssignmentState::from_physical($c['site_id'],$c['scope_type'],$c['scope_id'],$c['parent_id'],(int)$row['option_id'],$row['option_value']);$out[$key]=['row_id'=>(int)$row['option_id'],'revision'=>$parsed->revision(),'generation'=>$parsed->generation(),'digest'=>$parsed->digest(),'bytes_sha256'=>hash('sha256',$row['option_value']),'maps'=>['return_policy_v1'=>$parsed->map('return_policy_v1')->facts(),'refund_policy_v1'=>$parsed->map('refund_policy_v1')->facts()]];}
    ksort($out,SORT_STRING);return $out;
}
function cetech_r23_assignment_operations(array $state):array {
    global $wpdb;$out=[];foreach($state['targets']as$hash)foreach(cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND target_hash=%s ORDER BY id',$state['site_id'],$hash))as$row){if('policy.assignment.set'!==$row['operation']||1!==(int)$row['operation_version'])throw new RuntimeException('R23 foreign assignment operation.');$out[]=$row;}usort($out,static fn(array$a,array$b):int=>(int)$a['id']<=>(int)$b['id']);return $out;
}
function cetech_r23_journals(array $state):array {
    global $wpdb;$out=[];foreach(cetech_r23_rows($wpdb->prepare('SELECT option_id,option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name LIKE %s ORDER BY option_name',$wpdb->esc_like(WpdbPolicyAssignmentAdminIntent::PREFIX).'%'))as$row){if(!in_array($row['option_name'],$state['journal_names'],true))throw new RuntimeException('R23 foreign journal.');$out[$row['option_name']]=hash('sha256',$row['option_value']);}return $out;
}
function cetech_r23_inspect(array $state):array {
    $protected=cetech_r23_protected($state);$ops=cetech_r23_assignment_operations($state);
    return ['states'=>cetech_r23_options($state),'c01_sha256'=>cetech_r23_hash(cetech_r23_c01()),'operation_count'=>count($ops),'operations_sha256'=>cetech_r23_hash($ops),'journals'=>cetech_r23_journals($state),'protected_unchanged'=>$state['protected']===$protected,'configuration_unchanged'=>$state['configuration']===cetech_r23_configuration(),'adoption_unchanged'=>$state['protected']['adoption']===$protected['adoption'],'adoption_off'=>cetech_r23_adoption_off($protected['adoption']),'schema'=>(string)get_option('cetech_de_db_version')];
}
function cetech_r23_catalog_opened(array $state,array $entry,string $version_uuid):array {
    global $wpdb;$g=cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND family_code=%s',$state['site_id'],$entry['family']))[0]??null;
    $l=cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('logical_rules').'` WHERE site_id=%d AND logical_uuid=%s',$state['site_id'],$entry['policy_uuid']))[0]??null;
    $v=$l?cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_versions').'` WHERE site_id=%d AND logical_rule_id=%d AND version_uuid=%s',$state['site_id'],(int)$l['id'],$version_uuid))[0]??null:null;
    $prior=$l&&!empty($l['current_published_version_id'])?cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_versions').'` WHERE id=%d',(int)$l['current_published_version_id']))[0]??null:null;
    return ['guard'=>$g,'logical'=>$l,'version'=>$v,'prior'=>$prior];
}
function cetech_r23_seed_policy(array &$state,string $key,bool $replace=false):void {
    $entry=$state['catalogs'][$key];$version=$replace?$entry['replacement_uuid']:$entry['version_uuid'];$return='return_policy_v1'===$entry['family'];
    $authority=$return?new CetechDeliveryEngine\Integrations\ReturnPolicy\NativeReturnPolicyAuthority():new CetechDeliveryEngine\Integrations\RefundPolicy\NativeRefundPolicyAuthority();
    $profile=$return?new CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily(static fn():bool=>false,static fn():bool=>false,static fn():bool=>false):new CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily(static fn():bool=>false,static fn():bool=>false,static fn():bool=>false);
    $scope=['policy_uuid'=>$entry['policy_uuid']];$fields=$entry['fields'];if($replace){$fields['customer_title'].=' — replacement';$fields['summary'].="\nA supplied later publication.";}
    $content=$return?CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent::from_array($fields):CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyContent::from_array($fields);
    foreach(['rule.draft.create','rule.publish']as$operation){
        $o=cetech_r23_catalog_opened($state,$entry,$version);$g=$o['guard'];$l=$o['logical'];$v=$o['version'];$prior=$o['prior'];
        $payload=['family'=>$entry['family'],'logical_uuid'=>$entry['policy_uuid'],'version_uuid'=>$version,'scope'=>$scope,'payload'=>$content->to_rule_payload(),'start_mode'=>'immediate','effective_from'=>null,'effective_until'=>null,'priority'=>0,'author_user_id'=>$state['actors']['a']['id'],'change_reason'=>'Owned R23 supplied policy fixture','predecessor_uuid'=>$replace?($prior['version_uuid']??null):null,'preconditions'=>['family_revision'=>(int)($g['revision']??0),'logical_id'=>(int)($l['id']??0),'logical_revision'=>(int)($l['revision']??0),'version_id'=>(int)($v['id']??0),'version_revision'=>(int)($v['row_revision']??0),'predecessor_id'=>$replace?(int)($prior['id']??0):0,'predecessor_revision'=>$replace?(int)($prior['row_revision']??0):0]];
        $target=RuleLifecycleCommand::make_target_key($entry['family'],RuleContent::scope_hash($profile,$scope),$entry['policy_uuid']);$tuple=$authority->identity_data($operation,$target,bin2hex(random_bytes(16)));$identity=$authority->identity($tuple);$grant=$authority->capture($identity,$scope,$state['actors']['a']['id']);
        $state['catalog_operations'][$identity->namespace_digest()]=['operation'=>$operation,'target_hash'=>hash('sha256','cetech-operation-target-v1:'.$target)];cetech_r23_save($state);
        $factory=new CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory();$service=$return?new CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService($grant['family'],$factory,$grant['catalog']):new CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyCatalogService($grant['family'],$factory,$grant['catalog']);
        $result=$service->attempt($identity,$payload,RequestContext::create());if('accepted'!==$result->outcome->state)throw new RuntimeException('R23 owned catalog seed refused.');
    }
}
function cetech_r23_capture(array $state,string $kind,string $offer_key):array {
    wp_set_current_user($state['actors']['a']['id']);$container=Plugin::instance()->container();$authority=$container->get(NativePolicyAssignmentAuthority::class);$c=cetech_r23_coordinate($state,$kind);$offer=$state['offers'][$offer_key]['id'];
    $identity=$authority->identity($authority->identity_data('policy.assignment.read',$c,$offer,bin2hex(random_bytes(16))));$grant=$authority->capture($identity,$c,$offer);$binding=$authority->binding();
    $catalog=new PolicyCurrentCatalog($grant['return_family'],$grant['refund_family'],$identity);$service=new PolicyAssignmentCurrentService(new CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory(),new PolicyAssignmentStore($binding['site_id'],$binding['prefix']),$catalog,$container->get(EffectiveConfigurationResolver::class),$grant['authorize']);
    $capture=$service->capture($identity,$c,$offer);if(!$authority->authorize($identity,$c,$offer))throw new RuntimeException('R23 capture final grant refused.');$out=[];
    foreach($capture['selections']as$family=>$selection){$v=$selection->version();$out[$family]=['disposition'=>$selection->disposition(),'fields'=>null===$v?null:$v->content()->facts(),'reference'=>null===$v?null:$v->reference()->facts(),'fingerprint'=>$selection->fingerprint(),'evaluated_at'=>$selection->evaluated_at()->sql(),'code'=>$selection->code()];}
    return ['selections'=>$out,'configuration_unchanged'=>$state['configuration']===cetech_r23_configuration()];
}
function cetech_r23_delete_records(array $state,string $column,string $value):void {
    global $wpdb;if(!in_array($column,['namespace_hash','target_hash'],true))throw new RuntimeException('R23 delete selector refused.');
    foreach(cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND '.$column.'=%s ORDER BY id',$state['site_id'],$value))as$row){if(1!==(int)$row['operation_version']||$row['operation']!==('target_hash'===$column?'policy.assignment.set':($state['catalog_operations'][$value]['operation']??null))||('namespace_hash'===$column&&$row['target_hash']!==($state['catalog_operations'][$value]['target_hash']??null)))throw new RuntimeException('R23 delete operation refused.');if(false===$wpdb->delete(TableNames::for('operation_changes'),['site_id'=>$state['site_id'],'operation_id'=>(int)$row['id']])||false===$wpdb->delete(TableNames::for('operation_records'),['site_id'=>$state['site_id'],'id'=>(int)$row['id']]))throw new RuntimeException('R23 operation deletion unacknowledged.');}
}
}

if('prepare'===$mode) {
    echo "policy_assignment_admin_fixture_step=guarded\n";
    if(file_exists($state_path)||is_link($state_path))throw new RuntimeException('R23 private allocation exists.');global $wpdb;$identity=opening_http_identity();$token='r23-'.bin2hex(random_bytes(6));
    foreach(['return_policy_v1','refund_policy_v1','policy_assignment_v1']as$family)if(cetech_r23_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND family_code=%s',get_current_blog_id(),$family)))throw new RuntimeException('R23 requires unused owned families.');
    foreach([PolicyAssignmentStore::PREFIX,WpdbPolicyAssignmentAdminIntent::PREFIX]as$prefix)if(cetech_r23_rows($wpdb->prepare('SELECT option_id FROM `'.$wpdb->options.'` WHERE option_name LIKE %s',$wpdb->esc_like($prefix).'%')))throw new RuntimeException('R23 requires unused assignment namespaces.');
    $state=['state_path'=>$state_path,'site_path'=>realpath(ABSPATH),'database_name'=>DB_NAME,'site_id'=>get_current_blog_id(),'prefix'=>$wpdb->prefix,'base_url'=>'http://127.0.0.1:8085','probe_token'=>getenv('CETECH_DE_HTTP_PROBE_TOKEN'),'identity'=>$identity,'token'=>$token,'actors'=>[],'products'=>[],'offers'=>[],'catalogs'=>[],'catalog_operations'=>[],'targets'=>[],'option_names'=>[],'journal_names'=>[],'cleanup_done'=>false];
    $state['protected']=cetech_r23_protected($state);$state['configuration']=cetech_r23_configuration();$state['original_c01']=cetech_r23_c01();if(!cetech_r23_adoption_off($state['protected']['adoption']))throw new RuntimeException('R23 requires existing adoption OFF.');cetech_r23_save($state);
    echo "policy_assignment_admin_fixture_step=actors\n";
    foreach(['a','b','denied']as$kind){$state['actors'][$kind]=['id'=>0,'login'=>$token.'-'.$kind,'password'=>bin2hex(random_bytes(24))];cetech_r23_save($state);$a=$state['actors'][$kind];if(get_user_by('login',$a['login']))throw new RuntimeException('R23 actor allocation not unique.');$id=wp_insert_user(['user_login'=>$a['login'],'user_pass'=>$a['password'],'user_email'=>$a['login'].'@example.invalid','role'=>'denied'===$kind?'subscriber':'administrator']);if(!is_int($id)||$id<1)throw new RuntimeException('R23 actor creation refused.');$state['actors'][$kind]['id']=$id;cetech_r23_save($state);}
    wp_set_current_user($state['actors']['a']['id']);
    echo "policy_assignment_admin_fixture_step=products\n";
    foreach(['parent','variation']as$kind){$state['products'][$kind]=['id'=>0,'slug'=>$token.'-'.$kind];cetech_r23_save($state);$product='parent'===$kind?new WC_Product_Variable():new WC_Product_Variation();$product->set_name('R23 owned '.$kind);$product->set_slug($state['products'][$kind]['slug']);$product->set_status('draft');if('variation'===$kind){$product->set_parent_id($state['products']['parent']['id']);$product->set_regular_price('1');}$id=$product->save();if(!is_int($id)||$id<1||get_post($id)->post_name!==$state['products'][$kind]['slug'])throw new RuntimeException('R23 product creation refused.');$state['products'][$kind]['id']=$id;cetech_r23_save($state);}
    echo "policy_assignment_admin_fixture_step=offers\n";
    $repository=new WpdbDeliveryOfferRepository();foreach(['a','b']as$kind){$state['offers'][$kind]=['id'=>0,'code'=>$token.'-offer-'.$kind,'label'=>'R23 owned offer '.strtoupper($kind)];cetech_r23_save($state);$e=$state['offers'][$kind];if(null!==$repository->findByCode($e['code']))throw new RuntimeException('R23 offer allocation not unique.');$id=$repository->save(['internal_code'=>$e['code'],'internal_name'=>$e['label'],'public_label'=>$e['label'],'route'=>'local_delivery','service_level'=>'R23 fixture','status'=>'active']);if($id<1)throw new RuntimeException('R23 offer creation refused.');$state['offers'][$kind]['id']=$id;cetech_r23_save($state);}
    echo "policy_assignment_admin_fixture_step=targets\n";
    $store=cetech_r23_store($state);foreach(['global','product','variation']as$kind){$c=cetech_r23_coordinate($state,$kind);$state['option_names'][$kind]=$store->name($c);foreach([0,$state['offers']['a']['id'],$state['offers']['b']['id']]as$offer){$target=PolicyAssignmentCommand::make_target($c['scope_type'],$c['scope_id'],$offer);$hash=hash('sha256','cetech-operation-target-v1:'.$target);if(cetech_r23_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND target_hash=%s',$state['site_id'],$hash)))throw new RuntimeException('R23 assignment target is already used.');$state['targets'][]=$hash;}}
    foreach($state['actors']as$a)$state['journal_names'][]=WpdbPolicyAssignmentAdminIntent::name($state['site_id'],$a['id']);
    $declared=[];foreach(['return_a','return_b','refund_a','refund_b']as$key){
        $family=str_starts_with($key,'return')?'return_policy_v1':'refund_policy_v1';$label='R23 '.str_replace('_',' ',$key);$ids=[wp_generate_uuid4(),wp_generate_uuid4(),wp_generate_uuid4()];
        if(count(array_unique([...$declared,...$ids]))!==count($declared)+3)throw new RuntimeException('R23 catalog UUID allocation is not unique.');
        foreach($ids as$uuid){if(cetech_r23_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('logical_rules').'` WHERE logical_uuid=%s',$uuid))||cetech_r23_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('rule_versions').'` WHERE version_uuid=%s',$uuid)))throw new RuntimeException('R23 catalog UUID already exists.');}
        $declared=[...$declared,...$ids];$state['catalogs'][$key]=['family'=>$family,'policy_uuid'=>$ids[0],'version_uuid'=>$ids[1],'replacement_uuid'=>$ids[2],'fields'=>['name'=>$label,'customer_title'=>$label.' title','summary'=>$label." supplied summary.\nUnicode café.",'full_text'=>$label.' supplied <em>literal</em> full text.','exceptions'=>$label.' supplied exception.']];cetech_r23_save($state);
    }
    foreach(array_keys($state['catalogs'])as$key){echo 'policy_assignment_admin_fixture_step=seed_'.$key."\n";cetech_r23_seed_policy($state,$key);}
    if($state['configuration']!==cetech_r23_configuration())throw new RuntimeException('R23 setup altered configuration.');
    echo "policy_assignment_admin_fixture_step=ready\n";
    opening_http_write_json($output_path,['ready'=>true]);return;
}
$state=opening_http_read_state($state_path);
global $wpdb;if(($wpdb->prefix??null)!==($state['prefix']??null)||($wpdb->options??null)!==$state['prefix'].'options'||DB_NAME!==$state['database_name']||realpath(ABSPATH)!==$state['site_path'])throw new RuntimeException('R23 captured fixture route differs.');
if('cleanup'===$mode) {
    if(true===($state['cleanup_done']??false)){opening_http_write_json($output_path,$state['cleanup']);return;}global $wpdb;
    // Predeclared exact keys/targets, never an appeared-ID difference or wildcard deletion.
    if($state['option_names']){cetech_r23_options($state);foreach($state['option_names']as$name)foreach(cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.$wpdb->options.'` WHERE option_name=%s',$name))as$row)if(1!==$wpdb->query($wpdb->prepare('DELETE FROM `'.$wpdb->options.'` WHERE option_id=%d AND BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s',(int)$row['option_id'],$name,$row['option_value'])))throw new RuntimeException('R23 assignment removal unacknowledged.');}
    foreach($state['journal_names']as$name)foreach(cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.$wpdb->options.'` WHERE option_name=%s',$name))as$row)if(1!==$wpdb->query($wpdb->prepare('DELETE FROM `'.$wpdb->options.'` WHERE option_id=%d AND BINARY option_name=BINARY %s AND BINARY option_value=BINARY %s',(int)$row['option_id'],$name,$row['option_value'])))throw new RuntimeException('R23 journal removal unacknowledged.');
    foreach($state['targets']as$hash)cetech_r23_delete_records($state,'target_hash',$hash);foreach($state['catalog_operations']as$namespace=>$ignored)cetech_r23_delete_records($state,'namespace_hash',$namespace);
    foreach($state['catalogs']as$entry)foreach(cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('logical_rules').'` WHERE site_id=%d AND logical_uuid=%s',$state['site_id'],$entry['policy_uuid']))as$l){
        $is_return='return_policy_v1'===$entry['family'];$profile=$is_return?new CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily(static fn():bool=>false,static fn():bool=>false,static fn():bool=>false):new CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily(static fn():bool=>false,static fn():bool=>false,static fn():bool=>false);
        $g=cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND id=%d',$state['site_id'],(int)$l['family_guard_id']))[0]??throw new RuntimeException('R23 catalog guard absent.');
        $guard=CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard::from_row($g,$profile);$logical=CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule::from_row($l,$guard,$profile);
        if($logical->site_id!==$state['site_id']||$logical->family_code!==$entry['family']||$logical->scope()!==['policy_uuid'=>$entry['policy_uuid']])throw new RuntimeException('R23 catalog removal scope refused.');
        $versions=cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_versions').'` WHERE site_id=%d AND logical_rule_id=%d ORDER BY id',$state['site_id'],(int)$l['id']));
        foreach($versions as$v){if(!in_array($v['version_uuid'],[$entry['version_uuid'],$entry['replacement_uuid']],true)||(int)$v['author_user_id']!==$state['actors']['a']['id'])throw new RuntimeException('R23 undeclared catalog version removal refused.');
            $version=CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion::from_row($v,$logical,$profile);$candidate=new CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate($logical,$version);$typed=$is_return?CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyVersion::from_candidate($candidate,$profile):CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyVersion::from_candidate($candidate,$profile);
            $expected=$entry['fields'];if($v['version_uuid']===$entry['replacement_uuid']){$expected['customer_title'].=' — replacement';$expected['summary'].="\nA supplied later publication.";}if($typed->content()->facts()!==$expected)throw new RuntimeException('R23 catalog original content removal refused.');
        }
        foreach($versions as$v)if(1!==$wpdb->delete(TableNames::for('rule_versions'),['site_id'=>$state['site_id'],'id'=>(int)$v['id'],'logical_rule_id'=>(int)$l['id'],'version_uuid'=>$v['version_uuid']]))throw new RuntimeException('R23 version removal unacknowledged.');
        if(cetech_r23_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('rule_versions').'` WHERE logical_rule_id=%d',(int)$l['id']))||1!==$wpdb->delete(TableNames::for('logical_rules'),['site_id'=>$state['site_id'],'id'=>(int)$l['id'],'logical_uuid'=>$entry['policy_uuid']]))throw new RuntimeException('R23 logical removal unacknowledged.');
    }
    foreach(['return_policy_v1','refund_policy_v1','policy_assignment_v1']as$family)foreach(cetech_r23_rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('rule_family_guards').'` WHERE site_id=%d AND family_code=%s',$state['site_id'],$family))as$g){if(1!==(int)$g['family_format']||$g['policy_hash']!==match($family){'return_policy_v1'=>CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily::definition_hash(),'refund_policy_v1'=>CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily::definition_hash(),'policy_assignment_v1'=>PolicyAssignmentStore::guard_hash()}||cetech_r23_rows($wpdb->prepare('SELECT id FROM `'.TableNames::for('logical_rules').'` WHERE family_guard_id=%d',(int)$g['id']))||false===$wpdb->delete(TableNames::for('rule_family_guards'),['site_id'=>$state['site_id'],'id'=>(int)$g['id']]))throw new RuntimeException('R23 guard removal refused.');}
    wp_set_current_user($state['actors']['a']['id']??1);$products=true;foreach(array_reverse($state['products'],true)as$kind=>$p){$post=$p['id']?get_post($p['id']):get_page_by_path($p['slug'],OBJECT,'variation'===$kind?'product_variation':'product');if(!$post)continue;if($post->post_name!==$p['slug']||(int)$post->post_author!==($state['actors']['a']['id']??0))throw new RuntimeException('R23 product removal ownership refused.');$product=wc_get_product($post->ID);if(!$product instanceof WC_Product)throw new RuntimeException('R23 product removal type refused.');$product->delete(true);$products=null===get_post($post->ID)&&$products;}
    $offers=true;$repo=new WpdbDeliveryOfferRepository();foreach($state['offers']as$e){$row=$e['id']?$repo->findById($e['id']):$repo->findByCode($e['code']);if(null===$row)continue;if($row['internal_code']!==$e['code']||$row['public_label']!==$e['label'])throw new RuntimeException('R23 offer removal ownership refused.');$offers=$repo->hardDelete((int)$row['id'])&&null===$repo->findByCode($e['code'])&&$offers;}
    wp_set_current_user(1);if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';$users=true;foreach($state['actors']as$a){$u=$a['id']?get_userdata($a['id']):get_user_by('login',$a['login']);if(!$u)continue;if($u->user_login!==$a['login'])throw new RuntimeException('R23 actor removal ownership refused.');$users=wp_delete_user($u->ID)&&false===get_userdata($u->ID)&&$users;}
    $state['cleanup']=['original_c01_rows_restored'=>$state['original_c01']===cetech_r23_c01(),'original_delivery_configuration_preserved'=>$state['configuration']===cetech_r23_configuration(),'protected_domain_and_native_rows_preserved'=>$state['protected']===cetech_r23_protected($state),'owned_native_products_and_offers_removed'=>$products&&$offers,'owned_assignment_options_removed'=>[]===cetech_r23_options($state),'owned_users_removed'=>$users,'owned_journal_removed'=>[]===cetech_r23_journals($state)];$state['cleanup_done']=true;cetech_r23_save($state);opening_http_write_json($output_path,$state['cleanup']);return;
}
if(true===($state['cleanup_done']??false))throw new RuntimeException('R23 fixture is closed.');
if('replace'===$mode){wp_set_current_user($state['actors']['a']['id']);cetech_r23_seed_policy($state,'return_a',true);opening_http_write_json($output_path,['replaced'=>true,'policy_uuid'=>$state['catalogs']['return_a']['policy_uuid'],'version_uuid'=>$state['catalogs']['return_a']['replacement_uuid']]);return;}
if('revoke'===$mode){$u=get_userdata($state['actors']['a']['id']);if(!$u||$u->user_login!==$state['actors']['a']['login'])throw new RuntimeException('R23 revoke owner differs.');$u->add_cap('manage_site_wide_defaults',false);clean_user_cache($u->ID);opening_http_write_json($output_path,['revoked'=>!user_can($u,'manage_site_wide_defaults')]);return;}
if(str_starts_with($mode,'capture-')){[, $kind,$offer]=explode('-',$mode);opening_http_write_json($output_path,cetech_r23_capture($state,$kind,$offer));return;}
opening_http_write_json($output_path,cetech_r23_inspect($state));
