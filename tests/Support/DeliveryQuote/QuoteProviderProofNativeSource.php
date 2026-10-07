<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteNativeCaptureSource,QuoteNativeState};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;

/** Synthetic receipt for SQL phase ordering only; never native Woo qualification. */
final class QuoteProviderProofNativeSource implements QuoteNativeCaptureSource {
	public int $reads=0;
	public bool $all_prior_owners_retired=false;
	public ?\Closure $during_capture=null;
	public function __construct(private QuoteProviderProofFactory $factory){}
	public function current_owner():QuoteOwner{return QuoteFixtures::owner();}
	public function unchanged():bool{return true;}
	public function capture():QuoteNativeState {
		++$this->reads;$this->all_prior_owners_retired=[]!==$this->factory->sessions;
		foreach($this->factory->sessions as $s){$this->all_prior_owners_retired=$this->all_prior_owners_retired&&$s->is_retired()&&!$s->in_transaction();}
		if(!$this->all_prior_owners_retired){throw new \RuntimeException('SQL owner leaked into synthetic native receipt capture.');}
		if(null!==$this->during_capture){($this->during_capture)();}
		return QuoteNativeState::from_array(['currency'=>'GHS','display_precision'=>2,'internal_precision'=>4,'exempt'=>true,'tax_class'=>'','location_digest'=>QuoteFixtures::digest('tax_location'),'destination_digest'=>QuoteFixtures::digest('destination'),'key_epoch'=>'fixture_key_1','rounding'=>'per_line','tax_enabled'=>false,'coupons'=>0,'unsupported_effects'=>false,'cart_shipping_total'=>'12.5000','cart_shipping_tax'=>'0','packages'=>[['group_id'=>'physical_fixture|delivery|20','rate_id'=>'delivery_engine_selected_offer:1:fixture','method_id'=>'delivery_engine_selected_offer','instance_id'=>1,'tax_status'=>'none','label'=>'Fixture delivery','cost'=>'12.5000','taxes'=>[],'fresh_taxes'=>[],'rounded_tax'=>'0.00','display_total'=>'12.50','members'=>[['line_key'=>'line_one','product_id'=>10,'variation_id'=>null,'quantity'=>'2']]]],'source_facts'=>['option_rows'=>[],'tax_rows'=>[],'tax_class_rows'=>[],'tax_location_rows'=>[],'method_rows'=>[],'session_row'=>[],'customer_rows'=>[],'selectors'=>['option_names'=>['woocommerce_currency'],'tax_class'=>'','method_instance_ids'=>[1],'session_key'=>'fixture-session','customer_id'=>0,'site_id'=>1,'table_prefix'=>$this->factory->prefix]]]);
	}
}
