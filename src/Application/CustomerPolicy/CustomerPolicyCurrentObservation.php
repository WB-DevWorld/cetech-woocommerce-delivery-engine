<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;
use CetechDeliveryEngine\Domain\CustomerPolicy\{PolicyFamilyRecord,PolicyJson};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Complete source result, published only after its read owner acknowledged retirement. */
final readonly class CustomerPolicyCurrentObservation implements \JsonSerializable {
 private function __construct(private CustomerPolicyReadGrant $resource,private array $records,private RuleTime $at,private string $material){}
 public static function captured(CustomerPolicyReadGrant $grant,array $families,RuleTime $at):self{if(!$grant->allows())throw new \RuntimeException('Customer policies are unavailable.');return new self($grant,$families,$at,self::material_for_frame($grant->frame(),$families));}
 public function grant():CustomerPolicyReadGrant{return $this->resource;}public function frame():array{return $this->resource->frame();}public function families():array{return $this->records;}public function material_digest():string{return $this->material;}public function captured_at():RuleTime{return $this->at;}
 public static function material_for_frame(array $frame,array $families):string{
  $frame=CustomerPolicyReadGrant::checked_frame($frame);if(count($families)!==2||array_diff(['return_policy_v1','refund_policy_v1'],array_keys($families)))throw new \RuntimeException('Customer policies are unavailable.');$hashes=[];
  foreach(['return_policy_v1','refund_policy_v1']as$key){if(!$families[$key] instanceof PolicyFamilyRecord)throw new \RuntimeException('Customer policies are unavailable.');$hashes[$key]=$families[$key]->material_digest();}
  return hash('sha256','cetech-customer-policy-material-v1:'.PolicyJson::encode(['frame'=>$frame,'families'=>$hashes],65536));
 }
 public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return ['customer_policy_observation'=>'redacted'];}private static function private_only():never{throw new \LogicException('An explicit customer policy projection is required.');}
}
