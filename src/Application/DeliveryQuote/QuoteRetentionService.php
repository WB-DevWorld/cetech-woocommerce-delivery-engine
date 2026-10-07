<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Application\Operation\{OperationCoordinator,OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError,OperationOutcome,RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult,OperationConnectionFactory,OperationPhaseObserver,OperationProfileRegistry,OperationSession};
/** Internal explicit maintenance adopter. No cron, endpoint, Plugin or uninstall registration. */
final class QuoteRetentionService {
 private \Closure $authorize;private QuoteRetentionReferenceInspector $references;private QuoteRetentionControl $control;
 public function __construct(private OperationConnectionFactory $factory,private OperationProfileRegistry $producers,callable $authorizer,?QuoteRetentionReferenceInspector $references=null,?QuoteRetentionControl $control=null,private ?OperationReadiness $readiness=null,private ?OperationPhaseObserver $observer=null,private $monotonic_clock=null) { $this->authorize=\Closure::fromCallable($authorizer);$this->references=$references??new QuoteRetentionUnknownReferences();$this->control=$control??new QuoteRetentionNativeControl(); }
 public function policy_digest():string { return QuoteRetentionProfile::policy_digest($this->references); }
 public function start(int $site,RequestContext $context,?string $run_id=null):QuoteRetentionResult { return $this->execute(QuoteRetentionRequest::start($site,$this->policy_digest(),$run_id),$context,false); }
 public function batch(QuoteRetentionCheckpoint $checkpoint,RequestContext $context):QuoteRetentionResult { return $this->execute(QuoteRetentionRequest::next($checkpoint),$context,false); }
 public function attempt(QuoteRetentionRequest $request,RequestContext $context):QuoteRetentionResult { return $this->execute($request,$context,false); }
 public function reconcile(QuoteRetentionRequest $request,RequestContext $context):QuoteRetentionResult { return $this->execute($request,$context,true); }
 private function execute(QuoteRetentionRequest $request,RequestContext $context,bool $reconcile):QuoteRetentionResult {
  $profiles=[];foreach(['start','batch'] as $kind) { $profiles[]=new QuoteRetentionProfile($kind,$this->policy_digest(),$this->authorize,$this->references,$this->control,$this->producers,$this->monotonic_clock); }
  $tracked=new class($this->factory) implements OperationConnectionFactory { public array $sessions=[];public function __construct(private OperationConnectionFactory $factory) {}public function open():OperationSession { $session=$this->factory->open();$this->sessions[]=$session;return $session; } };
  $coordinator=new OperationCoordinator(new OperationProfileRegistry($profiles),$tracked,$this->readiness,$this->observer);$attempt=$reconcile?$coordinator->reconcile($request->identity,$request,$context):$coordinator->attempt($request->identity,$request,$context);$checkpoint=null;$release_ok=true;foreach($tracked->sessions as $session) { try { if(!$session->is_retired()) { $release_ok=$session->retire()&&$release_ok; }$release_ok=$session->is_retired()&&$release_ok; }catch(\Throwable) { $release_ok=false; } }
  // Authority is current after the final rollback/retirement callback and before disclosure.
  try { $current=true===($this->authorize)($request->identity->site_id)&&hash_equals($request->policy,$this->policy_digest()); }catch(\Throwable) { $current=false; }
  if(!$release_ok||!$current&&in_array($attempt->outcome->state,['accepted','not_applicable','unconfirmed'],true)) { $attempt=new OperationAttemptResult(OperationOutcome::unconfirmed(new ContractError('outcome_unknown',$context,'reconcile_original_request'))); }
  elseif(!$current) { $attempt=new OperationAttemptResult(OperationOutcome::rejected(new ContractError('not_authorized',$context,'contact_support'))); }
  if('accepted'===$attempt->completion?->state&&'accepted'===$attempt->outcome->state) { $checkpoint=QuoteRetentionCheckpoint::from_completion($attempt->completion->result,$request->identity->namespace_digest()); }
  return new QuoteRetentionResult($attempt,$request,$checkpoint);
 }
}
