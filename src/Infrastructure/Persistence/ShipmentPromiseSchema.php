<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Infrastructure\Persistence;

/** Additive private shipment history. Existing text ETA columns and histories remain unchanged. */
final class ShipmentPromiseSchema {
 public const SUFFIX = 'shipment_promises';
 public const JSON_BYTES = 65536;
 public const MIGRATION_ID = '20261009161941_create_shipment_promise_table';
 public const SUFFIXES = [self::SUFFIX];
 public static function tables(string $prefix):array { if(''===$prefix||1!==preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)||strlen($prefix.TableNames::PREFIX.self::SUFFIX)>64){throw new \InvalidArgumentException('Invalid shipment promise table prefix.');}return [$prefix.TableNames::PREFIX.self::SUFFIX]; }
 public static function charset_details(string $value):array{return PromiseStorageSchema::charset_details($value);}
 public static function columns(string $suffix=self::SUFFIX):array {
  if(self::SUFFIX!==$suffix){throw new \InvalidArgumentException('Unknown shipment promise table.');}
  $number=['bigint unsigned',false,null,'',null]; $ascii=static fn(string $type='char(64)',bool $null=false):array=>[$type,$null,null,'','ascii_bin'];$json=static fn(bool $null=false):array=>['longtext',$null,null,'','site'];$time=['datetime(6)',false,null,'',null];
  return ['id'=>['bigint unsigned',false,null,'auto_increment',null],'site_id'=>$number,'site_key'=>$ascii('varchar(64)'),'shipment_id'=>$number,'order_id'=>$number,'delivery_group_id'=>$ascii('varchar(191)'),'revision'=>$number,'original_json'=>$json(),'original_digest'=>$ascii(),'packet_json'=>$json(),'packet_digest'=>$ascii(),'current_json'=>$json(true),'current_digest'=>$ascii('char(64)',true),'original_namespace_hash'=>$ascii(),'original_intent_hash'=>$ascii(),'current_namespace_hash'=>$ascii('char(64)',true),'current_intent_hash'=>$ascii('char(64)',true),'created_at'=>$time,'updated_at'=>$time];
 }
 public static function indexes(string $suffix=self::SUFFIX):array {self::columns($suffix);return ['PRIMARY'=>['unique'=>true,'columns'=>['id']],'site_shipment'=>['unique'=>true,'columns'=>['site_id','shipment_id']],'site_order_group'=>['unique'=>true,'columns'=>['site_id','order_id','delivery_group_id']],'order_id'=>['unique'=>false,'columns'=>['order_id']]];}
 public static function payload_limits(string $suffix=self::SUFFIX):array{self::columns($suffix);return ['original_json'=>self::JSON_BYTES,'packet_json'=>self::JSON_BYTES,'current_json'=>self::JSON_BYTES];}
 public static function create_table_statements(string $charset_collate,?string $qualified_prefix=null):array {
  self::charset_details($charset_collate);$prefix=$qualified_prefix??((string)($GLOBALS['wpdb']->prefix??'wp_').TableNames::PREFIX);if(1!==preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)||strlen($prefix.self::SUFFIX)>64){throw new \InvalidArgumentException('Invalid shipment promise table prefix.');}$lines=[];
  foreach(self::columns() as $name=>[$type,$nullable,$default,$extra,$collation]){$line="  {$name} {$type}";if('ascii_bin'===$collation){$line.=' CHARACTER SET ascii COLLATE ascii_bin';}$line.=$nullable?' NULL':' NOT NULL';if(''!==$extra){$line.=' '.strtoupper($extra);}$lines[]=$line;}
  foreach(self::indexes() as $name=>$spec){$cols=implode(', ',$spec['columns']);$lines[]='PRIMARY'===$name?"  PRIMARY KEY  ({$cols})":'  '.($spec['unique']?'UNIQUE KEY':'KEY')." {$name} ({$cols})";}
  return [self::SUFFIX=>"CREATE TABLE {$prefix}".self::SUFFIX." (\n".implode(",\n",$lines)."\n) ENGINE=InnoDB {$charset_collate};"];
 }
 public static function required_markers():array{return [self::SUFFIX=>[...array_keys(self::columns()),'UNIQUE KEY site_shipment (site_id, shipment_id)','UNIQUE KEY site_order_group (site_id, order_id, delivery_group_id)','ENGINE=InnoDB']];}
}
