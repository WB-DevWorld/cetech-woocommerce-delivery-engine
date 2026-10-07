<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Infrastructure\WordPress\{OperationConnection,OperationConnectionMysqliTransport};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase;

/** Fresh physical RR owners in an exclusive marked-disposable database. */
final class QuoteProviderProofFactory implements OperationConnectionFactory {
	public array $transports=[];
	public array $sessions=[];
	public ?\Closure $configure=null;
	public int $clock;
	public function __construct(public readonly string $prefix,public readonly int $site_id=1){DataLifecycleProofDatabase::validate_prefix($prefix);$this->clock=time();}
	public function open():OperationSession {
		$native=OperationConnectionMysqliTransport::connect((string)(getenv('CETECH_DE_REAL_DB_HOST')?:'127.0.0.1'),(string)(getenv('CETECH_DE_REAL_DB_USER')?:'root'),(string)(getenv('CETECH_DE_REAL_DB_PASSWORD')?:''),(string)(getenv('CETECH_DE_REAL_DB_NAME')?:''),(int)(getenv('CETECH_DE_REAL_DB_PORT')?:3306));
		foreach(['SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ','SET SESSION innodb_snapshot_isolation=0',"SET SESSION time_zone='+00:00'",'SET timestamp='.$this->clock] as $sql){if(!$native->execute($sql)->acknowledged){$native->close();throw new \RuntimeException('Disposable provider runtime refused.');}}
		$t=new QuoteProviderProofTransport($native);$this->transports[]=$t;if(null!==$this->configure){($this->configure)($t,count($this->transports));}
		$s=new OperationConnection($this->site_id,$this->prefix,$t,'utf8mb4','utf8mb4_unicode_ci');$this->sessions[]=$s;return $s;
	}
	public function close_all():void{foreach($this->transports as $t){$t->force_close();}}
}
