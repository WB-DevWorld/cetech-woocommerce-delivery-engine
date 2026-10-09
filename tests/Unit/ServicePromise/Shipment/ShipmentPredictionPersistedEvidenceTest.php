<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Shipment;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteSavedOrderPlacementEvidenceReader;
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager;
use CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromiseService;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use PHPUnit\Framework\Attributes\{PreserveGlobalState,RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;

/** Native carrier choice only; persisted payment SQL is qualified on the disposable Woo site. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ShipmentPredictionPersistedEvidenceTest extends TestCase {
 private function service(callable $authorize):ShipmentPromiseService{$factory=new class implements OperationConnectionFactory{public function open():OperationSession{throw new \LogicException('No owner may open before native payment authority.');}};$repository=$this->createMock(ShipmentRepositoryInterface::class);foreach((new \ReflectionClass(ShipmentRepositoryInterface::class))->getMethods() as $method){$repository->expects(self::never())->method($method->getName());}return new ShipmentPromiseService(PromiseSiteBinding::bind(1,'site-1'),$factory,new QuoteSavedOrderPlacementEvidenceReader($factory,new QuoteNativeOrderStager($factory),static fn():bool=>false),$repository,$authorize);}
 protected function setUp():void{require_once dirname(__DIR__,3).'/Support/ServicePromise/Shipment/native-shipment-runtime-stubs.php';$GLOBALS['cetech_de_test_wc_orders']=[];$GLOBALS['cetech_de_test_persisted_wc_orders']=[];}
 protected function tearDown():void{unset($GLOBALS['cetech_de_test_wc_orders'],$GLOBALS['cetech_de_test_persisted_wc_orders']);}
 public function test_prediction_authority_receives_independent_persisted_timestamp_when_factory_cache_is_dirty():void{$saved_at=new \DateTimeImmutable('2026-10-09T12:00:00Z');$dirty_at=new \DateTimeImmutable('2026-10-12T12:00:00Z');$saved=new \WC_Order(['id'=>91,'status'=>'processing','date_paid'=>$saved_at]);$dirty=new \WC_Order(['id'=>91,'status'=>'processing','date_paid'=>$dirty_at]);$GLOBALS['cetech_de_test_persisted_wc_orders'][91]=$saved;$GLOBALS['cetech_de_test_wc_orders'][91]=$dirty;self::assertSame($dirty,wc_get_order(91));$calls=0;$service=$this->service(static function(\WC_Order $order,string $action,?int $actor)use(&$calls,$saved,$dirty,$saved_at):bool{++$calls;self::assertNotSame($dirty,$order);self::assertNotSame($saved,$order);self::assertSame(91,$order->get_id());self::assertSame($saved_at,$order->get_date_paid());self::assertSame('payment_confirmed',$action);self::assertNull($actor);return false;});$service->predict_paid_order($dirty);self::assertSame(1,$calls);self::assertSame($dirty_at,$dirty->get_date_paid());self::assertSame($saved_at,$saved->get_date_paid());}
 public function test_paid_cached_carrier_cannot_substitute_for_missing_independent_saved_order():void{$dirty=new \WC_Order(['id'=>91,'status'=>'processing','date_paid'=>new \DateTimeImmutable('2026-10-09T12:00:00Z')]);$GLOBALS['cetech_de_test_wc_orders'][91]=$dirty;$GLOBALS['cetech_de_test_persisted_wc_orders'][91]=new \WC_Order(['id'=>0]);$called=false;$service=$this->service(static function()use(&$called):bool{$called=true;return false;});$service->predict_paid_order($dirty);self::assertFalse($called);self::assertSame(91,$dirty->get_id());}
}
