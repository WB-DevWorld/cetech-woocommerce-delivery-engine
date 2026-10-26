<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\CustomerPolicy;

use CetechDeliveryEngine\Application\CustomerPolicy\{CartPolicyReceiptService,CustomerPolicyReadGrant,CustomerPolicyCurrentObservation,CustomerPolicySessionOwner};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\CustomerPolicyReceiptStore;

/** Genuine packet/source records with equivalent in-memory native resource/SQL ports. */
final class CustomerPolicyReceiptTestFixture {
	public readonly \PDO$pdo;
	public readonly CustomerPolicyReceiptStore$store;
	public readonly CartPolicyReceiptService$service;
	public readonly CustomerPolicySessionOwner$owner;
	public array$transports=[];
	public array$frames=[];
	public array$families;
	public int$source_reads=0;
	public int$owner_refreshes=0;
	public int$grant_refreshes=0;
	public bool$native_valid=true;
	public ?\Closure$configure=null;
	private int$uuid=800;
	public function __construct(?\PDO$pdo=null,public readonly string$subject='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'){
		$this->pdo=$pdo??CustomerPolicyReceiptTestTransport::database();$this->families=CustomerPolicyDomainFixture::families();
		$this->store=new CustomerPolicyReceiptStore(1,'wp_',function():CustomerPolicyReceiptTestTransport{$t=new CustomerPolicyReceiptTestTransport($this->pdo);$this->transports[]=$t;if(null!==$this->configure){($this->configure)($t,count($this->transports));}return$t;});
		$this->owner=CustomerPolicySessionOwner::captured(1,0,$subject,['site_id'=>1,'prefix'=>'wp_'],function():bool{++$this->owner_refreshes;if($this->pdo->inTransaction()){throw new \RuntimeException('Native callbacks are forbidden inside SQL.');}return$this->native_valid;});
		$this->service=new CartPolicyReceiptService($this->store,static fn():string=>str_repeat('server-secret-',5),fn()=>$this->owner,function(array$frames):array{++$this->source_reads;$out=[];foreach($frames as$key=>$frame){$grant=$this->grant($key,$this->frames[$key]??[]);$out[$key]=$this->observation($grant);}return$out;},fn():string=>CustomerPolicyDomainFixture::uuid(++$this->uuid));
	}
	public function frame(string$key='native-key',array$changes=[]):array{return array_replace(['site_id'=>1,'actor_id'=>0,'session_subject'=>$this->subject,'product_id'=>33,'variation_id'=>0,'parent_id'=>0,'quantity'=>'1','real_offer_id'=>4,'context_digest'=>str_repeat('b',64),'cart_key'=>$key,'purpose'=>''===$key?'product':'cart'],$changes);}
	public function grant(string$key='native-key',array$changes=[]):CustomerPolicyReadGrant{$frame=$this->frame($key,$changes);if('cart'===$frame['purpose']){$this->frames[$key]=$frame;}return CustomerPolicyReadGrant::captured($frame,['site_id'=>1,'prefix'=>'wp_'],function():bool{++$this->grant_refreshes;if($this->pdo->inTransaction()){throw new \RuntimeException('Native callbacks are forbidden inside SQL.');}return$this->native_valid;});}
	public function observation(CustomerPolicyReadGrant$grant,?array$families=null,?string$at=null):CustomerPolicyCurrentObservation{return CustomerPolicyCurrentObservation::captured($grant,$families??$this->families,RuleTime::parse($at??CustomerPolicyDomainFixture::NOW));}
	public function accepted(string$key='native-key',array$changes=[]):array{$g=$this->grant($key,$changes);$o=$this->observation($g);$view=$this->service->present_line($g,$o);if(!$this->service->accept_line($view['ticket'],$g,$o)){throw new \RuntimeException('Owned receipt acceptance failed.');}return[$g,$o];}
	public function rows():array{return$this->pdo->query('SELECT option_id,option_name,option_value,autoload FROM wp_options ORDER BY option_id')->fetchAll(\PDO::FETCH_ASSOC);}
}
