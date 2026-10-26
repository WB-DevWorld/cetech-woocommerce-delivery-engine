<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Presentation\Frontend;
use CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyFacade;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Shared\CustomerPolicyRenderer;

/** Saved-only rendering, independent of writer flags and shipment cards. */
final class CustomerPolicySavedPresenter {
	public const ORDER_AUDIENCE='customer_order';public const EMAIL_AUDIENCE='customer_email';private readonly \Closure$authorize;
	public function __construct(private Requirements$requirements,private CustomerPolicyFacade$facade,?callable$authorize=null){$this->authorize=null===$authorize?static fn():bool=>false:\Closure::fromCallable($authorize);}
	public function register():void{if(!$this->requirements->is_woocommerce_active()){return;}add_action('woocommerce_order_details_after_order_table',[$this,'render_order'],20,1);add_action('woocommerce_email_after_order_table',[$this,'render_email'],20,4);}
	public function render_order(\WC_Order$order):void{if(!$this->requirements->is_woocommerce_active()||$order->get_id()<=0){return;}echo CustomerPolicyRenderer::saved_html($this->items($order,self::ORDER_AUDIENCE,null));}
	public function render_email(\WC_Order$order,bool$sent_to_admin,bool$plain_text,mixed$email=false):void{if(!$this->requirements->is_woocommerce_active()||$order->get_id()<=0||$sent_to_admin||!$email instanceof \WC_Email||!method_exists($email,'is_customer_email')||!$email->is_customer_email()){return;}$items=$this->items($order,self::EMAIL_AUDIENCE,$email);echo$plain_text?CustomerPolicyRenderer::saved_plain($items):CustomerPolicyRenderer::saved_html($items);}
	private function items(\WC_Order$order,string$surface,?\WC_Email$email):array{
		$authorize=function(mixed...$args)use($order,$surface,$email):bool{if(isset($args[0])&&$args[0]instanceof \WC_Order&&$args[0]!==$order){return false;}try{return true===($this->authorize)($order,$surface,$email);}catch(\Throwable){return false;}};if(!$authorize()){return[];}
		try{$views=$this->facade->saved_order($order,$authorize);}catch(\Throwable){return[];}if([]===$views){return[];}$items=[];$position=0;
		foreach($order->get_items('line_item')as$id=>$item){++$position;if(!$item instanceof \WC_Order_Item_Product||(int)$id<=0||(int)$id!==$item->get_id()||!isset($views[(int)$id])){return[];}$v=CustomerPolicyRenderer::normalize($views[(int)$id]);if(null===$v||'saved'!==$v['status']){return[];}$name=$item->get_name('edit');$qty=(string)$item->get_quantity('edit');$label=sprintf(__('Item %d','cetech-woocommerce-delivery-engine'),$position);if(is_string($name)&&1===preg_match('//u',$name)){$name=preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u',' ',$name)??'';preg_match_all('/./us',$name,$characters);$name=implode('',array_slice($characters[0],0,240));if(''!==$name){$label.=': '.$name;}}if(1===preg_match('/\A[1-9][0-9]*\z/D',$qty)){$label.=' × '.$qty;}$items[]=['label'=>$label,'view'=>$v];}
		return count($items)===count($views)?$items:[];
	}
}
