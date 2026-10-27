<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

/** A server-captured product/cart resource; a posted coordinate is never this grant. */
final class CustomerPolicyReadGrant implements \JsonSerializable {
 private bool $valid=true;private readonly \Closure $current;
 private function __construct(private readonly array $facts,private readonly array $route,private readonly string $resource,callable $current){$this->current=\Closure::fromCallable($current);}
 public static function captured(array $frame,array $binding,callable $current):self{
  $frame=self::checked_frame($frame);if($binding!==['site_id'=>$frame['site_id'],'prefix'=>$binding['prefix']??null]||!is_string($binding['prefix'])||1!==preg_match('/\A[A-Za-z0-9_]{1,57}\z/D',$binding['prefix']))self::deny();
  $self=new self($frame,$binding,hash('sha256',random_bytes(32)),$current);if(!$self->refresh())self::deny();return $self;
 }
 public static function checked_frame(array $f):array{
  $keys=['site_id','actor_id','session_subject','product_id','variation_id','parent_id','quantity','real_offer_id','context_digest','cart_key','purpose'];if(count($f)!==count($keys)||array_diff($keys,array_keys($f)))self::deny();
  foreach(['site_id','product_id','real_offer_id']as$key)if(!is_int($f[$key])||$f[$key]<1)self::deny();foreach(['actor_id','variation_id','parent_id']as$key)if(!is_int($f[$key])||$f[$key]<0)self::deny();
  foreach(['session_subject','context_digest']as$key)if(!is_string($f[$key])||1!==preg_match('/\A[0-9a-f]{64}\z/D',$f[$key]))self::deny();
  if(!is_string($f['quantity'])||1!==preg_match('/\A[1-9][0-9]*\z/D',$f['quantity'])||strlen($f['quantity'])>strlen((string)PHP_INT_MAX)||(strlen($f['quantity'])===strlen((string)PHP_INT_MAX)&&strcmp($f['quantity'],(string)PHP_INT_MAX)>0))self::deny();
  if(!is_string($f['cart_key'])||strlen($f['cart_key'])>256||1!==preg_match('//u',$f['cart_key'])||!in_array($f['purpose'],['product','cart'],true)||('product'===$f['purpose']?''!==$f['cart_key']:''===$f['cart_key']))self::deny();
  if(0===$f['variation_id']?0!==$f['parent_id']:($f['parent_id']!==$f['product_id']||$f['variation_id']===$f['product_id']))self::deny();
  $out=[];foreach($keys as$key)$out[$key]=$f[$key];return $out;
 }
 public function key():string{return $this->resource;}public function frame():array{return $this->facts;}public function binding():array{return $this->route;}public function session_subject():string{return $this->facts['session_subject'];}
 public function refresh():bool{try{$this->valid=$this->valid&&true===($this->current)($this->facts,$this->route);}catch(\Throwable){$this->valid=false;}return $this->valid;}
 /** Pure under owned SQL. Only refresh() re-enters native resource validation. */
 public function allows():bool{return $this->valid;}
 public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return ['native_policy_resource'=>'redacted'];}
 private static function deny():never{throw new \RuntimeException('Customer policy resource is unavailable.');}private static function private_only():never{throw new \LogicException('An explicit customer policy projection is required.');}
}
