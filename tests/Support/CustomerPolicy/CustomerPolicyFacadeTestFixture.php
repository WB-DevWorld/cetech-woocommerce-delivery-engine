<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\CustomerPolicy;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\CustomerPolicy\{CartPolicyReceiptService,CustomerPolicyCurrentProvider,CustomerPolicyFacade,PolicySavedOrderReader};
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{CustomerPolicyCurrentSource,CustomerPolicyReceiptStore};
use CetechDeliveryEngine\Integrations\CustomerPolicy\NativeShopperPolicyAuthority;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentUnitFactory;
require_once __DIR__.'/CurrentPolicyFixture.php';

/** Actual Facade/source/receipts over declared equivalent native resource ports. */
final class CustomerPolicyFacadeTestFixture {
	public readonly CurrentPolicyFixture$source;
	public readonly CustomerPolicyFacade$facade;
	public readonly CustomerPolicyFacadeCart$cart;
	public readonly NativeShopperPolicyAuthority$authority;
	public readonly CartPolicyReceiptService$receipts;
	public array$transports=[];
	public bool$enabled=true;
	public bool$fresh=true;
	public bool$available=true;
	public bool$visible=true;
	public bool$variable=false;
	public int$fresh_calls=0;
	public int$bootstrap_calls=0;
	public int$native_reads=0;
	public int$factory_reads=0;
	private array$owner;
	public function __construct(bool$with_fresh=true){
		$this->source=new CurrentPolicyFixture();$this->cart=new CustomerPolicyFacadeCart();$wc=new \stdClass();$session=new \stdClass();$db=new \stdClass();$this->owner=['site_id'=>1,'actor_id'=>0,'session_id'=>'owned-facade-guest','cookie'=>false,'wc'=>$wc,'session'=>$session,'cart'=>$this->cart,'database'=>$db,'auth_session'=>''];
		$this->authority=new NativeShopperPolicyAuthority(fn():bool=>$this->available,function(int$id):array{++$this->native_reads;return['id'=>$id,'parent_id'=>44===$id?33:0,'is_variation'=>44===$id,'is_variable'=>33===$id&&$this->variable,'visible'=>$this->visible,'viewable'=>$this->visible];},fn():array=>$this->owner,fn(string$key):?array=>$this->cart->lines[$key]??null,static fn():array=>['site_id'=>1,'prefix'=>PolicyAssignmentUnitFactory::PREFIX]);
		$provider=new CustomerPolicyCurrentProvider(new CustomerPolicyCurrentSource($this->source->resolver(),fn(array$b)=>$this->source->connection()));$pdo=$this->source->base->factory->inspection();$store=new CustomerPolicyReceiptStore(1,PolicyAssignmentUnitFactory::PREFIX,function()use($pdo):CustomerPolicyReceiptTestTransport{$t=new CustomerPolicyReceiptTestTransport($pdo,'r24_facade',PolicyAssignmentUnitFactory::PREFIX);$this->transports[]=$t;return$t;});
		$this->receipts=new CartPolicyReceiptService($store,static fn():string=>str_repeat('private-facade-secret-',3),fn()=>$this->authority->session_owner(),function(array$frames)use($provider):array{$grants=[];foreach($frames as$key=>$frame){$grants[$key]=$this->authority->cart($key);}return$provider->capture_batch($grants);});
		$owner=$this;$factory=new class($owner)implements OperationConnectionFactory{public function __construct(private CustomerPolicyFacadeTestFixture$f){}public function open():OperationSession{++$this->f->factory_reads;throw new \RuntimeException('Saved proof SQL not available in orchestration fixture.');}};
		$this->facade=new CustomerPolicyFacade($this->authority,$provider,$this->receipts,new PolicySavedOrderReader($factory),fn():bool=>$this->enabled,$with_fresh?function(string$route,int$revision):bool{++$this->fresh_calls;return$this->fresh&&in_array($route,['classic','store_api'],true)&&$revision===1;}:null,fn()=>$this->cart,function():bool{++$this->bootstrap_calls;return true;});
	}
	public function line(string$key='native-key',array$changes=[]):array{$context=CustomerCartContext::delivery(4);$intent=['contract_version'=>'1','product_id'=>33,'variation_id'=>null,'target_type'=>'product','target_id'=>33,'display_key'=>'in_warehouse:delivery:4','fulfilment_availability'=>'in_warehouse','fulfilment_choice'=>'delivery','delivery_offer_id'=>4,'rule_id'=>1,'issued_at'=>'2026-10-24T10:00:00Z'];return array_replace(['key'=>$key,'product_id'=>33,'variation_id'=>0,'quantity'=>1,CartDeliverySelectionCapture::CART_SELECTION_KEY=>$intent,CustomerCartContext::CART_KEY=>$context->toArray()],$changes);}
	public function publish():void{$f=$this->source->base;$f->publish('return_policy_v1',101,1,['customer_title'=>'Returns Ω','summary'=>'Original return summary']);$f->publish('refund_policy_v1',201,2,['customer_title'=>'Refunds 漢字','summary'=>'Original refund summary']);$this->source->seed(\CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentState::make_coordinate(1,'global',0),['mode'=>'assigned','policy_uuid'=>$f::uuid(101)],['mode'=>'assigned','policy_uuid'=>$f::uuid(201)]);}
	public function close():void{$this->source->close();}
}
final class CustomerPolicyFacadeCart {
	public array$lines=[],$fees=[],$coupons=[];
	public function get_cart():array{return$this->lines;}
	public function get_fees():array{return$this->fees;}
	public function get_applied_coupons():array{return$this->coupons;}
}
