<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentCommand;
use CetechDeliveryEngine\Integrations\PolicyAssignment\NativePolicyAssignmentAuthority;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/Support/PolicyAssignment/PolicyAssignmentAdminNativeFixtures.php';

final class NativePolicyAssignmentAuthorityTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private array $products; private object $offers; private NativePolicyAssignmentAuthority $authority; private int $native_reads=0; private mixed $old_blog;
	protected function setUp():void {
		$this->remember_fixture_globals(); $this->old_blog=$GLOBALS['blog_id']??null; $GLOBALS['blog_id']=1;
		$GLOBALS['cetech_de_test_is_admin']=true; $GLOBALS['cetech_de_test_user_id']=9;
		$GLOBALS['cetech_de_test_caps']=['manage_delivery_settings'=>true,'manage_site_wide_defaults'=>true];
		$GLOBALS['cetech_de_test_edit_posts']=[10=>true,11=>true,20=>true]; AdminPageAccess::bind(null);
		$this->products=[10=>['parent_id'=>0,'is_variation'=>false,'label'=>'Product café'],11=>['parent_id'=>10,'is_variation'=>true,'label'=>'Variation Ω'],20=>['parent_id'=>0,'is_variation'=>false,'label'=>'Other parent']];
		$this->offers=new class implements DeliveryOfferRepositoryInterface {
			public array $rows=[7=>['id'=>7,'status'=>'active','public_label'=>'Delivery café'],8=>['id'=>'8','status'=>'inactive','public_label'=>'Inactive delivery']]; public mixed $on_read=null;
			public function findById(int $id):?array {if(null!==$this->on_read){($this->on_read)();}return $this->rows[$id]??null;}
			public function findByCode(string $code):?array{return null;}public function save(array $data):int{throw new \LogicException();}public function list(array $criteria=[]):array{return array_values($this->rows);}
			public function softDelete(int $id):bool{throw new \LogicException();}public function hardDelete(int $id):bool{throw new \LogicException();}public function count_all():int{return count($this->rows);}
		};
		$this->authority=$this->authority();
	}
	protected function tearDown():void { AdminPageAccess::bind(null);$this->restore_fixture_globals();if(null===$this->old_blog){unset($GLOBALS['blog_id']);}else{$GLOBALS['blog_id']=$this->old_blog;} }
	private function authority(?callable $binding=null):NativePolicyAssignmentAuthority {return new NativePolicyAssignmentAuthority($this->offers,function(int $id):mixed{++$this->native_reads;return $this->products[$id]??null;},$binding??function():array{++$this->native_reads;return ['site_id'=>1,'prefix'=>'policy_assignment_unit_'];});}
	private function identity(string $type='global',int $id=0,int $offer=7):OperationIdentity {$c=$this->authority->target($type,$id);return $this->authority->identity($this->authority->identity_data('policy.assignment.read',$c,$offer,'original'));}
	private function refusal(callable $f):void {try{$f();self::fail('Native scope must refuse.');}catch(\RuntimeException){self::assertTrue(true);}}

	#[DataProvider('capabilities')]
	public function test_existing_pair_is_required_for_all_scopes(array $caps,bool $allowed):void {$GLOBALS['cetech_de_test_caps']=$caps;self::assertSame($allowed,$this->authority->can_access());if(!$allowed){$this->refusal(fn()=>$this->authority->target('global',0));}}
	public static function capabilities():array{return [[[ 'manage_delivery_settings'=>true,'manage_site_wide_defaults'=>true],true],[['manage_delivery_settings'=>true],false],[['manage_site_wide_defaults'=>true],false],[['manage_options'=>true],false],[[],false]];}

	#[DataProvider('invalid_actors')]
	public function test_guest_wrong_site_or_nonadmin_has_no_actor(array $changes):void {foreach($changes as $k=>$v){$GLOBALS[$k]=$v;}self::assertFalse($this->authority->can_access());$this->refusal(fn()=>$this->authority->actor());}
	public static function invalid_actors():array{return [[['cetech_de_test_user_id'=>0]],[['blog_id'=>0]],[['cetech_de_test_is_admin'=>false]]];}

	public function test_real_product_parent_is_derived_and_explicit_post_grants_are_required():void {
		self::assertSame(['site_id'=>1,'scope_type'=>'global','scope_id'=>0,'parent_id'=>0],$this->authority->target('global',0));
		self::assertSame(['site_id'=>1,'scope_type'=>'product','scope_id'=>10,'parent_id'=>0],$this->authority->target('product',10));
		self::assertSame(['site_id'=>1,'scope_type'=>'variation','scope_id'=>11,'parent_id'=>10],$this->authority->target('variation',11));
		$GLOBALS['cetech_de_test_edit_posts'][10]=false;$this->refusal(fn()=>$this->authority->target('variation',11));
	}
	#[DataProvider('invalid_targets')]
	public function test_missing_wrong_kind_and_noncanonical_scopes_refuse(string $type,int $id):void {$this->refusal(fn()=>$this->authority->target($type,$id));}
	public static function invalid_targets():array{return [['global',1],['product',0],['product',11],['variation',10],['variation',999],['category',10],['product',-1]];}

	public function test_same_variation_reparenting_invalidates_original_coordinate():void {$c=$this->authority->target('variation',11);$id=$this->identity('variation',11);self::assertTrue($this->authority->authorize($id,$c,7));$this->products[11]['parent_id']=20;self::assertFalse($this->authority->authorize($id,$c,7));}
	public function test_actual_offer_ids_are_separate_from_default_edit_slot():void {self::assertSame(0,$this->authority->offer(0));self::assertSame(7,$this->authority->offer(7));self::assertSame(8,$this->authority->offer(8));foreach([-1,1,999] as $id){$this->refusal(fn()=>$this->authority->offer($id));}self::assertSame([7,8],array_column($this->authority->offer_choices(),'id'));}
	public function test_offer_lookup_hooks_cannot_revoke_native_grant_and_still_authorize():void {$this->offers->on_read=static function():void{$GLOBALS['cetech_de_test_caps']=[];};$this->refusal(fn()=>$this->authority->offer(7));}

	#[DataProvider('invalid_bindings')]
	public function test_native_route_is_closed_and_site_bound(mixed $binding):void {$a=$this->authority(static fn():mixed=>$binding);$this->refusal(fn()=>$a->binding());}
	public static function invalid_bindings():array{return [[null],[[]],[['site_id'=>2,'prefix'=>'wp_']],[['site_id'=>1,'prefix'=>'']],[['site_id'=>1,'prefix'=>'bad-']],[['site_id'=>1,'prefix'=>str_repeat('a',60)]],[['site_id'=>1,'prefix'=>'wp_','extra'=>1]]];}

	public function test_captured_owner_and_family_grants_are_pure_and_actor_bound():void {
		$c=$this->authority->target('variation',11);$id=$this->identity('variation',11);$g=$this->authority->capture($id,$c,7);$before=$this->native_reads;
		$GLOBALS['cetech_de_test_caps']=[];self::assertTrue(($g['authorize'])($id));self::assertTrue(($g['authorize'])($id,9));self::assertFalse(($g['authorize'])($id,10));
		self::assertFalse(($g['authorize'])(clone $id,9));$scope=['policy_uuid'=>'00000000-0000-4000-8000-000000000101'];
		self::assertTrue($g['return_family']->authorize($id,$scope));self::assertTrue($g['refund_family']->authorize($id,$scope));self::assertFalse($g['return_family']->authorize_author(1,9,$scope));
		self::assertSame($before,$this->native_reads);self::assertFalse($this->authority->authorize($id,$c,7));
	}
	#[DataProvider('captured_frames')]
	public function test_captured_pure_grant_binds_the_complete_coordinate_and_offer(array $changes,int $offer,bool $allowed):void {
		$c=$this->authority->target('variation',11);$id=$this->identity('variation',11);$g=$this->authority->capture($id,$c,7);$before=$this->native_reads;
		$GLOBALS['cetech_de_test_caps']=[];$candidate=array_replace($c,$changes);
		self::assertSame($allowed,($g['authorize'])($id,null,$candidate,$offer));
		self::assertSame($allowed,($g['authorize'])($id,9,$candidate,$offer));
		self::assertSame($before,$this->native_reads);
	}
	public static function captured_frames():array{return [[[],7,true],[['parent_id'=>20],7,false],[[],8,false],[['scope_type'=>'product'],7,false]];}
	#[DataProvider('foreign_identity')]
	public function test_foreign_actor_authority_operation_or_site_never_gets_native_identity(string $key,mixed $value):void {
		$c=$this->authority->target('global',0);$tuple=$this->authority->identity_data('policy.assignment.read',$c,7,'original');$tuple[$key]=$value;$this->refusal(fn()=>$this->authority->identity($tuple));
	}
	public static function foreign_identity():array{return [['site_id',2],['principal','user:10'],['principal','staff:9'],['authority','refund_policy.configuration'],['operation','rule.publish'],['operation_version',2]];}
	public function test_wrong_offer_or_scope_target_cannot_reuse_an_original_grant():void {$c=$this->authority->target('global',0);$id=$this->identity();self::assertFalse($this->authority->authorize($id,$c,8));self::assertFalse($this->authority->authorize($id,$this->authority->target('product',10),7));}
	public function test_native_binding_stays_lazy_on_unsupported_database():void {$old=$GLOBALS['wpdb']??null;$GLOBALS['wpdb']=new \stdClass();try{$a=new NativePolicyAssignmentAuthority($this->offers);self::assertTrue($a->can_access());$this->refusal(fn()=>$a->binding());}finally{if(null===$old){unset($GLOBALS['wpdb']);}else{$GLOBALS['wpdb']=$old;}}}
}
