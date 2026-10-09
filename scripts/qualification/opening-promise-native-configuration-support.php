<?php
declare(strict_types=1);
use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseConfigurationService;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity,RequestContext};
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion,ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseSiteBinding,PromiseVersionCommand};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Integrations\ServicePromise\Configuration\NativePromiseConfigurationAuthority;
use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
require_once __DIR__.'/opening-promise-handoff-support.php';

/** Separate finite native administrative namespace; every delete has prior exact identity. */
final class CetechPromiseNativeConfigurationFixture {
 public string $token;
 public PromiseSiteBinding $binding;
 public NativePromiseConfigurationAuthority $authority;
 public PromiseConfigurationService $service;
 public CetechQuotePlacementFactory $factory;
 private array $before;
 private array $registered = ['promise_objects'=>[], 'promise_versions'=>[], 'promise_assignments'=>[]];
 private array $namespaces = [];
 private array $declared = [];
 private array $uuids = [];
 private int $user;
 private array $users=[];
 private array $products=[];
 public function __construct(private wpdb $db) {
  $this->user=get_current_user_id();wp_set_current_user(1);
  $this->token='p05-'.bin2hex(random_bytes(6)); $this->binding=PromiseSiteBinding::bind(get_current_blog_id(),$this->token);
  $this->before=$this->rows_all();$this->factory=new CetechQuotePlacementFactory($db);
  $this->authority=new NativePromiseConfigurationAuthority(static fn():bool=>true);
  $activation=new PromiseQuotePlacementActivation($this->factory,static fn():bool=>true);
  $this->service=new PromiseConfigurationService($this->factory,$this->authority,$activation);
 }
 public function calendar(int $version=1):BusinessCalendarVersion { $facts=CetechNativePromiseBodies::calendar($this->binding->site_key(),$version)->private_facts();$facts['calendar_id']=$this->token.'-calendar';return BusinessCalendarVersion::from_array($facts); }
 public function policy(BusinessCalendarVersion $calendar,int $version=1,array $changes=[]):ServicePromisePolicy {
  $at=CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse(CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now()->sql());
  $facts=CetechNativePromiseBodies::policy($this->binding->site_key(),CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::from_epoch_microseconds($at->epoch_microseconds()-3600000000)->sql(),CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::from_epoch_microseconds($at->epoch_microseconds()+86400000000)->sql(),$calendar->reference(),$version)->private_facts();
  $facts['policy_id']=$this->token.'-policy';$facts['anchor']='payment_confirmed';$facts['late_payment_rule']='relative_after_payment';$facts['graph']['components'][0]['duration']=['format_version'=>1,'min'=>60,'max'=>120,'unit'=>'elapsed_minutes','calendar'=>null];$facts['graph']['components'][0]['operating_calendar']=null;$facts['graph']['components'][0]['completion_window_rule']='none';
  return ServicePromisePolicy::from_array(array_replace($facts,$changes));
 }
 public function payload(string $action,BusinessCalendarVersion|ServicePromisePolicy $body):array {
  $kind=$body instanceof ServicePromisePolicy?'policy':'calendar';$facts=$body->private_facts();$logical=$facts[$kind.'_id'];$this->declared[$kind.':'.$logical]=true;
  $object=$this->rows($this->db->prepare('SELECT * FROM `'.TableNames::for('promise_objects').'` WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s',get_current_blog_id(),$this->binding->site_key(),$kind,$logical))[0]??null;
  $version=$this->rows($this->db->prepare('SELECT * FROM `'.TableNames::for('promise_versions').'` WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s AND domain_version=%d',get_current_blog_id(),$this->binding->site_key(),$kind,$logical,$facts['version']))[0]??null;
  $uuid=$version['version_uuid']??QuoteId::generate()->value();$this->uuids[$uuid]=true;
  return ['kind'=>$kind,'logical_id'=>$logical,'domain_version'=>$facts['version'],'version_uuid'=>$uuid,'body_digest'=>$body->digest(),'scope'=>$body instanceof ServicePromisePolicy?PromiseVersionCommand::policy_scope($body):['kind'=>'global','target_id'=>0],'body_json'=>'create'===$action?$body->to_private_json():null,'declared_from'=>'policy'===$kind?$facts['effective_from']:'1970-01-01 00:00:00.000000','declared_until'=>'policy'===$kind?$facts['effective_until']:null,'reason'=>'Tracked native P05 qualification','scheduled_author_user_id'=>null,'preconditions'=>['object_revision'=>(int)($object['revision']??0),'version_revision'=>(int)($version['row_revision']??0),'published_version_id'=>(int)($object['published_version_id']??0)]];
 }
 public function command(string $action,array $payload,?string $token=null,bool $reconcile=false):CetechDeliveryEngine\Domain\Operation\OperationAttemptResult {
  $token??=RequestContext::create()->request_id;$operation='promise.version.'.$action;
  $identity=$this->authority->identity($this->binding,$operation,PromiseVersionCommand::target_key($this->binding,$payload['kind'],$payload['logical_id']),$token);
  $this->namespaces[$identity->namespace_digest()]=$identity->operation;
  $result=$this->service->version($this->binding,$operation,$token,$payload,$reconcile);$this->register();return $result;
 }
 public function register():void {
  foreach($this->registered as $suffix=>$previous) foreach($this->rows($this->db->prepare('SELECT * FROM `'.TableNames::for($suffix).'` WHERE site_id=%d AND site_key=%s ORDER BY id',get_current_blog_id(),$this->binding->site_key())) as $row) {
   if(isset($row['logical_id'])&&!isset($this->declared[$row['kind'].':'.$row['logical_id']])||'promise_versions'===$suffix&&!isset($this->uuids[$row['version_uuid']]))throw new RuntimeException('P05 source lacks predeclared cleanup authority.');
   $this->registered[$suffix][(int)$row['id']]=$row;
  }
 }
 public function rows(string $sql):array {$rows=$this->db->get_results($sql,ARRAY_A);if(!is_array($rows)||''!==$this->db->last_error||count($rows)>20000)throw new RuntimeException('P05 physical observation refused.');return $rows;}
 public function rows_all():array {$out=[];foreach(['promise_objects','promise_versions','promise_assignments'] as $suffix)$out[$suffix]=$this->rows('SELECT * FROM `'.TableNames::for($suffix).'` ORDER BY id');return $out;}
 public function version_rows():array {return $this->rows($this->db->prepare('SELECT * FROM `'.TableNames::for('promise_versions').'` WHERE site_id=%d AND site_key=%s ORDER BY id',get_current_blog_id(),$this->binding->site_key()));}
 public function scoped_native_actors():array {
  $actors=[];foreach(['editor','other']as$name){$login=$this->token.'-'.$name;$id=wp_insert_user(['user_login'=>$login,'user_pass'=>bin2hex(random_bytes(24)),'user_email'=>$login.'@example.invalid','role'=>'subscriber']);if(!is_int($id)||$id<1)throw new RuntimeException('P05 tracked scoped actor allocation refused.');$this->users[$id]=$login;$actors[$name]=$id;}
  $editor=new WP_User($actors['editor']);foreach(['read','manage_product_delivery_rules','edit_product','edit_products','edit_published_products','publish_products']as$cap)$editor->add_cap($cap);
  $simple=new WC_Product_Simple();$simple->set_name($this->token.'-owned-product');$simple->set_status('publish');$simple->set_regular_price('20');$id=$simple->save();$this->products[$id]=$simple->get_name();wp_update_post(['ID'=>$id,'post_author'=>$actors['editor']]);
  $foreign=new WC_Product_Simple();$foreign->set_name($this->token.'-foreign-product');$foreign->set_status('publish');$foreign->set_regular_price('20');$other=$foreign->save();$this->products[$other]=$foreign->get_name();wp_update_post(['ID'=>$other,'post_author'=>$actors['other']]);
  $parent=new WC_Product_Variable();$parent->set_name($this->token.'-variation-parent');$parent->set_status('publish');$parent_id=$parent->save();$this->products[$parent_id]=$parent->get_name();wp_update_post(['ID'=>$parent_id,'post_author'=>$actors['editor']]);
  $variation=new WC_Product_Variation();$variation->set_parent_id($parent_id);$variation->set_status('publish');$variation->set_regular_price('20');$variation_id=$variation->save();$this->products[$variation_id]=$variation->get_name();wp_update_post(['ID'=>$variation_id,'post_author'=>$actors['editor']]);
  return $actors+['owned'=>$id,'foreign'=>$other,'parent'=>$parent_id,'variation'=>$variation_id];
 }
 public function cleanup():array {
  $ok=$this->factory->close_all();
  foreach(array_reverse(array_keys($this->registered)) as $suffix)foreach($this->registered[$suffix] as $id=>$row){$now=$this->rows($this->db->prepare('SELECT * FROM `'.TableNames::for($suffix).'` WHERE id=%d AND site_id=%d AND site_key=%s',$id,get_current_blog_id(),$this->binding->site_key()));if([$row]!==$now)throw new RuntimeException('P05 changed source cleanup refused.');$ok=false!==$this->db->delete(TableNames::for($suffix),['id'=>$id,'site_id'=>get_current_blog_id(),'site_key'=>$this->binding->site_key()])&&$ok;}
  foreach($this->namespaces as $namespace=>$operation){$records=$this->rows($this->db->prepare('SELECT id FROM `'.TableNames::for('operation_records').'` WHERE site_id=%d AND namespace_hash=%s AND operation=%s LIMIT 2',get_current_blog_id(),$namespace,$operation));if(count($records)>1)throw new RuntimeException('P05 ambiguous operation cleanup refused.');foreach($records as $record){$ok=false!==$this->db->delete(TableNames::for('operation_changes'),['site_id'=>get_current_blog_id(),'operation_id'=>(int)$record['id']])&&$ok;$ok=false!==$this->db->delete(TableNames::for('operation_records'),['site_id'=>get_current_blog_id(),'id'=>(int)$record['id']])&&$ok;}}
  wp_set_current_user(1);foreach(array_reverse($this->products,true)as$id=>$name){$product=wc_get_product($id);if(!$product instanceof WC_Product||$product->get_name()!==$name)throw new RuntimeException('P05 foreign scoped product cleanup refused.');$product->delete(true);$ok=null===get_post($id)&&$ok;}if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';foreach($this->users as$id=>$login){$user=get_userdata($id);if(!$user||$user->user_login!==$login)throw new RuntimeException('P05 foreign scoped user cleanup refused.');$ok=wp_delete_user($id)&&false===get_userdata($id)&&$ok;}
  wp_set_current_user($this->user);return ['tracked_sources_restored'=>$ok&&$this->before===$this->rows_all(),'all_connections_retired'=>$this->factory->all_retired(),'native_actor_restored'=>get_current_user_id()===$this->user];
 }
}
