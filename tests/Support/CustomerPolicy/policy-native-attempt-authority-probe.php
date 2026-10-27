<?php
declare(strict_types=1);
// Genuine Prepared/issuer/Stage on equivalent native and in-memory SQL ports only.
$mode=$argv[1]??'authority';$backend=$argv[2]??'hpos';if(!in_array($backend,['hpos','cpt'],true))throw new RuntimeException('Unknown owned backend.');$argv[1]=$backend;define('R24_WRITER_PROBE_LIBRARY',true);require __DIR__.'/customer-policy-writer-probe.php';require_once __DIR__.'/customer-policy-attempt-fixture.php';
use CetechDeliveryEngine\Application\CustomerPolicy\{PolicyNativeOrderFacts as Facts,PolicyNativeOrderStager,PolicySavedOrderReader};
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyCheckoutRuntime;
use CetechDeliveryEngine\Tests\Support\CustomerPolicy\CustomerPolicyDomainFixture as Domain;

function denied(callable$work):bool{try{$work();return false;}catch(Throwable){return true;}}
$stager=new PolicyNativeOrderStager(new Factory());
if($mode==='wrong-key'||$mode==='preexisting'){
 $rejected=false;$preexisting=null;try{writer_initial_attempt($p,$stager,mint:$mode==='preexisting'?static function($order)use($p,$stager,&$preexisting):void{$preexisting=Domain::uuid(999);$order->add_meta_data(Facts::META_ATTEMPT,$preexisting,false);$stager->begin_attempt($order,$p);}:null,key:$mode==='wrong-key'?'foreign-key':'native-key');}catch(Throwable){$rejected=true;}
 echo json_encode(['rejected'=>$rejected,'permission'=>$stager->allows_initial_legacy_write(Probe::$order,$p),'marker_values'=>$preexisting===null?[]:[Probe::$order->get_meta(Facts::META_ATTEMPT,true)],'expected_existing'=>$preexisting,'physical_native_claim'=>false],JSON_THROW_ON_ERROR);return;
}
[$order,$item]=writer_initial_attempt($p,$stager);$registry=(new ReflectionProperty(PolicyNativeOrderStager::class,'attempts'))->getValue($stager);$claim=$registry[$order];$issued=$claim->issued_for($order,$p);$before=Facts::values(Facts::metadata($order),Facts::META_ATTEMPT);$out=['initial'=>$issued,'physical_native_claim'=>false];
if($mode==='authority'){
 $other=new PolicyNativeOrderStager(new Factory());$reconciled=$p->reconcile_original();$out+=['same_object'=>$stager->allows_initial_legacy_write($order,$p),'foreign_issuer_refused'=>!$other->owns_attempt($claim,$order,$p),'copied_order_refused'=>!$claim->issued_for(clone$order,$p),'reissued_handle_refused'=>!$claim->issued_for($order,$reconciled),'wrong_raw_key_refused'=>denied(static function()use($p):void{$s=new PolicyNativeOrderStager(new Factory());writer_initial_attempt($p,$s,124,457,false,key:'foreign-key');}),'marker_unchanged'=>Facts::values(Facts::metadata($order),Facts::META_ATTEMPT)===$before];
}elseif(in_array($mode,['duplicate','null','future','foreign','unknown_root','item'],true)){
 match($mode){'duplicate'=>$order->add_meta_data(Facts::META_ATTEMPT,$p->attempt_id(),false),'null'=>$order->replace(Facts::META_ATTEMPT,null),'future'=>$order->replace(Facts::META_ATTEMPT,'future-attempt-format-v99'),'foreign'=>$order->replace(Facts::META_ATTEMPT,Domain::uuid(999)),'unknown_root'=>$order->add_meta_data('_cetech_de_policy_future','future',false),'item'=>$item->add_meta_data(Facts::META_ATTEMPT,$p->attempt_id(),false)};
 $out+=['refused'=>!$stager->allows_initial_legacy_write($order,$p),'still_owned'=>Facts::attempted($order),'stage_refused'=>denied(static fn()=>$stager->stage($order,$p,static fn()=>true)),'revoked'=>!$stager->allows_initial_legacy_write($order,$p)&&!$claim->issued_for($order,$p)];
}elseif(str_starts_with($mode,'revoke-')){
 $kind=substr($mode,7);$authorize=static fn()=>true;
 if($kind==='authorize')$authorize=static fn()=>false;
 elseif($kind==='native')$order->paid();
 elseif($kind==='legacy')$item->remove(CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT);
 elseif($kind==='binding')(new ReflectionProperty(PolicyNativeOrderStager::class,'latch'))->setValue($stager,[]);
 elseif($kind==='physical')Probe::$mode='tables-refused';
 elseif($kind==='original'){$original=$p->original_witness()['original'];Probe::$db->exec('DELETE FROM owned_options WHERE option_id='.$original['row_id']);$q=Probe::$db->prepare('INSERT INTO owned_options(option_name,option_value,autoload) VALUES(?,?,?)');$q->execute([$original['option_name'],$original['bytes'],$original['autoload']]);}
 elseif($kind!=='runtime-legacy')throw new RuntimeException('Unknown revocation probe.');
 if($kind==='runtime-legacy'){$runtime=new PolicyCheckoutRuntime(new Factory(),$stager,new PolicySavedOrderReader(new Factory()),static fn()=>$p,static fn()=>false,static fn()=>true,static fn()=>true,validate_new_legacy:static fn()=>false);$errors=new class{public function has_errors():bool{return false;}public function add($code,$message):void{throw new RuntimeException('Unexpected preflight failure.');}};$runtime->early_classic([],$errors);$failed=denied(static fn()=>$runtime->stage_classic($order));}
 else$failed=denied(static fn()=>$stager->stage($order,$p,$authorize));
 Probe::$owned=false;$out+=['stage_refused'=>$failed,'revoked'=>!$stager->allows_initial_legacy_write($order,$p)&&!$claim->issued_for($order,$p),'permanent_marker_unchanged'=>Facts::values(Facts::metadata($order),Facts::META_ATTEMPT)===$before];
}elseif($mode==='privacy'){
 ob_start();var_dump($claim);$debug=(string)ob_get_clean();$class=get_class($claim);$raw='O:'.strlen($class).':"'.$class.'":0:{}';$out+=['clone_refused'=>denied(static fn()=>clone$claim),'json_refused'=>denied(static fn()=>json_encode($claim,JSON_THROW_ON_ERROR)),'serialize_refused'=>denied(static fn()=>serialize($claim)),'unserialize_refused'=>denied(static fn()=>unserialize($raw)),'debug_private'=>!str_contains($debug,$p->attempt_id())&&!str_contains($debug,'owned-packet')&&!str_contains($debug,'raw-owned-key')&&$claim->__debugInfo()===['native_policy_attempt'=>'private'],'original_permission_unchanged'=>$claim->issued_for($order,$p)];
}else throw new RuntimeException('Unknown owned authority probe.');
$out['getters_in_owned_sql']=Probe::$getters;echo json_encode($out,JSON_THROW_ON_ERROR);
