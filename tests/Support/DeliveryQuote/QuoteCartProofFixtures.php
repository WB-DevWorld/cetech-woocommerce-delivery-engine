<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCartDraft,QuoteNativeContextIdentity};
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;

/** Cheap already-loaded fixture inputs; no real shopper/Woo authority claim. */
final class QuoteCartProofFixtures {
	public static function identity():QuoteNativeContextIdentity{return QuoteNativeContextIdentity::from_private_key('finite-q05-context-proof-secret');}
	public static function owner():QuoteOwner{return QuoteOwner::from_array(array_replace(QuoteFixtures::owner()->facts(),['key_epoch'=>self::identity()->key_epoch()]));}
	public static function draft(?QuoteOwner $owner=null,int $quantity=2):QuoteCartDraft {
		$owner??=self::owner();$destination=['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'00233','address'=>'Fixture address','address_2'=>''];
		$item=['product_id'=>10,'variation_id'=>0,'quantity'=>$quantity,CartDeliverySelectionCapture::CART_SELECTION_KEY=>['contract_version'=>'1','product_id'=>10,'variation_id'=>null,'target_type'=>'product','target_id'=>10,'display_key'=>'fixture_product','fulfilment_availability'=>'in_store','fulfilment_choice'=>'delivery','delivery_offer_id'=>20,'rule_id'=>null,'configuration_fingerprint'=>QuoteFixtures::digest('source')],CartDeliverySelectionCapture::CART_HASH_KEY=>QuoteFixtures::digest('selection'),CustomerCartContext::CART_KEY=>['contract_version'=>1,'fulfilment_choice'=>'delivery','delivery_address'=>$destination]];
		return QuoteCartDraft::from_loaded_cart($owner,['line_one'=>$item],$destination,'GHS',2,self::identity());
	}
}
