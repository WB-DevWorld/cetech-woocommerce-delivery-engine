<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\CustomerPolicy;
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnectionResult,OperationConnectionTransport};
use CetechDeliveryEngine\Infrastructure\Persistence\{OperationStoreSchema,RuleLifecycleSchema,CustomerPolicyCurrentConnection};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentFixture,PolicyAssignmentUnitFactory};
use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver,EffectiveConfigurationValidator};
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap,PolicyAssignmentState};
use CetechDeliveryEngine\Application\CustomerPolicy\CustomerPolicyReadGrant;
require_once dirname(__DIR__).'/PolicyAssignment/PolicyAssignmentFixture.php';

/** Actual production SQL on an owned SQLite adapter; no native MariaDB/locking claim. */
final class CurrentPolicyFixture {
 public readonly PolicyAssignmentFixture $base;public array $transports=[];public mixed $configure=null;public int $grant_refreshes=0;public bool $granted=true;
 public function __construct(){ $this->base=new PolicyAssignmentFixture();$db=$this->base->factory->inspection();$db->sqliteCreateFunction('OCTET_LENGTH',static fn(mixed$v):?int=>null===$v?null:strlen((string)$v));$this->option('cetech_de_db_version','12');$this->option('cetech_de_last_migration_status',serialize(['status'=>'success','to_version'=>'12','migration_id'=>'owned-fixture-migration'])); }
 public function option(string$name,string$bytes):void{$db=$this->base->factory->inspection();$q=$db->prepare('INSERT INTO '.$this->base->table('options').' (option_name,option_value,autoload) VALUES (?,?,?) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value');$q->execute([$name,$bytes,'off']);}
 public function resolver():EffectiveConfigurationResolver{return new EffectiveConfigurationResolver(new InMemoryScopedConfigurationRepository(),new EffectiveConfigurationValidator());}
 public function connection():CustomerPolicyCurrentConnection{$t=new CurrentPolicyTransport($this->base);if(null!==$this->configure)($this->configure)($t);$this->transports[]=$t;return new CustomerPolicyCurrentConnection(1,PolicyAssignmentUnitFactory::PREFIX,$t,'utf8mb4','utf8mb4_unicode_ci');}
 public function grant(array$changes=[]):CustomerPolicyReadGrant{$frame=array_replace(['site_id'=>1,'actor_id'=>0,'session_subject'=>str_repeat('a',64),'product_id'=>33,'variation_id'=>0,'parent_id'=>0,'quantity'=>'2','real_offer_id'=>7,'context_digest'=>str_repeat('b',64),'cart_key'=>'raw-line','purpose'=>'cart'],$changes);return CustomerPolicyReadGrant::captured($frame,['site_id'=>1,'prefix'=>PolicyAssignmentUnitFactory::PREFIX],function():bool{++$this->grant_refreshes;return $this->granted;});}
 public function seed(array$c,array$returns=['mode'=>'inherit'],array$refunds=['mode'=>'inherit'],int$revision=1):void{$generation=PolicyAssignmentFixture::uuid(900);$bytes=PolicyAssignmentState::record_json($c,$generation,$revision,PolicyAssignmentMap::empty(1,'return_policy_v1')->with_instruction(0,$returns),PolicyAssignmentMap::empty(1,'refund_policy_v1')->with_instruction(0,$refunds),9,'Owned source fixture',$this->base->factory->clock);$this->option($this->base->store->name($c),$bytes);}
 public function close():void{foreach($this->transports as$t)$t->close();$this->base->close();}
}
final class CurrentPolicyTransport implements OperationConnectionTransport {
 public array $sql=[];public string $isolation='REPEATABLE-READ';public bool $read_only=false,$closed=false,$reject_close=false,$reject_rollback=false;public int $id=133;public mixed $on_query=null,$filter_rows=null;public ?string $error_sql=null;public ?string $last_error=null;
 public function __construct(public readonly PolicyAssignmentFixture $base){}
 public function connection_id():int{return $this->closed?0:$this->id;}
 public function transaction_state():?array{return $this->closed?null:['connection_id'=>$this->id,'in_transaction'=>$this->base->factory->inspection()->inTransaction(),'autocommit'=>true];}
 public function escape(string$v):string{return str_replace("'","''",$v);}
 public function close():bool{if($this->reject_close)return false;if(!$this->closed&&$this->base->factory->inspection()->inTransaction())$this->base->factory->inspection()->rollBack();$this->closed=true;return true;}
 public function execute(string$sql):OperationConnectionResult{
  $this->sql[]=$sql;if(null!==$this->on_query)($this->on_query)($sql,$this);if(null!==$this->error_sql&&str_contains($sql,$this->error_sql))return new OperationConnectionResult(false,true,errno:2013);
  try{
   if('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'===$sql){$this->isolation='READ-COMMITTED';$rows=[];}
   elseif('SET SESSION TRANSACTION READ ONLY'===$sql){$this->read_only=true;$rows=[];}
   elseif(str_starts_with($sql,'SELECT @@session.tx_isolation')){$rows=[['isolation_level'=>$this->isolation,'read_only'=>$this->read_only?'1':'0','database_name'=>'owned_memory','connection_id'=>(string)$this->id]];}
   elseif('START TRANSACTION'===$sql){$this->base->factory->inspection()->beginTransaction();$rows=[];}
   elseif('ROLLBACK'===$sql){if($this->reject_rollback)return new OperationConnectionResult(false,false,errno:2013);$this->base->factory->inspection()->rollBack();$rows=[];}
   elseif(str_contains($sql,'information_schema.TABLES')){$rows=[['engine'=>'InnoDB']];}
   elseif(str_starts_with($sql,'SHOW TABLE STATUS')){$rows=[['Engine'=>'InnoDB','Collation'=>'utf8mb4_unicode_ci']];}
   elseif(str_starts_with($sql,'SHOW CHARACTER SET')){$rows=[['Default collation'=>'utf8mb4_unicode_ci']];}
   elseif(str_starts_with($sql,'SHOW FULL COLUMNS')){$rows=$this->columns($sql);}
   elseif(str_starts_with($sql,'SHOW INDEX')){$rows=$this->indexes($sql);}
   else{$translated=str_replace([' FORCE INDEX (`option_name`)','UTC_TIMESTAMP(6)'],['','UTC_NOW()'],$sql);$rows=$this->base->factory->inspection()->query($translated)->fetchAll(\PDO::FETCH_ASSOC);}
   if(null!==$this->filter_rows)$rows=($this->filter_rows)($sql,$rows,$this);return new OperationConnectionResult(true,true,$rows);
  }catch(\Throwable$e){$this->last_error=$e->getMessage();return new OperationConnectionResult(false,true,errno:1105);}
 }
 private function suffix(string$sql):array{preg_match('/`([^`]+)`/',$sql,$m);$name=$m[1]??'';if($name===$this->base->table('options'))return ['options',null];foreach([OperationStoreSchema::class,RuleLifecycleSchema::class]as$schema)foreach($schema::SUFFIXES as$s)if($name===$this->base->table($s))return [$s,$schema];throw new \RuntimeException('Unexpected metadata table.');}
 private function columns(string$sql):array{[$s,$schema]=$this->suffix($sql);if('options'===$s){$out=[];foreach(['option_id'=>'bigint unsigned','option_name'=>'varchar(191)','option_value'=>'longtext','autoload'=>'varchar(20)']as$n=>$t)$out[]=['Field'=>$n,'Type'=>$t,'Null'=>'NO','Default'=>null,'Extra'=>'option_id'===$n?'auto_increment':'','Collation'=>'option_id'===$n?null:'utf8mb4_unicode_ci'];return$out;}$out=[];foreach($schema::columns($s)as$n=>[$type,$nullable,$default,$extra,$purpose])$out[]=['Field'=>$n,'Type'=>$type,'Null'=>$nullable?'YES':'NO','Default'=>$default,'Extra'=>$extra,'Collation'=>'site'===$purpose?'utf8mb4_unicode_ci':$purpose];return$out;}
 private function indexes(string$sql):array{[$s,$schema]=$this->suffix($sql);$indexes='options'===$s?['PRIMARY'=>['unique'=>true,'columns'=>['option_id']],'option_name'=>['unique'=>true,'columns'=>['option_name']]]:$schema::indexes($s);$out=[];foreach($indexes as$n=>$definition)foreach($definition['columns']as$i=>$column)$out[]=['Key_name'=>$n,'Seq_in_index'=>(string)($i+1),'Non_unique'=>$definition['unique']?'0':'1','Sub_part'=>null,'Index_type'=>'BTREE','Expression'=>null,'Column_name'=>$column,'Collation'=>'A'];return$out;}
}
