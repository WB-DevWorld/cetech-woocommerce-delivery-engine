<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteEnvironment,LegacyQuotePreparedCapture,LegacyQuoteProviderStack,LegacyQuoteSourcePlan,QuoteCartCurrentEvidence,QuoteCartDraft,QuoteIssueCommand,QuoteNativeReceiptCapture};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteHeader,QuoteOwner};

/** Real owned source SQL with synthetic native receipt; no shopper/Woo qualification. */
final class QuoteCartProofEnvironment implements CartQuoteEnvironment {
	public int $prepares=0;
	public QuoteCartDraft $loaded_draft;
	public ?\Closure $before_prepare=null;
	public readonly QuoteProviderProofFactory $source_factory;
	public array $native_sources=[];
	public bool $allowed=true;
	private ?LegacyQuotePreparedCapture $last=null;
	public function __construct(string $prefix,?QuoteOwner $owner=null){$this->source_factory=new QuoteProviderProofFactory($prefix);$this->loaded_draft=QuoteCartProofFixtures::draft($owner);}
	public function draft():?QuoteCartDraft{return $this->loaded_draft;}
	public function authorize(QuoteOwner $owner,string $operation):bool{return $this->allowed&&$owner->equals($this->loaded_draft->owner())&&str_starts_with($operation,'delivery_quote.');}
	public function prepare(QuoteCartDraft $draft):LegacyQuotePreparedCapture {
		++$this->prepares;if(null!==$this->before_prepare){($this->before_prepare)($draft);}
		return $this->last=$this->capture_current($draft);
	}
	public function evidence(QuoteIssueCommand $original,QuoteHeader $header,QuoteCartDraft $draft):?QuoteCartCurrentEvidence {
		if(!$this->authorize($draft->owner(),'delivery_quote.read')){return null;}try{$current=$this->capture_current($draft);return new QuoteCartCurrentEvidence($current->context(),$current->guard());}catch(\Throwable){return null;}
	}
	private function capture_current(QuoteCartDraft $draft):LegacyQuotePreparedCapture {
		$native=new QuoteCartProofNativeSource($this->source_factory,$draft);$this->native_sources[]=$native;$facts=QuoteProviderProofFixtures::context()->private_facts();$facts['destination']['key_epoch']=$draft->owner()->key_epoch();$facts['selection_digest']=$draft->draft_digest();$facts['lines'][0]['quantity']=$draft->private_facts()['lines'][0]['quantity'];$context=QuoteContext::from_array($facts);
		$template=QuoteProviderProofFixtures::plan();$plan=LegacyQuoteSourcePlan::create($draft->owner(),$context,$template->member_proofs(),$template->rate_ranges(),$template->fences());$stack=new LegacyQuoteProviderStack($this->source_factory,new QuoteNativeReceiptCapture($native));$component=$context->private_facts()['groups'][0]['component_key'];return $stack->prepare($draft->owner(),$context,$plan,[$component=>'physical_fixture|delivery|20']);
	}
	public function native_reads():int{return array_sum(array_map(static fn(QuoteCartProofNativeSource $s):int=>$s->base->reads,$this->native_sources));}
	public function source_captures():int{$n=0;foreach($this->source_factory->transports as $t){foreach($t->sql as $sql){if(str_contains($sql,' FORCE INDEX (`quote_candidate_range`)')){++$n;}}}return $n;}
	public function close_all():void{$this->source_factory->close_all();}
}
