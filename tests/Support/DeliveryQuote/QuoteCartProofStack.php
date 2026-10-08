<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteService,QuotePreparationGate,NativeCartQuoteSessionStore};
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
/** Actual native SQL protocol; the native monetary receipt remains explicitly synthetic. */
final class QuoteCartProofStack {
 public readonly QuoteCartProofEnvironment $environment;
 public readonly QuoteLifecycleProofFactory $factory;
 public readonly NativeCartQuoteSessionStore $sessions;
 public readonly QuotePreparationGate $gate;
 public readonly CartQuoteService $service;
 public function __construct(string $prefix,?\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner $owner=null,?callable $configure=null) {
  $this->environment=new QuoteCartProofEnvironment($prefix,$owner);$this->factory=new QuoteLifecycleProofFactory($prefix);$this->factory->configure=null===$configure?null:\Closure::fromCallable($configure);$authorize=[$this->environment,'authorize'];$control=new EmergencyControlStore(static fn():bool=>false);
  $this->gate=new QuotePreparationGate($this->factory,$authorize,null,$control);
  $this->sessions=new NativeCartQuoteSessionStore($this->factory,$authorize,null,'finite-q05-session-proof-secret',static fn():int=>QuoteLifecycleProofFactory::NOW+3600);
  $this->service=new CartQuoteService($this->environment,$this->gate,$this->sessions,$this->factory,null,null,$control,new QuoteLifecycleProofPublication());
 }
 public function close_all():void{$this->factory->close_all();$this->environment->close_all();}
}
