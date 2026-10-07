<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

/** A request-local admission exists only after fresh validation and confirmed lock release. */
final class EmergencyCheckoutAdmissionService {
	/** @var \WeakMap<\WC_Order,array{route:string,fingerprint:string,revision:int,site:int,binding:EmergencyCheckoutLocalBinding}> */
	private \WeakMap $stamps;

	public function __construct(
		private readonly EmergencyAdmissionControlInterface $control,
		private readonly EmergencyOwnershipClassifier $classifier,
		private readonly EmergencyOrderQuoteValidatorInterface $quote_validator,
		private readonly EmergencyOwnershipLatch $latch
	) {
		$this->stamps = new \WeakMap();
	}

	public function early_product( int $product_id, ?int $variation_id = null ): EmergencyAdmissionResult {
		return $this->early( $this->classifier->product( $product_id, $variation_id ) );
	}

	public function early_cart( array $lines, array $packages = [] ): EmergencyAdmissionResult {
		$ownership = $this->classifier->cart( $lines, $packages );
		if ( EmergencyOwnership::Unmanaged === $ownership ) {
			foreach ( $lines as $key => $line ) {
				if ( $this->latch->contains_line( (string) $key ) ) {
					$ownership = EmergencyOwnership::Managed;
					break;
				}
			}
		}
		return $this->early( $ownership );
	}

	public function final_order( \WC_Order $order, string $route ): EmergencyAdmissionResult {
		$ownership = EmergencyOwnership::Unresolved;
		try {
			if ( ! in_array( $route, [ 'classic', 'blocks', 'store_api', 'order_pay' ], true ) ) {
				return new EmergencyAdmissionResult( false, 'unsupported_activation_policy', $ownership );
			}
			if ( $order->is_paid() ) {
				return new EmergencyAdmissionResult( true, 'already_paid', EmergencyOwnership::Unmanaged );
			}
			if ( $this->admitted( $order, $route ) ) {
				return new EmergencyAdmissionResult( true, 'allowed', EmergencyOwnership::Managed, $this->stamps[ $order ]['revision'] );
			}
			$ownership = $this->classifier->order( $order );
			if ( $this->latch->has_possible_ownership() ) {
				if ( ! $this->latch->matches_order( $order ) ) {
					return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', EmergencyOwnership::Unresolved );
				}
				$ownership = EmergencyOwnership::Managed;
			}
			if ( EmergencyOwnership::Unmanaged === $ownership ) {
				return new EmergencyAdmissionResult( true, 'unmanaged', $ownership );
			}
			if ( EmergencyOwnership::Unresolved === $ownership ) {
				return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', $ownership );
			}
			$site = get_current_blog_id();
			if ( ! is_int( $site ) || $site < 1 ) {
				return new EmergencyAdmissionResult( false, 'control_unavailable', $ownership );
			}
			$read = $this->control->read( $site );
			if ( ! $read->available || null === $read->state || $read->state->site_id !== $site ) {
				return new EmergencyAdmissionResult( false, 'control_unavailable', $ownership );
			}
			if ( ! $read->state->enabled() ) {
				return new EmergencyAdmissionResult( false, 'checkout_suspended', $ownership, $read->state->revision );
			}
			$before = $this->quote_validator->fingerprint( $order );
			if ( null === $before || ! $this->quote_validator->validate_order( $order, $route ) ) {
				return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', $ownership, $read->state->revision );
			}
			$bound = $this->quote_validator->fingerprint( $order );
			if ( null === $bound || ! hash_equals( $before, $bound ) ) {
				return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', $ownership, $read->state->revision );
			}
			$local = EmergencyCheckoutLocalBinding::capture( $order );
			if ( null === $local ) {
				return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', $ownership, $read->state->revision );
			}
			// Only prewarmed native raw data is compared under the lock. Filtered Woo reads stay outside it.
			$unchanged = $local->unchanged( ... );
			$confirmed = $this->control->confirm_enabled( $site, $read->state->revision, $unchanged );
			if ( ! $confirmed->available || null === $confirmed->state ) {
				$code = match ( $confirmed->code ) {
					'checkout_suspended' => 'checkout_suspended',
					'stale_revision' => 'stale_control_revision',
					default => 'control_unavailable',
				};
				return new EmergencyAdmissionResult( false, $code, $ownership );
			}
			if ( $confirmed->state->site_id !== $site || ! $confirmed->state->enabled() || $confirmed->state->revision !== $read->state->revision
				|| get_current_blog_id() !== $site || $this->quote_validator->fingerprint( $order ) !== $bound || ! $local->unchanged() ) {
				return new EmergencyAdmissionResult( false, 'checkout_revalidation_required', $ownership );
			}
			$this->stamps[ $order ] = [ 'route' => $route, 'fingerprint' => $bound, 'revision' => $confirmed->state->revision, 'site' => $site, 'binding' => $local ];
			return new EmergencyAdmissionResult( true, 'allowed', $ownership, $confirmed->state->revision );
		} catch ( \Throwable ) {
			return new EmergencyAdmissionResult( false, 'control_unavailable', $ownership );
		}
	}

	public function admitted( \WC_Order $order, string $route ): bool {
		try {
			return isset( $this->stamps[ $order ] ) && $this->stamps[ $order ]['route'] === $route
				&& get_current_blog_id() === $this->stamps[ $order ]['site']
				&& $this->quote_validator->fingerprint( $order ) === $this->stamps[ $order ]['fingerprint']
				&& $this->stamps[ $order ]['binding']->unchanged();
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function early( EmergencyOwnership $ownership ): EmergencyAdmissionResult {
		if ( EmergencyOwnership::Unmanaged === $ownership ) {
			return new EmergencyAdmissionResult( true, 'unmanaged', $ownership );
		}
		try {
			$read = $this->control->read( get_current_blog_id() );
			if ( ! $read->available || null === $read->state || $read->state->site_id !== get_current_blog_id() ) {
				return new EmergencyAdmissionResult( false, 'control_unavailable', $ownership );
			}
			if ( ! $read->state->enabled() ) {
				return new EmergencyAdmissionResult( false, 'checkout_suspended', $ownership, $read->state->revision );
			}
			return EmergencyOwnership::Unresolved === $ownership
				? new EmergencyAdmissionResult( false, 'checkout_revalidation_required', $ownership, $read->state->revision )
				: new EmergencyAdmissionResult( true, 'allowed', $ownership, $read->state->revision );
		} catch ( \Throwable ) {
			return new EmergencyAdmissionResult( false, 'control_unavailable', $ownership );
		}
	}
}
