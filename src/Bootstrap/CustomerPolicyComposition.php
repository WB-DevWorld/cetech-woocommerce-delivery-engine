<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Application\Cart\{CartDeliverySelectionRevalidator,CartDeliverySelectionRevalidationResult};
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\CustomerContext\{LocationAwareDeliveryOptions,ProductPageQuoteContext};
use CetechDeliveryEngine\Application\CustomerPolicy\{CartPolicyReceiptService,CustomerPolicyCurrentProvider,CustomerPolicyFacade,CustomerPolicyFinalGuard,CustomerPolicyPreparedCheckout,PolicyNativeOrderFacts,PolicyNativeOrderStager,PolicySavedOrderReader};
use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuoteSessionStore,QuoteNativeOwnerResolver};
use CetechDeliveryEngine\Application\EmergencyControl\{EmergencyControlService,EmergencyCheckoutQuoteValidator};
use CetechDeliveryEngine\Application\Order\{OrderDeliverySnapshotPersister,QuoteNativeOrderHistory};
use CetechDeliveryEngine\Application\Selector\{ProductDeliveryOption,ProductDeliverySelectionValidator};
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\{CustomerPolicyCurrentSource,CustomerPolicyReceiptStore};
use CetechDeliveryEngine\Integrations\CustomerPolicy\{CustomerPolicyRuntimeGate,NativePolicyCartRuntime,NativeShopperPolicyAuthority,PolicyCheckoutRuntime,PolicySavedAudience};
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime;
use CetechDeliveryEngine\Presentation\Frontend\{CustomerPolicyCartPresenter,CustomerPolicySavedPresenter};
use CetechDeliveryEngine\Presentation\Shared\CustomerPolicyRenderer;

/** Server-owned lazy wiring; constructors establish no SQL unit or shopper session. */
final class CustomerPolicyComposition {
	public static function register(ServiceContainer $c):void {
		$c->singleton(CustomerPolicyRuntimeGate::class,static fn(ServiceContainer $c)=>new CustomerPolicyRuntimeGate($c->get(FeatureFlags::class)));
		$c->singleton(NativeShopperPolicyAuthority::class,static fn(ServiceContainer $c)=>new NativeShopperPolicyAuthority(static fn(array $frame,CustomerCartContext $context,?array $line,string $key):bool=>self::available($c,$frame,$context,$line,$key)));
		$c->singleton(CustomerPolicyCurrentSource::class,static fn(ServiceContainer $c)=>new CustomerPolicyCurrentSource($c->get(EffectiveConfigurationResolver::class)));
		$c->singleton(CustomerPolicyCurrentProvider::class,static fn(ServiceContainer $c)=>new CustomerPolicyCurrentProvider($c->get(CustomerPolicyCurrentSource::class)));
		$c->singleton(CustomerPolicyReceiptStore::class,static fn():CustomerPolicyReceiptStore=>CustomerPolicyReceiptStore::from_wordpress());
		$c->singleton(PolicySavedOrderReader::class,static fn(ServiceContainer $c)=>new PolicySavedOrderReader($c->get(OperationConnectionFactory::class)));
		$c->singleton(PolicySavedAudience::class,static fn()=>new PolicySavedAudience());
		$c->singleton(CartPolicyReceiptService::class,static fn(ServiceContainer $c)=>new CartPolicyReceiptService(
			$c->get(CustomerPolicyReceiptStore::class),self::secret(...),[$c->get(NativeShopperPolicyAuthority::class),'session_owner'],
			static function(array $frames)use($c):array {
				if(!$c->get(CustomerPolicyRuntimeGate::class)->fresh()||!self::quote_free($c)){self::refuse();}$grants=[];
				foreach($frames as$key=>$frame){if(!is_string($key)){self::refuse();}$grant=$c->get(NativeShopperPolicyAuthority::class)->cart($key);if($grant->frame()!==$frame){self::refuse();}$grants[$key]=$grant;}
				return $c->get(CustomerPolicyCurrentProvider::class)->capture_batch($grants);
			}));
		$c->singleton(CustomerPolicyFacade::class,static fn(ServiceContainer $c)=>new CustomerPolicyFacade(
			$c->get(NativeShopperPolicyAuthority::class),$c->get(CustomerPolicyCurrentProvider::class),$c->get(CartPolicyReceiptService::class),$c->get(PolicySavedOrderReader::class),
			[$c->get(CustomerPolicyRuntimeGate::class),'enabled'],static fn(string $route,int $revision):bool=>self::fresh_admission($c,$route,$revision)));
		$c->singleton(NativePolicyCartRuntime::class,static fn(ServiceContainer $c)=>new NativePolicyCartRuntime($c->get(CartPolicyReceiptService::class),$c->get(NativeShopperPolicyAuthority::class),$c->get(CustomerPolicyCurrentProvider::class),[$c->get(CustomerPolicyRuntimeGate::class),'enabled'],$c->get(CustomerPolicyFacade::class)));
		$c->singleton(PolicyNativeOrderStager::class,static fn(ServiceContainer $c)=>new PolicyNativeOrderStager($c->get(OperationConnectionFactory::class)));
		$c->singleton(PolicyCheckoutRuntime::class,static fn(ServiceContainer $c)=>new PolicyCheckoutRuntime(
			$c->get(OperationConnectionFactory::class),$c->get(PolicyNativeOrderStager::class),$c->get(PolicySavedOrderReader::class),
			static function(string $route)use($c):?CustomerPolicyPreparedCheckout {
				$read=$c->get(EmergencyControlService::class)->read(get_current_blog_id());
				$prepared=$c->get(CustomerPolicyFacade::class)->prepare_checkout($route,$read->available&&null!==$read->state?$read->state->revision:0);
				// Bound cart retry remains exclusive. Saved order-pay never uses this port.
				if(null!==$prepared&&null!==$prepared->binding()&&!self::quote_free($c)){self::refuse();}return $prepared;
			},[$c->get(PolicySavedAudience::class),'authorize_order'],[$c->get(CustomerPolicyRuntimeGate::class),'fresh'],
			static function(int $site,int $revision,callable $unchanged)use($c):bool {$result=$c->get(EmergencyControlService::class)->confirm_enabled($site,$revision,$unchanged);return $result->available&&null!==$result->state&&$result->state->site_id===$site&&$result->state->enabled()&&$result->state->revision===$revision;},
			static fn(\WC_Order $order,string $route)=>$c->get(EmergencyControlRuntime::class)->final_order_decision($order,$route),
			static fn(\WC_Order $order,CustomerPolicyPreparedCheckout $prepared):bool=>self::freeze_recovered($c,$order,$prepared),
			static fn(\WC_Order $order,string $route):bool=>$c->get(EmergencyCheckoutQuoteValidator::class)->validate_order($order,$route)));
		$c->singleton(CustomerPolicyFinalGuard::class,static fn(ServiceContainer $c)=>new CustomerPolicyFinalGuard($c->get(QuotePlacementRuntime::class),$c->get(QuotePlacementRuntime::class),$c->get(PolicyCheckoutRuntime::class),$c->get(PolicyCheckoutRuntime::class),[$c->get(PolicyCheckoutRuntime::class),'owns_order'],[QuoteNativeOrderHistory::class,'attempted'],[$c->get(PolicyCheckoutRuntime::class),'requires_live_legacy_validation']));
		$c->singleton(CustomerPolicyCartPresenter::class,static fn(ServiceContainer $c)=>new CustomerPolicyCartPresenter($c->get(Requirements::class),$c->get(CustomerPolicyFacade::class)));
		$c->singleton(CustomerPolicySavedPresenter::class,static fn(ServiceContainer $c)=>new CustomerPolicySavedPresenter($c->get(Requirements::class),$c->get(CustomerPolicyFacade::class),$c->get(PolicySavedAudience::class)));
	}
	public static function mount(ServiceContainer $c):void {
		$c->get(NativePolicyCartRuntime::class)->register();$c->get(PolicyCheckoutRuntime::class)->register();$c->get(CustomerPolicyCartPresenter::class)->register();$c->get(CustomerPolicySavedPresenter::class)->register();
		$c->get(OrderDeliverySnapshotPersister::class)->set_policy_preservation_guard(static function(\WC_Order $order)use($c):bool {
			// Only the issuer-held capability may finish this exact new order's initial V2 write.
			if($c->get(PolicyCheckoutRuntime::class)->allows_initial_legacy_write($order)){return false;}
			$reader=$c->get(PolicySavedOrderReader::class);if(!$reader->attempted($order)){return false;}
			// An internal preservation probe projects nothing to any audience.
			if([]===$reader->read($order,static fn():bool=>true)){self::refuse();}return true;
		});
		add_action('wp_enqueue_scripts',static function()use($c):void {
			if(!$c->get(CustomerPolicyFacade::class)->enabled()){return;}CustomerPolicyRenderer::register_assets();
			wp_localize_script(CustomerPolicyRenderer::SCRIPT_HANDLE,'cetechDeCustomerPolicy',['ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(NativePolicyCartRuntime::REVIEW_ACTION)]);
		},4);
	}
	private static function available(ServiceContainer $c,array $frame,CustomerCartContext $context,?array $line,string $key):bool {
		try {
			$validation=$c->get(ProductDeliverySelectionValidator::class)->validate($frame['product_id'],$frame['variation_id']>0?$frame['variation_id']:null,$key);$option=$validation->matched_option;
			if(!$validation->valid||!is_array($option)||($option['display_key']??null)!==$key||($option['delivery_offer_id']??null)!==$frame['real_offer_id']||'delivery'!==($option['fulfilment_choice']??null)||true!==($option['is_available']??null)){return false;}
			if(null!==$line&&CartDeliverySelectionRevalidationResult::STATUS_VALID!==$c->get(CartDeliverySelectionRevalidator::class)->revalidate_cart_item($frame['cart_key'],$line)->status){return false;}
			$actual=ProductDeliveryOption::fromArray($option);$locations=$c->get(LocationAwareDeliveryOptions::class);if(!$locations->delivery_requires_matching_location([$actual])){return true;}
			$quantity=(int)$frame['quantity'];if((string)$quantity!==$frame['quantity']||$quantity<1){return false;}
			$options=$locations->filter([$actual],$context->matching_location,get_woocommerce_currency(),new ProductPageQuoteContext($frame['product_id'],$frame['variation_id'],$quantity));
			return 1===count($options)&&$options[0]->display_key===$key&&$options[0]->delivery_offer_id===$frame['real_offer_id']&&$options[0]->is_available;
		}catch(\Throwable){return false;}
	}
	private static function fresh_admission(ServiceContainer $c,string $route,int $revision):bool {
		try {
			if(!in_array($route,['classic','store_api'],true)||$revision<1||!$c->get(CustomerPolicyRuntimeGate::class)->fresh()||!self::quote_free($c)){return false;}
			$result=$c->get(EmergencyControlService::class)->confirm_enabled(get_current_blog_id(),$revision);return $result->available&&null!==$result->state&&$result->state->enabled()&&$result->state->revision===$revision;
		}catch(\Throwable){return false;}
	}
	private static function quote_free(ServiceContainer $c):bool {
		try {$session=function_exists('WC')?(WC()->session??null):null;if(!$session instanceof \WC_Session_Handler||get_class($session)!==\WC_Session_Handler::class||!$session->has_session()||null!==$session->get(QuotePlacementRuntime::SESSION_CONTINUATION,null)){return false;}return null===$c->get(NativeCartQuoteSessionStore::class)->load((new QuoteNativeOwnerResolver())->current());}catch(\Throwable){return false;}
	}
	private static function freeze_recovered(ServiceContainer $c,\WC_Order $order,CustomerPolicyPreparedCheckout $prepared):bool {
		try {
			$binding=$prepared->binding();if(null===$binding||$binding['order_id']!==$order->get_id()||$binding['order_key']!==$order->get_order_key('edit')){return false;}$items=$order->get_items('line_item');if(count($items)!==count($binding['lines'])){return false;}
			foreach($binding['lines']as$line){$item=$items[$line['item_id']]??null;if(!$item instanceof \WC_Order_Item_Product||$item->get_id()!==$line['item_id']||!isset($prepared->lines()[$line['cart_key']])||PolicyNativeOrderFacts::one(PolicyNativeOrderFacts::metadata($item),PolicyNativeOrderFacts::META_LINE_KEY)!==$line['cart_key']){return false;}$c->get(EmergencyControlRuntime::class)->bind_order_line($line['cart_key'],$item);}
			$c->get(EmergencyControlRuntime::class)->freeze_saved_order($order);return true;
		}catch(\Throwable){return false;}
	}
	private static function secret():string {if(!function_exists('wp_salt')||(function_exists('has_filter')&&false!==has_filter('salt'))){self::refuse();}$secret=wp_salt('auth');if(!is_string($secret)||strlen($secret)<32||strlen($secret)>4096){self::refuse();}return $secret;}
	private static function refuse():never {throw new \RuntimeException('Delivery policies need review before checkout.');}
}
