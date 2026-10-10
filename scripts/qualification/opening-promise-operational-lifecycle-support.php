<?php
/** Finite P06 private process transport and physical observations; no production hooks. */
declare(strict_types=1);

final class CetechPromiseOperationalLifecycle {
 public static function guard():void {
  global $wpdb;
  if('1'!==getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION')||!defined('WP_ADMIN')||true!==WP_ADMIN||!defined('DB_HOST')||1!==preg_match('/\A127\.0\.0\.1(?::[0-9]+)?\z/D',DB_HOST)||!defined('DB_NAME')||1!==preg_match('/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D',DB_NAME)||'1'!==(string)get_option('cetech_opening_qualification_disposable')||!$wpdb instanceof wpdb||get_class($wpdb)!==wpdb::class||is_multisite()||WP_Object_Cache::class!==get_class($GLOBALS['wp_object_cache'])||is_file(WP_CONTENT_DIR.'/object-cache.php')||is_file(WP_CONTENT_DIR.'/db.php'))throw new RuntimeException('P06 requires the owned marked native default-cache fixture.');
  foreach(get_included_files()as$file)if(str_ends_with(str_replace('\\','/',$file),'/tests/bootstrap.php'))throw new RuntimeException('P06 refuses unit bootstrap in native qualification.');
 }
 public static function rows(string $sql):array {
  global $wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||count($rows)>20000||''!==$wpdb->last_error)throw new RuntimeException('P06 bounded physical observation unavailable.');return $rows;
 }
 public static function domain():array {
  $out=[];foreach(\CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as$suffix)$out[$suffix]=self::rows('SELECT * FROM `'.\CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for($suffix).'` ORDER BY id LIMIT 20001');return $out;
 }
 public static function digest(array $value):string {return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
 public static function map(string $root):array {
  $out=[];foreach(['src','database']as$directory)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS))as$file)if($file->isFile()&&'php'===$file->getExtension())$out[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());foreach(['cetech-woocommerce-delivery-engine.php','uninstall.php']as$name)$out[$name]=hash_file('sha256',$root.'/'.$name);ksort($out,SORT_STRING);return $out;
 }
 public static function order_facts(int $id,string $mode):array {
  global $wpdb;$out=[];
  $queries='hpos_on'===$mode?['order'=>$wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_orders` WHERE id=%d LIMIT 2",$id),'operational'=>$wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_order_operational_data` WHERE order_id=%d LIMIT 2",$id),'addresses'=>$wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_order_addresses` WHERE order_id=%d ORDER BY id LIMIT 100",$id),'meta'=>$wpdb->prepare("SELECT * FROM `{$wpdb->prefix}wc_orders_meta` WHERE order_id=%d ORDER BY id LIMIT 20001",$id)]:['order'=>$wpdb->prepare("SELECT * FROM `{$wpdb->posts}` WHERE ID=%d LIMIT 2",$id),'meta'=>$wpdb->prepare("SELECT * FROM `{$wpdb->postmeta}` WHERE post_id=%d ORDER BY meta_id LIMIT 20001",$id)];
  $queries['items']=$wpdb->prepare("SELECT * FROM `{$wpdb->prefix}woocommerce_order_items` WHERE order_id=%d ORDER BY order_item_id LIMIT 201",$id);$queries['item_meta']=$wpdb->prepare("SELECT m.* FROM `{$wpdb->prefix}woocommerce_order_itemmeta` m INNER JOIN `{$wpdb->prefix}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d ORDER BY m.meta_id LIMIT 20001",$id);foreach($queries as$name=>$sql)$out[$name]=self::rows($sql);return $out;
 }
 public static function options():array {
  global $wpdb;$names=[$wpdb->prefix.'user_roles','cron','rewrite_rules','active_plugins','cetech_de_db_version','cetech_de_last_migration_status'];
  foreach(['COORDINATOR_OPTION','UNINSTALL_STATUS','UNINSTALL_INTENT','CAPABILITIES_MARKER']as$constant)$names[]=constant(\CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::class.'::'.$constant);
  $names[]='_transient_'.\CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::ACTIVATION_NOTICE;$names[]='_transient_timeout_'.\CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::ACTIVATION_NOTICE;
  foreach((new \CetechDeliveryEngine\Bootstrap\FeatureFlags())->defaults()as$name=>$value)$names[]='cetech_de_'.$name;
  $out=[];foreach(array_unique($names)as$name){$rows=self::rows($wpdb->prepare("SELECT option_id,option_name,option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s LIMIT 2",$name));if(count($rows)>1)throw new RuntimeException('P06 option observation ambiguous.');$out[$name]=$rows[0]??null;}return $out;
 }
 public static function restore_options(array $before,array $observed):bool {
  global $wpdb;$ok=true;$now=self::options();if($now!==$observed||array_keys($before)!==array_keys($observed))throw new RuntimeException('P06 refuses changed lifecycle option cleanup.');
  foreach($before as$name=>$row){$current=$observed[$name];if($row===$current)continue;
   if(null===$current){if(null!==$row&&1!==$wpdb->insert($wpdb->options,$row))throw new RuntimeException('P06 missing original lifecycle row restore refused.');}
   else{$where=['option_id'=>$current['option_id'],'option_name'=>$current['option_name'],'option_value'=>$current['option_value'],'autoload'=>$current['autoload']];$affected=null===$row?$wpdb->delete($wpdb->options,$where):$wpdb->update($wpdb->options,$row,$where);if(1!==$affected)throw new RuntimeException('P06 exact lifecycle row restoration refused.');}
   wp_cache_delete($name,'options');
  }
  wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');wp_roles()->for_site(get_current_blog_id());return $ok&&self::options()===$before;
 }
 /** Finish only an acknowledged original expired-cache checkpoint; uninstall never bypasses an active owner. */
 public static function complete_original_expired_maintenance():void {
  self::guard();$site=get_current_blog_id();$maintenance=new \CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService(\CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry::standard(),new \CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory(),static fn(int$requested):bool=>$site===$requested);$pending=$maintenance->read($site);
  if(null!==$pending->progress&&'completed'!==$pending->progress->status){if('accepted'!==$pending->status||'expired'!==$pending->progress->mode)throw new RuntimeException('P06 original maintenance unavailable.');for($i=0;$i<64&&'completed'!==$pending->progress->status;++$i){$pending=$maintenance->batch($site,$pending->continuation);if('accepted'!==$pending->status||null===$pending->progress||'expired'!==$pending->progress->mode)throw new RuntimeException('P06 original maintenance did not acknowledge.');}if('completed'!==$pending->progress->status)throw new RuntimeException('P06 maintenance bound exceeded.');}
 }
 /** Shell-free argv, exact PID/exit, finite time/output. Diagnostics stay private. */
 public static function process(array $command,int $seconds=45,int $output_limit=16384):array {
  $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('P06 child did not start.');$initial=proc_get_status($process);$pid=$initial['pid'];fclose($pipes[0]);unset($pipes[0]);foreach($pipes as$pipe)stream_set_blocking($pipe,false);$out='';$errors='';$exit=-1;$status=$initial;$deadline=microtime(true)+$seconds;
  try{do{$out.=stream_get_contents($pipes[1]);$errors.=stream_get_contents($pipes[2]);$status=proc_get_status($process);if(!$status['running']){$exit=$status['exitcode'];break;}if(strlen($out)>$output_limit||strlen($errors)>65536)break;usleep(10000);}while(microtime(true)<$deadline);if($status['running'])proc_terminate($process,9);$out.=stream_get_contents($pipes[1]);$errors.=stream_get_contents($pipes[2]);foreach($pipes as$pipe)fclose($pipe);$pipes=[];$closed=proc_close($process);$process=null;if($status['running']||strlen($out)>$output_limit||strlen($errors)>65536||0!==($exit>=0?$exit:$closed))throw new RuntimeException('P06 bounded child transport failed.');return ['output'=>$out,'pid'=>$pid];}
  finally{foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process,9);proc_close($process);}}
 }
 public static function child(array $config,bool $standalone=false,bool $rollback=false):array {
  $path=tempnam(sys_get_temp_dir(),'p06-lifecycle-');if(false===$path)throw new RuntimeException('P06 private config unavailable.');
  try{if(!chmod($path,0600)||false===file_put_contents($path,json_encode($config,JSON_THROW_ON_ERROR)))throw new RuntimeException('P06 private config write refused.');
   if($standalone)$command=[PHP_BINARY,__DIR__.'/opening-promise-operational-lifecycle-standalone.php',$path];
   else{$command=[PHP_BINARY,self::env_file('CETECH_DE_P06_WP_CLI'),'--allow-root','--path='.rtrim(ABSPATH,'/'),'--require='.__DIR__.'/admin-context.php'];if($rollback)$command[]='--skip-plugins=cetech-woocommerce-delivery-engine';$command=[...$command,'eval-file',__DIR__.'/opening-promise-operational-lifecycle-reader.php',$path,'--use-include'];}
   $result=self::process($command);$decoded=json_decode($result['output'],true,16,JSON_THROW_ON_ERROR);if(!is_array($decoded)||($decoded['process_id']??null)!==$result['pid']||'PASS'!==($decoded['status']??null)){
    $phase=in_array($decoded['phase']??null,['configuration','wordpress','authority','roles','snapshot','lifecycle','known_order','unknown_reference','shipment','forward_reader','uninstall','observations'],true)?$decoded['phase']:'unreported';$class=in_array($decoded['error_class']??null,['Error','TypeError','RuntimeException','JsonException','ValueError','InvalidArgumentException','ParseError','ErrorException','mysqli_sql_exception','OtherThrowable'],true)?$decoded['error_class']:'unreported';$code=in_array($decoded['uninstall_code']??null,['completed','not_requested','storage_refused','active_cleanup','batch_incomplete','retained_unknown_cache','cache_publication_pending','outcome_unknown','intent_publication_pending','intent_removal_refused','status_unconfirmed'],true)?$decoded['uninstall_code']:'unreported';
    $failed=[];foreach(['exact_production_autoload','native_known_sealed_order_read','unknown_future_snapshot_preserved_refused','original_current_shipment_rows_preserved','all39_business_stores_preserved','uninstall_completed','supported_forward_reader_only','compatible_base_storage_ready','new_shipment_writer_unavailable','old_plugin_writers_unmounted','native_storage_mode_exact']as$key)if(false===($decoded[$key]??null))$failed[]=$key;
    throw new RuntimeException('P06 fresh lifecycle child failed: phase='.$phase.' error_class='.$class.' uninstall_code='.$code.' failed_checks='.([]===$failed?'unreported':implode(',',$failed)).'.');
   }$decoded['private_config_removed']=true;return $decoded;
  }finally{if(!unlink($path)||file_exists($path)||is_link($path))throw new RuntimeException('P06 private child file cleanup refused.');}
 }
 public static function env_file(string $name):string {$value=getenv($name);if('CETECH_DE_P06_PREDECESSOR_PACKAGE'===$name&&!is_string($value))$value=getenv('CETECH_DE_P06_PREVIOUS_PACKAGE');if(!is_string($value)||!is_file($value)||is_link($value))throw new RuntimeException('P06 exact package/runtime input unavailable.');return $value;}
 public static function package(string $name,string $head,?array $expected_map=null):array {
  $zip=self::env_file($name);$metadata=substr($zip,0,-4).'.json';if(!str_ends_with($zip,'.zip')||!is_file($metadata)||is_link($metadata)||filesize($metadata)>8388608)throw new RuntimeException('P06 immutable package report absent.');$report=json_decode((string)file_get_contents($metadata),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($report)||'cetech-promise-qualification-package-v1'!==($report['format']??null)||'PASS'!==($report['status']??null)||$head!==($report['source_head']??null)||!is_string($report['source_tree']??null)||1!==preg_match('/\A[a-f0-9]{40}\z/D',$report['source_tree'])||hash_file('sha256',$zip)!==($report['zip_sha256']??null)||filesize($zip)!==($report['zip_bytes']??null)||!is_array($report['production_php_sources']??null)||!is_array($report['package_files']??null)||!is_array($report['checks']??null)||in_array(false,$report['checks'],true))throw new RuntimeException('P06 immutable package report differs.');$map=$report['production_php_sources'];ksort($map,SORT_STRING);if(self::digest($map)!==($report['production_php_sources_hash']??null)||(null!==$expected_map&&$map!==$expected_map))throw new RuntimeException('P06 immutable package source differs.');
  $archive=new ZipArchive();$actual=[];try{if(true!==$archive->open($zip)||$archive->numFiles<1||$archive->numFiles>15000)throw new RuntimeException('P06 package inventory invalid.');for($i=0;$i<$archive->numFiles;++$i){$entry=$archive->getNameIndex($i);if(!is_string($entry)||!str_starts_with($entry,'cetech-woocommerce-delivery-engine/')||str_contains($entry,'..')||str_contains($entry,'\\')||str_ends_with($entry,'/'))throw new RuntimeException('P06 package path invalid.');$path=substr($entry,strlen('cetech-woocommerce-delivery-engine/'));if(isset($actual[$path]))throw new RuntimeException('P06 duplicate package path invalid.');$bytes=$archive->getFromIndex($i);if(!is_string($bytes)||strlen($bytes)>33554432)throw new RuntimeException('P06 package bytes unavailable.');$actual[$path]=hash('sha256',$bytes);}ksort($actual,SORT_STRING);$expected=$report['package_files'];ksort($expected,SORT_STRING);if($actual!==$expected)throw new RuntimeException('P06 extracted package inventory differs.');}finally{$archive->close();}
  return ['source_head'=>$report['source_head'],'source_tree'=>$report['source_tree'],'zip_sha256'=>$report['zip_sha256'],'package_report_sha256'=>hash_file('sha256',$metadata),'production_php_sources'=>$map,'production_php_sources_hash'=>self::digest($map)];
 }
 public static function extract_reader(string $zip):string {
  $base=sys_get_temp_dir().'/p06-reader-'.bin2hex(random_bytes(8));if(!mkdir($base,0700))throw new RuntimeException('P06 reader directory unavailable.');$archive=new ZipArchive();
  try{if(true!==$archive->open($zip)||$archive->numFiles<1||$archive->numFiles>15000)throw new RuntimeException('P06 reader package invalid.');$seen=[];for($i=0;$i<$archive->numFiles;++$i){$name=$archive->getNameIndex($i);$opsys=0;$attributes=0;if(!is_string($name)||!str_starts_with($name,'cetech-woocommerce-delivery-engine/')||str_contains($name,'..')||str_contains($name,'\\')||str_contains($name,chr(0))||isset($seen[$name])||!$archive->getExternalAttributesIndex($i,$opsys,$attributes)||(($attributes>>16)&0170000)===0120000)throw new RuntimeException('P06 reader package path refused.');$seen[$name]=true;}if(!$archive->extractTo($base))throw new RuntimeException('P06 reader extraction refused.');$archive->close();$root=$base.'/cetech-woocommerce-delivery-engine';if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('P06 reader production autoload absent.');return $root;}
  catch(Throwable $error){$archive->close();self::remove_reader($base);throw $error;}
 }
 public static function remove_reader(string $base):bool {
  if(dirname($base)!==sys_get_temp_dir()||1!==preg_match('/\Ap06-reader-[a-f0-9]{16}\z/D',basename($base))||is_link($base))throw new RuntimeException('P06 reader cleanup selector refused.');$ok=true;if(is_dir($base)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){$ok=($file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname()))&&$ok;}$ok=rmdir($base)&&$ok;}return $ok&&!file_exists($base);
 }
}


/** A new native request reuses original evidence; it never refreshes or switches its retained route. */
final class CetechPromiseOperationalNativeRequest {
 public \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime $placement;
 public \CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService $admission;
 public \CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime $runtime;
 public ?\CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionResult $last_admission=null;
 public ?string $last_route=null;
 public ?string $redirect_url=null;
 public ?int $redirect_status=null;
 public function __construct(private CetechPromiseHandoffNativeFixture $fixture) {
  foreach($GLOBALS['wp_filter']['woocommerce_available_payment_gateways']->callbacks??[]as$priority=>$entries)foreach($entries as$entry){$fn=$entry['function']??null;if(is_array($fn)&&2===count($fn)&&$fn[0]instanceof \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime&&'guard_payment_gateways'===$fn[1])remove_filter('woocommerce_available_payment_gateways',$fn,$priority);}
  $container=\CetechDeliveryEngine\Bootstrap\Plugin::instance()->container();$latch=new \CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch();
  $control=$container->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService::class);$classifier=$container->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier::class);
  $reader=new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader($fixture->native->cart->environment,$fixture->native->cart->sessions,$fixture->native->factory);
  $stager=new \CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager($fixture->native->factory);
  $this->placement=new \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime($fixture->native->factory,$reader,$stager,$container->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutQuoteValidator::class),static fn():bool=>true,static fn():bool=>true,redirect:function(string$url,int$status):bool{$this->redirect_url=$url;$this->redirect_status=$status;return true;},terminate:static function():never{throw new CetechPromiseOperationalRecoveryRedirect();},final_admission:function(WC_Order$order,string$route){$this->last_route=$route;return $this->last_admission=$this->admission->final_order($order,$route);});
  $this->admission=new \CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService($control,$classifier,$this->placement,$latch,$this->placement);
  $this->runtime=new \CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime($control,$classifier,$latch,$this->admission);
 }
 public static function store_request(WC_Order$order):WP_REST_Request {
  $request=new WP_REST_Request('POST','/wc/store/v1/checkout');$request->set_header('Nonce',wp_create_nonce('wc_store_api'));
  foreach(['billing','shipping']as$type)$request->set_param($type.'_address',$order->get_address($type));$request->set_param('payment_method',$order->get_payment_method('edit'));return$request;
 }
 /** Genuine saved native Woo order staged by the Store API adapter; this is not an HTTP route claim. */
 public function create_store(string$method):array {
  $fixture=$this->fixture->native;$evidence=$fixture->confirmed_evidence();$service=$fixture->placement_service($evidence);$stager=new \CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager($fixture->factory);$created=null;
  $hooks=['woocommerce_checkout_create_order_line_item','woocommerce_checkout_order_created'];$before=[];
  foreach($hooks as$name){$before[$name]=isset($GLOBALS['wp_filter'][$name])?clone$GLOBALS['wp_filter'][$name]:null;foreach($GLOBALS['wp_filter'][$name]->callbacks??[]as$priority=>$entries)foreach($entries as$entry){$fn=$entry['function']??null;if(is_array($fn)&&2===count($fn)&&is_object($fn[0])&&($fn[0]instanceof \CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyCheckoutHooks&&'bind_order_line'===$fn[1]||$fn[0]instanceof \CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime&&'freeze_saved_order'===$fn[1]||$fn[0]instanceof \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime&&'stage_classic'===$fn[1]))remove_action($name,$fn,$priority);}}
  $line=function(WC_Order_Item_Product$item,string$key,array$values):void{$item->update_meta_data(\CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts::META_LINE_KEY,$key);$this->runtime->latch_line($key,$values);$this->runtime->bind_order_line($key,$item);};
  $stage=function(WC_Order$order)use(&$created,$fixture):void{$created=$order;$fixture->orders[]=$order->get_id();$this->placement->remember_store_post($order,self::store_request($order));$this->placement->stage_store_post($order);};
  add_action($hooks[0],$line,PHP_INT_MAX,3);add_action($hooks[1],$stage,PHP_INT_MAX-1,1);add_action($hooks[1],[$this->runtime,'freeze_saved_order'],PHP_INT_MAX,1);
  try{$order=$fixture->create_order(false,$method);}finally{foreach($before as$name=>$hook){if(null===$hook)unset($GLOBALS['wp_filter'][$name]);else$GLOBALS['wp_filter'][$name]=$hook;}}
  if($created instanceof WC_Order)$order=$created;$mapping=$stager->mapping($order,$evidence);$row=$fixture->binding_row($evidence->header()->id()->value());if(null===$row)throw new RuntimeException('P06 native Store API original binding unavailable.');$binding=\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row($row,$evidence->quote_record());$saved=$stager->saved_guard($order,$evidence->quote_record(),$binding);
  $prepared=$service->prepare($order->get_id(),$mapping,\CetechDeliveryEngine\Domain\Contracts\RequestContext::create());$fixture->track_result($prepared);$verified=$service->verify($binding,$saved,\CetechDeliveryEngine\Domain\Contracts\RequestContext::create());$fixture->track_result($verified);
  if('accepted'!==$prepared->attempt->outcome->state||'accepted'!==$verified->attempt->outcome->state)throw new RuntimeException('P06 actual Store API staged original did not acknowledge.');
  // Exercise the installed Store API legacy-payment hook, with this request's native guard.
  \Automattic\WooCommerce\StoreApi\StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\Legacy::class)->init();
  foreach($GLOBALS['wp_filter']['woocommerce_rest_checkout_process_payment_with_context']->callbacks??[]as$priority=>$entries)foreach($entries as$entry){$fn=$entry['function']??null;if(is_array($fn)&&2===count($fn)&&$fn[0]instanceof \CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime&&'guard_store_payment_context'===$fn[1])remove_action('woocommerce_rest_checkout_process_payment_with_context',$fn,$priority);}
  add_action('woocommerce_rest_checkout_process_payment_with_context',[$this->placement,'guard_store_payment_context'],-PHP_INT_MAX,2);
  return['order'=>$order,'evidence'=>$evidence,'service'=>$service,'binding'=>$binding,'saved'=>$saved];
 }
 public function replay_seal(array$original):\CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult {
  $control=\CetechDeliveryEngine\Bootstrap\Plugin::instance()->container()->get(\CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService::class)->read(get_current_blog_id());$local=\CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding::capture($original['order']);if(null===$local||null===$control->state)throw new RuntimeException('P06 original Store API final proof unavailable.');
  $proof=\CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementProof::capture($original['binding'],$control->state->revision,$local,$original['saved'],$original['evidence']->terms(),\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now());$result=$original['service']->seal($original['binding'],$proof,\CetechDeliveryEngine\Domain\Contracts\RequestContext::create());$this->fixture->native->track_result($result);return$result;
 }
 public function recover_store(WC_Order$order):bool {
  $route=\Automattic\WooCommerce\StoreApi\StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\RoutesController::class)->get('checkout');$response=$this->placement->recover_store_post(null,self::store_request($order),'/wc/store/v1/checkout',['callback'=>[$route,'get_response']]);
  return$response instanceof WP_REST_Response&&200===$response->get_status()&&($response->get_data()['order_id']??null)===$order->get_id()&&($response->get_data()['payment_result']['redirect_url']??null)===$order->get_checkout_payment_url()&&true===$this->last_admission?->allowed;
 }
 public function recover_classic(WC_Order$order):bool {
  $post=$_POST;$request=$_REQUEST;
  try{foreach(['billing','shipping']as$type)foreach($order->get_address($type)as$field=>$value)$_POST[$type.'_'.$field]=$value;$_POST['ship_to_different_address']='1';$_POST['payment_method']=$order->get_payment_method('edit');$_REQUEST=$_POST;$this->placement->protect_classic_retry();try{$this->placement->resume_classic($order->get_id());}catch(CetechPromiseOperationalRecoveryRedirect){}
   return 303===$this->redirect_status&&$this->redirect_url===$order->get_checkout_payment_url()&&true===$this->last_admission?->allowed;
  }finally{$_POST=$post;$_REQUEST=$request;}
 }
}

/** Observe native redirect/termination without exiting the qualification process. */
final class CetechPromiseOperationalRecoveryRedirect extends RuntimeException {}
