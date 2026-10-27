<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;
use CetechDeliveryEngine\Infrastructure\Persistence\CustomerPolicyCurrentSource;

/** One batch is all-or-none. No persistent or admission memo is created. */
final readonly class CustomerPolicyCurrentProvider {
 public function __construct(private CustomerPolicyCurrentSource $source){}
 public function capture_batch(array $grants):array{
  $capture=$this->source->capture_batch($grants);$result=[];
  if(array_keys($capture['items'])!==array_keys($grants))throw new \RuntimeException('Customer policies are unavailable.');
  foreach($grants as$key=>$grant){if(!$grant instanceof CustomerPolicyReadGrant||!$grant->refresh())throw new \RuntimeException('Customer policies are unavailable.');$result[$key]=CustomerPolicyCurrentObservation::captured($grant,$capture['items'][$key]['families'],$capture['at']);}
  return $result;
 }
}
