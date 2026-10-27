<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Private handle issued and registered by the actual receipt owner; never a binding DTO. */
final readonly class CustomerPolicyPreparedCheckout implements \JsonSerializable {
	private function __construct(private CartPolicyReceiptService$service,private array$opened,private CustomerPolicySessionOwner$owner){}
	public function attempt_id():string{return$this->opened['record']['attempt_id'];}
	public function site_id():int{return$this->opened['record']['site_id'];}
	public function owner_digest():string{return$this->opened['record']['owner_digest'];}
	public function captured_at():RuleTime{return RuleTime::parse($this->opened['record']['captured_at']);}
	public function lines():array{return$this->opened['lines'];}
	public function route():string{return$this->opened['record']['route'];}
	public function state():string{return$this->opened['record']['state'];}
	public function binding():?array{return$this->opened['record']['binding'];}
	public function control_revision():int{return$this->opened['record']['initial_control_revision'];}
	public function bind_native(PolicyNativeOrderStage$proof):self{return$this->service->bind_prepared($this,$proof);}
	public function mark_complete(PolicyNativeOrderStage$proof,int$revision):self{return$this->service->complete_prepared($this,$proof,$revision);}
	public function reconcile_original():self{return$this->service->reconcile_prepared($this);}
	public function original_witness():array{return$this->service->witness_for($this);}
	public function verify_original(OperationSession$session):bool{return$this->service->verify_prepared($this,$session);}
	/** Read-only participant for a typed native retry proof; never an order grant. */
	public function verify_unbound_original(OperationSession$session,int$order_id):bool{return$this->service->verify_unbound_prepared($this,$session,$order_id);}
	/** Issuer-identity checks prevent any rehydrated object from becoming a live original. */
	public function issued_record(CartPolicyReceiptService$service):array{if($service!==$this->service){throw new \LogicException('Foreign original owner.');}return$this->opened['record'];}
	public function jsonSerialize():never{throw new \LogicException('Customer policy originals require an explicit projection.');}
	public function __serialize():never{throw new \LogicException('Customer policy originals are request-local.');}
	public function __unserialize(array$data):never{throw new \LogicException('Customer policy originals are request-local.');}
	public function __debugInfo():array{return['customer_policy_original'=>'redacted'];}
}
