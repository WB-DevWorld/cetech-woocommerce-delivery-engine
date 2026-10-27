<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Integrations\CustomerPolicy\CustomerPolicyRuntimeGate;
use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementActivation,PromiseQuotePlacementActivation};
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\{CurrentPolicyFixture,CurrentPolicyTransport};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentUnitFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/Support/CustomerPolicy/CurrentPolicyFixture.php';
final class CustomerPolicyRuntimeGateTest extends TestCase{
 private CurrentPolicyFixture$f;private array$cache;private int$opens=0,$reads=0;
 protected function setUp():void{$this->f=new CurrentPolicyFixture();$this->cache=[];foreach(CustomerPolicyRuntimeGate::FLAGS as$flag){$name='cetech_de_'.$flag;$this->cache[$name]='1';$this->f->option($name,'1');}}
 protected function tearDown():void{$this->f->close();}
 private function gate(?callable$binding=null):CustomerPolicyRuntimeGate{return new CustomerPolicyRuntimeGate(new FeatureFlags(),function(array$b){++$this->opens;return$this->f->connection();},function(string$n):mixed{++$this->reads;return$this->cache[$n]??null;},$binding??static fn():array=>['site_id'=>1,'prefix'=>PolicyAssignmentUnitFactory::PREFIX]);}
 public function test_enabled_is_session_independent_and_opens_no_sql():void{self::assertTrue($this->gate()->enabled());self::assertSame(0,$this->opens);self::assertSame(8,$this->reads);}
 public function test_fresh_reads_actual_options_then_acknowledges_retirement():void{self::assertTrue($this->gate()->fresh());self::assertSame(1,$this->opens);self::assertTrue($this->f->transports[0]->closed);self::assertSame('READ-COMMITTED',$this->f->transports[0]->isolation);self::assertTrue($this->f->transports[0]->read_only);}
 #[DataProvider('wrong_flags')]
 public function test_default_off_or_noncanonical_cheap_flags_never_open_sql(mixed$v):void{$this->cache['cetech_de_enable_customer_policy_runtime']=$v;self::assertFalse($this->gate()->enabled());self::assertFalse($this->gate()->fresh());self::assertSame(0,$this->opens);}
 public static function wrong_flags():array{return[[null],[0],['0'],[true],[false],['01'],['1 '],['yes'],[1.0],[[]]];}
 #[DataProvider('basic_flags')]
 public function test_every_basic_capture_gate_is_required(string$flag):void{unset($this->cache['cetech_de_'.$flag]);self::assertFalse($this->gate()->enabled());self::assertSame(0,$this->opens);}
 public static function basic_flags():array{return array_map(static fn(string$f):array=>[$f],array_slice(CustomerPolicyRuntimeGate::FLAGS,1));}
 #[DataProvider('bad_adoptions')]
 public function test_malformed_or_enabled_adoption_never_allows_basic_fallback(string$name,mixed$bytes):void{$this->cache[$name]=$bytes;self::assertFalse($this->gate()->enabled());self::assertSame(0,$this->opens);}
 public static function bad_adoptions():array{$on=json_encode(['format'=>1,'profile'=>QuotePlacementActivation::PROFILE,'enabled'=>true,'revision'=>1],JSON_THROW_ON_ERROR);return[[QuotePlacementActivation::OPTION,'{}'],[QuotePlacementActivation::OPTION,$on],[QuotePlacementActivation::OPTION,false],[QuotePlacementActivation::OPTION,[]],[PromiseQuotePlacementActivation::OPTION,'{}'],[PromiseQuotePlacementActivation::OPTION,'future'],[PromiseQuotePlacementActivation::OPTION,false],[PromiseQuotePlacementActivation::OPTION,[]]];}
 public function test_stored_off_quote_adoption_is_distinct_from_missing_and_is_accepted():void{$bytes=json_encode(['enabled'=>false,'format'=>1,'profile'=>QuotePlacementActivation::PROFILE,'revision'=>2],JSON_THROW_ON_ERROR);$this->cache[QuotePlacementActivation::OPTION]=$bytes;$this->f->option(QuotePlacementActivation::OPTION,$bytes);self::assertTrue($this->gate()->enabled());self::assertTrue($this->gate()->fresh());}
 public function test_filtered_cache_cannot_hide_physical_disabled_flag():void{$this->f->option('cetech_de_enable_customer_policy_runtime','0');self::assertTrue($this->gate()->enabled());self::assertFalse($this->gate()->fresh());self::assertTrue($this->f->transports[0]->closed);}
 public function test_filtered_cache_cannot_hide_physical_adoption_on_or_malformed():void{$this->f->option(QuotePlacementActivation::OPTION,'{}');self::assertTrue($this->gate()->enabled());self::assertFalse($this->gate()->fresh());self::assertTrue($this->f->transports[0]->closed);}
 public function test_oversized_adoption_is_clipped_before_decode():void{$this->f->option(PromiseQuotePlacementActivation::OPTION,str_repeat('x',65537));self::assertFalse($this->gate()->fresh());$sql=implode("\n",$this->f->transports[0]->sql);self::assertStringContainsString('THEN 65536',$sql);self::assertTrue($this->f->transports[0]->closed);}
 public function test_foreign_or_changed_route_cannot_publish_read():void{$calls=0;$binding=static function()use(&$calls):array{return['site_id'=>++$calls===1?1:2,'prefix'=>PolicyAssignmentUnitFactory::PREFIX];};self::assertFalse($this->gate($binding)->fresh());self::assertTrue($this->f->transports[0]->closed);}
 public function test_unknown_or_duplicate_physical_option_name_is_not_ignored():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->filter_rows=static function(string$sql,array$rows):array{if(str_contains($sql,'THEN 65536'))$rows[]=$rows[0];return$rows;};};self::assertFalse($this->gate()->fresh());self::assertTrue($this->f->transports[0]->closed);}
 #[DataProvider('bad_ids')]
 public function test_native_option_identity_must_be_a_bounded_positive_integer(mixed$id):void{$this->f->configure=static function(CurrentPolicyTransport$t)use($id):void{$t->filter_rows=static function(string$sql,array$rows)use($id):array{if(str_contains($sql,'THEN 65536'))$rows[0]['option_id']=$id;return$rows;};};self::assertFalse($this->gate()->fresh());self::assertTrue($this->f->transports[0]->closed);}
 public static function bad_ids():array{return [[false],['0'],['01'],['1.0'],['9223372036854775808']];}
 public function test_failure_to_retire_keeps_the_gate_closed():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->reject_close=true;};self::assertFalse($this->gate()->fresh());$this->f->transports[0]->reject_close=false;}
 public function test_transport_loss_never_silently_uses_cached_enablement():void{$this->f->configure=static function(CurrentPolicyTransport$t):void{$t->error_sql='THEN 65536';};self::assertFalse($this->gate()->fresh());self::assertTrue($this->f->transports[0]->closed);}
}
