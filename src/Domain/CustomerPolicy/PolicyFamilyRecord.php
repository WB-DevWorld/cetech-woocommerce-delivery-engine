<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerPolicy;

use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap,PolicyAssignmentState,PolicyEffectiveSelection};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyContent,ReturnPolicyFamily,ReturnPolicyVersion};
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyContent,RefundPolicyFamily,RefundPolicyVersion};
use CetechDeliveryEngine\Domain\RuleLifecycle\{LogicalRule,RuleCandidate,RuleFamilyGuard,RuleFamilyProfile,RuleSnapshot,RuleState,RuleTime,RuleVersion};

/** Self-contained immutable policy wording and its original C01/source witnesses. */
final readonly class PolicyFamilyRecord implements \JsonSerializable {
    public const FORMAT=1;
    public const MAX_BYTES=131072;
    public const FAMILIES=['return_policy_v1','refund_policy_v1'];
    private function __construct(private string $bytes){}

    /** A real complete ECR selection is compared with its exact captured chain, never resolved from DTOs. */
    public static function capture(PolicyEffectiveSelection $selection,array $coordinate,int $real_offer,array $states,?RuleSnapshot $snapshot):self {
        $family=$selection->family();self::definition($family);$at=$selection->evaluated_at();
        [$chain,$fingerprint,$provenance,$instruction]=self::chain($coordinate,$real_offer,$states,$family,$at);
        $disposition=$selection->disposition();$assigned='assigned'===$disposition;
        if(!in_array($disposition,['assigned','disabled','not_assigned'],true)||self::mode($instruction)!==$disposition||PolicyJson::encode((object)$selection->provenance())!==PolicyJson::encode((object)$provenance)){self::fail();}
        $content=$reference=$guard=$logical=$version=$candidate_digest=null;
        if($assigned){
            if(null===$snapshot||!$snapshot->complete||null===$snapshot->guard||$snapshot->guard->site_id!==$coordinate['site_id']){self::fail();}
            $expected=PolicyEffectiveSelection::assigned($family,$snapshot,$instruction['policy_uuid'],$at,$provenance,$fingerprint);
            if($expected->fingerprint()!==$selection->fingerprint()||null===$selection->version()||$expected->version()?->reference()->facts()!==$selection->version()->reference()->facts()){self::fail();}
            $selected=$expected->version();$reference=$selected->reference()->facts();$content=$selected->content()->facts();
            foreach($snapshot->candidates()as$candidate){if($candidate->version->id===$reference['version_id']&&$candidate->version->version_uuid===$reference['version_uuid']){if(null!==$version){self::fail();}$guard=$snapshot->guard->row();$logical=$candidate->logical->row();$version=$candidate->version->row();}}
            if(null===$version){self::fail();}$candidate_digest=$snapshot->candidate_digest();
        } else {
            if(null!==$snapshot||null!==$selection->version()){self::fail();}
            $expected='disabled'===$disposition?PolicyEffectiveSelection::disabled($family,$at,$provenance,$fingerprint):PolicyEffectiveSelection::not_assigned($family,$at,$provenance,$fingerprint);
            if($expected->fingerprint()!==$selection->fingerprint()){self::fail();}
        }
        $data=['format'=>1,'family'=>$family,'definition_hash'=>self::definition($family),'disposition'=>$disposition,'content'=>null===$content?null:(object)$content,
            'source'=>(object)['evaluated_at'=>$at->sql(),'coordinate'=>(object)$coordinate,'real_offer_id'=>$real_offer,'chain'=>$chain,'assignment_fingerprint'=>$fingerprint,'provenance'=>(object)$provenance,'selection_fingerprint'=>$selection->fingerprint(),'candidate_digest'=>$candidate_digest,'guard'=>null===$guard?null:(object)$guard,'logical'=>null===$logical?null:(object)$logical,'version'=>null===$version?null:(object)$version,'reference'=>null===$reference?null:(object)$reference]];
        return self::from_private_json(PolicyJson::encode($data,self::MAX_BYTES));
    }

    public static function from_private_json(string $json):self { try { return self::parse_private($json); } catch(\Throwable) { self::fail(); } }
    private static function parse_private(string $json):self {
        $data=PolicyJson::fields(PolicyJson::decode($json,self::MAX_BYTES),['format','family','definition_hash','disposition','content','source']);
        if(1!==$data['format']||!is_string($data['family'])||!is_string($data['disposition'])||!in_array($data['disposition'],['assigned','disabled','not_assigned'],true)||$data['definition_hash']!==self::definition($data['family'])){self::fail();}
        $source=PolicyJson::fields($data['source'],['evaluated_at','coordinate','real_offer_id','chain','assignment_fingerprint','provenance','selection_fingerprint','candidate_digest','guard','logical','version','reference']);
        if(!is_string($source['evaluated_at'])){self::fail();}$at=RuleTime::parse($source['evaluated_at']);$coordinate=PolicyJson::fields($source['coordinate'],['site_id','scope_type','scope_id','parent_id']);
        $states=[];foreach(PolicyJson::list($source['chain'],3,1)as$row){$row=PolicyJson::fields($row,['coordinate','row_id','bytes']);$c=PolicyJson::fields($row['coordinate'],['site_id','scope_type','scope_id','parent_id']);if(!is_string($row['bytes'])){self::fail();}$id=PolicyJson::integer($row['row_id'],0);$states[]=0===$id&&''===$row['bytes']?PolicyAssignmentState::absent($c['site_id'],$c['scope_type'],$c['scope_id'],$c['parent_id']):PolicyAssignmentState::from_physical($c['site_id'],$c['scope_type'],$c['scope_id'],$c['parent_id'],$id,$row['bytes']);}
        [, $fingerprint,$provenance,$instruction]=self::chain($coordinate,PolicyJson::integer($source['real_offer_id']),$states,$data['family'],$at);
        if($fingerprint!==PolicyJson::digest($source['assignment_fingerprint'])||self::mode($instruction)!==$data['disposition']||PolicyJson::encode((object)$provenance)!==PolicyJson::encode($source['provenance'])){self::fail();}
        if('assigned'===$data['disposition']){
            $profile=self::profile($data['family']);$guard=RuleFamilyGuard::from_row(self::plain($source['guard']),$profile);$logical=LogicalRule::from_row(self::plain($source['logical']),$guard,$profile);$version=RuleVersion::from_row(self::plain($source['version']),$logical,$profile);$candidate=new RuleCandidate($logical,$version);
            if($guard->site_id!==$coordinate['site_id']||$guard->updated_at->compare($at)>0||$logical->logical_uuid!==$instruction['policy_uuid']||$logical->scope()!==['policy_uuid'=>$instruction['policy_uuid']]||$logical->current_published_version_id!==$version->id||RuleState::Published!==$version->state||$version->hypothetical||0!==$version->priority||!$version->eligible_at($at)){self::fail();}
            $selected='return_policy_v1'===$data['family']?ReturnPolicyVersion::from_candidate($candidate,$profile):RefundPolicyVersion::from_candidate($candidate,$profile);
            if(PolicyJson::encode((object)$selected->reference()->facts())!==PolicyJson::encode($source['reference'])||PolicyJson::encode((object)$selected->content()->facts())!==PolicyJson::encode($data['content'])){self::fail();}
            $selection=self::selection_fingerprint($data['family'],'assigned',$fingerprint,$provenance,['family_revision'=>$guard->revision,'candidate_digest'=>PolicyJson::digest($source['candidate_digest']),'reference'=>$selected->reference()->facts(),'authored_content_digest'=>$selected->content()->digest(),'metadata'=>$selected->metadata()]);
        } else {
            foreach(['candidate_digest','guard','logical','version','reference']as$key){if(null!==$source[$key]){self::fail();}}if(null!==$data['content']){self::fail();}
            $selection=('disabled'===$data['disposition']?PolicyEffectiveSelection::disabled($data['family'],$at,$provenance,$fingerprint):PolicyEffectiveSelection::not_assigned($data['family'],$at,$provenance,$fingerprint))->fingerprint();
        }
        if($selection!==PolicyJson::digest($source['selection_fingerprint'])||PolicyJson::encode($data,self::MAX_BYTES)!==$json){self::fail();}return new self($json);
    }
    public function private_json():string{return $this->bytes;}
    /** Detached private facts; nested objects retain the persisted closed shape. */
    public function facts():array{return PolicyJson::fields(PolicyJson::decode($this->bytes,self::MAX_BYTES),['format','family','definition_hash','disposition','content','source']);}
    public function family():string{return $this->facts()['family'];}
    public function disposition():string{return $this->facts()['disposition'];}
    public function customer_fields():array{$f=$this->facts();$out=['disposition'=>$f['disposition']];foreach(['customer_title','summary','full_text','exceptions']as$key){$out[$key]='assigned'===$f['disposition']?$f['content']->{$key}:null;}return $out;}
    public function digest():string{return hash('sha256','cetech-customer-policy-family-v1:'.$this->bytes);}
    public function material_digest():string{$f=$this->facts();unset($f['source']->evaluated_at);return hash('sha256','cetech-customer-policy-family-material-v1:'.PolicyJson::encode($f,self::MAX_BYTES));}
    public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return['customer_policy_family'=>'redacted'];}

    /** Replays the original chain's equality witness, not a new resolution or authorization API. */
    private static function chain(array $coordinate,int $offer,array $states,string $family,RuleTime $at):array{
        PolicyJson::fields($coordinate,['site_id','scope_type','scope_id','parent_id']);$coordinate=PolicyAssignmentState::make_coordinate($coordinate['site_id'],$coordinate['scope_type'],$coordinate['scope_id'],$coordinate['parent_id']);if($offer<1||!array_is_list($states)){self::fail();}
        $site=$coordinate['site_id'];$expected=[PolicyAssignmentState::make_coordinate($site,'global',0)];if('product'===$coordinate['scope_type']){$expected[]=$coordinate;}elseif('variation'===$coordinate['scope_type']){$expected[]=PolicyAssignmentState::make_coordinate($site,'product',$coordinate['parent_id']);$expected[]=$coordinate;}
        if(count($states)!==count($expected)){self::fail();}$saved=$witnesses=[];$provenance=[];$instruction=['mode'=>'inherit'];
        foreach($states as$i=>$state){if(!$state instanceof PolicyAssignmentState||$state->coordinate()!==$expected[$i]||null!==$state->changed_at()&&$state->changed_at()->compare($at)>0){self::fail();}$maps=[];foreach(self::FAMILIES as$f){$maps[$f]=$state->map($f)->digest();}$witnesses[]=(object)['coordinate'=>(object)$expected[$i],'row_id'=>$state->row_id(),'revision'=>$state->revision(),'generation'=>$state->generation(),'digest'=>$state->digest(),'maps'=>(object)$maps];$saved[]=(object)['coordinate'=>(object)$expected[$i],'row_id'=>$state->row_id(),'bytes'=>$state->original_bytes()];
            $selected=$state->map($family)->instruction($offer);if('inherit'!==$selected['instruction']['mode']){$instruction=$selected['instruction'];$provenance=['scope_type'=>$expected[$i]['scope_type'],'scope_id'=>$expected[$i]['scope_id'],'parent_id'=>$expected[$i]['parent_id'],'slot'=>0===$selected['slot']?'default':'offer','offer_id'=>$selected['slot'],'row_id'=>$state->row_id(),'revision'=>$state->revision(),'generation'=>$state->generation(),'assignment_digest'=>$state->digest()];}}
        $material=OperationJson::encode((object)['coordinate'=>(object)$coordinate,'offer_id'=>$offer,'states'=>$witnesses]);return[$saved,hash('sha256','cetech-policy-assignment-chain-v1:'.$material),$provenance,$instruction];
    }
    private static function mode(array$instruction):string{return match($instruction['mode']){'assigned'=>'assigned','disabled'=>'disabled','inherit'=>'not_assigned',default=>throw new \InvalidArgumentException('Unsupported policy disposition.')};}
    private static function selection_fingerprint(string$family,string$disposition,string$assignment,array$provenance,array$captured):string{$material=OperationJson::encode((object)['format'=>1,'family'=>$family,'definition_hash'=>self::definition($family),'disposition'=>$disposition,'code'=>'policy_assigned','assignment_fingerprint'=>$assignment,'provenance'=>$provenance,'captured'=>(object)$captured]);return hash('sha256','cetech-effective-policy-selection-v1:'.$material);}
    private static function definition(string$family):string{return match($family){'return_policy_v1'=>ReturnPolicyFamily::definition_hash(),'refund_policy_v1'=>RefundPolicyFamily::definition_hash(),default=>throw new \InvalidArgumentException('Unsupported customer policy family.')};}
    private static function profile(string$family):RuleFamilyProfile{$deny=static fn():bool=>false;return match($family){'return_policy_v1'=>new ReturnPolicyFamily($deny,$deny,$deny),'refund_policy_v1'=>new RefundPolicyFamily($deny,$deny,$deny),default=>throw new \InvalidArgumentException('Unsupported customer policy family.')};}
    private static function plain(mixed$v):array{if(!$v instanceof \stdClass||\stdClass::class!==get_class($v)){self::fail();}return get_object_vars($v);}
    private static function private_only():never{throw new \LogicException('Customer policy history requires an explicit private projection.');}
    private static function fail():never{throw new \InvalidArgumentException('Invalid immutable customer policy family.');}
}
