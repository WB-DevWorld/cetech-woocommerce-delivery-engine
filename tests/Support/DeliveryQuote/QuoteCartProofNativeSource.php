<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteNativeCaptureSource,QuoteNativeState,QuoteCartDraft};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

/** Synthetic native attestation for one physical fixture rate; no real Woo charge claim. */
final class QuoteCartProofNativeSource implements QuoteNativeCaptureSource {
	public readonly QuoteProviderProofNativeSource $base;
	public function __construct(private QuoteProviderProofFactory $factory,private QuoteCartDraft $draft){$this->base=new QuoteProviderProofNativeSource($factory);}
	public function current_owner():QuoteOwner{return $this->draft->owner();}
	public function unchanged():bool{return true;}
	public function capture():QuoteNativeState {
		$facts=$this->base->capture()->facts();$db=DB::connect();try{$amount=(string)DB::scalar($db,"SELECT base_amount FROM `{$this->factory->prefix}delivery_engine_rate_cards` WHERE id=1");}finally{$db->close();}
		$facts['key_epoch']=$this->draft->owner()->key_epoch();$facts['cart_shipping_total']=$amount;$facts['packages'][0]['cost']=$amount;$facts['packages'][0]['display_total']=substr($amount,0,-2);$facts['packages'][0]['members'][0]['quantity']=$this->draft->private_facts()['lines'][0]['quantity'];return QuoteNativeState::from_array($facts);
	}
}
