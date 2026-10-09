<?php
/** Private tracked administrative fixture only; production owns each actual page POST and view. */
declare(strict_types=1);
$p05_args=$args??null;unset($args);require_once __DIR__.'/opening-http-promise-handoff-support.php';if(is_array($p05_args))$args=$p05_args;unset($p05_args);
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity,RequestContext};
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion,PromiseJson};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseSiteBinding,PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Integrations\ServicePromise\Configuration\NativePromiseConfigurationAuthority;

final class CetechPromiseNativeConfigurationHttpFixture {
 public static function register():void {
  add_action('template_redirect',static function():void {
   if(!isset($_GET['cetech_p05_fixture']))return;$state=cetech_q05_state();CetechQuotePlacementHttpFixture::guard($state);$token=$_SERVER['HTTP_X_CETECH_P05_FIXTURE']??null;
   if(!CetechQuotePlacementHttpFixture::principal($state)||true!==($state['p05']['active']??false)||!is_string($token)||!hash_equals($state['fixture_token'],$token))wp_send_json_error(['code'=>'fixture_forbidden'],403);
   if($_GET['cetech_p05_fixture']!=='inspect')wp_send_json_error(['code'=>'fixture_mode'],400);if(!WC()->cart||!WC()->session)wc_load_cart();WC()->cart->get_cart();WC()->session->set_customer_session_cookie(true);
   $out=CetechPromiseHandoffHttpFixture::inspect($state);foreach($out['orders']as$id=>&$facts){$order=wc_get_order((int)$id);$read=(new CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader())->read_package($order);$packet=$read->delivery_quote?->envelope?->promise_packet();$facts['original_public_promise']=null===$packet?['contract_version'=>1,'original'=>true,'groups'=>[]]:CetechDeliveryEngine\Application\ServicePromise\Presentation\PromisePublicProjection::quote($packet);}unset($facts);
   wp_send_json_success($out);
  },-118);
 }
 public static function rows(string $sql):array {return CetechPromiseHandoffHttpFixture::rows($sql);}
 public static function protected_source_rows(array $state):array {
  global $wpdb;$companion=$state['p04']['token']??null;if(!is_string($companion)||!preg_match('/^p04-http-[a-f0-9]{12}$/D',$companion)||!is_array($state['p04']['binding']??null))throw new RuntimeException('P05 companion source binding refused.');$binding=PromiseSiteBinding::from_array($state['p04']['binding']);if($binding->site_id()!==$state['site_id']||$binding->site_id()!==get_current_blog_id()||!hash_equals($companion,$binding->site_key()))throw new RuntimeException('P05 companion native site binding differs.');
  // The separately tracked P04 fixture deliberately publishes a newer policy in the stale-review case.
  // Its own final cleanup verifies complete prior history; this snapshot protects every other binding.
  $out=[];foreach(['promise_objects','promise_versions','promise_assignments']as$suffix)$out[$suffix]=self::rows($wpdb->prepare('SELECT * FROM `'.TableNames::for($suffix).'` WHERE NOT (site_id=%d AND site_key=%s) ORDER BY id',$state['site_id'],$companion));return $out;
 }
 public static function source_rows(array $state):array {global $wpdb;$out=[];foreach(['promise_objects','promise_versions','promise_assignments']as$suffix)$out[$suffix]=self::rows($wpdb->prepare('SELECT * FROM `'.TableNames::for($suffix).'` WHERE site_id=%d AND site_key=%s ORDER BY id',$state['site_id'],$state['p05']['token']));return $out;}
 public static function register_sources(array &$state):void {
  foreach(self::source_rows($state)as$suffix=>$rows)foreach($rows as$row){if(isset($row['logical_id'])&&$row['logical_id']!==$state['p05']['token'].'-calendar'||'promise_versions'===$suffix&&!isset($state['p05']['uuids'][$row['version_uuid']]))throw new RuntimeException('P05 refuses undeclared HTTP source.');$state['p05']['registered'][$suffix][(string)$row['id']]=$row;}cetech_q05_write($state);
 }
 public static function inspect(array &$state):array {self::register_sources($state);$rows=self::source_rows($state);$versions=$rows['promise_versions'];return ['ready'=>true,'identity'=>$state['identity'],'counts'=>array_map('count',$rows),'version_states'=>array_column($versions,'state'),'version_bodies'=>array_map(static fn(array $row):string=>hash('sha256',$row['body_json']),$versions),'server_authors'=>array_map(static fn(array $row):int=>(int)$row['author_user_id'],$versions),'configuration_token'=>$state['p05']['token']];}
 public static function payload(array &$state,string $action):array {
  global $wpdb;$kind='calendar';$logical=$state['p05']['token'].'-calendar';$binding=PromiseSiteBinding::bind($state['site_id'],$state['p05']['token']);
  $facts=CetechNativePromiseBodies::calendar($binding->site_key())->private_facts();$facts['calendar_id']=$logical;$body=BusinessCalendarVersion::from_array($facts);
  $objects=self::rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('promise_objects').'` WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s',$state['site_id'],$binding->site_key(),$kind,$logical));$versions=self::rows($wpdb->prepare('SELECT * FROM `'.TableNames::for('promise_versions').'` WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s AND domain_version=1',$state['site_id'],$binding->site_key(),$kind,$logical));if(count($objects)>1||count($versions)>1)throw new RuntimeException('P05 ambiguous private command source.');$object=$objects[0]??null;$version=$versions[0]??null;
  $uuid=$version['version_uuid']??RequestContext::create()->request_id;$token=RequestContext::create()->request_id;$operation='promise.version.'.$action;
  $identity=new OperationIdentity($state['site_id'],NativePromiseConfigurationAuthority::AUTHORITY,'user:'.$state['p05']['admin']['id'],$operation,1,PromiseVersionCommand::target_key($binding,$kind,$logical),$token);
  $state['p05']['namespaces'][$identity->namespace_digest()]=$identity->operation;$state['p05']['uuids'][$uuid]=true;cetech_q05_write($state);
  $command=['kind'=>$kind,'logical_id'=>$logical,'domain_version'=>1,'version_uuid'=>$uuid,'body_digest'=>$body->digest(),'scope'=>['kind'=>'global','target_id'=>0],'body_json'=>'create'===$action?$body->to_private_json():null,'declared_from'=>'1970-01-01 00:00:00.000000','declared_until'=>null,'reason'=>'Tracked P05 authenticated administration','scheduled_author_user_id'=>null,'preconditions'=>['object_revision'=>(int)($object['revision']??0),'version_revision'=>(int)($version['row_revision']??0),'published_version_id'=>(int)($object['published_version_id']??0)]];
  return ['site_key'=>$binding->site_key(),'kind'=>$kind,'logical_id'=>$logical,'domain_version'=>'1','scope_kind'=>'global','scope_id'=>'0','body_json'=>$body->to_private_json(),'declared_from'=>'1970-01-01 00:00:00.000000','declared_until'=>'','object_revision'=>(string)($object['revision']??0),'published_version_id'=>(string)($object['published_version_id']??0),'reason'=>$command['reason'],'version_uuid'=>$uuid,'request_token'=>$token,'command_json'=>PromiseJson::encode($command)];
 }
}
if(defined('WP_CLI')&&WP_CLI&&isset($args)&&count($args)===3){
 require_once __DIR__.'/opening-http-fixture-common.php';[$mode,$state_path,$output_path]=$args;$state=opening_http_read_state($state_path);CetechQuotePlacementHttpFixture::guard($state);global $wpdb;
 if('prepareconfiguration'===$mode){
  if(isset($state['p05'])||true!==($state['p04']['active']??null))throw new RuntimeException('P05 refuses existing or inactive HTTP fixture.');
  $native=CetechQuoteCartHttpFixture::hydrate_native($wpdb,$state['native']);$native->set_option('cetech_de_enable_shipment_records','0');$state['native']=CetechQuoteCartHttpFixture::export_native($native);cetech_q05_write($state);
  $token='p05-http-'.bin2hex(random_bytes(6));$before=CetechPromiseNativeConfigurationHttpFixture::protected_source_rows($state);
  $state['p05']=['active'=>true,'token'=>$token,'registered'=>['promise_objects'=>[],'promise_versions'=>[],'promise_assignments'=>[]],'namespaces'=>[],'uuids'=>[],'before'=>$before,'product_url'=>get_permalink((int)$state['native']['products'][0])];cetech_q05_write($state);
  $users=[];foreach(['admin'=>'administrator','denied'=>'subscriber']as$name=>$role){$login='p05_'.$name.'_'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));$id=wp_insert_user(['user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.invalid','role'=>$role]);if(!is_int($id)||$id<1)throw new RuntimeException('P05 owned HTTP actor creation failed.');$users[$name]=['id'=>$id,'login'=>$login,'password'=>$password];$state['p05'][$name]=$users[$name];cetech_q05_write($state);}
  $state['p05']=['active'=>true,'token'=>$token,'registered'=>['promise_objects'=>[],'promise_versions'=>[],'promise_assignments'=>[]],'namespaces'=>[],'uuids'=>[],'before'=>$before,'product_url'=>get_permalink((int)$state['native']['products'][0])]+$users;cetech_q05_write($state);opening_http_write_json($output_path,['ready'=>true]);
 }elseif('inspectconfiguration'===$mode){opening_http_write_json($output_path,CetechPromiseNativeConfigurationHttpFixture::inspect($state));}
 elseif(in_array($mode,['payloadcreate','payloadseal','payloadpublish'],true)){opening_http_write_json($output_path,CetechPromiseNativeConfigurationHttpFixture::payload($state,substr($mode,7)));}
 elseif('cleanupconfiguration'===$mode){
  if(!isset($state['p05'])){opening_http_write_json($output_path,['cleanup_restored'=>true,'original_promise_history_restored'=>true,'tracked_users_removed'=>true]);return;}
  if(true===($state['p05']['cleanup_done']??false)){opening_http_write_json($output_path,$state['p05']['cleanup']);return;}
  CetechPromiseNativeConfigurationHttpFixture::register_sources($state);$ok=true;
  foreach(['promise_assignments','promise_versions','promise_objects']as$suffix)foreach($state['p05']['registered'][$suffix]as$id=>$row){$current=CetechPromiseHandoffHttpFixture::rows($wpdb->prepare('SELECT * FROM `'.TableNames::for($suffix).'` WHERE id=%d AND site_id=%d AND site_key=%s',(int)$id,$state['site_id'],$state['p05']['token']));if([$row]!==$current)throw new RuntimeException('P05 changed HTTP source cleanup refused.');$ok=false!==$wpdb->delete(TableNames::for($suffix),['id'=>(int)$id,'site_id'=>$state['site_id'],'site_key'=>$state['p05']['token']])&&$ok;}
  foreach($state['p05']['namespaces']as$namespace=>$operation){$rows=CetechPromiseHandoffHttpFixture::rows($wpdb->prepare('SELECT id,operation FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND namespace_hash=%s LIMIT 2',$state['site_id'],$namespace));if(count($rows)>1)throw new RuntimeException('P05 ambiguous HTTP cleanup namespace.');foreach($rows as$row){if($row['operation']!==$operation)throw new RuntimeException('P05 foreign HTTP cleanup namespace.');$ok=false!==$wpdb->delete(TableNames::for('operation_changes'),['site_id'=>$state['site_id'],'operation_id'=>(int)$row['id']])&&$ok;$ok=false!==$wpdb->delete(TableNames::for('operation_records'),['site_id'=>$state['site_id'],'id'=>(int)$row['id']])&&$ok;}}
  $users=true;if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';foreach(['admin','denied']as$name){if(!isset($state['p05'][$name]))continue;$owned=$state['p05'][$name];$user=get_userdata($owned['id']);if(!$user||$user->user_login!==$owned['login']||!str_starts_with($owned['login'],'p05_'.$name.'_'))throw new RuntimeException('P05 foreign HTTP user cleanup refused.');$users=wp_delete_user($owned['id'])&&false===get_userdata($owned['id'])&&$users;}
  $history=$state['p05']['before']===CetechPromiseNativeConfigurationHttpFixture::protected_source_rows($state);
  $state['p05']['active']=false;$state['p05']['cleanup_done']=true;$state['p05']['cleanup']=['cleanup_restored'=>$ok&&$users&&$history,'original_promise_history_restored'=>$history,'tracked_users_removed'=>$users];cetech_q05_write($state);opening_http_write_json($output_path,$state['p05']['cleanup']);
 }else throw new RuntimeException('P05 private HTTP mode refused.');
}
