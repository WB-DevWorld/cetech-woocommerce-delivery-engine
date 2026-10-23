<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Qualification;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCartDraft, QuoteNativeContextIdentity};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteOwner};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeHandoffFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';
require_once dirname(__DIR__,3).'/scripts/qualification/opening-http-promotion-cart-support.php';

/** Pure address projection only: public typed facts grant no native capture or accepted C03 history. */
final class PromotionCheckoutAddressFixtureTest extends TestCase {
    private array $saved=[];
    protected function setUp(): void {
        foreach (['cetech_de_n17_native_identity_key','cetech_de_test_wc','wp_filter'] as $key) { $this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null]; }
        $GLOBALS['cetech_de_n17_native_identity_key']='private-promotion-native-handoff-fixture-key'; $GLOBALS['wp_filter']=[];
        $GLOBALS['cetech_de_test_wc']=new class { public function __get(string $name): never { throw new \LogicException('Global Woo customer must not supply this address.'); } };
    }
    protected function tearDown(): void {
        foreach ($this->saved as $key=>[$exists,$value]) { if ($exists) { $GLOBALS[$key]=$value; } else { unset($GLOBALS[$key]); } }
    }
    private function fixture(bool $two_lines=false,bool $two_groups=false): array {
        $f=F::create(); $draft=$f['draft']->private_facts(); $context=$f['evidence']->context()->base_context()->private_facts();
        $context['groups'][0]['endpoint_digest']=$context['destination']['digest'];
        if ($two_lines) {
            $line=$draft['lines'][0]; $line['line_key']='line_two'; $draft['lines'][]=$line;
            $line=$context['lines'][0]; $line['line_key']='line_two'; $context['lines'][]=$line; $context['groups'][0]['line_keys'][]='line_two';
            $selection=[]; foreach ($draft['lines'] as $line) { $selection[$line['line_key']]=$line['selection_hash']; }
            ksort($selection,SORT_STRING); $context['selection_digest']=hash('sha256',\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode($selection));
            if ($two_groups) {
                $component=hash('sha256','second explicit fixture group'); $context['lines'][1]['component_key']=$component;
                $group=$context['groups'][0]; $group['component_key']=$component; $group['line_keys']=['line_two'];
                $context['groups'][0]['line_keys']=['line_one']; $context['groups'][]=$group;
            }
        }
        return ['draft'=>QuoteCartDraft::from_private_facts($f['owner'],$draft),'context'=>QuoteContext::from_array($context),'owner'=>$f['owner'],'address'=>$f['destination']];
    }
    private function project(array $f): array {
        // Invoke the actual private pure projection, without fabricating placement evidence.
        return (new \ReflectionMethod(\CetechPromotionCartHttpFixture::class,'project_checkout_address'))->invoke(null,$f['draft'],$f['context'],$f['owner']);
    }
    public function test_explicit_line_address_works_with_no_global_customer_and_preserves_original_facts(): void {
        $f=$this->fixture(); $before=[$f['draft']->private_facts(),$f['context']->to_private_json(),$f['owner']->facts(),$GLOBALS['cetech_de_test_wc']];
        self::assertSame($f['address'],$this->project($f));
        self::assertSame($before,[$f['draft']->private_facts(),$f['context']->to_private_json(),$f['owner']->facts(),$GLOBALS['cetech_de_test_wc']]);
        self::assertSame($f['context']->private_facts()['destination']['digest'],QuoteNativeContextIdentity::from_server()->destination_digest($f['owner']->site_id(),$this->project($f)));
    }
    public function test_multiple_cart_lines_require_one_identical_explicit_destination(): void {
        $f=$this->fixture(true); self::assertCount(2,$f['draft']->private_facts()['lines']); self::assertSame($f['address'],$this->project($f));
    }
    public function test_multiple_groups_covering_the_same_full_endpoint_project_one_address(): void {
        $f=$this->fixture(true,true); self::assertCount(2,$f['context']->private_facts()['groups']); self::assertSame($f['address'],$this->project($f));
    }
    public static function refusals(): array {
        return array_map(static fn(string $case): array=>[$case],['foreign owner','changed quantity','missing address','different address','different offer','different selection offer','different group endpoint','different second group endpoint','different second line address','same identity different tuple','rotated epoch']);
    }
    #[DataProvider('refusals')]
    public function test_projection_refuses_incomplete_or_nonoriginal_destination(string $case): void {
        $f=$this->fixture(in_array($case,['different second group endpoint','different second line address','same identity different tuple'],true),'different second group endpoint'===$case); $draft=$f['draft']->private_facts(); $context=$f['context']->private_facts();
        switch ($case) {
            case 'foreign owner': $f['owner']=QuoteOwner::from_array(array_replace($f['owner']->facts(),['principal_hash'=>hash('sha256','different owner')])); break;
            case 'changed quantity': $draft['lines'][0]['quantity']='3'; break;
            case 'missing address': $draft['lines'][0]['customer_context']['delivery_address']=null; break;
            case 'different address': $draft['lines'][0]['customer_context']['delivery_address']['address_1']='PRIVATE-DIFFERENT-STREET'; break;
            case 'different offer': $draft['lines'][0]['customer_context']['delivery_offer_id']=21; break;
            case 'different selection offer': $draft['lines'][0]['selection']['delivery_offer_id']=21; break;
            case 'different group endpoint': $context['groups'][0]['endpoint_digest']=hash('sha256','different endpoint'); break;
            case 'different second group endpoint': $context['groups'][1]['endpoint_digest']=hash('sha256','different second endpoint'); break;
            case 'different second line address': $draft['lines'][1]['customer_context']['delivery_address']['address_1']='PRIVATE-DIFFERENT-STREET'; break;
            case 'same identity different tuple': $draft['lines'][1]['customer_context']['delivery_address']['city']='ACCRA'; break;
            case 'rotated epoch': $GLOBALS['cetech_de_n17_native_identity_key']='private-rotated-promotion-native-fixture-key'; break;
        }
        $f['draft']=QuoteCartDraft::from_private_facts($f['draft']->owner(),$draft,'rotated epoch'===$case?QuoteNativeContextIdentity::from_private_key('private-promotion-native-handoff-fixture-key'):null);
        $f['context']=QuoteContext::from_array($context);
        $this->expectException(\RuntimeException::class); $this->project($f);
    }
    public function test_public_wrapper_keeps_the_typed_evidence_endpoint_check(): void {
        // This older synthetic seed has an intentionally unrelated group endpoint.
        $f=F::create(); $this->expectException(\RuntimeException::class);
        \CetechPromotionCartHttpFixture::checkout_address($f['draft'],$f['evidence']);
    }
    public static function original_destination_fields(): array { return array_map(static fn(string $field): array=>[$field],['country','state','city','postcode','address','address_2']); }
    #[DataProvider('original_destination_fields')]
    public function test_complete_line_endpoint_cannot_replace_a_different_original_native_customer_field(string $field): void {
        $f=$this->fixture(); $draft=$f['draft']->private_facts(); $draft['customer_destination'][$field]='different original native field';
        $f['draft']=QuoteCartDraft::from_private_facts($f['owner'],$draft); $before=$f['draft']->private_facts();
        try { $this->project($f); self::fail('The original native checkout destination must agree before any order effect.'); }
        catch (\RuntimeException $error) { self::assertSame('N18 original draft checkout destination differs.',$error->getMessage()); self::assertSame($before,$f['draft']->private_facts()); }
    }
    public function test_blank_original_customer_address_refuses_despite_complete_accepted_line_endpoint(): void {
        $f=$this->fixture(); $draft=$f['draft']->private_facts(); $draft['customer_destination']=array_fill_keys(array_keys($draft['customer_destination']),'');
        $f['draft']=QuoteCartDraft::from_private_facts($f['owner'],$draft); $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('N18 original draft checkout destination differs.'); $this->project($f);
    }
}
