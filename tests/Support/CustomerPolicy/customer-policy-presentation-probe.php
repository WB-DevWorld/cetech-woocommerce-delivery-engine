<?php
/** Trusted presentation port double only: this probe certifies no private authority API. */
declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy {
 final class CustomerPolicyFacade {
  public array$calls=[];public bool$on=true;public array$views=[];public array$saved=[];
  public function enabled():bool{$this->calls[]='enabled';return$this->on;}
  public function for_cart_lines(array$cart):array{$this->calls[]='cart';return$this->views;}
  public function saved_order(\WC_Order$order,callable$authorize):array{$this->calls[]='saved';if(!$authorize())throw new \RuntimeException('Unauthorized');return$this->saved;}
 }
}
namespace {
 class WooCommerce{}
 class WC_Cart {public function __construct(public array$lines=[]){}public function get_cart(){return$this->lines;}}
 class WC_Order_Item_Product {public function get_id(){return 11;}public function get_name($context=''){return'<Widget>';}public function get_quantity($context=''){return 2;}public function get_product(){throw new RuntimeException('Current product lookup forbidden');}}
 class WC_Order {public function get_id(){return 51;}public function get_items($type=''){return[11=>new WC_Order_Item_Product()];}}
 class WC_Email {public function is_customer_email(){return true;}}
 $GLOBALS['wc_calls']=0;$GLOBALS['assets']=[];$GLOBALS['nonces']=[];$GLOBALS['hooks']=[];$GLOBALS['wc']=(object)['cart'=>new WC_Cart(['native-key'=>['key'=>'native-key','quantity'=>2]])];
 function WC(){++$GLOBALS['wc_calls'];return$GLOBALS['wc'];}function __($s,$d=''){return$s;}function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}function esc_attr($s){return esc_html($s);}function esc_html__($s,$d=''){return esc_html($s);}function wp_create_nonce($action){$GLOBALS['nonces'][]=$action;return'native-nonce';}function add_filter($hook,$callback,$priority=10,$argc=1){$GLOBALS['hooks'][]=[$hook,$priority,$argc];}function add_action($hook,$callback,$priority=10,$argc=1){add_filter($hook,$callback,$priority,$argc);}function is_cart(){return true;}function is_checkout(){return false;}function wp_register_script(...$args){$GLOBALS['assets'][]=$args[0];}function wp_enqueue_script($handle){$GLOBALS['assets'][]=$handle;}
 require dirname(__DIR__,3).'/src/Core/Requirements.php';require dirname(__DIR__,3).'/src/Presentation/Shared/CustomerPolicyRenderer.php';require dirname(__DIR__,3).'/src/Presentation/Frontend/CustomerPolicyCartPresenter.php';require dirname(__DIR__,3).'/src/Presentation/Frontend/CustomerPolicySavedPresenter.php';
 $family=['disposition'=>'assigned','customer_title'=>'Returns','summary'=>'Summary','full_text'=>'<script>literal</script>','exceptions'=>'Box'];$view=['version'=>1,'status'=>'review_required','ticket'=>'opaque-original-ticket','families'=>(object)['return_policy_v1'=>$family,'refund_policy_v1'=>$family]];
 $facade=new CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyFacade();$facade->views=['native-key'=>$view];$saved=$view;$saved['status']='saved';$saved['ticket']=null;$facade->saved=[11=>$saved];$req=new CetechDeliveryEngine\Core\Requirements();$cart=new CetechDeliveryEngine\Presentation\Frontend\CustomerPolicyCartPresenter($req,$facade);$case=$argv[1]??'';$rows=[];$authorize_calls=0;ob_start();
 switch($case){
 case'cart-controls':$cart->cart_controls($GLOBALS['wc']->cart->lines['native-key'],'native-key');break;
 case'checkout-controls':$cart->checkout_controls();break;
 case'item-data':$rows=$cart->item_data([],$GLOBALS['wc']->cart->lines['native-key']);break;
 case'foreign-line':$rows=$cart->item_data([],['key'=>'foreign','quantity'=>2]);$cart->cart_controls(['key'=>'foreign','quantity'=>2],'foreign');break;
 case'off':$facade->on=false;$cart->item_data([],$GLOBALS['wc']->cart->lines['native-key']);$cart->checkout_controls();$cart->enqueue_assets();break;
 case'saved-order':case'saved-email-html':case'saved-email-plain':case'saved-denied':case'saved-admin-email':case'saved-default-deny':
  $facade->on=false;$authorize=static function()use(&$authorize_calls,$case):bool{++$authorize_calls;return'saved-denied'!==$case;};$presenter=new CetechDeliveryEngine\Presentation\Frontend\CustomerPolicySavedPresenter($req,$facade,'saved-default-deny'===$case?null:$authorize);$order=new WC_Order();if(in_array($case,['saved-order','saved-denied','saved-default-deny'],true))$presenter->render_order($order);else$presenter->render_email($order,'saved-admin-email'===$case,'saved-email-plain'===$case,new WC_Email());break;
 case'hooks':$cart->register();(new CetechDeliveryEngine\Presentation\Frontend\CustomerPolicySavedPresenter($req,$facade))->register();break;
 default:throw new RuntimeException('Unknown finite presentation probe');
 }
 $html=ob_get_clean();echo json_encode(['html'=>$html,'rows'=>$rows,'calls'=>$facade->calls,'wc_calls'=>$GLOBALS['wc_calls'],'assets'=>$GLOBALS['assets'],'nonces'=>$GLOBALS['nonces'],'hooks'=>$GLOBALS['hooks'],'authorize_calls'=>$authorize_calls],JSON_THROW_ON_ERROR);
}
