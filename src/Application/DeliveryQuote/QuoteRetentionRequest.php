<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
/** Original run/page envelope; reconciliation never manufactures a replacement envelope. */
final readonly class QuoteRetentionRequest implements OperationCommand,\JsonSerializable {
 private function __construct(public OperationIdentity $identity,public ?QuoteRetentionCheckpoint $previous,public string $policy,private CanonicalIntent $canonical) {}
 public static function start(int $site,string $policy,?string $run=null):self { QuoteShape::digest($policy);$run??=QuoteId::generate()->value();QuoteId::from_string($run);$id=self::identity($site,$run,0);return new self($id,null,$policy,CanonicalIntent::from_command($id,['run_id'=>$run],['sequence'=>0],['policy_digest'=>$policy])); }
 public static function next(QuoteRetentionCheckpoint $previous):self { $data=$previous->facts();if($previous->complete()||$data['sequence']===PHP_INT_MAX) { throw new \InvalidArgumentException('Quote retention run is complete.'); }$id=self::identity($data['site_id'],$data['run_id'],$data['sequence']+1);return new self($id,$previous,$data['policy_digest'],CanonicalIntent::from_command($id,['run_id'=>$data['run_id']],$data,['previous_namespace'=>$previous->namespace_hash(),'previous_digest'=>$previous->digest()])); }
 public function to_private_array():array { return ['format'=>1,'site_id'=>$this->identity->site_id,'run_id'=>substr($this->identity->target_key,strlen('retention:')),'policy'=>$this->policy,'previous'=>$this->previous?->facts(),'previous_namespace'=>$this->previous?->namespace_hash()]; }
 public static function from_private_array(array $data):self { if(count($data)!==6||array_diff(['format','site_id','run_id','policy','previous','previous_namespace'],array_keys($data))!==[]||$data['format']!==1||!is_int($data['site_id'])||$data['site_id']<1||!is_string($data['run_id'])||!is_string($data['policy'])) { throw new \InvalidArgumentException('Invalid retention request envelope.'); }if(null===$data['previous']&&null===$data['previous_namespace']) { return self::start($data['site_id'],$data['policy'],$data['run_id']); }if(!is_array($data['previous'])||!is_string($data['previous_namespace'])) { throw new \InvalidArgumentException('Invalid retention request envelope.'); }$cp=QuoteRetentionCheckpoint::from_completion($data['previous'],$data['previous_namespace']);$facts=$cp->facts();if($facts['site_id']!==$data['site_id']||$facts['run_id']!==$data['run_id']||$facts['policy_digest']!==$data['policy']) { throw new \InvalidArgumentException('Invalid retention request envelope.'); }return self::next($cp); }
 public function intent():CanonicalIntent { return $this->canonical; }
 public static function identity(int $site,string $run,int $sequence):OperationIdentity { return new OperationIdentity($site,'delivery_quote_retention','quote-retention','delivery_quote.retention.'.($sequence===0?'start':'batch'),1,'retention:'.$run, $run.':'.$sequence); }
 public function jsonSerialize():never { throw new \LogicException('An explicit quote retention request projection is required.'); }
 public function __serialize():array { throw new \LogicException('An explicit quote retention request projection is required.'); }
 public function __unserialize(array $data):void { throw new \LogicException('Quote retention requests require validated facts.'); }
}
