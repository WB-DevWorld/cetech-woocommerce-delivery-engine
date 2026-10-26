<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\CustomerPolicy\PolicyJson;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationSession};
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnection,OperationConnectionMysqliTransport,OperationConnectionResult,OperationConnectionTransport};

/** One dedicated RC/RW owner atomically hands the cart ledger to its immutable original. */
final class CustomerPolicyReceiptStore {
	public const CART_PREFIX = 'cetech_de_customer_policy_cart_v1_';
	public const ORIGINAL_PREFIX = 'cetech_de_customer_policy_original_v1_';
	public const CART_MAX_BYTES = 262144;
	public const ORIGINAL_MAX_BYTES = 3145728;
	public const ORDER_INDEX_MAX_BYTES = 2048;
	private readonly ?\Closure $factory;
	private bool $busy = false;
	public function __construct( private readonly int $site, private readonly string $prefix, ?callable $transport_factory = null ) {
		if ( $site < 1 || 1 !== preg_match( '/\A[A-Za-z0-9_]{1,57}\z/D', $prefix ) ) { self::fail( 'unavailable' ); }
		$this->factory = null === $transport_factory ? null : \Closure::fromCallable( $transport_factory );
	}
	public static function from_server_configuration( int $site, string $prefix, array $configuration ): self { return new self( $site, $prefix, static fn(): OperationConnectionTransport => self::connect( $configuration ) ); }
	public function binding(): array { return [ 'site_id' => $this->site, 'prefix' => $this->prefix ]; }
	public static function cart_name( int $site, string $subject ): string { self::subject( $subject ); if ( $site < 1 ) { self::fail(); } return self::CART_PREFIX . hash( 'sha256', 'cetech-customer-policy-cart-v1:' . $site . ':' . $subject ); }
	public static function original_name( int $site, string $attempt ): string { PolicyJson::uuid( $attempt ); if ( $site < 1 ) { self::fail(); } return self::ORIGINAL_PREFIX . hash( 'sha256', 'cetech-customer-policy-original-v1:' . $site . ':' . $attempt ); }
	public static function order_index_name( int $site, int $order ): string { if ( $site < 1 || $order < 1 ) { self::fail(); } return self::ORIGINAL_PREFIX . $site . '_order_' . $order; }
	public static function order_index_bytes( int $site, string $attempt, string $owner_digest, array $binding ): string {
		PolicyJson::uuid( $attempt ); PolicyJson::digest( $owner_digest );
		if ( $site < 1 || ( $binding['site_id'] ?? null ) !== $site || ! is_int( $binding['order_id'] ?? null ) || $binding['order_id'] < 1 ) { self::fail(); }
		return PolicyJson::encode( [ 'format'=>1, 'site_id'=>$site, 'order_id'=>$binding['order_id'], 'attempt_id'=>$attempt, 'owner_digest'=>$owner_digest, 'binding_digest'=>hash( 'sha256', 'cetech-customer-policy-native-binding-v1:' . PolicyJson::encode( $binding ) ) ], self::ORDER_INDEX_MAX_BYTES );
	}
	/** Unknown COMMIT is never rolled back or blindly replayed. No result escapes before retirement. */
	public function transaction( string $subject, callable $work ): mixed {
		self::subject( $subject ); if ( $this->busy ) { self::fail( 'unavailable' ); } $this->busy = true;
		$transport=null; $session=null; $active=false; $locked=false; $committed=false; $uncertain=false; $route=null; $connection=0; $database=''; $packet=0; $lock=''; $unit=null;
		try {
			if ( null === $this->factory ) { $route=$this->native_route(); $transport=self::connect( $route['configuration'] ); } else { $transport=( $this->factory )(); }
			if ( ! $transport instanceof OperationConnectionTransport ) { self::fail( 'unavailable' ); }
			$connection=$transport->connection_id(); $this->state( $transport,$connection,false );
			$this->execute( $transport,$connection,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
			$this->execute( $transport,$connection,'SET SESSION TRANSACTION READ WRITE' );
			$probe=$this->probe( $transport,$connection ); $database=$probe['database']; $packet=$probe['max_packet'];
			$lock='cetech_cp_' . substr( hash( 'sha256',$database.':'.$this->prefix.':'.$this->site.':'.$subject ),0,48 ); $literal="'".$transport->escape($lock)."'";
			$prior=$this->execute($transport,$connection,'SELECT IS_USED_LOCK('.$literal.') AS lock_owner')->rows;
			if ( 1!==count($prior) || !array_key_exists('lock_owner',$prior[0]) || null!==$prior[0]['lock_owner'] ) { self::fail('unavailable'); }
			$acquired=$this->execute($transport,$connection,'SELECT GET_LOCK('.$literal.',0) AS acquired')->rows;
			if ( 1!==count($acquired) || '1'!==(string)($acquired[0]['acquired']??'') ) { self::fail('unavailable'); } $locked=true;
			$session=new OperationConnection($this->site,$this->prefix,$transport);
			if ( !$session->begin() ) { self::fail(); } $active=true;
			(new DataLifecycleOptionsStore())->assert_ready($session,$this->site);
			$fence=function()use($transport,$connection,$database,$packet,$lock,$session):void { if($session->is_retired()||!$session->in_transaction()){self::fail();}$this->assert_owned($transport,$connection,$database,$packet,$lock,true); };
			$unit=new CustomerPolicyReceiptUnit($session,$this->site,$this->prefix,$subject,$packet,$fence);
			$fence(); $result=$work($unit); $unit->verify_native_participants(); $fence();
			$commit=$session->commit();
			if (OperationCommitResult::Unconfirmed===$commit){$uncertain=true;self::fail();}
			if (OperationCommitResult::NotSent===$commit){if(!$session->rollback()){$uncertain=true;self::fail();}$active=false;self::fail('not_sent');}
			$active=false; $committed=true; $this->assert_owned($transport,$connection,$database,$packet,$lock,false);
			$this->release($transport,$connection,$lock); $locked=false;
			if (!$session->retire()||!$session->is_retired()){$uncertain=true;self::fail();}
			if(null!==$route&&$this->native_route()!==$route){self::fail();}
			$this->invalidate($unit->written_names()); return $result;
		}catch(\Throwable $error){
			$known=$error instanceof CustomerPolicyReceiptStorageException?$error->outcome:'outcome_unknown';
			if(!$committed&&!$uncertain&&$session instanceof OperationSession&&$active&&!$session->is_retired()){try{if(!$session->rollback()){$uncertain=true;}$active=false;}catch(\Throwable){$uncertain=true;}}
			if($locked&&$transport instanceof OperationConnectionTransport&&!$uncertain){try{$this->release($transport,$connection,$lock);$locked=false;}catch(\Throwable){$uncertain=true;}}
			if($session instanceof OperationSession){try{if(!$session->retire()){$uncertain=true;}}catch(\Throwable){$uncertain=true;}}elseif($transport instanceof OperationConnectionTransport){try{if(!$transport->close()){$uncertain=true;}}catch(\Throwable){$uncertain=true;}}
			throw new CustomerPolicyReceiptStorageException($uncertain||$committed?'outcome_unknown':$known);
		}finally{$this->busy=false;}
	}
	/** Original/cart/index row identities are physical witnesses, not decoded command authority. */
	public static function verify_witness(OperationSession $session,array $witness):bool{
		try{
			$keys=['site_id','prefix','subject','attempt_id','cart','original','order_index'];if(count($witness)!==count($keys)||array_diff($keys,array_keys($witness))||$witness['site_id']!==$session->site_id()||$witness['prefix']!==$session->table_prefix()||!$session->in_transaction()||$session->is_retired()){return false;}
			self::subject($witness['subject']); PolicyJson::uuid($witness['attempt_id']);(new DataLifecycleOptionsStore())->assert_ready($session,$witness['site_id']);
			foreach(['cart'=>self::CART_MAX_BYTES,'original'=>self::ORIGINAL_MAX_BYTES,'order_index'=>self::ORDER_INDEX_MAX_BYTES]as$key=>$max){$expected=$witness[$key];if(null===$expected){if('order_index'!==$key){return false;}continue;}if(!is_array($expected)||count($expected)!==4||array_diff(['row_id','option_name','bytes','autoload'],array_keys($expected))){return false;}$actual=CustomerPolicyReceiptUnit::read_row($session,$expected['option_name'],$max);if($actual!==$expected){return false;}}
			return $witness['cart']['option_name']===self::cart_name($witness['site_id'],$witness['subject'])&&$witness['original']['option_name']===self::original_name($witness['site_id'],$witness['attempt_id']);
		}catch(\Throwable){return false;}
	}
	private function execute(OperationConnectionTransport$t,int$id,string$sql):OperationConnectionResult{if($id<1||$t->connection_id()!==$id){self::fail();}$r=$t->execute($sql);if(!$r->acknowledged||$t->connection_id()!==$id){self::fail();}return$r;}
	private function state(OperationConnectionTransport$t,int$id,bool$active):void{if($id<1||$t->connection_id()!==$id||$t->transaction_state()!==['connection_id'=>$id,'in_transaction'=>$active,'autocommit'=>true]){self::fail();}}
	private function probe(OperationConnectionTransport$t,int$id):array{
		$r=$this->execute($t,$id,'SELECT DATABASE() AS database_name, CONNECTION_ID() AS connection_id, @@max_allowed_packet AS max_packet, @@tx_isolation AS isolation_level, @@tx_read_only AS read_only')->rows;
		if(1!==count($r)||!is_string($r[0]['database_name']??null)||''===$r[0]['database_name']||strlen($r[0]['database_name'])>64||(string)$id!==(string)($r[0]['connection_id']??'')||'READ-COMMITTED'!==strtoupper((string)($r[0]['isolation_level']??''))||'0'!==(string)($r[0]['read_only']??'')||!self::positive($r[0]['max_packet']??null)||($packet=(int)$r[0]['max_packet'])<4096){self::fail();}return['database'=>$r[0]['database_name'],'max_packet'=>$packet];
	}
	private function assert_owned(OperationConnectionTransport$t,int$id,string$db,int$packet,string$lock,bool$active):void{$this->state($t,$id,$active);if($this->probe($t,$id)!==['database'=>$db,'max_packet'=>$packet]){self::fail();}$r=$this->execute($t,$id,"SELECT IS_USED_LOCK('".$t->escape($lock)."') AS lock_owner")->rows;if(1!==count($r)||(string)$id!==(string)($r[0]['lock_owner']??'')){self::fail();}}
	private function release(OperationConnectionTransport$t,int$id,string$lock):void{$r=$this->execute($t,$id,"SELECT RELEASE_LOCK('".$t->escape($lock)."') AS released")->rows;if(1!==count($r)||'1'!==(string)($r[0]['released']??'')){self::fail();}}
	private function native_route():array{
		global$wpdb;if(!is_object($wpdb)||\wpdb::class!==get_class($wpdb)||(defined('WP_CONTENT_DIR')&&is_file(WP_CONTENT_DIR.'/db.php'))||!defined('DB_HOST')||!defined('DB_USER')||!defined('DB_PASSWORD')||!defined('DB_NAME')||!method_exists($wpdb,'parse_db_host')||($wpdb->prefix??null)!==$this->prefix||($wpdb->options??null)!==$this->prefix.'options'||!function_exists('get_current_blog_id')||get_current_blog_id()!==$this->site){self::fail('unavailable');}
		$host=$wpdb->parse_db_host(DB_HOST);if(!is_array($host)||count($host)<3){self::fail('unavailable');}return['wpdb'=>$wpdb,'site'=>$this->site,'prefix'=>$this->prefix,'configuration'=>['host'=>$host[0],'port'=>$host[1]?:3306,'socket'=>$host[2],'user'=>DB_USER,'password'=>DB_PASSWORD,'database'=>DB_NAME,'charset'=>$wpdb->charset?:'utf8mb4','flags'=>defined('MYSQL_CLIENT_FLAGS')?MYSQL_CLIENT_FLAGS:0]];
	}
	private static function connect(array$c):OperationConnectionTransport{return OperationConnectionMysqliTransport::connect((string)($c['host']??''),(string)($c['user']??''),(string)($c['password']??''),(string)($c['database']??''),(int)($c['port']??3306),isset($c['socket'])?(string)$c['socket']:null,(string)($c['charset']??'utf8mb4'),(int)($c['flags']??0));}
	private function invalidate(array$names):void{if(!function_exists('wp_cache_delete')){return;}foreach($names as$name){wp_cache_delete($name,'options');}if([]!==$names){wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}}
	private static function subject(string$s):void{if(1!==preg_match('/\A[0-9a-f]{64}\z/D',$s)){self::fail('unavailable');}}
	private static function positive(mixed$v):bool{return is_int($v)?$v>0:is_string($v)&&1===preg_match('/\A[1-9][0-9]*\z/D',$v)&&strlen($v)<=strlen((string)PHP_INT_MAX)&&(strlen($v)<strlen((string)PHP_INT_MAX)||strcmp($v,(string)PHP_INT_MAX)<=0);}
	private static function fail(string$outcome='outcome_unknown'):never{throw new CustomerPolicyReceiptStorageException($outcome);}
	public function __debugInfo():array{return['customer_policy_receipt_store'=>'redacted'];}
}
