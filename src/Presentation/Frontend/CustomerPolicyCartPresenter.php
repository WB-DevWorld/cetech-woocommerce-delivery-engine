<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Presentation\Frontend;
use CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyFacade;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\CustomerPolicyRenderer;

/** Customer wording never acknowledges a policy during display. */
final class CustomerPolicyCartPresenter {
	public const REVIEW_FIELD='cetech_de_policy_review';
	public const NONCE_FIELD='cetech_de_policy_nonce';
	public const NONCE_ACTION='cetech_de_customer_policy_review';
	public function __construct(private Requirements$requirements,private CustomerPolicyFacade$facade){}
	public function register():void{
		if(!$this->requirements->is_woocommerce_active()){return;}add_filter('woocommerce_get_item_data',[$this,'item_data'],20,2);add_action('woocommerce_after_cart_item_name',[$this,'cart_controls'],20,2);add_action('woocommerce_review_order_after_cart_contents',[$this,'checkout_controls'],20);add_action('wp_enqueue_scripts',[$this,'enqueue_assets'],5);
	}
	public function enqueue_assets():void{if(!$this->requirements->is_woocommerce_active()||!$this->facade->enabled()||!((function_exists('is_cart')&&is_cart())||(function_exists('is_checkout')&&is_checkout()))||[]===$this->views()){return;}CustomerPolicyRenderer::register_assets();wp_enqueue_script(CustomerPolicyRenderer::SCRIPT_HANDLE);}
	public function item_data(array$rows,array$line):array{$key=$this->actual_key($line);if(''===$key){return$rows;}$view=$this->views()[$key]??null;$html=CustomerPolicyRenderer::html($view);if(''!==$html){$rows[]=['key'=>__('Policies','cetech-woocommerce-delivery-engine'),'value'=>CustomerPolicyRenderer::plain($view),'display'=>'<div data-cetech-de-policy-cart-key="'.esc_attr($key).'">'.$html.'</div>'];}return$rows;}
	public function cart_controls(array$line,string$key):void{if($key!==$this->actual_key($line)){return;}echo$this->controls($key,$this->views()[$key]??null);}
	public function checkout_controls():void{foreach($this->views()as$key=>$view){$html=$this->controls($key,$view);if(''!==$html){echo'<tr class="cetech-de-policy-review-row"><td colspan="2">'.$html.'</td></tr>';}}}
	private function controls(string$key,mixed$value):string{$v=CustomerPolicyRenderer::normalize($value);if(null===$v||'review_required'!==$v['status']||null===$v['ticket']){return'';}return'<div class="cetech-de-policy-review" data-cetech-de-policy-controls="1" data-cetech-de-policy-cart-key="'.esc_attr($key).'">'.CustomerPolicyRenderer::ticket_input($v,$key).'<input type="hidden" name="'.self::NONCE_FIELD.'" value="'.esc_attr(wp_create_nonce(self::NONCE_ACTION)).'" /><button type="submit" class="button" name="'.self::REVIEW_FIELD.'" value="'.esc_attr($key).'" data-cetech-de-policy-review="1">'.esc_html__('Use these policies','cetech-woocommerce-delivery-engine').'</button><span role="status" data-cetech-de-policy-result="1"></span></div>';}
	private function views():array{if(!$this->facade->enabled()){return[];}try{return$this->facade->for_cart_lines($this->cart());}catch(\Throwable){return[];}}
	private function cart():array{$cart=function_exists('WC')?(WC()->cart??null):null;return$cart instanceof \WC_Cart?$cart->get_cart():[];}
	private function actual_key(array$line):string{if(!$this->facade->enabled()){return'';}$cart=$this->cart();$key=$line['key']??null;if(is_string($key)&&isset($cart[$key])&&$cart[$key]===$line){return$key;}foreach($cart as$key=>$actual){if(is_string($key)&&$actual===$line){return$key;}}return'';}
}
