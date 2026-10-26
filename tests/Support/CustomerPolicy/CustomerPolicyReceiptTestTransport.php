<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\CustomerPolicy;

use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnectionResult,OperationConnectionTransport};

/** Owned in-memory SQLite/native-metadata double. It is not MariaDB qualification. */
final class CustomerPolicyReceiptTestTransport implements OperationConnectionTransport {
	public static array$locks=[];
	private static int$next_id=100;
	private int$id;
	private bool$closed=false;
	private string$isolation='REPEATABLE-READ';
	private string$read_only='0';
	public int$max_packet=16777216;
	public string$commit_fault='';
	public bool$reject_close=false;
	public bool$reject_rollback=false;
	public string$schema_fault='';
	public int$commits=0;
	public int$sent_commits=0;
	public array$sql=[];
	public ?\Closure$handler=null;
	public ?\Closure$before=null;
	public ?\Closure$after=null;
	public function __construct(public readonly \PDO$pdo,public readonly string$database='r24_unit',public readonly string$prefix='wp_'){$this->id=++self::$next_id;$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$pdo->sqliteCreateFunction('OCTET_LENGTH',static fn($v)=>null===$v?null:strlen((string)$v));}
	public static function database(string$prefix='wp_'): \PDO{$pdo=new \PDO('sqlite::memory:');$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$pdo->exec('CREATE TABLE `'.$prefix.'options` (option_id INTEGER PRIMARY KEY AUTOINCREMENT,option_name TEXT NOT NULL COLLATE NOCASE UNIQUE,option_value TEXT NOT NULL,autoload TEXT NOT NULL)');return$pdo;}
	public function execute(string$sql):OperationConnectionResult{
		$this->sql[]=$sql;if($this->closed){return new OperationConnectionResult(false,false);}if(null!==$this->before){($this->before)($sql,$this);}
		try{
			$result=$this->dispatch($sql);
		}catch(\Throwable){$result=new OperationConnectionResult(false,true,errno:45000);}
		if(null!==$this->after){($this->after)($sql,$this,$result);}return$result;
	}
	private function dispatch(string$sql):OperationConnectionResult{
		if(null!==$this->handler){$custom=($this->handler)($sql,$this);if($custom instanceof OperationConnectionResult){return$custom;}}
		if('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'===$sql){$this->isolation='READ-COMMITTED';return$this->ok();}
		if('SET SESSION TRANSACTION READ WRITE'===$sql){$this->read_only='0';return$this->ok();}
		if('START TRANSACTION'===$sql){return$this->pdo->beginTransaction()?$this->ok():new OperationConnectionResult(false,false);}
		if('COMMIT'===$sql){++$this->commits;if('unsent'===$this->commit_fault){$this->commit_fault='';return new OperationConnectionResult(false,false);}++$this->sent_commits;$this->pdo->commit();if('lost_ack'===$this->commit_fault){$this->commit_fault='';return new OperationConnectionResult(false,true);}return$this->ok();}
		if('ROLLBACK'===$sql){if($this->reject_rollback){return new OperationConnectionResult(false,false);}$this->pdo->rollBack();return$this->ok();}
		if(str_starts_with($sql,'SELECT DATABASE() AS database_name')){return$this->ok([['database_name'=>$this->database,'connection_id'=>(string)$this->id,'max_packet'=>(string)$this->max_packet,'isolation_level'=>$this->isolation,'read_only'=>$this->read_only]]);}
		if(preg_match("/\ASELECT (IS_USED_LOCK|GET_LOCK|RELEASE_LOCK)\('([^']+)'(?:,0)?\) AS (\w+)\z/D",$sql,$m)){$key=$this->database.':'.$m[2];$held=self::$locks[$key]??null;$v=null;if('IS_USED_LOCK'===$m[1]){$v=$held;}elseif('GET_LOCK'===$m[1]){if(null===$held){self::$locks[$key]=$this->id;$v='1';}else{$v='0';}}elseif($held===$this->id){unset(self::$locks[$key]);$v='1';}else{$v='0';}return$this->ok([[$m[3]=>$v]]);}
		if(str_starts_with($sql,'SELECT ENGINE AS engine FROM information_schema.TABLES')){preg_match("/TABLE_NAME='([^']+)'/",$sql,$m);$q=$this->pdo->prepare('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=?');$q->execute([$m[1]??'']);return$this->ok(false!==$q->fetchColumn()?[['engine'=>'nontransactional'===$this->schema_fault?'MyISAM':'InnoDB']]:[]);}
		if('SHOW FULL COLUMNS FROM `'.$this->prefix.'options`'===$sql){$rows=[];foreach(['option_id'=>'bigint(20) unsigned','option_name'=>'varchar(191)','option_value'=>'longtext','autoload'=>'varchar(20)']as$name=>$type){$rows[]=['Field'=>$name,'Type'=>$type,'Null'=>'NO','Extra'=>'option_id'===$name?'auto_increment':''];}if('extra_column'===$this->schema_fault){$rows[]=['Field'=>'future','Type'=>'int','Null'=>'NO','Extra'=>''];}return$this->ok($rows);}
		if('SHOW INDEX FROM `'.$this->prefix.'options`'===$sql){$rows=[];foreach(['PRIMARY'=>'option_id','option_name'=>'option_name']as$key=>$field){if('no_unique'===$this->schema_fault&&'option_name'===$key){continue;}$rows[]=['Key_name'=>$key,'Column_name'=>$field,'Non_unique'=>'0','Seq_in_index'=>'1','Sub_part'=>'prefix_unique'===$this->schema_fault&&'option_name'===$key?'32':null,'Index_type'=>'BTREE','Expression'=>null];}return$this->ok($rows);}
		$native=$this->translate($sql);$q=$this->pdo->query($native);$rows=preg_match('/\A\s*(?:SELECT|SHOW)\b/i',$sql)?$q->fetchAll(\PDO::FETCH_ASSOC):[];return new OperationConnectionResult(true,true,$rows,$q->rowCount(),(int)$this->pdo->lastInsertId());
	}
	private function translate(string$sql):string{
		$out='';$n=strlen($sql);$i=0;while($i<$n){if("'"!==$sql[$i]){$out.=$sql[$i++];continue;}++$i;$value='';$closed=false;while($i<$n){$c=$sql[$i++];if("\\"===$c){if($i===$n){throw new \RuntimeException();}$e=$sql[$i++];$value.=match($e){'0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'Z'=>chr(26),default=>$e};continue;}if("'"===$c){if($i<$n&&"'"===$sql[$i]){++$i;$value.="'";continue;}$closed=true;break;}$value.=$c;}if(!$closed){throw new \RuntimeException();}$out.=$this->pdo->quote($value);}
		$out=preg_replace('/ FORCE INDEX \(`\w+`\)/','',$out);$out=preg_replace('/\s+FOR UPDATE\z/','',$out);return preg_replace('/\bBINARY (\w+)/','$1 COLLATE BINARY',$out);
	}
	private function ok(array$rows=[]):OperationConnectionResult{return new OperationConnectionResult(true,true,$rows);}
	public function connection_id():int{return$this->closed?0:$this->id;}
	public function transaction_state():?array{return$this->closed?null:['connection_id'=>$this->id,'in_transaction'=>$this->pdo->inTransaction(),'autocommit'=>true];}
	public function escape(string$value):string{return str_replace(["\\","\0","\n","\r","'",'"',chr(26)],["\\\\","\\0","\\n","\\r","\\'",'\\"','\\Z'],$value);}
	public function close():bool{if($this->reject_close){return false;}if($this->closed){return true;}if($this->pdo->inTransaction()){$this->pdo->rollBack();}foreach(self::$locks as$key=>$id){if($id===$this->id){unset(self::$locks[$key]);}}$this->closed=true;return true;}
}
