<?php
declare(strict_types=1);
/** Actual qualification wrapper with declared private-output/work ports; no WordPress or database. */
require dirname(__DIR__,3).'/scripts/qualification/customer-policy-history.php';
function r24_require(bool$ok):void{if(!$ok)throw new RuntimeException('PRIVATE-UNAPPROVED-REFUSAL');}
$GLOBALS['writes']=[];$GLOBALS['write_calls']=0;$GLOBALS['writer_failure']=false;
function r24_json(string$path,array$value):void{++$GLOBALS['write_calls'];if($GLOBALS['writer_failure'])throw new Error('PRIVATE-WRITER-PATH-AND-MESSAGE');$GLOBALS['writes'][]=$value;}
$case=$argv[1]??'runtime';$original=match($case){'invalid_argument'=>new InvalidArgumentException('PRIVATE-DATA'),'type_error'=>new TypeError('PRIVATE-DATA'),'error'=>new Error('PRIVATE-DATA'),'exception'=>new Exception('PRIVATE-DATA'),default=>new RuntimeException('PRIVATE-DATA')};
$prior=['step'=>'PRIVATE-PRIOR'];$GLOBALS['r24_hist_diagnostic_context']=$prior;$calls=0;$caught=null;$success=['saved_packet_bytes_unchanged'=>true];
try{r24_hist_observed_command($case==='invalid_mode'?'PRIVATE-MODE':($case==='no_current'?'history-no-current':'history-lifecycle'),'PRIVATE-PATH',static function()use($case,$original,&$calls,$success):array{
 ++$calls;r24_hist_diagnostic_step($case==='no_current'?'source_probe':'shipment_cards');
 if($case==='success')return$success;
 if($case==='writer_failure')$GLOBALS['writer_failure']=true;
 if($case==='unknown_label')$GLOBALS['r24_hist_diagnostic_context']['step']=['PRIVATE-ARBITRARY-DATA'];
 if($case==='cleanup_preserves')try{throw$original;}catch(Throwable$error){r24_hist_diagnostic_failure($error);throw$error;}finally{r24_hist_diagnostic_step('absent_restore');}
 if($case==='cleanup_replaces')try{throw new InvalidArgumentException('PRIVATE-EARLIER');}catch(Throwable$error){r24_hist_diagnostic_failure($error);throw$error;}finally{r24_hist_diagnostic_step('source_restore');throw$original;}
 throw$original;
 });}catch(Throwable$error){$caught=$error;}
echo json_encode(['physical_qualification'=>false,'caught_same'=>$caught===$original,'refused'=>$caught instanceof Throwable,'writes'=>$GLOBALS['writes'],'write_calls'=>$GLOBALS['write_calls'],'work_calls'=>$calls,'context_restored'=>$GLOBALS['r24_hist_diagnostic_context']===$prior],JSON_THROW_ON_ERROR),"\n";
