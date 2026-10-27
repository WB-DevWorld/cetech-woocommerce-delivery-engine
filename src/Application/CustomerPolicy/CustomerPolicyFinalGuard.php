<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Application\CustomerPolicy;

use CetechDeliveryEngine\Application\EmergencyControl\{EmergencyCheckoutLocalBinding,EmergencyFinalPlacementGuard,EmergencyOrderQuoteValidatorInterface};

/** An exclusive policy owner adds its own fences; ordinary delegates remain unchanged. */
final class CustomerPolicyFinalGuard implements EmergencyOrderQuoteValidatorInterface, EmergencyFinalPlacementGuard {
	private readonly \Closure $policy_owned;
	private readonly \Closure $legacy_owned;
	private readonly ?\Closure $live;
	public function __construct(
		private readonly EmergencyOrderQuoteValidatorInterface $legacy_validator,
		private readonly EmergencyFinalPlacementGuard $legacy_placement,
		private readonly EmergencyOrderQuoteValidatorInterface $policy_validator,
		private readonly EmergencyFinalPlacementGuard $policy_placement,
		callable $policy_owned, callable $legacy_owned, ?callable $requires_live_legacy_validation = null
	) { $this->policy_owned=\Closure::fromCallable($policy_owned); $this->legacy_owned=\Closure::fromCallable($legacy_owned); $this->live=null===$requires_live_legacy_validation?null:\Closure::fromCallable($requires_live_legacy_validation); }
	public function fingerprint(\WC_Order $order):?string {
		try {
			$owned=$this->owned($order);$legacy=$this->legacy_validator->fingerprint($order);
			if(!$this->same_ownership($order,$owned)){return null;}if(!$owned){return $legacy;}
			if(false!==($this->legacy_owned)($order)||!self::digest($legacy)){return null;}
			$policy=$this->policy_validator->fingerprint($order);
			return $this->same_ownership($order,true)&&self::digest($policy)?hash('sha256','cetech-policy-final-composite-v1:'.$legacy.':'.$policy):null;
		}catch(\Throwable){return null;}
	}
	public function validate_order(\WC_Order $order,string $route):bool {
		try {
			$owned=$this->owned($order);if($owned&&false!==($this->legacy_owned)($order)){return false;}
			$live=true;if($owned&&null!==$this->live){$live=($this->live)($order,$route);if(!is_bool($live)){return false;}}
			if(($live&&!$this->legacy_validator->validate_order($order,$route))||!$this->same_ownership($order,$owned)){return false;}
			if(!$owned){return true;}$allowed=$this->policy_validator->validate_order($order,$route);return $allowed&&$this->same_ownership($order,true);
		}catch(\Throwable){return false;}
	}
	public function complete(\WC_Order $order,string $route,int $revision,EmergencyCheckoutLocalBinding $binding):bool {
		try {
			$owned=$this->owned($order);if($owned&&false!==($this->legacy_owned)($order)){return false;}
			if(!$this->legacy_placement->complete($order,$route,$revision,$binding)||!$this->same_ownership($order,$owned)){return false;}
			if(!$owned){return true;}$allowed=$this->policy_placement->complete($order,$route,$revision,$binding);return $allowed&&$this->same_ownership($order,true);
		}catch(\Throwable){return false;}
	}
	public function admitted(\WC_Order $order,string $route,int $revision,EmergencyCheckoutLocalBinding $binding):bool {
		try {
			$owned=$this->owned($order);if($owned&&false!==($this->legacy_owned)($order)){return false;}
			if(!$this->legacy_placement->admitted($order,$route,$revision,$binding)||!$this->same_ownership($order,$owned)){return false;}
			if(!$owned){return true;}$allowed=$this->policy_placement->admitted($order,$route,$revision,$binding);return $allowed&&$this->same_ownership($order,true);
		}catch(\Throwable){return false;}
	}
	private function owned(\WC_Order $order):bool {$value=($this->policy_owned)($order);if(!is_bool($value)){throw new \RuntimeException('Order ownership unavailable.');}return $value;}
	private function same_ownership(\WC_Order $order,bool $expected):bool {return $this->owned($order)===$expected&&(!$expected||false===($this->legacy_owned)($order)&&$this->owned($order)===$expected);}
	private static function digest(mixed $value):bool {return is_string($value)&&1===preg_match('/\A[0-9a-f]{64}\z/D',$value);}
}
