<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;

/**
 * Activates the supported RC.3 Classic Checkout runtime chain as one operation.
 *
 * Incomplete setup must not enable customer-facing runtime.
 */
final class ClassicCheckoutRuntimeActivation {

	/**
	 * Required Classic Checkout + site-wide defaults runtime flags.
	 *
	 * @var list<string>
	 */
	public const CHAIN = [
		'enable_product_delivery_selector',
		'enable_cart_delivery_selection_capture',
		'enable_checkout_delivery_selection_validation',
		'enable_woocommerce_shipping_rate_calculation',
		'enable_order_delivery_snapshot_persistence',
		'enable_classic_checkout_adapter',
		'enable_effective_configuration_runtime',
		'enable_variable_product_ecr_runtime',
	];

	public function __construct(
		private readonly FeatureFlags $feature_flags,
		private readonly SiteWideDefaultsSettings $settings
	) {
	}

	public function is_active(): bool {
		foreach ( self::CHAIN as $flag ) {
			if ( ! $this->feature_flags->is_enabled( $flag ) ) {
				return false;
			}
		}

		return true;
	}

	public function can_activate(): bool {
		return $this->settings->is_setup_complete();
	}

	public function activate(): bool {
		if ( ! $this->can_activate() ) {
			return false;
		}

		foreach ( self::CHAIN as $flag ) {
			$this->feature_flags->set( $flag, true );
		}

		return true;
	}
}
