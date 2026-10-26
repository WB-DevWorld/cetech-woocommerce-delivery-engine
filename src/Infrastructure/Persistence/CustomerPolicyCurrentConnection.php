<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Infrastructure\Persistence;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationSession};
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnection,OperationConnectionTransport,OperationConnectionMysqliTransport};

/** Dedicated non-reconnecting RC/READ ONLY owner. Generic C03 SQL rules are untouched. */
final class CustomerPolicyCurrentConnection implements OperationSession {
 private readonly OperationConnection $native;private readonly int $connection;private bool $begun=false;private int $last_errno=0;
 public function __construct(private readonly int $site,private readonly string $prefix,private readonly OperationConnectionTransport $transport,private readonly string $charset='utf8mb4',private readonly string $collation=''){
  $this->native=new OperationConnection($site,$prefix,$transport,$charset,$collation);$this->connection=$transport->connection_id();if($this->connection<1)self::deny();
 }
 public static function from_server_configuration(int$site,string$prefix,array$c):self{
  $transport=OperationConnectionMysqliTransport::connect((string)($c['host']??''),(string)($c['user']??''),(string)($c['password']??''),(string)($c['database']??''),(int)($c['port']??3306),isset($c['socket'])?(string)$c['socket']:null,(string)($c['charset']??'utf8mb4'),(int)($c['flags']??0));
  return new self($site,$prefix,$transport,(string)($c['charset']??'utf8mb4'),(string)($c['collation']??''));
 }
 public static function from_wordpress(array$binding):self{
  $db=$GLOBALS['wpdb']??null;if(!is_object($db)||get_class($db)!==\wpdb::class||(defined('WP_CONTENT_DIR')&&is_file(WP_CONTENT_DIR.'/db.php'))||!defined('DB_HOST')||!defined('DB_USER')||!defined('DB_PASSWORD')||!defined('DB_NAME')||!method_exists($db,'parse_db_host')||$binding!==['site_id'=>get_current_blog_id(),'prefix'=>$db->prefix]||$db->options!==$db->prefix.'options')self::deny();
  $host=$db->parse_db_host(DB_HOST);if(!is_array($host)||count($host)<3)self::deny();return self::from_server_configuration($binding['site_id'],$binding['prefix'],['host'=>$host[0],'port'=>$host[1]?:3306,'socket'=>$host[2],'user'=>DB_USER,'password'=>DB_PASSWORD,'database'=>DB_NAME,'charset'=>$db->charset?:'utf8mb4','collation'=>$db->collate?:'','flags'=>defined('MYSQL_CLIENT_FLAGS')?MYSQL_CLIENT_FLAGS:0]);
 }
 public function begin():bool{
  try{
   if($this->is_retired()||$this->begun||$this->in_transaction()||$this->transport->transaction_state()!==['connection_id'=>$this->connection,'in_transaction'=>false,'autocommit'=>true])return false;
   $this->raw('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->raw('SET SESSION TRANSACTION READ ONLY');
   $settings=$this->raw('SELECT @@session.tx_isolation AS isolation_level,@@session.tx_read_only AS read_only,DATABASE() AS database_name,CONNECTION_ID() AS connection_id');
   if(count($settings)!==1||'READ-COMMITTED'!==($settings[0]['isolation_level']??null)||'1'!==(string)($settings[0]['read_only']??null)||(string)$this->connection!==(string)($settings[0]['connection_id']??null)||!is_string($settings[0]['database_name']??null)||''===$settings[0]['database_name'])self::deny();
   if(!$this->native->begin())return false;$this->begun=true;return $this->owned();
  }catch(\Throwable){$this->native->retire();return false;}
 }
 public function commit():OperationCommitResult{return OperationCommitResult::NotSent;}
 public function query(string$sql):int|false{unset($sql);return false;}
 public function rollback():bool{if(!$this->begun)return false;$ok=$this->native->rollback();if($ok)$this->begun=false;return $ok;}
 public function retire():bool{$this->begun=false;return $this->native->retire();}
 public function is_retired():bool{return $this->native->is_retired();}public function in_transaction():bool{return $this->native->in_transaction();}
 public function site_id():int{return $this->site;}public function table_prefix():string{return $this->prefix;}public function charset_collate():string{return $this->native->charset_collate();}
 public function validate_tables(array$tables):bool{return $this->owned()&&$this->native->validate_tables($tables)&&$this->owned();}
 public function prepare(string$sql,mixed...$args):string{return $this->native->prepare($sql,...$args);}
 public function get_results(string$sql):array|false{
  try{if(!$this->owned()||!$this->safe($sql))return false;$rows=$this->raw($sql);return $this->owned()?$rows:false;}catch(\Throwable){return false;}
 }
 public function get_row(string$sql):array|null|false{$rows=$this->get_results($sql);return false===$rows?false:($rows[0]??null);}
 public function errno():int{return $this->last_errno;}public function insert_id():int{return 0;}
 private function raw(string$sql):array{if($this->transport->connection_id()!==$this->connection)self::deny();$r=$this->transport->execute($sql);$this->last_errno=$r->errno;if(!$r->acknowledged||$r->errno||$this->transport->connection_id()!==$this->connection)self::deny();return $r->rows;}
 private function owned():bool{try{return $this->begun&&!$this->native->is_retired()&&$this->native->in_transaction()&&$this->transport->connection_id()===$this->connection&&$this->transport->transaction_state()===['connection_id'=>$this->connection,'in_transaction'=>true,'autocommit'=>true];}catch(\Throwable){return false;}}
 private function safe(string$sql):bool{
  // Remove only correctly closed literals before checking structural tokens/calls.
  $clean='';$length=strlen($sql);for($i=0;$i<$length;){$c=$sql[$i];if("'"!==$c&&'"'!==$c){$clean.=$c;++$i;continue;}$quote=$c;++$i;$closed=false;while($i<$length){if('\\'===$sql[$i]){$i+=2;continue;}if($quote===$sql[$i++]){if($i<$length&&$quote===$sql[$i]){++$i;continue;}$closed=true;break;}}if(!$closed||$i>$length)return false;$clean.="''";}
  if(1!==preg_match('/\A\s*(?:SELECT|SHOW|WITH)\b/i',$clean)||1===preg_match('/;|--|#|\/\*|\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|INTO|OUTFILE|DUMPFILE|LOCK|UNLOCK|CALL|PROCEDURE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK)\b/i',$clean))return false;
  $clean=str_replace(' FORCE INDEX (`option_name`)','',$clean);preg_match_all('/([a-zA-Z0-9_$]+)\s*\(/',$clean,$calls);foreach($calls[1]as$call)if(!in_array(strtoupper($call),['AS','COUNT','MIN','MAX','SUM','COALESCE','IFNULL','CAST','CONVERT','DATABASE','CONNECTION_ID','UTC_TIMESTAMP','OCTET_LENGTH','LENGTH','CHAR_LENGTH','LEFT','IN','WHERE','AND','OR','NOT','IF'],true))return false;return 1!==preg_match('/[^\x00-\x7f]|`[^`]*`\s*\(|\.\s*[a-zA-Z0-9_$]+\s*\(/s',$clean);
 }
 public function __debugInfo():array{return ['customer_policy_read_owner'=>'redacted'];}private static function deny():never{throw new \RuntimeException('Customer policy read connection is unavailable.');}
}
