<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\CustomerPolicy\PolicyNativeOrderStage;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Exact option-row operations, usable only inside the store's pinned subject owner. */
final class CustomerPolicyReceiptUnit {
	private readonly \Closure $fence;
	private array $written=[];
	private array $native=[];
	public function __construct(private readonly OperationSession$session,private readonly int$site,private readonly string$prefix,private readonly string$subject,private readonly int$max_packet,callable$fence){$this->fence=\Closure::fromCallable($fence);}
	public function cart():?array{return$this->read(CustomerPolicyReceiptStore::cart_name($this->site,$this->subject),CustomerPolicyReceiptStore::CART_MAX_BYTES);}
	public function original(string$attempt):?array{return$this->read(CustomerPolicyReceiptStore::original_name($this->site,$attempt),CustomerPolicyReceiptStore::ORIGINAL_MAX_BYTES);}
	public function order_index(int$order):?array{return$this->read(CustomerPolicyReceiptStore::order_index_name($this->site,$order),CustomerPolicyReceiptStore::ORDER_INDEX_MAX_BYTES);}
	public function put_cart(?array$expected,string$bytes):array{return$this->put(CustomerPolicyReceiptStore::cart_name($this->site,$this->subject),$expected,$bytes,CustomerPolicyReceiptStore::CART_MAX_BYTES);}
	public function put_original(string$attempt,?array$expected,string$bytes):array{return$this->put(CustomerPolicyReceiptStore::original_name($this->site,$attempt),$expected,$bytes,CustomerPolicyReceiptStore::ORIGINAL_MAX_BYTES);}
	/** A newly bound original has exactly one newly inserted physical order index. */
	public function put_order_index(PolicyNativeOrderStage$proof):array{
		if('before_write'!==$proof->phase()||$proof->site_id()!==$this->site){self::fail();}$this->enlist_native($proof);$binding=$proof->binding();$name=CustomerPolicyReceiptStore::order_index_name($this->site,$binding['order_id']);
		if(null!==$this->order_index($binding['order_id'])){self::fail();}
		return$this->put($name,null,CustomerPolicyReceiptStore::order_index_bytes($this->site,$proof->attempt_id(),$proof->owner_digest(),$binding),CustomerPolicyReceiptStore::ORDER_INDEX_MAX_BYTES);
	}
	public function enlist_native(PolicyNativeOrderStage$proof):void{
		$this->guard();if($proof->site_id()!==$this->site){self::fail();}$tables=$proof->tables($this->session);if(!$this->session->validate_tables(array_values(array_unique($tables)))||!$proof->verify($this->session)){self::fail();}$this->native[spl_object_id($proof)]=$proof;$this->guard();
	}
	public function verify_native_participants():void{$this->guard();foreach($this->native as$proof){if(!$proof->verify($this->session)){self::fail();}}$this->guard();}
	public function written_names():array{return array_keys($this->written);}
	public function session():OperationSession{$this->guard();return$this->session;}
	private function read(string$name,int$max):?array{$this->guard();$row=self::read_row($this->session,$name,$max);$this->guard();return$row;}
	/** Bounded SQL projection happens before transport, with a duplicate sentinel and exact spelling. */
	public static function read_row(OperationSession$s,string$name,int$max):?array{
		if(!$s->in_transaction()||$s->is_retired()||strlen($name)>191||$max<1||$max>CustomerPolicyReceiptStore::ORIGINAL_MAX_BYTES||!self::owned_name($name)){self::fail();}
		$p=$s->table_prefix();$sql=$s->prepare("SELECT option_id,option_name,CASE WHEN OCTET_LENGTH(option_value)<=%d THEN option_value ELSE NULL END AS option_value,OCTET_LENGTH(option_value) AS byte_length,autoload FROM `{$p}options` FORCE INDEX (`option_name`) WHERE option_name=%s LIMIT 2 FOR UPDATE",$max,$name);$rows=$s->get_results($sql);
		if(!is_array($rows)||count($rows)>1){self::fail();}if([]===$rows){return null;}$r=$rows[0];$id=self::integer($r['option_id']??null);$n=self::integer($r['byte_length']??null,true);
		if(($r['option_name']??null)!==$name||!is_string($r['option_value']??null)||$n>$max||strlen($r['option_value'])!==$n||$n<1||1!==preg_match('//u',$r['option_value'])||!is_string($r['autoload']??null)||strlen($r['autoload'])>20||1!==preg_match('//u',$r['autoload'])){self::fail();}
		return['row_id'=>$id,'option_name'=>$name,'bytes'=>$r['option_value'],'autoload'=>$r['autoload']];
	}
	private function put(string$name,?array$expected,string$bytes,int$max):array{
		if(''===$bytes||strlen($bytes)>$max||1!==preg_match('//u',$bytes)){self::fail('unavailable');}$actual=$this->read($name,$max);if($actual!==$expected){self::fail();}$table=$this->prefix.'options';
		if(null===$expected){$sql=$this->session->prepare("INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES (%s,%s,%s)",$name,$bytes,'off');}
		else{if($expected['option_name']!==$name||$expected['row_id']<1){self::fail();}if($expected['bytes']===$bytes){return$expected;}$sql=$this->session->prepare("UPDATE `{$table}` SET option_value=%s WHERE option_id=%d AND BINARY option_name=%s AND BINARY option_value=%s AND BINARY autoload=%s",$bytes,$expected['row_id'],$name,$expected['bytes'],$expected['autoload']);}
		$this->guard();if(strlen($sql)+33>=$this->max_packet){self::fail('not_sent');}if(1!==$this->session->query($sql)){self::fail();}$this->written[$name]=true;
		$row=$this->read($name,$max);if(null===$row||$row['bytes']!==$bytes||$row['autoload']!==(null===$expected?'off':$expected['autoload'])||(null!==$expected&&$row['row_id']!==$expected['row_id'])){self::fail();}return$row;
	}
	private function guard():void{if($this->session->site_id()!==$this->site||$this->session->table_prefix()!==$this->prefix||!$this->session->in_transaction()||$this->session->is_retired()){self::fail();}($this->fence)();}
	private static function owned_name(string$n):bool{return 1===preg_match('/\A(?:'.preg_quote(CustomerPolicyReceiptStore::CART_PREFIX,'/').'[0-9a-f]{64}|'.preg_quote(CustomerPolicyReceiptStore::ORIGINAL_PREFIX,'/').'(?:[0-9a-f]{64}|[1-9][0-9]*_order_[1-9][0-9]*))\z/D',$n);}
	private static function integer(mixed$v,bool$zero=false):int{if(is_int($v)&&$v>=($zero?0:1)){return$v;}if(!is_string($v)||1!==preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)||strlen($v)>strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0)||(!$zero&&'0'===$v)){self::fail();}return(int)$v;}
	private static function fail(string$outcome='outcome_unknown'):never{throw new CustomerPolicyReceiptStorageException($outcome);}
}
