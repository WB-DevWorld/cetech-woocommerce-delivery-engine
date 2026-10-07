<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyQuoteSourcePlan,LegacyQuoteSourceSnapshot};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

/** Native SQL source facts; the money/tax fixture is explicitly synthetic. */
final class QuoteProviderProofFixtures {
	public static function seed(\mysqli $database,string $prefix):void {
		QuoteProviderProofDatabase::install_wordpress_sources($database,$prefix);
		QuoteProviderProofDatabase::offer($database,$prefix);QuoteProviderProofDatabase::zone($database,$prefix);QuoteProviderProofDatabase::origin($database,$prefix);
		QuoteProviderProofDatabase::insert($database,$prefix,'configuration_scopes',['id'=>1,'scope_type'=>'product','scope_id'=>10,'slice_key'=>'fulfilment','status'=>'active','config_version'=>3,'source'=>'native']);
		QuoteProviderProofDatabase::insert($database,$prefix,'configuration_fields',['id'=>1,'scope_row_id'=>1,'field_key'=>'origin_id','mode'=>'set','value_type'=>'integer','value_text'=>'40']);
		QuoteProviderProofDatabase::insert($database,$prefix,'configuration_collections',['id'=>1,'scope_row_id'=>1,'field_key'=>'delivery_offer_ids','mode'=>'replace','members_json'=>'[20]']);
		foreach(LegacyQuoteSourcePlan::ROUTE_OPTIONS as $name){DB::insert_option($database,$prefix,$name,str_contains($name,'effective_configuration')||str_contains($name,'variable_product')?'1':'0');}
	}
	public static function context():QuoteContext {$data=QuoteFixtures::context()->private_facts();$data['groups'][0]['service_id']=20;return QuoteContext::from_array($data);}
	public static function plan(?QuoteContext $context=null,array $extra_fences=[]):LegacyQuoteSourcePlan {
		$context??=self::context();$facts=$context->private_facts();$members=[];
		foreach($facts['groups'] as $group){foreach($group['line_keys'] as $key){$proof=['line_key'=>$key];foreach(['offer_id','service_id','choice','origin','supplier','profile','destination_zone_id','endpoint_digest'] as $field){$proof[$field]=$group[$field];}$members[]=$proof;}}
		$product_ids=[];$targets=[['type'=>'global','id'=>0]];foreach($facts['lines'] as $line){foreach(array_filter([$line['product_id'],$line['variation_id'],$line['parent_id']]) as $id){$product_ids[$id]=$id;}foreach([['product',$line['product_id']],['variation',$line['variation_id']]] as [$type,$id]){if(null!==$id){$targets[$type.':'.$id]=['type'=>$type,'id'=>$id];}}}
		$fences=[['source'=>'product','ids'=>array_values($product_ids)],['source'=>'product_meta','ids'=>array_values($product_ids)],['source'=>'term_relationships','ids'=>array_values($product_ids)],['source'=>'options','names'=>LegacyQuoteSourcePlan::ROUTE_OPTIONS],['source'=>'offers','ids'=>[20]],['source'=>'origins','ids'=>[40]],['source'=>'scopes','targets'=>array_values($targets)],['source'=>'scope_fields'],['source'=>'scope_collections'],['source'=>'zones'],['source'=>'zone_rules']];
		return LegacyQuoteSourcePlan::create(QuoteFixtures::owner(),$context,$members,[['delivery_offer_id'=>20,'destination_zone_id'=>50,'base_currency'=>'GHS']],[...$fences,...$extra_fences]);
	}
	public static function capture(OperationConnectionFactory $factory,?QuoteContext $context=null,?LegacyQuoteSourcePlan $plan=null):LegacyQuoteSourceSnapshot {
		$context??=self::context();$plan??=self::plan($context);$s=$factory->open();
		try{if(!$s->begin()){throw new \RuntimeException('Native source owner refused.');}$snapshot=(new LegacyQuoteSourceSnapshotReader())->capture($s,QuoteFixtures::owner(),$context,$plan);if(!$s->rollback()||!$s->retire()){throw new \RuntimeException('Native source capture did not retire.');}return $snapshot;}finally{if(!$s->is_retired()){$s->rollback();$s->retire();}}
	}
	public static function bind(LegacyQuoteSourceSnapshot $snapshot):LegacyQuoteSourceSnapshot {$facts=$snapshot->context()->private_facts();foreach($facts['groups'] as &$group){$group['candidate_count']=$snapshot->candidate_count($group['offer_id'],$group['destination_zone_id'],$facts['currency']['base']);$group['candidate_digest']=$snapshot->candidate_digest($group['offer_id'],$group['destination_zone_id'],$facts['currency']['base']);$group['policy_digest']=$snapshot->policy_digest();}unset($group);return $snapshot->bind_context(QuoteContext::from_array($facts));}
}
