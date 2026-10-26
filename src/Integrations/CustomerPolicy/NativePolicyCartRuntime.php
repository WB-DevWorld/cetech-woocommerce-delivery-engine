<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\CustomerPolicy;

use CetechDeliveryEngine\Application\CustomerPolicy\{CartPolicyReceiptService,CustomerPolicyCurrentProvider,CustomerPolicyFacade};
use CetechDeliveryEngine\Application\Cart\{CartDeliverySelectionCapture,CartDeliverySelectionSessionData};
use CetechDeliveryEngine\Domain\CustomerPolicy\{PolicyJson,PolicyLineage};

/** Native session/add/review transport; posted keys or nonces never grant cart possession. */
final class NativePolicyCartRuntime {
	public const REVIEW_ACTION='cetech_de_customer_policy_review';
	public const NONCE_FIELD='cetech_de_policy_nonce';
	public const STORE_NAMESPACE='customer_policy_review';
	private readonly \Closure$enabled;
	private readonly \Closure$review;
	private bool$registered=false;
	private bool$store_registered=false;
	private ?object$store_request=null;
	private ?array$store_post=null;
	public function __construct(private readonly CartPolicyReceiptService$receipts,private readonly NativeShopperPolicyAuthority$authority,private readonly CustomerPolicyCurrentProvider$provider,callable$enabled,CustomerPolicyFacade|callable$review){$this->enabled=\Closure::fromCallable($enabled);$this->review=\Closure::fromCallable(is_callable($review)?$review:[$review,'accept_cart_review']);}
	public function register():void{
		if($this->registered){return;}$this->registered=true;
		add_action('wp_loaded',[$this,'bootstrap_session'],11);add_action('wp_loaded',[$this,'handle_no_js'],12);add_action('template_redirect',[$this,'handle_no_js'],1);
		add_action('woocommerce_add_to_cart',[$this,'after_add'],100,6);add_action('woocommerce_after_cart_item_quantity_update',[$this,'quantity_changed'],100,4);add_action('woocommerce_cart_item_removed',[$this,'line_removed'],100,2);add_action('woocommerce_cart_item_restored',[$this,'line_removed'],100,2);
		add_action('woocommerce_cart_emptied',[$this,'cart_emptied'],100,1);
		add_action('cetech_de_native_cart_frame_changed',[$this,'native_frame_changed'],100,3);
		add_filter('woocommerce_store_api_add_to_cart_data',[$this,'remember_store_add'],30,2);add_action('wp_ajax_'.self::REVIEW_ACTION,[$this,'handle_ajax']);add_action('wp_ajax_nopriv_'.self::REVIEW_ACTION,[$this,'handle_ajax']);add_action('woocommerce_blocks_loaded',[$this,'register_store_update'],6);$this->register_store_update();
	}
	public function is_enabled():bool{try{return true===($this->enabled)();}catch(\Throwable){return false;}}
	public function bootstrap_session():void{if($this->is_enabled()){self::establish_session();}}
	public static function establish_session():bool{
		try{if(!function_exists('WC')){return false;}$wc=WC();if(!is_object($wc)){return false;}if(!($wc->session??null)instanceof \WC_Session&&method_exists($wc,'initialize_session')){$wc->initialize_session();}if(!($wc->cart??null)instanceof \WC_Cart&&function_exists('wc_load_cart')&&function_exists('did_action')&&did_action('wp_loaded')){wc_load_cart();}$session=$wc->session??null;if(!$session instanceof \WC_Session||!($wc->cart??null)instanceof \WC_Cart){return false;}if(!$session->has_session()){if(headers_sent()||!method_exists($session,'set_customer_session_cookie')){return false;}$session->set_customer_session_cookie(true);}return true===$session->has_session();}catch(\Throwable){return false;}
	}
	public function register_store_update():void{if($this->store_registered||!function_exists('woocommerce_store_api_register_update_callback')){return;}woocommerce_store_api_register_update_callback(['namespace'=>self::STORE_NAMESPACE,'callback'=>[$this,'handle_store_update']]);$this->store_registered=true;}
	public function dispatch_review(array$data,string$method='POST',bool$ajax=true):array{
		if(!$this->is_enabled()){self::refuse(503);}if('POST'!==$method){self::refuse(405);}$keys=$ajax?['action','cart_key','ticket',self::NONCE_FIELD]:['cart_key','ticket',self::NONCE_FIELD];if(count($data)!==count($keys)||array_diff($keys,array_keys($data))||($ajax&&self::REVIEW_ACTION!==$data['action'])){self::refuse(400);}foreach($data as$key=>$v){$max='cart_key'===$key?PolicyLineage::MAX_CART_KEY_BYTES:256;if(!is_string($v)||''===$v||strlen($v)>$max||1!==preg_match('//u',$v)){self::refuse(400);}}if(!function_exists('wp_verify_nonce')||false===wp_verify_nonce($data[self::NONCE_FIELD],self::REVIEW_ACTION)){self::refuse(403);}try{$accepted=true===($this->review)($data['cart_key'],$data['ticket']);}catch(\Throwable){$accepted=false;}if(!$accepted){self::refuse(409);}return['accepted'=>true];
	}
	public function handle_ajax():void{try{$out=$this->dispatch_review(wp_unslash($_POST),(string)($_SERVER['REQUEST_METHOD']??''));}catch(\Throwable$e){$status=in_array($e->getCode(),[400,403,405,409,503],true)?$e->getCode():503;wp_send_json_error(['accepted'=>false],$status);return;}wp_send_json_success($out);}
	public function handle_store_update(mixed$data):void{try{if(!is_array($data)){self::refuse(400);}$this->dispatch_review($data,'POST',false);}catch(\Throwable$e){$status=in_array($e->getCode(),[400,403,405,409,503],true)?$e->getCode():503;if(class_exists('Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')){throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('cetech_de_customer_policy_review','Review the item policies again.',$status);}self::refuse($status);}}
	public function handle_no_js():void{
		if(!isset($_POST['cetech_de_policy_review'])||!$this->is_enabled()){return;}
		try{$raw=wp_unslash($_POST);$key=$raw['cetech_de_policy_review'];$tickets=$raw['cetech_de_policy_tickets']??null;if(!is_string($key)||!is_array($tickets)||!is_string($tickets[$key]??null)){self::refuse(400);}$this->dispatch_review(['cart_key'=>$key,'ticket'=>$tickets[$key],self::NONCE_FIELD=>$raw[self::NONCE_FIELD]??null],(string)($_SERVER['REQUEST_METHOD']??''),false);$url=wc_get_cart_url();if(!is_string($url)||''===$url){self::refuse(503);}wp_safe_redirect($url);exit;}catch(\Throwable){if(function_exists('wc_add_notice')){wc_add_notice('Review the item policies again.','error');}if(class_exists('WP_Error')&&isset($GLOBALS['wp'])){return;}throw new \RuntimeException('Review the item policies again.');}
	}
	public function remember_store_add(array$data,mixed$request):array{if(!$this->is_enabled()){return$data;}$this->store_request=null;$this->store_post=null;if($request instanceof \WP_REST_Request&&'POST'===$request->get_method()&&'/wc/store/v1/cart/add-item'===$request->get_route()){$extensions=$request->get_param('extensions');$extension=is_array($extensions)?($extensions['cetech-delivery-engine']??null):null;$ticket=is_array($extension)?($extension['policy_ticket']??null):null;if(is_string($ticket)&&strlen($ticket)<=256){$this->store_request=$request;$this->store_post=['ticket'=>$ticket];}}return$data;}
	public function after_add(mixed$key,mixed$product_id,mixed$quantity,mixed$variation_id=0,mixed$variation=[],mixed$item_data=[]):void{
		unset($product_id,$variation_id,$variation,$item_data);if(!$this->is_enabled()||!is_string($key)||''===$key||strlen($key)>PolicyLineage::MAX_CART_KEY_BYTES){return;}
		try{$grant=$this->authority->cart($key);$added=self::quantity($quantity);if($grant->frame()['quantity']!==$added){$this->receipts->invalidate_line($key,$this->authority->session_owner());return;}$ticket=$this->submitted_ticket($key);if(null===$ticket){$this->receipts->invalidate_line($key,$this->authority->session_owner());return;}$observations=$this->provider->capture_batch([$key=>$grant]);if(!$this->receipts->accept_after_add($ticket,$grant,$observations[$key])){$this->receipts->invalidate_line($key,$this->authority->session_owner());}}
		catch(\Throwable){try{$this->receipts->invalidate_line($key,$this->authority->session_owner());}catch(\Throwable){}}finally{$this->store_request=null;$this->store_post=null;}
	}
	private function submitted_ticket(string$key):?string{
		if(null!==$this->store_request){return$this->store_post['ticket']??null;}$cart=function_exists('WC')?(WC()->cart??null):null;if(!$cart instanceof \WC_Cart){return null;}$lines=$cart->get_cart();$line=$lines[$key]??null;if(!is_array($line)){return null;}$intent=CartDeliverySelectionSessionData::normalizeIntent($line[CartDeliverySelectionCapture::CART_SELECTION_KEY]??null);if(null===$intent){return null;}$post=function_exists('wp_unslash')?wp_unslash($_POST):$_POST;$tickets=$post['cetech_de_policy_tickets']??null;$ticket=is_array($tickets)?($tickets[$intent['display_key']]??null):null;if(null===$ticket){$ticket=$post['cetech_de_policy_ticket']??null;}return is_string($ticket)&&''!==$ticket&&strlen($ticket)<=256?$ticket:null;
	}
	public function quantity_changed(mixed$key,mixed$quantity,mixed$old,mixed$cart):void{if(!$this->is_enabled()||!is_string($key)||!$this->actual_cart($cart)){return;}try{if(self::quantity($quantity)===self::quantity($old)){return;}$this->receipts->invalidate_line($key,$this->authority->session_owner());}catch(\Throwable){}}
	public function line_removed(mixed$key,mixed$cart):void{if(!$this->is_enabled()||!is_string($key)||!$this->actual_cart($cart)){return;}try{$lines=$cart->get_cart();if(isset($lines[$key])){$this->receipts->invalidate_line($key,$this->authority->session_owner());}else{$this->receipts->forget_line($key,$this->authority->session_owner());}}catch(\Throwable){}}
	/** Actual plugin cart replacements are structural events, never display/read acknowledgements. */
	public function native_frame_changed(mixed$cart,array$before,array$after):void{
		if(!$this->is_enabled()||!$this->actual_cart($cart)||count($before)>200||count($after)>200){return;}
		try{if($cart->get_cart()!==$after){return;}$keys=array_values(array_unique([...array_keys($before),...array_keys($after)]));foreach($keys as$key){if(!is_string($key)||''===$key||strlen($key)>PolicyLineage::MAX_CART_KEY_BYTES||1!==preg_match('//u',$key)){return;}}$owner=$this->authority->session_owner();foreach($keys as$key){if(array_key_exists($key,$before)&&array_key_exists($key,$after)&&$before[$key]===$after[$key]){continue;}if(array_key_exists($key,$after)){$this->receipts->invalidate_line($key,$owner);}else{$this->receipts->forget_line($key,$owner);}}}catch(\Throwable){}
	}
	/** Mount independently of the new flag so a just-completed original can resolve after OFF. */
	public function cart_emptied(mixed$clear_persistent_cart=true):void{
		unset($clear_persistent_cart);
		try{if(!function_exists('WC')){return;}$wc=WC();$cart=$wc->cart??null;if(!$this->actual_cart($cart)||[]!==$cart->get_cart()){return;}$property=new \ReflectionProperty(\WC_Cart::class,'cart_contents');if(!$property->isInitialized($cart)){return;}$raw=static fn():mixed=>method_exists($property,'getRawValue')?$property->getRawValue($cart):$property->getValue($cart);if([]!==$raw()){return;}
			// Pure raw-object comparison only while SQL owns the lock; no Woo getter.
			$unchanged=static function()use($wc,$cart,$raw):bool{$global=$GLOBALS['woocommerce']??null;return$global===$wc&&($wc->cart??null)===$cart&&[]===$raw();};
			if(!$unchanged()){return;}$this->receipts->retire_completed_original($this->authority->session_owner(),$unchanged);
		}catch(\Throwable){}
	}
	private function actual_cart(mixed$cart):bool{try{return$cart instanceof \WC_Cart&&get_class($cart)===\WC_Cart::class&&function_exists('WC')&&(WC()->cart??null)===$cart;}catch(\Throwable){return false;}}
	private static function quantity(mixed$v):string{if(is_int($v)||is_string($v)){return PolicyJson::quantity((string)$v);}if(is_float($v)&&is_finite($v)&&$v>0&&$v<PHP_INT_MAX&&floor($v)===$v){return PolicyJson::quantity((string)(int)$v);}throw new \RuntimeException('Unsupported quantity.');}
	private static function refuse(int$status):never{throw new \RuntimeException('Review the item policies again.',$status);}
}
