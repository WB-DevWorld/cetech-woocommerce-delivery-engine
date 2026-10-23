<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\{EmergencyCheckoutAdmissionService, EmergencyCheckoutLocalBinding, EmergencyFinalPlacementGuard, EmergencyOwnership, EmergencyOwnershipClassifier, EmergencyOwnershipLatch};
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot, OrderDeliverySnapshotReader, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\CustomerContext\{CustomerCartContext, DeliveryAddress};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionNativeHandoffFixture as F, PromotionNativeUnitFixture as M};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/CheckoutTestFixtures.php';
require_once dirname(__DIR__,2).'/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';

/** Actual V4 reader/latch/C07 contracts over typed unit facts, never native SQL or sealed-history proof. */
final class PromotionOwnershipLatchTest extends TestCase {
    private array $globals=[];
    private array $modes=[];
    protected function setUp(): void {
        foreach (['current_user','blog_id','woocommerce','cetech_de_test_wc_products','cetech_de_test_options','_GET','_POST','_COOKIE'] as $key) { $this->globals[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null]; }
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) { foreach (['enabled','hpos'] as $property) { if (property_exists('\Automattic\WooCommerce\Utilities\OrderUtil',$property)) { $this->modes[$property]=\Automattic\WooCommerce\Utilities\OrderUtil::${$property}; } } }
    }
    protected function tearDown(): void {
        foreach ($this->globals as $key=>[$exists,$value]) { if ($exists) { $GLOBALS[$key]=$value; } else { unset($GLOBALS[$key]); } }
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) { foreach (['enabled','hpos'] as $property) { if (property_exists('\Automattic\WooCommerce\Utilities\OrderUtil',$property)) { \Automattic\WooCommerce\Utilities\OrderUtil::${$property}=$this->modes[$property]??false; } } }
    }
    /** Real public stager/reader construction; no reflection-created snapshot or acceptance grant. */
    private function fixture(?CustomerCartContext $latched_context=null): array {
        $model=M::create(); $seed=$model['fixture']; $line=$seed['draft']->private_facts()['lines'][0];
        $ack=F::acknowledged($seed,100,$model['mapping'],$model['guard']->evidence_digest());
        $stager=new QuoteNativeOrderStager($model['factory'],static fn(): \WC_Order=>clone $model['order']);
        $stager->stage_promotion($model['order'],$ack,$seed['draft'],$seed['tax_source'],'classic',static fn(): bool=>true);
        $staged=reset($model['order']->lines); $raw=$staged->get_meta(OrderDeliverySnapshot::META_LINE_SNAPSHOT,true);
        $item=new CheckoutPersistedItemFixture(['id'=>901,'product_id'=>10,'quantity'=>2,'meta'=>$staged->meta],100);
        $order=new \WC_Order(['id'=>100,'items'=>[$item],'meta'=>$model['order']->meta]);
        $latch=new EmergencyOwnershipLatch(); $original=$latched_context?->toArray()??$line['customer_context'];
        $latch->capture_line($line['line_key'],['product_id'=>10,'variation_id'=>0,'quantity'=>2,CustomerCartContext::CART_KEY=>$original],EmergencyOwnership::Managed);
        $latch->bind_order_line($line['line_key'],$item); $latch->freeze_saved_order($order);
        return compact('latch','item','order','raw','line');
    }
    public function test_real_readable_v4_context_matches_original_and_saved_reload_without_rewriting(): void {
        $f=$this->fixture(); $reader=new OrderDeliverySnapshotReader(); $read=$reader->read_line($f['item']);
        self::assertSame('',$read->error); self::assertSame(OrderDeliverySnapshot::VERSION_V4,$read->snapshot?->snapshot_version);
        self::assertTrue($read->delivery_quote->envelope->is_fixed_promotion());
        self::assertTrue($f['latch']->matches_order($f['order']));
        self::assertTrue($f['latch']->matches_order(new \WC_Order(['id'=>100,'items'=>[clone $f['item']]])));
        self::assertSame($f['raw'],$f['item']->get_meta(OrderDeliverySnapshot::META_LINE_SNAPSHOT,true));
    }
    public function test_v4_readability_does_not_make_a_different_original_customer_context_match(): void {
        $different=CustomerCartContext::delivery(20,address:DeliveryAddress::fromInput(['country'=>'GH','state'=>'AA','city'=>'Accra','postcode'=>'00001','address_1'=>'PRIVATE-OTHER-LATCH-DESTINATION']));
        $f=$this->fixture($different); self::assertSame('4',(new OrderDeliverySnapshotReader())->read_line($f['item'])->snapshot?->snapshot_version);
        self::assertFalse($f['latch']->matches_order($f['order']));
    }
    public static function corruptions(): array { return array_map(static fn(string $case): array=>[$case],['malformed','future outer','missing envelope','invalid envelope','wrong marker','missing marker']); }
    #[DataProvider('corruptions')]
    public function test_unknown_or_invalid_v4_packets_remain_refused_by_actual_reader_and_latch(string $case): void {
        $f=$this->fixture(); $item=clone $f['item']; $raw=json_decode($f['raw'],true,32,JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'malformed': $raw=null; break;
            case 'future outer': $raw['snapshot_version']='5'; $item->update_meta_data(OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION,'5'); break;
            case 'missing envelope': unset($raw[DeliveryQuoteSnapshotEnvelope::MEMBER]); break;
            case 'invalid envelope': $raw[DeliveryQuoteSnapshotEnvelope::MEMBER]=['format'=>3]; break;
            case 'wrong marker': $item->update_meta_data(DeliveryQuoteSnapshotEnvelope::META_FORMAT,'2'); break;
            case 'missing marker': $item->delete_meta_data(DeliveryQuoteSnapshotEnvelope::META_FORMAT); break;
        }
        $item->update_meta_data(OrderDeliverySnapshot::META_LINE_SNAPSHOT,null===$raw?'{':json_encode($raw,JSON_THROW_ON_ERROR));
        self::assertNull((new OrderDeliverySnapshotReader())->read_line($item)->snapshot);
        self::assertFalse($f['latch']->matches_order(new \WC_Order(['id'=>100,'items'=>[$item]])));
        self::assertTrue($f['latch']->matches_order($f['order']));
    }
    public static function identity_changes(): array { return array_map(static fn(string $case): array=>[$case],['site','order','item owner','item id','quantity','saved raw binding']); }
    #[DataProvider('identity_changes')]
    public function test_v4_recognition_preserves_site_saved_coordinates_and_raw_binding(string $case): void {
        $f=$this->fixture(); $item=clone $f['item']; $order_id=100;
        switch ($case) {
            case 'site': $GLOBALS['blog_id']=2; break;
            case 'order': $order_id=101; break;
            case 'item owner': $item->change_owner(101); break;
            case 'item id': $item->mutate_raw('id',902); break;
            case 'quantity': $item->view_quantity=3; break;
            case 'saved raw binding': $f['order']->set_status('cancelled'); break;
        }
        self::assertFalse($f['latch']->matches_order(new \WC_Order(['id'=>$order_id,'items'=>[$item]])));
    }
    public function test_c07_reaches_deny_only_completion_after_v4_correspondence_and_lock_release(): void {
        $f=$this->fixture(); $control=new CheckoutControlFixture(); $quote=new CheckoutQuoteFixture();
        $placement=new class($control) implements EmergencyFinalPlacementGuard {
            public int $calls=0;
            public bool $released=false;
            public bool $unchanged=false;
            public function __construct(private CheckoutControlFixture $control) {}
            public function complete(\WC_Order $order,string $route,int $control_revision,EmergencyCheckoutLocalBinding $binding): bool { ++$this->calls; $this->released=$this->control->released&&!$this->control->locked; $this->unchanged=$binding->unchanged(); return false; }
            public function admitted(\WC_Order $order,string $route,int $control_revision,EmergencyCheckoutLocalBinding $binding): bool { return false; }
        };
        $service=new EmergencyCheckoutAdmissionService($control,new EmergencyOwnershipClassifier([new CheckoutSourceFixture()]),$quote,$f['latch'],$placement);
        $result=$service->final_order($f['order'],'classic');
        self::assertSame(1,$placement->calls); self::assertSame(1,$control->confirms); self::assertTrue($placement->released); self::assertTrue($placement->unchanged);
        self::assertFalse($result->allowed); self::assertSame('checkout_revalidation_required',$result->code); self::assertFalse($service->admitted($f['order'],'classic'));
    }
}
