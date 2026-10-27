<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

/** Request-local possession of the actual native session, without catalog authority. */
final class CustomerPolicySessionOwner implements \JsonSerializable {
 private bool $valid=true;
 private readonly \Closure $current;
 private function __construct(private readonly int $site,private readonly int $actor,private readonly string $subject,private readonly array $route,callable $current){$this->current=\Closure::fromCallable($current);}
 public static function captured(int $site,int $actor,string $subject,array $binding,callable $current):self{
  if($site<1||$actor<0||1!==preg_match('/\A[0-9a-f]{64}\z/D',$subject)||$binding!==['site_id'=>$site,'prefix'=>$binding['prefix']??null]||!is_string($binding['prefix'])||1!==preg_match('/\A[A-Za-z0-9_]{1,57}\z/D',$binding['prefix']))self::deny();
  $self=new self($site,$actor,$subject,$binding,$current);if(!$self->refresh())self::deny();return $self;
 }
 public function site_id():int{return $this->site;}
 public function actor_id():int{return $this->actor;}
 public function session_subject():string{return $this->subject;}
 public function binding():array{return $this->route;}
 public function refresh():bool{try{$this->valid=$this->valid&&true===($this->current)($this->site,$this->actor,$this->subject,$this->route);}catch(\Throwable){$this->valid=false;}return $this->valid;}
 /** SQL-owner-safe: never invokes WordPress or an authorization callback. */
 public function allows():bool{return $this->valid;}
 public function jsonSerialize():never{self::private_only();}public function __serialize():never{self::private_only();}public function __unserialize(array$data):never{self::private_only();}public function __debugInfo():array{return ['native_policy_session'=>'redacted'];}
 private static function deny():never{throw new \RuntimeException('Customer session is unavailable.');}private static function private_only():never{throw new \LogicException('An explicit customer policy projection is required.');}
}
