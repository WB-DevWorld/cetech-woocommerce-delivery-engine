<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Presentation\Shared;

/** Closed public projection. It never accepts, resolves or publishes a policy. */
final class CustomerPolicyRenderer {
	public const SCRIPT_HANDLE = 'cetech-de-customer-policy';
	public const FAMILIES = ['return_policy_v1','refund_policy_v1'];
	public const FIELDS = ['customer_title','summary','full_text','exceptions'];
	public const TICKET_FIELD = 'cetech_de_policy_tickets';
	private const MAX_TEXT_BYTES = 262144;
	public static function register_assets(): void {
		if (!function_exists('wp_register_script')) { return; }
		wp_register_script(self::SCRIPT_HANDLE, (defined('CETECH_DE_URL') ? CETECH_DE_URL : '').'assets/frontend/customer-policy.js', [], defined('CETECH_DE_VERSION') ? CETECH_DE_VERSION : '0', true);
	}
	public static function public_schema(): array {
		$text=['type'=>['string','null']];$family=['type'=>'object','additionalProperties'=>false,'required'=>['disposition',...self::FIELDS],'properties'=>['disposition'=>['type'=>'string','enum'=>['assigned','disabled','not_assigned','unresolved']],...array_fill_keys(self::FIELDS,$text)]];
		return ['type'=>['object','null'],'readonly'=>true,'additionalProperties'=>false,'required'=>['version','status','ticket','families'],'properties'=>['version'=>['type'=>'integer','enum'=>[1]],'status'=>['type'=>'string','enum'=>['ready','review_required','saved','unavailable','unsupported']],'ticket'=>['type'=>['string','null'],'maxLength'=>256],'families'=>['type'=>'object','additionalProperties'=>false,'required'=>self::FAMILIES,'properties'=>array_fill_keys(self::FAMILIES,$family)]]];
	}
	public static function normalize(mixed $value): ?array {
		$v=self::plain_object($value);if(null===$v||!self::keys($v,['version','status','ticket','families'])||1!==$v['version']||!is_string($v['status'])||!in_array($v['status'],['ready','review_required','saved','unavailable','unsupported'],true)){return null;}
		if(null!==$v['ticket']&&(!is_string($v['ticket'])||''===$v['ticket']||strlen($v['ticket'])>256||!self::text($v['ticket'],false))){return null;}
		if(in_array($v['status'],['saved','unavailable','unsupported'],true)&&null!==$v['ticket']){return null;}
		$families=self::plain_object($v['families']);if(null===$families||!self::keys($families,self::FAMILIES)){return null;}$out=[];$bytes=0;
		foreach(self::FAMILIES as$code){$f=self::plain_object($families[$code]);if(null===$f||!self::keys($f,['disposition',...self::FIELDS])||!is_string($f['disposition'])||!in_array($f['disposition'],['assigned','disabled','not_assigned','unresolved'],true)){return null;}
			if('unresolved'===$f['disposition']&&!in_array($v['status'],['unavailable','unsupported'],true)){return null;}$row=['disposition'=>$f['disposition']];
			foreach(self::FIELDS as$field){$s=$f[$field];if('assigned'===$f['disposition']){if(!is_string($s)||strlen($s)>self::MAX_TEXT_BYTES-$bytes||!self::text($s,'customer_title'!==$field)){return null;}$bytes+=strlen($s);}elseif(null!==$s){return null;}$row[$field]=$s;}$out[$code]=$row;
		}
		return ['version'=>1,'status'=>$v['status'],'ticket'=>$v['ticket'],'families'=>$out];
	}
	public static function html(mixed$value): string {
		$v=self::normalize($value);if(null===$v){return '';}
		if(in_array($v['status'],['unavailable','unsupported'],true)){return '<div class="cetech-de-customer-policies" data-cetech-de-policy-view="1" style="overflow-wrap:anywhere"><p role="status">'.esc_html(self::unavailable_message()).'</p></div>';}
		$html='review_required'===$v['status']?'<p role="status" data-cetech-de-policy-review-notice="1">'.esc_html(self::review_message()).'</p>':'';
		foreach(self::FAMILIES as$code){$f=$v['families'][$code];if('assigned'!==$f['disposition']){continue;}$html.='<section class="cetech-de-policy-family" data-cetech-de-policy-family="'.esc_attr($code).'"><h4 data-cetech-de-policy-field="customer_title">'.esc_html($f['customer_title']).'</h4><p data-cetech-de-policy-field="summary" style="white-space:pre-wrap">'.esc_html($f['summary']).'</p><details><summary>'.esc_html__('Read full policy','cetech-woocommerce-delivery-engine').'</summary><div data-cetech-de-policy-field="full_text" style="white-space:pre-wrap">'.esc_html($f['full_text']).'</div>';
			if(''!==$f['exceptions']){$html.='<h5>'.esc_html__('Exceptions','cetech-woocommerce-delivery-engine').'</h5><div data-cetech-de-policy-field="exceptions" style="white-space:pre-wrap">'.esc_html($f['exceptions']).'</div>';}$html.='</details></section>';
		}
		return ''===$html?'':'<div class="cetech-de-customer-policies" data-cetech-de-policy-view="1" style="overflow-wrap:anywhere">'.$html.'</div>';
	}
	private static function plain_object(mixed$value): ?array { // Internal plain object/list boundary, never magic serializers.
		if(is_array($value)&&([]===$value||!array_is_list($value))){return$value;}if($value instanceof \stdClass&&get_class($value)===\stdClass::class){return get_object_vars($value);}return null;
	}
	public static function plain(mixed$value): string {
		$v=self::normalize($value);if(null===$v){return'';}if(in_array($v['status'],['unavailable','unsupported'],true)){return self::unavailable_message();}$parts='review_required'===$v['status']?[self::review_message()]:[];
		foreach(self::FAMILIES as$code){$f=$v['families'][$code];if('assigned'===$f['disposition']){$parts[]=implode("\n\n",array_filter(array_map(static fn(string$key):string=>$f[$key],self::FIELDS),static fn(string$s):bool=>''!==$s));}}return implode("\n\n",$parts);
	}
	public static function ticket_input(mixed$value,string$key): string {
		$v=self::normalize($value);if(null===$v||null===$v['ticket']||''===$key||strlen($key)>512||1!==preg_match('//u',$key)){return'';}return '<input type="hidden" data-cetech-de-policy-ticket="1" name="'.esc_attr(self::TICKET_FIELD.'['.$key.']').'" value="'.esc_attr($v['ticket']).'" />';
	}
	public static function option_html(mixed$value,string$key): string {
		if(strlen($key)>512||!self::text($key,false)){return'';}$body=self::html($value).self::ticket_input($value,$key);return ''===$body?'':'<div data-cetech-de-policy-option="'.esc_attr($key).'">'.$body.'</div>';
	}
	public static function saved_html(array$items): string {return self::saved($items,false);}
	public static function saved_plain(array$items): string {return self::saved($items,true);}
	private static function saved(array$items,bool$plain): string {
		if(count($items)>200){return'';}$groups=[];foreach($items as$item){if(!is_array($item)||!self::keys($item,['label','view'])||!is_string($item['label'])||strlen($item['label'])>2048||!self::text($item['label'],false)){return'';}$v=self::normalize($item['view']);if(null===$v||'saved'!==$v['status']){return'';}$key=json_encode($v['families'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(!isset($groups[$key])){$groups[$key]=['view'=>$v,'labels'=>[]];}$groups[$key]['labels'][]=$item['label'];}
		$out=[];foreach($groups as$g){$body=$plain?self::plain($g['view']):self::html($g['view']);if(''===$body){continue;}$labels=implode(', ',$g['labels']);$out[]=$plain?$labels."\n\n".$body:'<section class="cetech-de-saved-policy"><p><strong>'.esc_html($labels).'</strong></p>'.$body.'</section>';}
		return implode($plain?"\n\n":'', $out);
	}
	public static function unavailable_message(): string {return __('Policies are temporarily unavailable. Review this item again before checkout.','cetech-woocommerce-delivery-engine');}
	public static function review_message(): string {return __('Review the policies for this item before continuing.','cetech-woocommerce-delivery-engine');}
	private static function keys(array$value,array$keys): bool {return count($value)===count($keys)&&[]===array_diff($keys,array_keys($value));}
	private static function text(string$value,bool$empty): bool {return ($empty||''!==trim($value))&&1===preg_match('//u',$value)&&0===preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value);}
}
