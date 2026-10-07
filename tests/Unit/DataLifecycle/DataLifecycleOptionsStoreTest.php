<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use PHPUnit\Framework\TestCase;

final class DataLifecycleOptionsStoreTest extends TestCase {
	public function test_actual_native_owner_validates_options_innodb_and_full_unique_identity(): void {
		[$store,$session,$transport]=$this->fixture();$store->assert_ready($session,1);self::assertContains('SELECT 1 FROM `owned_options` LIMIT 0',$transport->statements);self::assertContains('SHOW FULL COLUMNS FROM `owned_options`',$transport->statements);self::assertContains('SHOW INDEX FROM `owned_options`',$transport->statements);self::assertTrue($session->rollback());self::assertTrue($session->retire());
	}
	public function test_missing_truncated_composite_or_wrong_unique_index_and_unknown_columns_refuse(): void {
		foreach(['missing_unique','prefix','composite','nonunique','unknown_column','nullable','engine']as$failure){[$store,$session,$transport]=$this->fixture();switch($failure){case'missing_unique':array_pop($transport->indexes);break;case'prefix':$transport->indexes[1]['Sub_part']=20;break;case'composite':$part=$transport->indexes[1];$part['Seq_in_index']=2;$part['Column_name']='autoload';$transport->indexes[]=$part;break;case'nonunique':$transport->indexes[1]['Non_unique']=1;break;case'unknown_column':$transport->columns[]=['Field'=>'foreign','Type'=>'longtext','Null'=>'YES','Extra'=>''];break;case'nullable':$transport->columns[1]['Null']='YES';break;case'engine':$transport->engine='MyISAM';break;}try{$store->assert_ready($session,1);self::fail('Unsupported options schema accepted.');}catch(\RuntimeException$error){self::assertSame('Data lifecycle options are unavailable.',$error->getMessage());}self::assertNotContains('COMMIT',$transport->statements);}
	}
	public function test_owner_site_and_current_wordpress_options_route_are_required(): void {
		[$store,$session]=$this->fixture();try{$store->assert_ready($session,2);self::fail('Wrong site accepted.');}catch(\RuntimeException){self::assertTrue(true);}
		$before=$GLOBALS['wpdb']??null;try{$GLOBALS['wpdb']=(object)['prefix'=>'owned_','options'=>'owned_options'];$store->assert_standard_wordpress_route($session);$GLOBALS['wpdb']->options='different_route';try{$store->assert_standard_wordpress_route($session);self::fail('Custom options route accepted.');}catch(\RuntimeException){self::assertTrue(true);}}finally{$GLOBALS['wpdb']=$before;}
		$session->rollback();try{$store->current_by_name($session,$this->option_name());self::fail('Unowned read accepted.');}catch(\RuntimeException){self::assertTrue(true);}
	}
	public function test_current_reads_are_locked_bounded_and_keep_oversize_metadata_without_transferring_payload(): void {
		[$store,$session,$transport]=$this->fixture();$transport->row=$this->row('valid');$row=$store->current_by_name($session,$this->option_name(),10);self::assertSame('valid',$row['option_value']);self::assertSame(7,$row['option_id']);$query=$transport->last_select();self::assertStringContainsString('CASE WHEN OCTET_LENGTH(option_value) <= 10 THEN option_value ELSE NULL END',$query);self::assertStringEndsWith('LIMIT 1 FOR UPDATE',$query);self::assertStringContainsString("option_name = '".$this->option_name()."'",$query);
		$transport->row=$this->row(null,100000);$row=$store->current_by_id($session,7,67584);self::assertNull($row['option_value']);self::assertSame(100000,$row['byte_length']);self::assertStringContainsString('WHERE option_id = 7',$transport->last_select());
		$transport->row=$this->row('wrong',100000);try{$store->current_by_id($session,7);self::fail('Unbounded payload transfer accepted.');}catch(\RuntimeException){self::assertTrue(true);}
	}
	public function test_window_is_ascending_sparse_bounded_prefix_prefilter_not_an_ownership_grant(): void {
		[$store,$session,$transport]=$this->fixture();$transport->rows=[['option_id'=>'9','option_name'=>'cetech_de_gc_geo_v1_invalid','byte_length'=>'4','autoload'=>'off'],['option_id'=>'100','option_name'=>$this->option_name(),'byte_length'=>'50','autoload'=>'off']];$rows=$store->window($session,5,1005);self::assertSame([9,100],array_column($rows,'option_id'));self::assertArrayNotHasKey('option_value',$rows[0]);$query=$transport->last_select();self::assertStringContainsString("option_name LIKE 'cetech!_de!_gc!_geo!_v1!_%' ESCAPE '!'",$query);self::assertStringEndsWith('ORDER BY option_id ASC LIMIT 201',$query);self::assertStringNotContainsString('OFFSET',$query);self::assertStringNotContainsString('option_value,',$query);
		foreach([[0,1001,201],[0,1,202],[10,9,201]]as$args){try{$store->window($session,...$args);self::fail('Unbounded selector accepted.');}catch(\RuntimeException){self::assertTrue(true);}}
		$transport->rows=array_reverse($transport->rows);try{$store->window($session,5,1005);self::fail('Descending candidates accepted.');}catch(\RuntimeException){self::assertTrue(true);}
	}
	public function test_store_uses_affected_rows_and_exact_id_name_value_guards_without_other_site_or_authored_option_writes(): void {
		[$store,$session,$transport]=$this->fixture();$store->assert_ready($session,1);$id=$store->insert($session,$this->option_name(),'{"safe":"value"}');self::assertSame(17,$id);self::assertStringContainsString("'off'",$transport->last_write());self::assertStringNotContainsString('ON DUPLICATE KEY',$transport->last_write());
		self::assertTrue($store->replace($session,7,$this->option_name(),'old','new'));self::assertStringContainsString("WHERE option_id = 7 AND BINARY option_name = '".$this->option_name()."' AND BINARY option_value = 'old'",$transport->last_write());
		$transport->affected=0;self::assertFalse($store->delete($session,7,$this->option_name(),'new'));self::assertStringContainsString('DELETE FROM `owned_options` WHERE option_id = 7',$transport->last_write());
		$before=count($transport->statements);foreach(['cetech_de_delete_data_on_uninstall','cetech_de_geography_revision','foreign_option',$this->option_name().'_foreign']as$name){try{$store->insert($session,$name,'value');self::fail('Foreign/authored option write accepted.');}catch(\RuntimeException$error){self::assertSame('Data lifecycle options are unavailable.',$error->getMessage());}}self::assertSame($before,count($transport->statements));
	}
	public function test_native_utc_clock_and_unsigned_ceiling_do_not_coerce_or_overflow(): void {
		[$store,$session,$transport]=$this->fixture();self::assertSame(1791331200,$store->now($session));$transport->ceiling='9223372036854775807';self::assertSame(PHP_INT_MAX,$store->max_id($session));$transport->ceiling='9223372036854775808';try{$store->max_id($session);self::fail('Overflowing identity accepted.');}catch(\RuntimeException){self::assertTrue(true);}
		$transport->utc='2026-02-30 00:00:00';try{$store->now($session);self::fail('Impossible server time accepted.');}catch(\RuntimeException){self::assertTrue(true);}
	}
	public function test_advisory_invalidation_names_only_exact_owned_option_and_managed_group_keys(): void {
		$store=new DataLifecycleOptionsStore();$GLOBALS['cetech_de_test_cache_deletes']=[];self::assertTrue($store->invalidate([$this->option_name(),DataLifecycleOptionsStore::COORDINATOR_OPTION]));$calls=$GLOBALS['cetech_de_test_cache_deletes'];self::assertContains([$this->option_name(),'options'],$calls);self::assertContains([str_repeat('a',64),ManagedGeographyCacheIdentity::CACHE_GROUP],$calls);self::assertContains(['alloptions','options'],$calls);self::assertContains(['notoptions','options'],$calls);self::assertFalse($store->invalidate(['foreign_option']));self::assertFalse($store->invalidate(array_fill(0,53,$this->option_name())));
	}
	private function fixture():array{$transport=new OptionsStoreProofTransport();$session=new OperationConnection(1,'owned_',$transport,'utf8mb4');self::assertTrue($session->begin());return [new DataLifecycleOptionsStore(),$session,$transport];}
	private function option_name():string{return ManagedGeographyCacheIdentity::OPTION_PREFIX.str_repeat('a',64);}
	private function row(?string $value,?int $length=null):array{return ['option_id'=>'7','option_name'=>$this->option_name(),'option_value'=>$value,'byte_length'=>(string)($length??strlen($value??'')),'autoload'=>'off'];}
}

final class OptionsStoreProofTransport implements OperationConnectionTransport {
	public array $statements=[];public bool $transaction=false;public bool $closed=false;public string $engine='InnoDB';public string $utc='2026-10-07 00:00:00';public string $ceiling='100';public ?array $row=null;public array $rows=[];public int $affected=1;public array $columns;public array $indexes;
	public function __construct(){$this->columns=[['Field'=>'option_id','Type'=>'bigint(20) unsigned','Null'=>'NO','Extra'=>'auto_increment'],['Field'=>'option_name','Type'=>'varchar(191)','Null'=>'NO','Extra'=>''],['Field'=>'option_value','Type'=>'longtext','Null'=>'NO','Extra'=>''],['Field'=>'autoload','Type'=>'varchar(20)','Null'=>'NO','Extra'=>'']];$this->indexes=[['Key_name'=>'PRIMARY','Non_unique'=>0,'Seq_in_index'=>1,'Column_name'=>'option_id','Sub_part'=>null,'Index_type'=>'BTREE'],['Key_name'=>'option_name','Non_unique'=>0,'Seq_in_index'=>1,'Column_name'=>'option_name','Sub_part'=>null,'Index_type'=>'BTREE']];}
	public function execute(string $sql):OperationConnectionResult{$this->statements[]=$sql;if('START TRANSACTION'===$sql){$this->transaction=true;}if(in_array($sql,['ROLLBACK','COMMIT'],true)){$this->transaction=false;}if(str_contains($sql,'information_schema.TABLES')){return new OperationConnectionResult(true,true,rows:[['engine'=>$this->engine]]);}if(str_starts_with($sql,'SHOW FULL COLUMNS')){return new OperationConnectionResult(true,true,rows:$this->columns);}if(str_starts_with($sql,'SHOW INDEX')){return new OperationConnectionResult(true,true,rows:$this->indexes);}if(str_starts_with($sql,'SELECT UTC_TIMESTAMP')){return new OperationConnectionResult(true,true,rows:[['utc'=>$this->utc]]);}if(str_starts_with($sql,'SELECT MAX')){return new OperationConnectionResult(true,true,rows:[['ceiling_id'=>$this->ceiling]]);}if(str_contains($sql,'ORDER BY option_id')){return new OperationConnectionResult(true,true,rows:$this->rows);}if(str_contains($sql,'LIMIT 1 FOR UPDATE')){return new OperationConnectionResult(true,true,rows:null===$this->row?[]:[$this->row]);}return new OperationConnectionResult(true,true,affected_rows:$this->affected,insert_id:17);}
	public function connection_id():int{return 7;}public function transaction_state():?array{return $this->closed?null:['connection_id'=>7,'autocommit'=>true,'in_transaction'=>$this->transaction];}public function escape(string $value):string{return addslashes($value);}public function close():bool{$this->closed=true;return true;}
	public function last_select():string{foreach(array_reverse($this->statements)as$sql){if(str_starts_with($sql,'SELECT ')&&!str_contains($sql,'information_schema')){return$sql;}}return'';}public function last_write():string{foreach(array_reverse($this->statements)as$sql){if(1===preg_match('/\A(?:INSERT|UPDATE|DELETE) /',$sql)){return$sql;}}return'';}
}
