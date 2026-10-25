<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\PolicyAssignment;

use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver, EffectiveConfigurationValidator};
use CetechDeliveryEngine\Application\PolicyAssignment\PolicyAssignmentAdminWorkflow;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Infrastructure\Persistence\{InMemoryScopedConfigurationRepository, WpdbPolicyAssignmentAdminIntent};
use CetechDeliveryEngine\Integrations\PolicyAssignment\NativePolicyAssignmentAuthority;

require_once __DIR__.'/PolicyAssignmentFixture.php';
require_once __DIR__.'/PolicyAssignmentAdminNativeFixtures.php';

/** Native ports and option fencing are models; C01/C03/ECR are actual production code. */
final class PolicyAssignmentAdminFixture {
	public readonly PolicyAssignmentFixture $core;
	public readonly PolicyAssignmentAdminIntentDatabase $options;
	public readonly PolicyAssignmentAdminOffers $offers;
	public readonly NativePolicyAssignmentAuthority $authority;
	public readonly InMemoryScopedConfigurationRepository $configuration;
	public array $products;
	public int $native_reads=0;
	public int $native_during_sql=0;
	public function __construct() {
		$this->core=new PolicyAssignmentFixture();$this->options=new PolicyAssignmentAdminIntentDatabase();$this->offers=new PolicyAssignmentAdminOffers();
		$this->configuration=new InMemoryScopedConfigurationRepository();
		$this->products=[10=>['parent_id'=>0,'is_variation'=>false,'label'=>'Owned product café'],11=>['parent_id'=>10,'is_variation'=>true,'label'=>'Owned variation Ω'],20=>['parent_id'=>0,'is_variation'=>false,'label'=>'Other parent']];
		$this->authority=new NativePolicyAssignmentAuthority($this->offers,function(int $id):mixed{$this->native();return $this->products[$id]??null;},function():array{$this->native();return ['site_id'=>1,'prefix'=>PolicyAssignmentUnitFactory::PREFIX];});
	}
	public function workflow(?OperationPhaseObserver $observer=null):PolicyAssignmentAdminWorkflow {return new PolicyAssignmentAdminWorkflow($this->authority,new WpdbPolicyAssignmentAdminIntent($this->options),$this->core->factory,new EffectiveConfigurationResolver($this->configuration,new EffectiveConfigurationValidator()),new PolicyAssignmentUnitReadiness(),$observer);}
	public function seed():void {$this->core->publish('return_policy_v1',101,1,['name'=>'Return café','customer_title'=>'Return <b>literal</b>','full_text'=>"Return wording\nsecond line"]);$this->core->publish('refund_policy_v1',102,2,['name'=>'Refund Ω','customer_title'=>'Refund <script>literal</script>']);}
	public function fields(array $changes=[]):array {return array_replace(['return_mode'=>'assigned','return_policy_uuid'=>PolicyAssignmentFixture::uuid(101),'refund_mode'=>'assigned','refund_policy_uuid'=>PolicyAssignmentFixture::uuid(102),'reason'=>'Owned assignment reason café'],$changes);}
	public function journal_name(int $owner=9):string{return WpdbPolicyAssignmentAdminIntent::name(1,$owner);}
	public function journal(int $owner=9):?array{return isset($this->options->rows[$this->journal_name($owner)])?json_decode($this->options->rows[$this->journal_name($owner)],true,32,JSON_THROW_ON_ERROR):null;}
	public function close():void{$this->core->close();}
	private function native():void {++$this->native_reads;foreach($this->core->factory->sessions as $session){if(!$session->is_retired()){++$this->native_during_sql;}}}
}

final class PolicyAssignmentAdminOffers implements DeliveryOfferRepositoryInterface {
	public array $rows=[7=>['id'=>7,'status'=>'active','public_label'=>'Owned delivery A'],8=>['id'=>8,'status'=>'active','public_label'=>'Owned delivery B']];
	public function findById(int $id):?array{return $this->rows[$id]??null;}public function findByCode(string $code):?array{return null;}
	public function save(array $data):int{throw new \LogicException();}public function list(array $criteria=[]):array{return array_values($this->rows);}
	public function softDelete(int $id):bool{throw new \LogicException();}public function hardDelete(int $id):bool{throw new \LogicException();}public function count_all():int{return count($this->rows);}
}
