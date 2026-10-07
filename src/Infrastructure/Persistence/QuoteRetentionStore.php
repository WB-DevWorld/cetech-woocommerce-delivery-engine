<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Infrastructure\Persistence;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** Exact bounded private reads for the explicit retention profile, never a public repository. */
final class QuoteRetentionStore {
 public function __construct(private readonly OperationSession $session) {}
 public function table(string $suffix):string { return WpdbOperationRecordRepository::table_name($this->session,$suffix); }
 public function assert_ready():void { (new DeliveryQuoteReadiness($this->session))->assert_ready(); }
 public function now():QuoteTime { $row=$this->session->get_row('SELECT UTC_TIMESTAMP(6) AS retention_now');if(!is_array($row)||!is_string($row['retention_now']??null)) { self::fail(); }return QuoteTime::parse($row['retention_now']); }
 public function ceiling(string $suffix):int { $table=$this->table($suffix);$row=$this->session->get_row($this->session->prepare("SELECT MAX(id) AS ceiling FROM `{$table}` WHERE site_id=%d",$this->session->site_id()));if(!is_array($row)||!array_key_exists('ceiling',$row)) { self::fail(); }return null===$row['ceiling']?0:OperationRecord::positive_integer($row['ceiling']); }
 /** A malformed candidate consumes one inspection; it never becomes deletion authority. */
 public function page(string $suffix,int $cursor,int $ceiling,int $limit):array {
  if(!in_array($suffix,['delivery_quotes','delivery_quote_budget_windows'],true)||$cursor<0||$ceiling<$cursor||$limit<1||$limit>100) { self::fail(); }
  $rows=$this->session->get_results($this->projection($suffix).$this->session->prepare(' WHERE site_id=%d AND id>%d AND id<=%d ORDER BY id ASC LIMIT %d',$this->session->site_id(),$cursor,$ceiling,$limit));if(false===$rows||!array_is_list($rows)||count($rows)>$limit) { self::fail(); }
  $last=$cursor;foreach($rows as $row) { $id=OperationRecord::positive_integer($row['id']??null);if($id<=$last||$id>$ceiling) { self::fail(); }$last=$id; }return $rows;
 }
 public function quote(array $raw):?QuoteStoredRow { try { if(!array_key_exists('payload_oversized',$raw)||!in_array($raw['payload_oversized'],[0,'0'],true)) { return null; }unset($raw['payload_oversized']);$quote=QuoteStoredRow::from_row($raw);return $quote->site_id()===$this->session->site_id()?$quote:null; }catch(\Throwable) { return null; } }
 public function current_quote(int $id):?array { $rows=$this->session->get_results($this->projection('delivery_quotes').$this->session->prepare(' WHERE site_id=%d AND id=%d LIMIT 2 FOR UPDATE',$this->session->site_id(),$id));if(false===$rows||!array_is_list($rows)||count($rows)>1) { self::fail(); }return $rows[0]??null; }
 /** All original producer namespaces are locked in one global lexical order, including missing keys. */
 public function producer_fences(array $quotes):array {
  $hashes=[];foreach($quotes as $quote) { foreach($quote->header()->namespace_hashes() as $hash) { $hashes[$hash]=true; } }$keys=array_keys($hashes);sort($keys,SORT_STRING);$table=$this->table(OperationStoreSchema::RECORDS_SUFFIX);$out=[];
  foreach($keys as $hash) { $rows=$this->session->get_results($this->session->prepare($this->record_projection($table)." WHERE site_id=%d AND namespace_hash=%s LIMIT 2 FOR UPDATE",$this->session->site_id(),$hash));if(false===$rows||!array_is_list($rows)||count($rows)>1) { self::fail(); }$out[$hash]=$rows[0]??null; }return $out;
 }
 public function producer_safe(QuoteStoredRow $quote,array $fences,OperationProfileRegistry $profiles):bool {
  foreach($quote->header()->namespace_hashes() as $purpose=>$hash) {
   $raw=$fences[$hash]??null;if(null===$raw) { if('issue'===$purpose) { return false; }continue; }
   try { $raw=$this->bounded_record($raw);$profile=$profiles->get('delivery_quote.'.$purpose,1);$event=$this->event($raw);$record=OperationRecord::from_row($raw,$profile,$event);
    if($record->site_id!==$quote->site_id()||!hash_equals($hash,$record->namespace_hash)||'pending'===$record->state||'pending'===$record->publication_state) { return false; }
    if('accept'===$purpose&&'accepted'===$record->state) { return false; }
    if('issue'===$purpose&&'accepted'!==$record->state) { return false; }
    if('accepted'===$record->state) { $result=$record->completion->result;if(($record->completion->publication['site_id']??null)!==$quote->site_id()||($result['quote_id']??null)!==$quote->header()->id()->value()||($result['body_digest']??null)!==$quote->header()->body_digest()||($result['owner_digest']??null)!==$quote->header()->owner()->digest()||($result['namespace_hash']??null)!==$hash) { return false; }
     if('issue'===$purpose&&(($result['state']??null)!=='issued'||($result['quote_revision']??null)!==1)) { return false; }
     if('invalidate'===$purpose&&(($result['state']??null)!=='invalidated'||$quote->state()!=='invalidated'||($result['quote_revision']??null)!==$quote->revision()||($result['completed_at']??null)!==QuoteTime::parse($quote->row()['transition_at'])->epoch_microseconds())) { return false; }
    }
   }catch(\Throwable) { return false; }
  }return true;
 }
 /** Budget before quote; a lost/unknown lease remains protective. */
 public function admission_safe(QuoteStoredRow $quote):bool {
  $table=$this->table('delivery_quote_budget_windows');$rows=$this->session->get_results($this->session->prepare("SELECT * FROM `{$table}` WHERE site_id=%d AND admission_namespace_hash=%s LIMIT 2 FOR UPDATE",$this->session->site_id(),$quote->row()['issue_namespace_hash']));if(false===$rows||!array_is_list($rows)||count($rows)>1) { self::fail(); }if([]===$rows) { return false; }
  try { $slot=QuoteBudgetSlot::from_row($rows[0], 'consumed'===($rows[0]['lease_state']??null)?$quote:null);$data=$slot->row();return 'admission'===$slot->kind()&&'consumed'===$data['lease_state']&&$data['consumed_quote_uuid']===$quote->header()->id()->value(); }catch(\Throwable) { return false; }
 }
 public function binding_absent(QuoteStoredRow $quote):bool {
  $table=$this->table('delivery_quote_bindings');$rows=$this->session->get_results($this->session->prepare("SELECT id FROM `{$table}` WHERE site_id=%d AND quote_uuid=%s LIMIT 2 FOR UPDATE",$this->session->site_id(),$quote->header()->id()->value()));if(false===$rows||!array_is_list($rows)||count($rows)>1) { self::fail(); }return []===$rows;
 }
 public function counter(array $raw):?QuoteBudgetSlot { try { if(!array_key_exists('payload_oversized',$raw)||!in_array($raw['payload_oversized'],[0,'0'],true)) { return null; }unset($raw['payload_oversized']);$slot=QuoteBudgetSlot::from_row($raw);return $slot->site_id()===$this->session->site_id()?$slot:null; }catch(\Throwable) { return null; } }
 public function delete_counter(array $opened,QuoteTime $cutoff):string {
  $slot=$this->counter($opened);if(null===$slot) { return 'invalid'; }if('admission'===$slot->kind()) { return 'protected'; }
  $a=$slot->row();if(QuoteTime::parse($a['last_seen_at'])->plus_seconds(86400)->compare($cutoff)>0||QuoteTime::parse($a['window_start'])->plus_seconds(86460)->compare($cutoff)>0) { return 'protected'; }
  $table=$this->table('delivery_quote_budget_windows');$rows=$this->session->get_results($this->projection('delivery_quote_budget_windows').$this->session->prepare(' WHERE site_id=%d AND id=%d LIMIT 2 FOR UPDATE',$this->session->site_id(),$slot->id()));if(false===$rows||!array_is_list($rows)||count($rows)>1) { self::fail(); }if([]===$rows) { return 'disappeared'; }$current=$this->counter($rows[0]);if(null===$current) { return 'invalid'; }if($current->row()!==$a) { return 'changed'; }
  $where=[];$args=[];foreach($a as $key=>$value) { if(null===$value) { $where[]="`{$key}` IS NULL"; }else { $where[]=(is_string($value)?'BINARY ':'')."`{$key}`=".(is_int($value)?'%d':'%s');$args[]=$value; } }
  $affected=$this->session->query($this->session->prepare("DELETE FROM `{$table}` WHERE ".implode(' AND ',$where),...$args));if(1!==$affected) { self::fail(); }return 'counters_deleted';
 }
 public function prior(string $namespace,OperationProfileRegistry $profiles):OperationRecord {
  $table=$this->table(OperationStoreSchema::RECORDS_SUFFIX);$raw=$this->session->get_row($this->session->prepare($this->record_projection($table)." WHERE site_id=%d AND namespace_hash=%s LIMIT 1 FOR UPDATE",$this->session->site_id(),$namespace));if(!is_array($raw)) { self::fail(); }$raw=$this->bounded_record($raw);if(($raw['namespace_hash']??null)!==$namespace||($raw['site_id']??null)!==$this->session->site_id()&&($raw['site_id']??null)!==(string)$this->session->site_id()) { self::fail(); }$profile=$profiles->get($raw['operation'],OperationRecord::positive_integer($raw['operation_version']));$event=$this->event($raw);return OperationRecord::from_row($raw,$profile,$event);
 }
 private function record_projection(string $table):string { return "SELECT id,site_id,namespace_hash,intent_hash,namespace_format,intent_format,record_format,operation,operation_version,target_hash,state,publication_state,CASE WHEN OCTET_LENGTH(completion_json)<=16384 THEN completion_json ELSE NULL END AS completion_json,audit_id,row_version,created_at,updated_at,completed_at,CASE WHEN OCTET_LENGTH(completion_json)>16384 THEN 1 ELSE 0 END AS retention_oversized FROM `{$table}`"; }
 private function bounded_record(array $raw):array { if(!array_key_exists('retention_oversized',$raw)||!in_array($raw['retention_oversized'],[0,'0'],true)) { self::fail(); }unset($raw['retention_oversized']);return $raw; }
 /** Every parent was current-locked first. Exact accepted IDs avoid sparse absent-event gap locks. */
 private function event(array $parent):?array {
  $record_id=OperationRecord::positive_integer($parent['id']??null);$site=OperationRecord::positive_integer($parent['site_id']??null);
  if($site!==$this->session->site_id()||!in_array($parent['state']??null,['pending','rejected','not_applicable','accepted'],true)||!array_key_exists('audit_id',$parent)) { self::fail(); }
  $table=$this->table(OperationStoreSchema::CHANGES_SUFFIX);
  $projection="SELECT id,site_id,operation_id,event_format,CASE WHEN OCTET_LENGTH(event_json)<=16384 THEN event_json ELSE NULL END AS event_json,created_at,CASE WHEN OCTET_LENGTH(event_json)>16384 THEN 1 ELSE 0 END AS retention_oversized FROM `{$table}`";
  if('accepted'===$parent['state']) { $audit_id=OperationRecord::positive_integer($parent['audit_id']);$sql=$this->session->prepare($projection.' WHERE id=%d AND site_id=%d AND operation_id=%d LIMIT 2 FOR UPDATE',$audit_id,$site,$record_id); }
  else { if(null!==$parent['audit_id']) { self::fail(); }$sql=$this->session->prepare($projection.' WHERE site_id=%d AND operation_id=%d LIMIT 2',$site,$record_id); }
  // A valid producer cannot append while retention owns its parent namespace.
  // The bounded nonlocking reverse read still exposes an existing orphan event.
  $rows=$this->session->get_results($sql);if(false===$rows||!array_is_list($rows)||count($rows)>1) { self::fail(); }return isset($rows[0])?$this->bounded_record($rows[0]):null;
 }
 private function projection(string $suffix):string {
  $fields='delivery_quotes'===$suffix?QuoteStoredRow::FIELDS:QuoteBudgetSlot::FIELDS;$columns=[];$oversized=[];
  foreach($fields as $field) { $max=match($field) { 'header_json'=>4096,'private_body_json'=>65536,default=>null };if(null!==$max) { $columns[]="CASE WHEN OCTET_LENGTH(`{$field}`)<={$max} THEN `{$field}` ELSE NULL END AS `{$field}`";$oversized[]="OCTET_LENGTH(`{$field}`)>{$max}"; }else { $columns[]="`{$field}`"; } }
  $columns[]=([]===$oversized?'0':'CASE WHEN '.implode(' OR ',$oversized).' THEN 1 ELSE 0 END').' AS payload_oversized';return 'SELECT '.implode(',',$columns)." FROM `{$this->table($suffix)}`";
 }
 private static function fail():never { throw new OperationStorageException(); }
}
