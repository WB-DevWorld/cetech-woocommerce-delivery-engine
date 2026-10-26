<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\CustomerPolicy;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementActivation,PromiseQuotePlacementActivation};
use CetechDeliveryEngine\Infrastructure\Persistence\{CustomerPolicyCurrentConnection,DataLifecycleOptionsStore};

/** New presentation/capture gate. Saved history does not depend on this decision. */
final class CustomerPolicyRuntimeGate {
 public const FLAGS=['enable_customer_policy_runtime','enable_product_delivery_selector','enable_cart_delivery_selection_capture','enable_checkout_delivery_selection_validation','enable_woocommerce_shipping_rate_calculation','enable_order_delivery_snapshot_persistence'];
 private readonly ?\Closure $factory;private readonly \Closure $options;private readonly ?\Closure $binding;
 public function __construct(private readonly FeatureFlags $flags,?callable $connection_factory=null,?callable $native_option_reader=null,?callable $binding_loader=null){$this->factory=null===$connection_factory?null:\Closure::fromCallable($connection_factory);$this->options=null===$native_option_reader?static fn(string$name):mixed=>get_option($name,null):\Closure::fromCallable($native_option_reader);$this->binding=null===$binding_loader?null:\Closure::fromCallable($binding_loader);}
 public function enabled():bool{try{foreach(self::FLAGS as$flag)if(!in_array(($this->options)($this->flags->option_name($flag)),[1,'1'],true))return false;return $this->off(($this->options)(QuotePlacementActivation::OPTION),($this->options)(PromiseQuotePlacementActivation::OPTION));}catch(\Throwable){return false;}}
 public function fresh():bool{
  if(!$this->enabled())return false;$c=null;$active=false;$closed=false;
  try{
   $binding=$this->native_binding();$c=null===$this->factory?CustomerPolicyCurrentConnection::from_wordpress($binding):($this->factory)($binding);
   if(!$c instanceof CustomerPolicyCurrentConnection||$c->site_id()!==$binding['site_id']||$c->table_prefix()!==$binding['prefix']||$c->in_transaction()||$c->is_retired())return false;$active=true;if(!$c->begin())return false;(new DataLifecycleOptionsStore())->assert_ready($c,$binding['site_id']);
   $names=array_map($this->flags->option_name(...),self::FLAGS);$all=[...$names,QuotePlacementActivation::OPTION,PromiseQuotePlacementActivation::OPTION];$quoted=implode(',',array_map(fn(string$n):string=>$c->prepare('%s',$n),$all));$table=$binding['prefix'].'options';
   $sql=$c->prepare("SELECT option_id,option_name,CASE WHEN OCTET_LENGTH(option_value)<=CASE WHEN option_name=%s THEN 65536 WHEN option_name=%s THEN 1024 ELSE 1 END THEN option_value ELSE NULL END AS option_value,autoload FROM `{$table}` FORCE INDEX (`option_name`) WHERE option_name IN ({$quoted}) ORDER BY option_id LIMIT 9",PromiseQuotePlacementActivation::OPTION,QuotePlacementActivation::OPTION);
   $rows=$c->get_results($sql);if(!is_array($rows)||count($rows)>8)return false;$map=[];foreach($rows as$r){$name=$r['option_name']??null;if(!is_string($name)||!in_array($name,$all,true)||array_key_exists($name,$map)||!is_string($r['option_value']??null)||!is_string($r['autoload']??null)||!in_array($r['autoload'],['yes','no','on','off','auto','auto-on','auto-off'],true)||!self::positive_id($r['option_id']??null))return false;$map[$name]=$r['option_value'];}
   foreach($names as$name)if(($map[$name]??null)!=='1')return false;if(!$this->off($map[QuotePlacementActivation::OPTION]??null,$map[PromiseQuotePlacementActivation::OPTION]??null))return false;
   if(!$c->rollback())return false;$active=false;if($c->in_transaction()||!$c->retire()||!$c->is_retired())return false;$closed=true;return $c->site_id()===$binding['site_id']&&$c->table_prefix()===$binding['prefix']&&$this->native_binding()===$binding&&$this->enabled();
  }catch(\Throwable){return false;}finally{if($c instanceof CustomerPolicyCurrentConnection&&!$closed){if($active){try{$c->rollback();}catch(\Throwable){}}try{$c->retire();}catch(\Throwable){}}}
 }
 private static function positive_id(mixed$v):bool{if(is_int($v))return$v>0;return is_string($v)&&1===preg_match('/\A[1-9][0-9]*\z/D',$v)&&(strlen($v)<strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)<=0));}
 private function off(mixed$q,mixed$p):bool{if((null!==$q&&!is_string($q))||(null!==$p&&!is_string($p)))return false;return false===QuotePlacementActivation::decode($q)['enabled']&&false===PromiseQuotePlacementActivation::decode($p)['enabled'];}
 private function native_binding():array{
  if(null!==$this->binding)$b=($this->binding)();else{$db=$GLOBALS['wpdb']??null;if(!is_object($db)||get_class($db)!==\wpdb::class||(defined('WP_CONTENT_DIR')&&is_file(WP_CONTENT_DIR.'/db.php'))||($db->options??null)!==($db->prefix??'').'options')throw new \RuntimeException();$b=['site_id'=>get_current_blog_id(),'prefix'=>$db->prefix];}
  if(!is_array($b)||count($b)!==2||!is_int($b['site_id']??null)||$b['site_id']<1||!is_string($b['prefix']??null)||1!==preg_match('/\A[A-Za-z0-9_]{1,57}\z/D',$b['prefix'])||array_diff(['site_id','prefix'],array_keys($b)))throw new \RuntimeException();return ['site_id'=>$b['site_id'],'prefix'=>$b['prefix']];
 }
}
