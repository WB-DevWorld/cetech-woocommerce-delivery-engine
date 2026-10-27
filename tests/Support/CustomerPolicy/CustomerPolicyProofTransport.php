<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\CustomerPolicy;
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnectionTransport,OperationConnectionResult};
/** Real transport decoration; a fault never supplies fabricated SQL rows. */
final class CustomerPolicyProofTransport implements OperationConnectionTransport {
 public array $sql=[]; public array $replies=[]; public ?\Closure $after=null; public int $commits=0; public int $sent_commits=0; public int $rollbacks=0; public bool $closed=false; public bool $fail_retire=false; public ?string $commit_fault=null; public ?\Closure $before=null;
 public function __construct(public readonly OperationConnectionTransport $native){}
 public function execute(string$sql):OperationConnectionResult{
  $this->sql[]=$sql;if(null!==$this->before){($this->before)($sql,$this);}
  if('COMMIT'===strtoupper(trim($sql))){++$this->commits;if('unsent'===$this->commit_fault){$this->commit_fault=null;return new OperationConnectionResult(false,false);}++$this->sent_commits;$result=$this->native->execute($sql);if('lost_ack'===$this->commit_fault){$this->commit_fault=null;return new OperationConnectionResult(false,true);}return$result;}
  if('ROLLBACK'===strtoupper(trim($sql))){++$this->rollbacks;}$result=$this->native->execute($sql);$this->replies[]=['sql'=>$sql,'result'=>$result];return null===$this->after?$result:($this->after)($sql,$result,$this);
 }
 public function connection_id():int{return$this->native->connection_id();}
 public function transaction_state():?array{return$this->native->transaction_state();}
 public function escape(string$value):string{return$this->native->escape($value);}
 public function close():bool{$result=$this->native->close();$this->closed=$result;return$result&&!$this->fail_retire;}
 public function force_close():void{$this->native->close();$this->closed=true;}
}
