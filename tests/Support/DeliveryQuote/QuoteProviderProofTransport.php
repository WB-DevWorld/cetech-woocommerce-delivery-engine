<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnectionResult,OperationConnectionTransport};

/** Native-only Q04 observation/barriers; no counterfeit SQL result or provider effect. */
final class QuoteProviderProofTransport implements OperationConnectionTransport {
	public array $sql=[];
	public array $row_counts=[];
	public int $lock_timeouts=0;
	public int $deadlocks=0;
	public ?\Closure $before=null;
	public ?\Closure $after=null;
	private bool $closed=false;
	public function __construct(private OperationConnectionTransport $native){}
	public function execute(string $sql):OperationConnectionResult {
		$this->sql[]=$sql;if(null!==$this->before){($this->before)($sql,$this);}
		$r=$this->native->execute($sql);$this->row_counts[]=count($r->rows);
		if(1205===$r->errno){++$this->lock_timeouts;}if(1213===$r->errno){++$this->deadlocks;}
		if(null!==$this->after){($this->after)($sql,$this,$r);}return $r;
	}
	public function native_execute(string $sql):OperationConnectionResult{return $this->native->execute($sql);}
	public function connection_id():int{return $this->native->connection_id();}
	public function transaction_state():?array{return $this->native->transaction_state();}
	public function escape(string $value):string{return $this->native->escape($value);}
	public function close():bool{$this->closed=$this->native->close();return $this->closed;}
	public function force_close():void{if(!$this->closed){$this->native->close();$this->closed=true;}}
}
