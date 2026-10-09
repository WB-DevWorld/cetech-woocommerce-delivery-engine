<?php
declare(strict_types=1);
namespace Automattic\WooCommerce\Utilities {
 final class OrderUtil { public static function custom_orders_table_usage_is_enabled(): bool { return \NoEffectProbe::$hpos; } }
}
namespace {
 require dirname(__DIR__,3).'/vendor/autoload.php';
 require __DIR__.'/CartQuoteFixtures.php';
 require __DIR__.'/QuoteStorageFixtures.php';
 use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuotePreparation,QuoteCurrentEvidenceGuard,QuoteNativeTaxSource,QuotePlacementEvidence};
 use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope,OrderDeliverySnapshot,QuoteNativeOrderFacts,QuoteNativeOrderHistory,QuoteNativeOrderStager};
 use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
 use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
 use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteHeader,QuoteJson,QuoteOwner,QuoteStoredRow};
 use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
 use CetechDeliveryEngine\Infrastructure\Persistence\{OperationStoreSchema,OperationStoreReadiness};
 use CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementNoEffectNativeGuard;
 use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtures,LegacyQuoteProviderFixtures,QuoteFixtures,QuoteStorageFixtures};
 /** Accurate raw native shape; separate real Woo/SQL qualification proves the installed transport. */
 final class NoEffectProbe { public static bool $hpos=true,$locked=false; public static int $getters=0,$locked_getters=0; public static function getter():void { ++self::$getters; if(self::$locked){ ++self::$locked_getters; throw new LogicException('Native getter under disposition SQL.'); } } }
 class WC_DateTime extends DateTime {}
 class WC_Meta_Data { protected array $data; protected mixed $current_data; public function __construct(array $data){$this->data=$this->current_data=$data;} public function raw():array{return $this->current_data;} public function change(string $map,string $key,mixed $value):void{$this->$map[$key]=$value;} }
 class WC_Data {
  protected array $data=[], $changes=[], $meta_data=[]; protected int $id;
  public function __construct(int $id,array $data){$this->id=$id;$this->data=$data;}
  public function get_id():int{NoEffectProbe::getter();return $this->id;}
  public function get_meta_data():array{NoEffectProbe::getter();return $this->meta_data;}
  public function get_meta(string $key,bool $single=true):mixed{NoEffectProbe::getter();foreach($this->meta_data as $meta){$raw=$meta->raw();if($raw['key']===$key)return $raw['value'];}return '';}
  public function meta_exists(string $key):bool{NoEffectProbe::getter();foreach($this->meta_data as $meta){if($meta->raw()['key']===$key)return true;}return false;}
  public function update_meta_data(string $key,mixed $value):void{foreach($this->meta_data as $index=>$meta){if($meta->raw()['key']===$key){$this->meta_data[$index]=new WC_Meta_Data(['id'=>$meta->raw()['id'],'key'=>$key,'value'=>$value]);return;}}$this->meta_data[]=new WC_Meta_Data(['id'=>100+count($this->meta_data),'key'=>$key,'value'=>$value]);}
  public function add_meta_data(string $key,mixed $value,bool $unique=false):void{if($unique){$this->update_meta_data($key,$value);}else{$this->meta_data[]=new WC_Meta_Data(['id'=>100+count($this->meta_data),'key'=>$key,'value'=>$value]);}}
  public function save():void{$this->data=array_replace_recursive($this->data,$this->changes);$this->changes=[];}
  public function read_meta_data(bool $force=false):void{NoEffectProbe::getter();}
  public function __call(string $method,array $args):mixed{NoEffectProbe::getter();if(str_starts_with($method,'get_')){return $this->changes[substr($method,4)]??$this->data[substr($method,4)]??null;}throw new LogicException($method);}
  public function set(string $key,mixed $value):void{$this->changes[$key]=$value;}
  public function mutate_meta(string $mode):void{foreach($this->meta_data as $meta){if($meta->raw()['key']==='_native_other'){$meta->change('backup'===$mode?'data':'current_data','value','changed');return;}}}
  public function raw_meta():array{return array_map(static fn(WC_Meta_Data $m):array=>$m->raw(),$this->meta_data);}
 }
 class WC_Order_Item extends WC_Data {}
 class WC_Order_Item_Product extends WC_Order_Item {}
 class WC_Order_Item_Shipping extends WC_Order_Item {}
 class WC_Order_Item_Tax extends WC_Order_Item {}
 class WC_Abstract_Order extends WC_Data {
  protected array $items=[];
  public function get_items(string $type='line_item'):array{NoEffectProbe::getter();$key=['line_item'=>'line_items','shipping'=>'shipping_lines','tax'=>'tax_lines','fee'=>'fee_lines','coupon'=>'coupon_lines'][$type];return $this->items[$key]??=[];}
 }
 class WC_Order extends WC_Abstract_Order {
  protected array $data;
  public function __construct(string $group){parent::__construct(100,['status'=>'pending','currency'=>'GHS','customer_id'=>0,'order_key'=>'wc_order_staging_synthetic','total'=>'37.50','total_tax'=>'0','cart_tax'=>'0','shipping_total'=>'12.50','shipping_tax'=>'0','shipping_country'=>'GH','shipping_state'=>'AA','shipping_city'=>'Accra','shipping_postcode'=>'00001','shipping_address_1'=>'PRIVATE-Q05-ADDRESS','shipping_address_2'=>'','billing'=>['address_1'=>'original'],'payment_method'=>'native','payment_method_title'=>'Native','date_modified'=>null]);
   $this->items=['line_items'=>[901=>new WC_Order_Item_Product(901,['order_id'=>100,'product_id'=>10,'variation_id'=>0,'quantity'=>2,'total'=>'25','total_tax'=>'0','subtotal'=>'25','subtotal_tax'=>'0','taxes'=>['total'=>[],'subtotal'=>[]]])],'shipping_lines'=>[902=>new WC_Order_Item_Shipping(902,['order_id'=>100,'method_id'=>'delivery_engine_selected_offer','instance_id'=>0,'name'=>'Fixture delivery','total'=>'12.50','total_tax'=>'0','taxes'=>['total'=>[]]])],'tax_lines'=>[],'fee_lines'=>[],'coupon_lines'=>[]];
   $this->items['shipping_lines'][902]->update_meta_data('cetech_de_group_id',$group);$this->update_meta_data('_native_other','original');$this->update_meta_data('_native_second','second');
  }
  public function __clone(){foreach($this->items as $type=>$items){$this->items[$type]=array_map(static fn(object $item):object=>clone $item,$items);}}
  public function mutate(string $mode):void{switch($mode){case 'money':$this->set('total','999');break;case 'line_money':$this->items['line_items'][901]->set('total','999');break;case 'line_tax':$this->items['line_items'][901]->set('taxes',['total'=>[1=>'9']]);break;case 'billing':$this->set('billing',['address_1'=>'changed']);break;case 'status':$this->set('status','processing');break;case 'date':$this->set('date_modified',new WC_DateTime('2026-10-08 00:00:01',new DateTimeZone('UTC')));break;case 'key':$this->set('order_key','changed');break;case 'method':$this->set('payment_method','changed');break;case 'metadata':$this->mutate_meta('current');break;case 'metadata_backup':$this->mutate_meta('backup');break;case 'metadata_order':$this->meta_data=array_reverse($this->meta_data,true);break;case 'metadata_null':(new ReflectionProperty($this->meta_data[0],'current_data'))->setValue($this->meta_data[0],null);break;case 'quote_meta':$this->update_meta_data(QuoteNativeOrderFacts::META_REFERENCE,'changed');break;case 'fee':$this->items['fee_lines']=[new WC_Order_Item_Product(905,['total'=>'0'])];break;case 'id':$this->id=101;break;}}
 }
 final class NoEffectFactory implements OperationConnectionFactory {
  public int $opens=0,$rollbacks=0,$retirements=0; public string $mode=''; public array $physical=[],$sql=[],$sessions=[];
  public function __construct(public WC_Order $order){}
  public function open():OperationSession{if(!NoEffectProbe::$locked&&$this->mode==='')$this->snapshot();++$this->opens;return $this->sessions[]=new NoEffectSession($this);}
  public function snapshot():void{
   $o=$this->order;$items=[];$meta=[];
   foreach(['line_item','shipping'] as $type){foreach($o->get_items($type) as $item){$id=$item->get_id();$items[]=['order_item_id'=>(string)$id,'order_item_type'=>$type,'order_item_name'=>'line_item'===$type?'Product':'Fixture delivery','order_id'=>'100'];$values='line_item'===$type?['_product_id'=>'10','_variation_id'=>'0','_qty'=>'2','_line_total'=>'25','_line_tax'=>'0','_line_subtotal'=>'25','_line_subtotal_tax'=>'0','_line_tax_data'=>serialize($item->get_taxes())]:['method_id'=>'delivery_engine_selected_offer','instance_id'=>'0','cost'=>'12.50','total_tax'=>'0','taxes'=>serialize($item->get_taxes())];foreach($item->raw_meta() as $row){$values[$row['key']]=$row['value'];}foreach($values as $key=>$value){$meta[]=['order_item_id'=>(string)$id,'meta_key'=>$key,'meta_value'=>$value];}}}
   $orders=[];foreach($o->raw_meta() as $row){if(str_starts_with($row['key'],'_cetech_de_')||$row['key']==='is_vat_exempt')$orders[]=['meta_key'=>$row['key'],'meta_value'=>$row['value']];}
   $this->physical=['items'=>$items,'item_meta'=>$meta,'order_meta'=>$orders];
   if(NoEffectProbe::$hpos){$this->physical+=['order'=>[['id'=>'100','status'=>'wc-pending','currency'=>'GHS','total_amount'=>'37.50000000','tax_amount'=>'0.00000000','customer_id'=>'0']],'money'=>[['order_id'=>'100','order_key'=>'wc_order_staging_synthetic','shipping_total_amount'=>'12.50000000','shipping_tax_amount'=>'0.00000000']],'address'=>[['order_id'=>'100','address_type'=>'shipping','country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'00001','address_1'=>'PRIVATE-Q05-ADDRESS','address_2'=>'']]];}else{
    $this->physical['order']=[['ID'=>'100','post_type'=>'shop_order','post_status'=>'wc-pending']];$values=['_order_key'=>'wc_order_staging_synthetic','_order_currency'=>'GHS','_order_total'=>'37.50','_order_tax'=>'0','_order_shipping'=>'12.50','_order_shipping_tax'=>'0','_customer_user'=>'0','_shipping_country'=>'GH','_shipping_state'=>'AA','_shipping_city'=>'Accra','_shipping_postcode'=>'00001','_shipping_address_1'=>'PRIVATE-Q05-ADDRESS','_shipping_address_2'=>''];foreach($values as $key=>$value){$this->physical['order_meta'][]=['meta_key'=>$key,'meta_value'=>$value];}
   }
  }
 }
 final class NoEffectSession implements OperationSession {
  public bool $active=false,$retired=false;public int $reads=0;public array $fences=[];
  public function __construct(private NoEffectFactory $factory){}
  public function site_id():int{return $this->factory->mode==='foreign_session'?2:1;}
  public function table_prefix():string{return 'wp_';}
  public function charset_collate():string{return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';}
  public function begin():bool{if($this->retired)return false;return $this->active=true;}
  public function commit():OperationCommitResult{throw new LogicException('No-effect evidence must not commit.');}
  public function rollback():bool{if($this->retired||!$this->active)return false;$this->active=false;++$this->factory->rollbacks;return $this->factory->mode!=='rollback_unknown';}
  public function retire():bool{$this->retired=true;$this->active=false;++$this->factory->retirements;return $this->factory->mode!=='retire_unknown';}
  public function is_retired():bool{return $this->retired;}
  public function in_transaction():bool{return $this->active&&!$this->retired;}
  public function validate_tables(array $tables):bool{return $this->active&&$this->factory->mode!=='invalid_tables';}
  public function query(string $sql):int|false{throw new LogicException('No-effect evidence must not write.');}
  public function get_row(string $sql):array|null|false{
   if(str_contains($sql,'option_name')){return ['option_value'=>str_contains($sql,'cetech_de_db_version')?'7':serialize(['status'=>'success','to_version'=>'7','migration_id'=>OperationStoreReadiness::MIGRATION_ID])];}
   if(str_starts_with($sql,'SHOW TABLE STATUS'))return ['Engine'=>'InnoDB','Collation'=>'utf8mb4_unicode_ci'];
   $rows=$this->get_results($sql);return false===$rows?false:($rows[0]??null);
  }
  public function get_results(string $sql):array|false{
   $this->factory->sql[]=$sql;
   if(str_starts_with($sql,'SHOW ')){$suffix=str_contains($sql,'operation_changes')?'operation_changes':'operation_records';if(str_contains($sql,'COLUMNS')){$out=[];foreach(OperationStoreSchema::columns($suffix) as $name=>[$type,$nullable,$default,$extra,$collation]){$out[]=['Field'=>$name,'Type'=>$type,'Null'=>$nullable?'YES':'NO','Default'=>$default,'Extra'=>$extra,'Collation'=>$collation==='site'?'utf8mb4_unicode_ci':$collation];}return $out;}$out=[];foreach(OperationStoreSchema::indexes($suffix) as $name=>$index){foreach($index['columns'] as $i=>$column){$out[]=['Key_name'=>$name,'Seq_in_index'=>(string)($i+1),'Non_unique'=>$index['unique']?'0':'1','Sub_part'=>null,'Index_type'=>'BTREE','Collation'=>'A','Column_name'=>$column];}}return $out;}
   ++$this->reads;if(preg_match('/(?:order_id|id|ID|post_id)=101\b/',$sql))return [];$current=str_ends_with($sql,' FOR UPDATE');$this->fences[]=$current;
   if($this->factory->mode==='sql_unknown'){$this->retired=true;return false;}
   if($this->factory->mode==='retired_after_read'&&$this->reads===2)$this->retired=true;
   if($this->factory->mode==='transaction_lost'&&$this->reads===2)$this->active=false;
   $key=match(true){str_contains($sql,'woocommerce_order_itemmeta')=>'item_meta',str_contains($sql,'woocommerce_order_items')=>'items',str_contains($sql,'wc_order_operational_data')=>'money',str_contains($sql,'wc_order_addresses')=>'address',str_contains($sql,'wc_orders_meta'),str_contains($sql,'postmeta')=>'order_meta',default=>'order'};
   return $this->factory->physical[$key];
  }
  public function prepare(string $sql,mixed ...$args):string{$i=0;return preg_replace_callback('/%[ds]/',static function(array $match)use($args,&$i):string{$v=$args[$i++];return $match[0]==='%d'?(string)$v:"'".str_replace("'","''",(string)$v)."'";},$sql);}
  public function errno():int{return 0;}
  public function insert_id():int{return 0;}
 }
 function get_current_blog_id():int{return $GLOBALS['blog_id'];}
 function sanitize_key(string $key):string{return preg_replace('/[^a-z0-9_\-]/','',strtolower($key));}
 function fixture():array {

		$draft = CartQuoteFixtures::draft(); $customer = CustomerCartContext::fromArray( $draft->private_facts()['lines'][0]['customer_context'] );
		$tax_source = QuoteNativeTaxSource::from_private_facts( [ 'format_version' => 1, 'digest_version' => 2, 'currency' => 'GHS', 'precision' => 2, 'exempt' => true, 'tax_class' => '', 'location_digest' => QuoteFixtures::digest( 'tax_location' ), 'rounding' => 'per_line', 'tax_enabled' => false, 'source' => [ 'option_rows' => [], 'tax_rows' => [], 'tax_class_rows' => [], 'tax_location_rows' => [], 'method_rows' => [], 'customer_rows' => [], 'selectors' => [ 'option_names' => [ 'woocommerce_currency' ], 'tax_class' => '', 'method_instance_ids' => [ 0 ], 'session_key' => 'fixture-session', 'customer_id' => 0, 'site_id' => 1, 'table_prefix' => 'wp_' ] ] ] );
		$group = DeliveryGroupIdentity::forHistorical( $draft->private_facts()['lines'][0]['selection'], $customer ); $component = NativeCartQuotePreparation::component_key( $group );
		$data = LegacyQuoteProviderFixtures::context()->private_facts(); $data['tax']['context_digest'] = $tax_source->digest(); $data['destination']['key_epoch'] = $draft->owner()->key_epoch(); $data['lines'][0]['component_key'] = $component; $data['groups'][0]['component_key'] = $component; $context = QuoteContext::from_array( $data );
		$terms = LegacyQuoteProviderFixtures::terms()->private_facts(); $terms['groups'][0]['component_key'] = $component; $terms['groups'][0]['native_tax_receipt']['context_digest'] = $tax_source->digest(); $terms = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms::from_array( $terms );
		$old = QuoteStorageFixtures::quote( state: 'accepted' ); $id = $old->header()->id(); $ref = QuoteFixtures::reference( $id );
		$header = QuoteHeader::issue( $id, $draft->owner(), $context, $terms, QuoteFixtures::time(), $old->header()->namespace_hashes(), 'legacy_fixed_base_v1', 1, $ref );
		$row = array_replace( $old->row(), [ 'profile_code' => 'legacy_fixed_base_v1', 'owner_digest' => $draft->owner()->digest(), 'principal_hash' => $draft->owner()->facts()['principal_hash'], 'header_json' => $header->to_private_json(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'private_body_json' => QuoteJson::encode( [ 'context' => $context->private_facts(), 'terms' => $terms->private_facts() ] ) ] );
		$quote = QuoteStoredRow::from_row( $row );
		$guard = new class implements QuoteCurrentEvidenceGuard { public function tables( OperationSession $s ): array { return []; } public function verify( OperationSession $s, QuoteOwner $o, QuoteContext $c ): bool { return true; } };
		$evidence = new QuotePlacementEvidence( $quote, $ref, $header, $context, $guard, static fn(): bool => true, QuoteFixtures::time()->plus_seconds( 2 ), $draft, native_tax_source: $tax_source );
		$order = new WC_Order( $group ); $factory = new NoEffectFactory( $order );
		$stager = new QuoteNativeOrderStager( $factory, static fn(): \WC_Order => clone $factory->order );
		$binding = QuoteStorageFixtures::binding( $quote );
		return [ $stager, $order, $evidence, $binding, $factory ];
 }

 $GLOBALS['blog_id']=1;NoEffectProbe::$hpos=($argv[1]??'hpos')==='hpos';$kind=$argv[2]??'none';$mode=$argv[3]??'matching';
 [$stager,$order,$evidence,$binding,$factory]=fixture();$quote=$evidence->quote_record();
 if($kind==='prepared1'){$stager->stage($order,$evidence,$binding,'classic');}
 $factory->snapshot();$initial_owned=QuoteNativeOrderHistory::owned($order);
 if(str_starts_with($mode,'capture_')){$factory->mode=substr($mode,8);}
 if($mode==='capture_foreign_order'){$order->mutate('id');}
 if($mode==='capture_foreign_site'){$GLOBALS['blog_id']=2;}
 if($mode==='capture_native_marker'){$order->update_meta_data(DeliveryQuoteSnapshotEnvelope::META_FORMAT,'unknown');}
 if(str_starts_with($mode,'capture_physical_')){
  $factory->mode='physical_marker';$which=substr($mode,17);$line=str_starts_with($which,'line_');$name=$line?substr($which,5):$which;
  $outer_values=['outer3'=>'{"snapshot_version":"3"}','outer_future'=>'{"snapshot_version":999}','outer_escaped'=>'{"snapsh\\u006ft_version":"\\u0033"}','outer_malformed'=>'{"snapshot_version":"3",BROKEN}','outer_unknown'=>'{"snapshot_version":"future"}','outer_duplicate'=>'{"snapshot_version":"3","snapshot_version":"2"}'];
  $key=match($name){'reference'=>QuoteNativeOrderFacts::META_REFERENCE,'draft'=>QuoteNativeOrderFacts::META_DRAFT,'tax_source'=>QuoteNativeOrderFacts::META_TAX_SOURCE,'linekey'=>QuoteNativeOrderFacts::META_LINE_KEY,'unknown'=>'_cetech_de_quote_unknown_private','version3'=> $line?OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION:OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION,'packet','outer3','outer_future','outer_escaped','outer_malformed','outer_unknown','outer_duplicate'=>$line?OrderDeliverySnapshot::META_LINE_SNAPSHOT:OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT,default=>DeliveryQuoteSnapshotEnvelope::META_FORMAT};
  $value=$outer_values[$name]??($name==='packet'?' {"deli\\u0076ery_quote":BROKEN}':($name==='version3'?'3':'unknown'));
  if($line||$which==='linekey'){$factory->physical['item_meta'][]=['order_item_id'=>'901','meta_key'=>$key,'meta_value'=>$value];}else{$factory->physical['order_meta'][]=['meta_key'=>$key,'meta_value'=>$value];}
 }
 $guard=QuotePlacementNoEffectNativeGuard::capture($factory,$stager,$order,$quote,$kind==='prepared1'?$binding:null);$capture_owners=$factory->opens;
 $expected=$kind==='prepared1'?$binding:null;$original=$quote;if($factory->mode==='')$factory->mode='verify';
 if(str_starts_with($mode,'raw_')){$order->mutate(substr($mode,4));}
 if($mode==='global_site'){$GLOBALS['blog_id']=2;}
 if($mode==='quote_drift'){$original=QuoteStorageFixtures::quote(state:'accepted');}
 if($mode==='binding_drift'){$expected=QuoteStorageFixtures::binding($quote,id:2);}
 if($mode==='binding_absent'){$expected=null;}
 if($mode==='binding_foreign'){$expected=QuoteStorageFixtures::binding($quote,order:101);}
 if(str_starts_with($mode,'physical_')){$field=substr($mode,9);switch($field){case 'money':if(NoEffectProbe::$hpos){$factory->physical['order'][0]['total_amount']='999';}else{foreach($factory->physical['order_meta'] as &$row){if($row['meta_key']==='_order_total')$row['meta_value']='999';}unset($row);}break;case 'item_money':$factory->physical['item_meta'][3]['meta_value']='999';break;case 'duplicate':$factory->physical['item_meta'][]=$factory->physical['item_meta'][0];break;case 'missing':array_shift($factory->physical['items']);break;case 'metadata':$factory->physical['order_meta'][]=['meta_key'=>QuoteNativeOrderFacts::META_REFERENCE,'meta_value'=>'changed'];break;case 'address':if(NoEffectProbe::$hpos)$factory->physical['address'][0]['address_1']='changed';else{foreach($factory->physical['order_meta'] as &$row){if($row['meta_key']==='_shipping_address_1')$row['meta_value']='changed';}unset($row);}break;}}
 if(in_array($mode,['sql_unknown','retired_after_read','transaction_lost','foreign_session'],true)){$factory->mode=$mode;}
 $session=$factory->open();$session->begin();if($mode==='retired')$session->retire();if($mode==='no_transaction')$session->rollback();
 $before=NoEffectProbe::$getters;NoEffectProbe::$locked=true;$unchanged=$guard?->unchanged()??false;$verified=$guard?->verify($session,$original,$expected)??false;NoEffectProbe::$locked=false;
 $pure_getter_delta=NoEffectProbe::$getters-$before;
 $serialization=false;$json=false;if($guard){try{serialize($guard);}catch(LogicException){$serialization=true;}try{json_encode($guard,JSON_THROW_ON_ERROR);}catch(LogicException){$json=true;}}
 echo json_encode(['captured'=>$guard!==null,'verified'=>$verified,'unchanged'=>$unchanged,'kind'=>$kind,'initial_owned'=>$initial_owned,'history_supported'=>$kind==='prepared1'?QuoteNativeOrderHistory::verify($order):false,'binding_revision'=>$binding->revision(),'binding_digests_absent'=>$binding->row()['snapshot_digest']===null&&$binding->row()['context_digest']===null,'capture_owners'=>$capture_owners,'capture_retired'=>array_map(static fn(NoEffectSession $s):bool=>$s->retired,array_slice($factory->sessions,0,$capture_owners)),'locked_getter_delta'=>$pure_getter_delta,'locked_getters'=>NoEffectProbe::$locked_getters,'reads'=>$session->reads,'fences'=>$session->fences,'tables'=>$guard?->tables($session)??[],'serialization_refused'=>$serialization,'json_refused'=>$json],JSON_THROW_ON_ERROR);
}
