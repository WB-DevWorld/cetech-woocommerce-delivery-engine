<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\CustomerPolicy;
use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver,EffectiveConfigurationValidator};
use CetechDeliveryEngine\Domain\CustomerPolicy\{PolicyFamilyRecord,PolicyLineage,PolicyLinePacket};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap,PolicyAssignmentState,PolicyEffectiveSelection};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentDomainFixture as F;

/** Genuine typed C01 and ECR fixture; no shopper, writer, SQL or grant authority. */
final class CustomerPolicyDomainFixture {
    public const NOW=F::NOW;
    public static function uuid(int$id):string{return F::uuid($id);}
    public static function capture(string$family='return_policy_v1',string$mode='assigned',bool$variation=false,array$content=[],?string$at=null):array {
        $at=RuleTime::parse($at??self::NOW);$coordinate=PolicyAssignmentState::make_coordinate(1,$variation?'variation':'product',$variation?44:33,$variation?33:0);$maps=[];
        foreach(PolicyFamilyRecord::FAMILIES as$f){$maps[$f]=PolicyAssignmentMap::empty(1,$f)->with_instruction(0,'assigned'===$mode?['mode'=>'assigned','policy_uuid'=>F::uuid(101)]:['mode'=>'disabled'===$mode?'disabled':'inherit']);}
        $global=F::state('global',0,0,50,1,$maps['return_policy_v1'],$maps['refund_policy_v1']);$states=[$global,PolicyAssignmentState::absent(1,'product',33)];if($variation){$states[]=PolicyAssignmentState::absent(1,'variation',44,33);}
        $resolver=new EffectiveConfigurationResolver(new InMemoryScopedConfigurationRepository(),new EffectiveConfigurationValidator());$resolved=$resolver->resolve_policy_instructions($coordinate,4,$states);$snapshot='assigned'===$mode?F::snapshot($family,content_changes:$content):null;if(null!==$snapshot){$snapshot=new \CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot($snapshot->profile,$snapshot->guard,$snapshot->logicals,$snapshot->versions,true,true);}
        $selection=match($mode){'assigned'=>PolicyEffectiveSelection::assigned($family,$snapshot,F::uuid(101),$at,$resolved['provenance'][$family],$resolved['fingerprint']),'disabled'=>PolicyEffectiveSelection::disabled($family,$at,$resolved['provenance'][$family],$resolved['fingerprint']),'unresolved'=>PolicyEffectiveSelection::unresolved($family,$at,$resolved['provenance'][$family],$resolved['fingerprint']),default=>PolicyEffectiveSelection::not_assigned($family,$at,$resolved['provenance'][$family],$resolved['fingerprint'])};
        $record='unresolved'===$mode?null:PolicyFamilyRecord::capture($selection,$coordinate,4,$states,$snapshot);
        return compact('record','selection','snapshot','coordinate','states','resolved','at');
    }
    public static function families(bool$variation=false):array{$out=[];foreach(PolicyFamilyRecord::FAMILIES as$f){$out[$f]=self::capture($f,variation:$variation)['record'];}return$out;}
    public static function lineage(array$changes=[]):PolicyLineage{return PolicyLineage::from_array(array_replace(['format'=>1,'site_id'=>1,'owner_uuid'=>F::uuid(700),'owner_digest'=>str_repeat('a',64),'line_uuid'=>F::uuid(701),'order_id'=>123,'item_id'=>456,'product_id'=>33,'variation_id'=>0,'parent_id'=>0,'quantity'=>'1','real_offer_id'=>4,'cart_line_digest'=>PolicyLineage::cart_line_digest(1,'native-key'),'customer_context_digest'=>str_repeat('b',64),'raw_package_digest'=>str_repeat('c',64),'raw_line_digest'=>str_repeat('d',64),'presentation_material_digest'=>str_repeat('e',64)],$changes));}
    public static function packet(array$changes=[],bool$variation=false):PolicyLinePacket{$families=self::families($variation);if($variation){$changes=array_replace(['variation_id'=>44,'parent_id'=>33],$changes);}return PolicyLinePacket::freeze(self::lineage($changes),$families['return_policy_v1'],$families['refund_policy_v1'],RuleTime::parse(self::NOW));}
}
