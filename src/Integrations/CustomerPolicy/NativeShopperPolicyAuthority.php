<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\CustomerPolicy;
use CetechDeliveryEngine\Application\Cart\{CartDeliverySelectionCapture,CartDeliverySelectionSessionData};
use CetechDeliveryEngine\Application\CustomerPolicy\{CustomerPolicyReadGrant,CustomerPolicySessionOwner};
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerPolicy\PolicyJson;

/** Actual native session and published resource possession; no nonce/catalog grant substitution. */
final class NativeShopperPolicyAuthority {
 private readonly \Closure $available;private readonly ?\Closure $products;private readonly ?\Closure $owners;private readonly ?\Closure $cart_lines;private readonly ?\Closure $bindings;
 public function __construct(callable $available_option,?callable $product_loader=null,?callable $owner_loader=null,?callable $cart_line_loader=null,?callable $binding_loader=null){$this->available=\Closure::fromCallable($available_option);$this->products=null===$product_loader?null:\Closure::fromCallable($product_loader);$this->owners=null===$owner_loader?null:\Closure::fromCallable($owner_loader);$this->cart_lines=null===$cart_line_loader?null:\Closure::fromCallable($cart_line_loader);$this->bindings=null===$binding_loader?null:\Closure::fromCallable($binding_loader);}
 public function product(int $product_id,int $variation_id,string $quantity,int $real_offer_id,CustomerCartContext $context,string $display_key):CustomerPolicyReadGrant{
  $captured=$this->product_facts($product_id,$variation_id,$quantity,$real_offer_id,$context,$display_key);
  if(!$this->option($captured['frame'],$context,null,$display_key))self::deny();
  return CustomerPolicyReadGrant::captured($captured['frame'],$captured['binding'],function(array$f,array$b)use($captured,$product_id,$variation_id,$quantity,$real_offer_id,$context,$display_key):bool{try{return $f===$captured['frame']&&$b===$captured['binding']&&$this->product_facts($product_id,$variation_id,$quantity,$real_offer_id,$context,$display_key)===$captured&&$this->option($f,$context,null,$display_key)&&$this->product_facts($product_id,$variation_id,$quantity,$real_offer_id,$context,$display_key)===$captured;}catch(\Throwable){return false;}});
 }
 public function cart(string $key):CustomerPolicyReadGrant{
  $captured=$this->cart_facts($key);if(!$this->option($captured['frame'],$captured['context'],$captured['line'],$captured['display_key']))self::deny();
  return CustomerPolicyReadGrant::captured($captured['frame'],$captured['binding'],function(array$f,array$b)use($captured,$key):bool{try{$now=$this->cart_facts($key);return $f===$captured['frame']&&$b===$captured['binding']&&$this->same_cart($now,$captured)&&$this->option($f,$now['context'],$now['line'],$now['display_key'])&&$this->same_cart($this->cart_facts($key),$captured);}catch(\Throwable){return false;}});
 }
 public function session_owner():CustomerPolicySessionOwner{
  $owner=$this->owner();$binding=$this->binding($owner['site_id']);$subject=$this->subject($owner);
  return CustomerPolicySessionOwner::captured($owner['site_id'],$owner['actor_id'],$subject,$binding,function(int$site,int$actor,string$s,array$b)use($owner,$binding,$subject):bool{try{return $site===$owner['site_id']&&$actor===$owner['actor_id']&&$s===$subject&&$b===$binding&&$this->owner()===$owner&&$this->binding($site)===$binding;}catch(\Throwable){return false;}});
 }
 public static function context_digest(CustomerCartContext|array $context):string{
  $raw=$context instanceof CustomerCartContext?$context->toArray():$context;$parsed=CustomerCartContext::fromArray($raw);
  if(!$parsed instanceof CustomerCartContext||!$parsed->isDelivery()||($parsed->delivery_offer_id??0)<1||$parsed->toArray()!==$raw)self::deny();
  return hash('sha256','cetech-customer-policy-native-context-v1:'.PolicyJson::encode($raw,65536));
 }
 private function product_facts(int $product_id,int $variation_id,string $quantity,int $offer,CustomerCartContext $context,string $key):array{
  if($product_id<1||$variation_id<0||$offer<1||''===$key||strlen($key)>512||1!==preg_match('//u',$key)||!$context->isDelivery()||$context->delivery_offer_id!==$offer)self::deny();
  $owner=$this->owner();$binding=$this->binding($owner['site_id']);$product=$this->product_row($product_id);if($product['is_variation']||!$product['visible']||!$product['viewable'])self::deny();$variation=null;
  if($variation_id>0){$variation=$this->product_row($variation_id);if(!$product['is_variable']||!$variation['is_variation']||$variation['parent_id']!==$product_id||!$variation['visible']||!$variation['viewable'])self::deny();}
  $frame=CustomerPolicyReadGrant::checked_frame(['site_id'=>$owner['site_id'],'actor_id'=>$owner['actor_id'],'session_subject'=>$this->subject($owner),'product_id'=>$product_id,'variation_id'=>$variation_id,'parent_id'=>$variation_id>0?$product_id:0,'quantity'=>$quantity,'real_offer_id'=>$offer,'context_digest'=>self::context_digest($context),'cart_key'=>'','purpose'=>'product']);
  return ['frame'=>$frame,'binding'=>$binding,'owner'=>$owner,'product'=>$product,'variation'=>$variation,'display_key'=>$key];
 }
 private function cart_facts(string $key):array{
  if(''===$key||strlen($key)>256||1!==preg_match('//u',$key))self::deny();$owner=$this->owner();$contents=null===$this->cart_lines?$owner['cart']->get_cart():null;$line=null===$this->cart_lines?($contents[$key]??null):($this->cart_lines)($key,$owner['cart']);
  if(!is_array($line)||!empty($line[CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY]))self::deny();$pid=self::integer($line['product_id']??null);$vid=self::integer($line['variation_id']??0,true);$quantity=self::quantity($line['quantity']??null);
  $context=CustomerCartContext::fromCartItem($line);if(!$context instanceof CustomerCartContext||($line[CustomerCartContext::CART_KEY]??null)!==$context->toArray())self::deny();$intent=CartDeliverySelectionSessionData::normalizeIntent($line[CartDeliverySelectionCapture::CART_SELECTION_KEY]??null);
  if(null===$intent||$intent['product_id']!==$pid||($intent['variation_id']??0)!==$vid||'delivery'!==$intent['fulfilment_choice']||($intent['delivery_offer_id']??0)<1||$intent['delivery_offer_id']!==$context->delivery_offer_id)self::deny();
  if(null===$this->cart_lines){$data=$line['data']??null;if(!$data instanceof \WC_Product||$data->get_id()!==($vid>0?$vid:$pid))self::deny();}
  $public=$this->product_facts($pid,$vid,$quantity,$intent['delivery_offer_id'],$context,$intent['display_key']);if($public['owner']!==$owner)self::deny();$frame=$public['frame'];$frame['purpose']='cart';$frame['cart_key']=$key;
  return ['frame'=>CustomerPolicyReadGrant::checked_frame($frame),'binding'=>$public['binding'],'owner'=>$owner,'product'=>$public['product'],'variation'=>$public['variation'],'line'=>$line,'context'=>$context,'display_key'=>$intent['display_key']];
 }
 private function option(array $frame,CustomerCartContext $context,?array $line,string $key):bool{$before=$context->toArray();return true===($this->available)($frame,$context,$line,$key)&&$context->toArray()===$before;}
 private function same_cart(array$a,array$b):bool{if($a['context']->toArray()!==$b['context']->toArray())return false;unset($a['context'],$b['context']);return $a===$b;}
 private function product_row(int$id):array{
  if(null!==$this->products){$row=($this->products)($id);}
  else{
   if(!function_exists('wc_get_product')||!function_exists('get_post'))self::deny();$p=wc_get_product($id);$post=get_post($id);
   if(!$p instanceof \WC_Product||$p->get_id()!==$id||!$post instanceof \WP_Post||$post->ID!==$id||'publish'!==$p->get_status('edit')||''!==$post->post_password)self::deny();
   $variation=$p instanceof \WC_Product_Variation;$parent=$variation?$p->get_parent_id('edit'):0;if(($post->post_type??null)!==($variation?'product_variation':'product')||($variation&&(!is_int($parent)||$parent<1||($post->post_parent??null)!==$parent)))self::deny();$row=['id'=>$id,'parent_id'=>$parent,'is_variation'=>$variation,'is_variable'=>$p->is_type('variable'),'visible'=>$variation?$p->variation_is_visible():$p->is_visible(),'viewable'=>'publish'===$post->post_status,'status'=>$post->post_status,'modified'=>$post->post_modified_gmt,'catalog_visibility'=>$p->get_catalog_visibility('edit')];
   if('hidden'===$row['catalog_visibility'])$row['visible']=false;
  }
  if(!is_array($row)||($row['id']??null)!==$id||!is_int($row['parent_id']??null))self::deny();foreach(['is_variation','is_variable','visible','viewable']as$k)if(!is_bool($row[$k]??null))self::deny();return $row;
 }
 private function owner():array{
  if(null!==$this->owners){$o=($this->owners)();}
  else{
   if(!function_exists('WC')||!function_exists('get_current_blog_id')||!function_exists('get_current_user_id'))self::deny();$wc=WC();$session=$wc->session??null;$cart=$wc->cart??null;
   if(!$session instanceof \WC_Session_Handler||get_class($session)!==\WC_Session_Handler::class||!$cart instanceof \WC_Cart||!$session->has_session())self::deny();$id=$session->get_customer_id();if(!is_string($id)&&!is_int($id))self::deny();
   $o=['site_id'=>get_current_blog_id(),'actor_id'=>get_current_user_id(),'session_id'=>(string)$id,'cookie'=>$session->get_session_cookie(),'wc'=>$wc,'session'=>$session,'cart'=>$cart,'database'=>$GLOBALS['wpdb']??null,'auth_session'=>function_exists('wp_get_session_token')?wp_get_session_token():''];
  }
  $keys=['site_id','actor_id','session_id','cookie','wc','session','cart','database','auth_session'];if(!is_array($o)||count($o)!==count($keys)||array_diff($keys,array_keys($o))||!is_int($o['site_id'])||$o['site_id']<1||!is_int($o['actor_id'])||$o['actor_id']<0||!is_string($o['session_id'])||''===$o['session_id']||'0'===$o['session_id']||strlen($o['session_id'])>256||1!==preg_match('//u',$o['session_id'])||(!is_array($o['cookie'])&&false!==$o['cookie'])||!is_string($o['auth_session'])||strlen($o['auth_session'])>512||($o['actor_id']>0&&(''===$o['auth_session']||(string)$o['actor_id']!==$o['session_id'])))self::deny();foreach(['wc','session','cart','database']as$k)if(!is_object($o[$k]))self::deny();return $o;
 }
 private function binding(int$site):array{
  if(null!==$this->bindings){$b=($this->bindings)();}else{$db=$GLOBALS['wpdb']??null;if(!is_object($db)||get_class($db)!==\wpdb::class||(defined('WP_CONTENT_DIR')&&is_file(WP_CONTENT_DIR.'/db.php'))||($db->options??null)!==($db->prefix??'').'options')self::deny();$b=['site_id'=>get_current_blog_id(),'prefix'=>$db->prefix];}
  if(!is_array($b)||$b!==['site_id'=>$site,'prefix'=>$b['prefix']??null]||!is_string($b['prefix'])||1!==preg_match('/\A[A-Za-z0-9_]{1,57}\z/D',$b['prefix']))self::deny();return $b;
 }
 private function subject(array$o):string{return hash('sha256','cetech-customer-policy-session-v1:'.json_encode([$o['site_id'],$o['actor_id'],$o['session_id'],$o['auth_session']],JSON_THROW_ON_ERROR));}
 private static function integer(mixed$v,bool$zero=false):int{if(is_int($v)&&$v>=($zero?0:1))return $v;self::deny();}
 private static function quantity(mixed$v):string{if(is_int($v)&&$v>0)return(string)$v;if(is_string($v)&&1===preg_match('/\A[1-9][0-9]*\z/D',$v))return $v;if(is_float($v)&&is_finite($v)&&$v>0&&$v<PHP_INT_MAX&&floor($v)===$v)return(string)(int)$v;self::deny();}
 public function __debugInfo():array{return ['native_policy_authority'=>'redacted'];}private static function deny():never{throw new \RuntimeException('Customer policy resource is unavailable.');}
}
