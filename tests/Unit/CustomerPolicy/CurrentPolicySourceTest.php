<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use CetechDeliveryEngine\Infrastructure\Persistence\CustomerPolicyCurrentSource;
use CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyCurrentProvider;
use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentState;
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\{CurrentPolicyFixture,CurrentPolicyTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/Support/CustomerPolicy/CurrentPolicyFixture.php';
final class CurrentPolicySourceTest extends TestCase {
 private CurrentPolicyFixture $f;
 protected function setUp():void{$this->f=new CurrentPolicyFixture();}
 protected function tearDown():void{$this->f->close();}
 private function source():CustomerPolicyCurrentSource{return new CustomerPolicyCurrentSource($this->f->resolver(),fn(array$b)=>$this->f->connection());}
 private function capture(array$changes=[]):array{return $this->source()->capture_batch(['line'=>$this->f->grant($changes)]);}
 private function refuse(callable$work):void{$error=null;try{$work();}catch(\RuntimeException$e){$error=$e;}self::assertInstanceOf(\RuntimeException::class,$error);}
 public function test_absence_has_two_explicit_not_assigned_families_and_no_writes():void{
  $before=$this->f->base->rows('options');$out=$this->capture();self::assertSame(['line'],array_keys($out['items']));
  foreach($out['items']['line']['families']as$family)self::assertSame('not_assigned',$family->disposition());self::assertSame($before,$this->f->base->rows('options'));
  $t=$this->f->transports[0];self::assertTrue($t->closed);self::assertTrue($t->read_only);self::assertSame('READ-COMMITTED',$t->isolation);
  self::assertCount(1,array_filter($t->sql,static fn(string$s):bool=>str_starts_with($s,'WITH cp_o AS')));
  self::assertFalse((bool)array_filter($t->sql,static fn(string$s):bool=>1===preg_match('/\A(?:INSERT|UPDATE|DELETE|COMMIT)\b/',$s)));
 }
 public function test_genuine_c01_return_and_refund_are_selected_independently():void{
  $f=$this->f->base;$f->publish('return_policy_v1',101,1,['customer_title'=>'Returns Ω','full_text'=>"Literal <b>not HTML</b>\nsecond line"]);$f->publish('refund_policy_v1',201,2,['customer_title'=>'Refunds café']);
  $this->f->seed(PolicyAssignmentState::make_coordinate(1,'global',0),['mode'=>'assigned','policy_uuid'=>$f::uuid(101)],['mode'=>'assigned','policy_uuid'=>$f::uuid(201)]);
  $out=$this->capture();self::assertSame('Returns Ω',$out['items']['line']['families']['return_policy_v1']->customer_fields()['customer_title']);self::assertSame('Refunds café',$out['items']['line']['families']['refund_policy_v1']->customer_fields()['customer_title']);self::assertTrue($this->f->transports[0]->closed);
 }
 public function test_one_batch_has_one_clock_and_time_does_not_change_material():void{
  $p=new CustomerPolicyCurrentProvider($this->source());$first=$p->capture_batch(['a'=>$this->f->grant(),'b'=>$this->f->grant(['cart_key'=>'second'])]);$this->f->base->factory->clock='2026-10-24 10:05:00.000001';$second=$p->capture_batch(['a'=>$this->f->grant()]);
  self::assertSame($first['a']->captured_at()->sql(),$first['b']->captured_at()->sql());self::assertNotSame($first['a']->captured_at()->sql(),$second['a']->captured_at()->sql());self::assertSame($first['a']->material_digest(),$second['a']->material_digest());self::assertCount(2,$this->f->transports);
 }
 public function test_variation_reassignment_overrides_product_disable_through_ecr():void{
  $f=$this->f->base;$f->publish('return_policy_v1',101,1);$this->f->seed(PolicyAssignmentState::make_coordinate(1,'product',33),['mode'=>'disabled']);$this->f->seed(PolicyAssignmentState::make_coordinate(1,'variation',44,33),['mode'=>'assigned','policy_uuid'=>$f::uuid(101)]);
  $out=$this->capture(['variation_id'=>44,'parent_id'=>33]);self::assertSame('assigned',$out['items']['line']['families']['return_policy_v1']->disposition());self::assertSame('not_assigned',$out['items']['line']['families']['refund_policy_v1']->disposition());
 }
 public function test_assignment_inserted_between_plan_and_final_refuses():void{
  $this->f->configure=function(CurrentPolicyTransport$t):void{$t->on_query=function(string$sql)use($t):void{if(str_starts_with($sql,'WITH cp_o AS')){$t->on_query=null;$this->f->seed(PolicyAssignmentState::make_coordinate(1,'product',33),['mode'=>'disabled']);}};};
  $this->refuse(fn()=>$this->capture());self::assertTrue($this->f->transports[0]->closed);
 }
 public function test_changed_logical_plan_refuses_without_retry():void{
  $f=$this->f->base;$f->publish('return_policy_v1',101,1);$this->f->seed(PolicyAssignmentState::make_coordinate(1,'global',0),['mode'=>'assigned','policy_uuid'=>$f::uuid(101)]);
  $this->f->configure=function(CurrentPolicyTransport$t)use($f):void{$t->on_query=function(string$sql)use($t,$f):void{if(str_starts_with($sql,'WITH cp_o AS')){$t->on_query=null;$f->factory->inspection()->exec('UPDATE '.$f->table('logical_rules').' SET revision=revision+1');}};};
  $this->refuse(fn()=>$this->capture());self::assertCount(1,$this->f->transports);
 }
 #[DataProvider('marker_faults')]
 public function test_invalid_markers_refuse_before_final(string$name,string$bytes):void{
  $this->f->option($name,$bytes);$this->refuse(fn()=>$this->capture());self::assertFalse((bool)array_filter($this->f->transports[0]->sql,static fn(string$s):bool=>str_starts_with($s,'WITH')));
 }
 public static function marker_faults():array{return [['cetech_de_db_version','11'],['cetech_de_db_version','012'],['cetech_de_db_version',str_repeat('x',16385)],['cetech_de_last_migration_status','not serialized'],['cetech_de_last_migration_status',serialize(['status'=>'running','to_version'=>'12','migration_id'=>'fixture'])],['cetech_de_last_migration_status',serialize(['status'=>'success','to_version'=>'11','migration_id'=>'fixture'])]];}
 public function test_unknown_assigned_policy_aborts_without_inheriting():void{$f=$this->f->base;$this->f->seed(PolicyAssignmentState::make_coordinate(1,'global',0),['mode'=>'assigned','policy_uuid'=>$f::uuid(999)]);$this->refuse(fn()=>$this->capture());self::assertTrue($this->f->transports[0]->closed);}
 public function test_unselected_private_catalog_is_not_fetched():void{$f=$this->f->base;$f->publish('return_policy_v1',101,1);$out=$this->capture();self::assertSame('not_assigned',$out['items']['line']['families']['return_policy_v1']->disposition());$sql=implode("\n",$this->f->transports[0]->sql);self::assertStringContainsString('logical_rule_id IN (0)',$sql);self::assertStringNotContainsString($f::uuid(101),$sql);}
 public function test_lost_final_reply_retires_without_partial_result():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->error_sql='WITH cp_o AS';};$this->refuse(fn()=>$this->capture());self::assertTrue($this->f->transports[0]->closed);}
 public function test_failed_retirement_never_publishes_valid_read():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->reject_close=true;};$this->refuse(fn()=>$this->capture());self::assertFalse($this->f->transports[0]->closed);$this->f->transports[0]->reject_close=false;}
 public function test_revoked_resource_after_rollback_cannot_publish():void{$this->f->configure=function(CurrentPolicyTransport$t):void{$t->on_query=function(string$sql):void{if('ROLLBACK'===$sql)$this->f->granted=false;};};$this->refuse(fn()=>$this->capture());self::assertTrue($this->f->transports[0]->closed);}
 public function test_aggregate_budget_failure_cannot_publish_any_private_rows():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->filter_rows=static function(string$sql,array$rows):array{if(str_starts_with($sql,'WITH'))return[array_replace($rows[0],['budget_ok'=>'0','source_bytes'=>'2097153','source_rows'=>'0'])];return$rows;};};$this->refuse(fn()=>$this->capture());self::assertTrue($this->f->transports[0]->closed);}
 public function test_oversized_logical_scope_is_clipped_before_final():void{$f=$this->f->base;$f->publish('return_policy_v1',101,1);$this->f->seed(PolicyAssignmentState::make_coordinate(1,'global',0),['mode'=>'assigned','policy_uuid'=>$f::uuid(101)]);$f->factory->inspection()->exec('UPDATE '.$f->table('logical_rules').' SET scope_json='.$f->factory->inspection()->quote(str_repeat('x',4097)));$this->refuse(fn()=>$this->capture());self::assertFalse((bool)array_filter($this->f->transports[0]->sql,static fn(string$s):bool=>str_starts_with($s,'WITH')));}
 public function test_option_plan_aggregate_overflow_transports_no_private_values_before_decode():void{
  $grants=[];for($i=0;$i<200;++$i){$id=100+$i;$grants['line-'.$i]=$this->f->grant(['product_id'=>$id,'cart_key'=>'line-'.$i]);$c=PolicyAssignmentState::make_coordinate(1,'product',$id);$this->f->option($this->f->base->store->name($c),str_repeat('x',12000));}
  $seen=[];$this->f->configure=static function(CurrentPolicyTransport$t)use(&$seen):void{$t->filter_rows=static function(string$sql,array$rows)use(&$seen):array{if(str_starts_with($sql,'SELECT option_id,option_name,CASE')){$seen=$rows;}return$rows;};};
  $this->refuse(fn()=>$this->source()->capture_batch($grants));self::assertCount(202,$seen);self::assertSame([],array_values(array_filter($seen,static fn(array$r):bool=>null!==$r['option_value'])));self::assertFalse((bool)array_filter($this->f->transports[0]->sql,static fn(string$q):bool=>str_starts_with($q,'WITH')));
 }
 public function test_input_overflow_opens_no_sql():void{$grants=[];for($i=0;$i<201;++$i)$grants['line-'.$i]=$this->f->grant();$this->refuse(fn()=>$this->source()->capture_batch($grants));self::assertSame([],$this->f->transports);}
}
