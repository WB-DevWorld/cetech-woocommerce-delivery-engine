<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\{CurrentPolicyFixture,CurrentPolicyTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/Support/CustomerPolicy/CurrentPolicyFixture.php';
final class CurrentPolicyReadConnectionTest extends TestCase{
 private CurrentPolicyFixture$f;protected function setUp():void{$this->f=new CurrentPolicyFixture();}protected function tearDown():void{$this->f->close();}
 public function test_read_owner_is_rc_readonly_and_never_sends_a_write_or_commit():void{$c=$this->f->connection();self::assertTrue($c->begin());$t=$this->f->transports[0];self::assertSame('READ-COMMITTED',$t->isolation);self::assertTrue($t->read_only);$before=count($t->sql);self::assertFalse($c->query('INSERT INTO forbidden VALUES (1)'));self::assertSame(OperationCommitResult::NotSent,$c->commit());self::assertSame($before,count($t->sql));self::assertTrue($c->in_transaction());self::assertSame([['a'=>1]],$c->get_results('SELECT 1 AS a'));self::assertTrue($c->rollback());self::assertTrue($c->retire());self::assertTrue($t->closed);}
 #[DataProvider('unsafe')]
 public function test_unknown_functions_external_effects_and_locking_reads_never_dispatch(string$sql):void{$c=$this->f->connection();self::assertTrue($c->begin());$t=$this->f->transports[0];$before=count($t->sql);self::assertFalse($c->get_results($sql));self::assertSame($before,count($t->sql));self::assertTrue($c->rollback());self::assertTrue($c->retire());}
 public static function unsafe():array{return[['SELECT GET_LOCK(\'unsafe\',0)'],['SELECT SLEEP(1)'],['SELECT 1 INTO OUTFILE \'/tmp/private\''],['SELECT 1; SELECT 2'],['SELECT 1 -- injected'],['SELECT 1 /*injected*/'],['SELECT 1 FOR UPDATE'],['SELECT unknown_function(1)'],['SELECT mysql.unknown_function(1)'],['DELETE FROM native_options'],['SET SESSION TRANSACTION READ WRITE']];}
 public function test_unicode_and_keywords_inside_literals_are_data():void{$c=$this->f->connection();self::assertTrue($c->begin());self::assertSame([['word'=>'DROP café']],$c->get_results("SELECT 'DROP café' AS word"));self::assertTrue($c->rollback());self::assertTrue($c->retire());}
 public function test_unconfirmed_readonly_configuration_retires_before_begin():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->filter_rows=static function(string$sql,array$rows):array{if(str_starts_with($sql,'SELECT @@session.tx_isolation'))$rows[0]['read_only']='0';return$rows;};};$c=$this->f->connection();self::assertFalse($c->begin());self::assertTrue($this->f->transports[0]->closed);self::assertNotContains('START TRANSACTION',$this->f->transports[0]->sql);}
 public function test_replaced_physical_connection_cannot_continue_reading():void{$c=$this->f->connection();self::assertTrue($c->begin());++$this->f->transports[0]->id;self::assertFalse($c->get_results('SELECT 1 AS a'));self::assertTrue($c->retire());}
}
