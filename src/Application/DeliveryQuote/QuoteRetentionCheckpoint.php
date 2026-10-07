<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
/** Private continuation bound to the exact committed C03 completion. */
final readonly class QuoteRetentionCheckpoint implements \JsonSerializable {
 public const FIELDS=['site_id','run_id','policy_digest','sequence','cutoff_us','quote_ceiling','counter_ceiling','quote_cursor','counter_cursor','phase','inspected','stripped','counters_deleted','protected','invalid','changed','disappeared','soft_stopped','effect_count','effects_digest','manifest_digest'];
 private function __construct(private array $data,private string $namespace) {
  self::schema()->validate($data); QuoteShape::digest($namespace);
  if($data['site_id']<1||$data['cutoff_us']<1||$data['quote_cursor']>$data['quote_ceiling']||$data['counter_cursor']>$data['counter_ceiling']||!hash_equals(self::manifest($data),$data['manifest_digest'])||($data['phase']==='complete'&&($data['quote_cursor']!==$data['quote_ceiling']||$data['counter_cursor']!==$data['counter_ceiling']))||$data['stripped']+$data['counters_deleted']>$data['inspected']||$data['effect_count']>100||$data['sequence']>PHP_INT_MAX-2||$data['effect_count']>$data['stripped']+$data['counters_deleted']
   ||$data['stripped']+$data['counters_deleted']+$data['protected']+$data['invalid']+$data['changed']+$data['disappeared']!==$data['inspected']
   ||($data['sequence']===0&&($data['inspected']!==0||$data['quote_cursor']!==0||$data['counter_cursor']!==0))
   ||($data['effect_count']===0)!==hash_equals(hash('sha256','[]'),$data['effects_digest'])
   ||($data['phase']==='quotes'&&$data['quote_cursor']>=$data['quote_ceiling'])
   ||($data['phase']==='counters'&&($data['quote_cursor']!==$data['quote_ceiling']||$data['counter_cursor']>=$data['counter_ceiling']))
   ||($data['soft_stopped']&&$data['phase']==='complete')) { throw new \InvalidArgumentException('Invalid quote retention checkpoint.'); }
 }
 public static function from_completion(array $facts,string $namespace):self { return new self($facts,$namespace); }
 public static function initial(int $site,string $run,string $policy,int $cutoff,int $quotes,int $counters,string $namespace):self {
  $data=['site_id'=>$site,'run_id'=>$run,'policy_digest'=>$policy,'sequence'=>0,'cutoff_us'=>$cutoff,'quote_ceiling'=>$quotes,'counter_ceiling'=>$counters,'quote_cursor'=>0,'counter_cursor'=>0,'phase'=>$quotes>0?'quotes':($counters>0?'counters':'complete'),'inspected'=>0,'stripped'=>0,'counters_deleted'=>0,'protected'=>0,'invalid'=>0,'changed'=>0,'disappeared'=>0,'soft_stopped'=>false,'effect_count'=>0,'effects_digest'=>hash('sha256','[]')];$data['manifest_digest']=self::manifest($data);return new self($data,$namespace);
 }
 public function facts():array { return $this->data; }
 public function namespace_hash():string { return $this->namespace; }
 public function digest():string { return hash('sha256',json_encode($this->data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); }
 public function complete():bool { return $this->data['phase']==='complete'; }
 public function safe():array { return array_intersect_key($this->data,array_flip(['phase','inspected','stripped','counters_deleted','protected','invalid','changed','disappeared','soft_stopped'])); }
 public static function schema():OperationSchema { return new OperationSchema(['site_id'=>'positive_int','run_id'=>'uuid','policy_digest'=>'sha256','sequence'=>'nonnegative_int','cutoff_us'=>'positive_int','quote_ceiling'=>'nonnegative_int','counter_ceiling'=>'nonnegative_int','quote_cursor'=>'nonnegative_int','counter_cursor'=>'nonnegative_int','phase'=>['enum'=>['quotes','counters','complete']],'inspected'=>'nonnegative_int','stripped'=>'nonnegative_int','counters_deleted'=>'nonnegative_int','protected'=>'nonnegative_int','invalid'=>'nonnegative_int','changed'=>'nonnegative_int','disappeared'=>'nonnegative_int','soft_stopped'=>'bool','effect_count'=>'nonnegative_int','effects_digest'=>'sha256','manifest_digest'=>'sha256']); }
 public static function manifest(array $data):string { $fixed=array_intersect_key($data,array_flip(['site_id','run_id','policy_digest','cutoff_us','quote_ceiling','counter_ceiling']));return hash('sha256','cetech-quote-retention-run-v1:'.json_encode($fixed,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); }
 public function jsonSerialize():never { throw new \LogicException('An explicit quote retention projection is required.'); }
 public function __serialize():array { throw new \LogicException('An explicit quote retention projection is required.'); }
 public function __unserialize(array $data):void { throw new \LogicException('Quote retention requires validated completion facts.'); }
}
