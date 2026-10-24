<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand,PolicyAssignmentState};
use CetechDeliveryEngine\Infrastructure\Persistence\PolicyAssignmentStore;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentFixture,PolicyAssignmentUnitFactory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/Support/PolicyAssignment/PolicyAssignmentFixture.php';

final class PolicyAssignmentStoreTest extends TestCase {
 private PolicyAssignmentFixture $f;
 protected function setUp():void{$this->f=new PolicyAssignmentFixture();}
 protected function tearDown():void{$this->f->close();}
 public function test_parent_is_body_witness_and_cannot_create_an_independent_reparented_row():void{
  $a=PolicyAssignmentState::make_coordinate(1,'variation',20,10);$b=PolicyAssignmentState::make_coordinate(1,'variation',20,11);self::assertSame($this->f->store->name($a),$this->f->store->name($b));
  self::assertSame('accepted',$this->f->attempt($this->f->payload($a,instructions:['return_policy_v1'=>['mode'=>'disabled']]))->outcome->state);$rows=$this->f->rows('options');$session=$this->f->factory->open();self::assertTrue($session->begin());$this->f->store->assert_ready($session);
  try{$this->f->store->current($session,$b);self::fail('Reparented body became current.');}catch(OperationStorageException){self::assertSame($rows,$this->f->rows('options'));}finally{$session->rollback();$session->retire();}
 }
 #[DataProvider('invalid_bindings')]
 public function test_invalid_native_store_bindings_refuse_before_queries(int $site,string $prefix):void{$this->expectException(OperationStorageException::class);new PolicyAssignmentStore($site,$prefix);}
 public static function invalid_bindings():array{return [[0,'wp_'],[1,''],[1,'other-db.wp_'],[1,str_repeat('a',60)]];}
 public function test_store_operations_refuse_outside_active_pinned_owner():void{
  $f=$this->f;$session=$f->factory->open();$c=PolicyAssignmentState::make_coordinate(1,'global',0);$this->blocked(fn()=>$f->store->current($session,$c));$session->begin();$this->blocked(fn()=>$f->store->current($session,$c));$f->store->assert_ready($session);self::assertSame(0,$f->store->current($session,$c)->revision());$session->rollback();$this->blocked(fn()=>$f->store->current($session,$c));$session->retire();$this->blocked(fn()=>$f->store->assert_ready($session));
 }
 public function test_capture_absence_is_read_only_and_does_not_initialize_guard_or_option():void{
  $f=$this->f;$session=$f->factory->open();$session->begin();$f->store->assert_ready($session);self::assertNull($f->store->guard($session,false));$state=$f->store->current($session,PolicyAssignmentState::make_coordinate(1,'product',22));self::assertSame(0,$state->row_id());self::assertSame(0,$state->revision());self::assertNull($state->generation());self::assertSame('',$state->original_bytes());self::assertSame(0,$f->count('options'));self::assertSame(0,$f->count('rule_family_guards'));$session->rollback();$session->retire();
 }
 public function test_save_preserves_existing_autoload_and_every_unowned_option():void{
  $f=$this->f;self::assertSame('accepted',$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]))->outcome->state);
  $db=$f->factory->inspection();$db->exec('UPDATE '.$f->table('options')." SET autoload='yes'");$db->exec('INSERT INTO '.$f->table('options')." (option_name,option_value,autoload) VALUES ('unowned_customer_terms','opaque-original','on')");
  self::assertSame('accepted',$f->attempt($f->payload(instructions:['refund_policy_v1'=>['mode'=>'disabled']]),'next')->outcome->state);$rows=$f->rows('options');self::assertSame('yes',$rows[0]['autoload']);self::assertSame(['option_id'=>2,'option_name'=>'unowned_customer_terms','option_value'=>'opaque-original','autoload'=>'on'],$rows[1]);
 }
 public function test_collated_alias_is_not_absence_or_replace_authority():void{
  $f=$this->f;$c=PolicyAssignmentState::make_coordinate(1,'global',0);$name=strtoupper($f->store->name($c));$f->factory->inspection()->exec('INSERT INTO '.$f->table('options').' (option_name,option_value,autoload) VALUES ('.$f->factory->inspection()->quote($name).",'foreign-case-alias','off')");$before=$f->rows('options');$session=$f->factory->open();$session->begin();$f->store->assert_ready($session);$this->blocked(fn()=>$f->store->current($session,$c));self::assertSame($before,$f->rows('options'));$session->rollback();$session->retire();
 }
 #[DataProvider('broken_current_bytes')]
 public function test_malformed_or_oversize_physical_value_is_never_empty_inherited_state(string $bytes):void{
  $f=$this->f;$c=PolicyAssignmentState::make_coordinate(1,'global',0);$f->factory->inspection()->exec('INSERT INTO '.$f->table('options').' (option_name,option_value,autoload) VALUES ('.$f->factory->inspection()->quote($f->store->name($c)).','.$f->factory->inspection()->quote($bytes).",'off')");$session=$f->factory->open();$session->begin();$f->store->assert_ready($session);$this->blocked(fn()=>$f->store->current($session,$c));self::assertSame($bytes,$f->rows('options')[0]['option_value']);$session->rollback();$session->retire();
 }
 public static function broken_current_bytes():array{return [[''],['null'],['{}'],['[]'],['{"format":2}'],[str_repeat('x',16385)],[str_repeat('é',9000)]];}
 #[DataProvider('broken_indexes')]
 public function test_native_options_requires_exact_full_single_unique_index(string $kind):void{
  $f=$this->f;$f->factory->filter_rows=static function(string $sql,array $rows)use($kind):array{if(!str_starts_with($sql,'SHOW INDEX')){return $rows;}if('missing'===$kind){return [$rows[0]];}if('prefix'===$kind){$rows[1]['Sub_part']='100';}if('nonunique'===$kind){$rows[1]['Non_unique']='1';}if('composite'===$kind){$rows[]=[...$rows[1],'Seq_in_index'=>'2','Column_name'=>'autoload'];}return $rows;};$session=$f->factory->open();$session->begin();$this->blocked(fn()=>$f->store->assert_ready($session));self::assertSame(0,$f->count('options'));$session->rollback();$session->retire();
 }
 public static function broken_indexes():array{return [['missing'],['prefix'],['nonunique'],['composite']];}
 public function test_wrong_physical_row_id_is_refused_even_with_same_original_bytes():void{
  $f=$this->f;$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]));$before=$f->state();$session=$f->factory->open();$session->begin();$f->store->assert_ready($session);$f->store->current($session,$before->coordinate());$c=$before->coordinate();$forged=PolicyAssignmentState::from_physical(1,'global',0,0,$before->row_id()+1,$before->original_bytes());$p=$f->payload(instructions:['refund_policy_v1'=>['mode'=>'disabled']]);$command=PolicyAssignmentCommand::from_array($f->identity($p,'new'),$p);$this->blocked(fn()=>$f->store->write($session,$forged,$command->desired_bytes('2026-10-24 10:00:01.000000')));self::assertSame($before->original_bytes(),$f->state()->original_bytes());$session->rollback();$session->retire();
 }
 public function test_fresh_readback_failure_rolls_back_the_entire_second_business_change():void{
  $f=$this->f;$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]));$before=$f->rows('options');$events=$f->count('operation_changes');$reads=0;$f->factory->on_query=static function(string $sql)use(&$reads):void{if(str_starts_with($sql,'SELECT option_id, option_name')&&2===++$reads){throw new \RuntimeException('Owned readback fault');}};
  $r=$f->attempt($f->payload(instructions:['refund_policy_v1'=>['mode'=>'disabled']]),'second');self::assertContains($r->outcome->state,['rejected','unconfirmed']);self::assertSame($before,$f->rows('options'));self::assertSame($events,$f->count('operation_changes'));
 }

 public function test_fresh_readback_must_confirm_original_autoload_not_only_value_bytes():void{
  $f=$this->f;$f->attempt($f->payload(instructions:['return_policy_v1'=>['mode'=>'disabled']]));$before=$f->rows('options');$events=$f->count('operation_changes');$reads=0;
  $f->factory->filter_rows=static function(string $sql,array $rows)use(&$reads):array{if(str_starts_with($sql,'SELECT option_id, option_name')&&2===++$reads){$rows[0]['autoload']='yes';}return $rows;};
  $r=$f->attempt($f->payload(instructions:['refund_policy_v1'=>['mode'=>'disabled']]),'autoload-readback');self::assertContains($r->outcome->state,['rejected','unconfirmed']);self::assertSame($before,$f->rows('options'));self::assertSame($events,$f->count('operation_changes'));
 }
 private function blocked(callable $work):void{try{$work();self::fail('Expected closed transport refusal.');}catch(OperationStorageException){self::assertTrue(true);}}
}
