<?php
/** Fresh installed/P04 supported historical reader; P04 never mounts writers or down-migrations. */
declare(strict_types=1);
require_once __DIR__.'/opening-promise-operational-lifecycle-support.php';
$result=['status'=>'FAIL','process_id'=>getmypid()];$phase='configuration';ob_start();
try {
 CetechPromiseOperationalLifecycle::guard();$path=$args[0]??null;
 if(!is_string($path)||!is_file($path)||is_link($path)||filesize($path)>32768||(fileperms($path)&0777)!==0600)throw new RuntimeException('P06 private reader configuration unavailable.');
 $config=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
 $keys=['action','plugin_root','php_map_hash','php_map_count','order_id','unknown_order_id','envelope_digest','packet_digest','unknown_bytes_digest','shipment_id','shipment_row_digest','domain_digest','order_facts_digest','mode'];
 if(!is_array($config)||array_keys($config)!==$keys||!in_array($config['action'],['read','upgrade','deactivate','native_uninstall','rollback'],true)||!in_array($config['mode'],['hpos_on','hpos_off'],true)||('hpos_on'===$config['mode'])!==Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()||!is_string($config['plugin_root'])||!is_dir($config['plugin_root']))throw new RuntimeException('P06 reader closed configuration differs.');
 foreach(['php_map_hash','envelope_digest','packet_digest','unknown_bytes_digest','shipment_row_digest','domain_digest','order_facts_digest']as$key)if(!is_string($config[$key])||1!==preg_match('/\A[a-f0-9]{64}\z/D',$config[$key]))throw new RuntimeException('P06 reader digest invalid.');
 foreach(['order_id','unknown_order_id','shipment_id','php_map_count']as$key)if(!is_int($config[$key])||$config[$key]<1)throw new RuntimeException('P06 reader identity invalid.');
 $phase='authority';$rollback='rollback'===$config['action'];
 if($rollback){if(class_exists(CetechDeliveryEngine\Bootstrap\Plugin::class,false)||dirname(dirname($config['plugin_root']))!==sys_get_temp_dir()||1!==preg_match('/\Ap06-reader-[a-f0-9]{16}\z/D',basename(dirname($config['plugin_root']))))throw new RuntimeException('P06 supported reader isolation absent.');require $config['plugin_root'].'/vendor/autoload.php';}
 else {if(realpath($config['plugin_root'])!==realpath(WP_CONTENT_DIR.'/plugins/cetech-woocommerce-delivery-engine'))throw new RuntimeException('P06 installed root invalid.');}
 $map=CetechPromiseOperationalLifecycle::map($config['plugin_root']);$map_ok=count($map)===$config['php_map_count']&&CetechPromiseOperationalLifecycle::digest($map)===$config['php_map_hash'];
 $reflection=dirname((string)(new ReflectionClass(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader::class))->getFileName(),4);
 if(!$map_ok||realpath($reflection)!==realpath($config['plugin_root'])||!in_array(realpath($config['plugin_root'].'/vendor/autoload.php'),array_map('realpath',get_included_files()),true))throw new RuntimeException('P06 exact reader package/autoload invalid.');
 add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);add_filter('flush_rewrite_rules_hard','__return_false',PHP_INT_MAX);wp_set_current_user(1);
 // P04's manifest has 38 retained stores; include the separately preserved P05
 // store explicitly. Recognition is never deletion authority for its unknown data.
 $domain=static function()use($rollback):array{$out=CetechPromiseOperationalLifecycle::domain();if($rollback)$out['shipment_promises']=CetechPromiseOperationalLifecycle::rows('SELECT * FROM `'.CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for('shipment_promises').'` ORDER BY id LIMIT 20001');return $out;};
 $phase='snapshot';$before=$domain();if(39!==count($before)||CetechPromiseOperationalLifecycle::digest($before)!==$config['domain_digest'])throw new RuntimeException('P06 original 39-store snapshot differs.');
 $phase='lifecycle';
 if('upgrade'===$config['action'])CetechDeliveryEngine\Bootstrap\Activator::activate();
 if('deactivate'===$config['action'])CetechDeliveryEngine\Bootstrap\Deactivator::deactivate();
 $uninstall_completed=true;
 if('native_uninstall'===$config['action']){
  CetechPromiseOperationalLifecycle::complete_original_expired_maintenance();
  update_option(CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::CAPABILITIES_MARKER,4,false);update_option(CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_INTENT,1,false);CetechDeliveryEngine\Bootstrap\Uninstaller::uninstall();$status=json_decode((string)get_option(CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_STATUS,''),true);$uninstall_completed='completed'===($status['status']??null)&&false===get_option(CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::UNINSTALL_INTENT,false);
 }
 $phase='known_order';$order=new WC_Order($config['order_id']);$read=(new CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader())->read_package($order);$envelope=$read->delivery_quote?->envelope;$packet=$envelope?->promise_packet();
 $known=null!==$envelope&&null!==$packet&&hash('sha256',$envelope->to_private_json())===$config['envelope_digest']&&hash('sha256',$packet->to_private_json())===$config['packet_digest']&&CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory::verify($order)&&CetechPromiseOperationalLifecycle::digest(CetechPromiseOperationalLifecycle::order_facts($config['order_id'],$config['mode']))===$config['order_facts_digest'];
 $phase='unknown_reference';$unknown=new WC_Order($config['unknown_order_id']);$unknown_bytes=$unknown->get_meta(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,true);$unknown_read=(new CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader())->read_package($unknown);$unknown_ok=is_string($unknown_bytes)&&hash('sha256',$unknown_bytes)===$config['unknown_bytes_digest']&&null===$unknown_read->snapshot&&null===$unknown_read->delivery_quote?->envelope&&'unsupported'===$unknown_read->delivery_quote?->status;
 $phase='shipment';$shipment=CetechPromiseOperationalLifecycle::rows($GLOBALS['wpdb']->prepare('SELECT * FROM `'.CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for('shipment_promises').'` WHERE id=%d LIMIT 2',$config['shipment_id']));$shipment_ok=1===count($shipment)&&CetechPromiseOperationalLifecycle::digest($shipment[0])===$config['shipment_row_digest'];
 // P04's base storage is deliberately compatible with schema >=10. Isolation,
 // not a global schema11 write prohibition, keeps this invocation reader-only.
 $phase='forward_reader';$base_ready=true;$new_shipment_unavailable=true;$writers_unmounted=true;
 if($rollback){
  $base_ready=true===(new CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageReadiness())->get_status()['ready'];
  $new_shipment_unavailable=!class_exists(CetechDeliveryEngine\Domain\ServicePromise\Shipment\ShipmentPromiseStored::class)&&!class_exists(CetechDeliveryEngine\Infrastructure\Persistence\ShipmentPromiseReadiness::class)&&!class_exists(CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromiseOperationReadiness::class)&&!class_exists(CetechDeliveryEngine\Integrations\ServicePromise\Shipment\NativeShipmentPromiseRuntime::class);
  $writers_unmounted=!class_exists(CetechDeliveryEngine\Bootstrap\Plugin::class,false)&&!class_exists(CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime::class,false);
  foreach($GLOBALS['wp_filter']as$hook){if(!$hook instanceof WP_Hook){$writers_unmounted=false;continue;}foreach($hook->callbacks as$entries)foreach($entries as$entry){$fn=$entry['function']??null;$target=is_array($fn)?($fn[0]??null):$fn;$class=$target instanceof Closure?((new ReflectionFunction($target))->getClosureScopeClass()?->getName()??''):(is_object($target)?get_class($target):(is_string($target)?$target:''));if(str_starts_with(ltrim($class,'\\'),'CetechDeliveryEngine\\'))$writers_unmounted=false;}}
 }
 $forward=$base_ready&&$new_shipment_unavailable&&$writers_unmounted;
 $phase='observations';$exact=$before===$domain();$result=['status'=>$known&&$unknown_ok&&$shipment_ok&&$forward&&$exact&&$uninstall_completed?'PASS':'FAIL','process_id'=>getmypid(),'private_config_0600'=>true,'exact_production_autoload'=>$map_ok,'native_known_sealed_order_read'=>$known,'unknown_future_snapshot_preserved_refused'=>$unknown_ok,'original_current_shipment_rows_preserved'=>$shipment_ok,'all39_business_stores_preserved'=>$exact,'uninstall_completed'=>$uninstall_completed,'supported_forward_reader_only'=>$forward,'native_storage_mode_exact'=>true];if($rollback)$result+=['compatible_base_storage_ready'=>$base_ready,'new_shipment_writer_unavailable'=>$new_shipment_unavailable,'old_plugin_writers_unmounted'=>$writers_unmounted];
}catch(Throwable$error){$class=get_class($error);$result['error_class']=in_array($class,['Error','TypeError','RuntimeException','JsonException','ValueError','InvalidArgumentException','ParseError','ErrorException','mysqli_sql_exception'],true)?$class:'OtherThrowable';}
finally{if('PASS'!==$result['status']){$result['phase']=$phase;$result['failed_checks']=[];foreach(['exact_production_autoload','native_known_sealed_order_read','unknown_future_snapshot_preserved_refused','original_current_shipment_rows_preserved','all39_business_stores_preserved','uninstall_completed','supported_forward_reader_only','compatible_base_storage_ready','new_shipment_writer_unavailable','old_plugin_writers_unmounted','native_storage_mode_exact']as$key)if(false===($result[$key]??null))$result['failed_checks'][]=$key;}ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
