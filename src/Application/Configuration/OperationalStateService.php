<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Builds the one authoritative operational state snapshot used by admin surfaces.
 */
final class OperationalStateService {

	/**
	 * Flags that mean an existing supported Classic Checkout runtime is serving customers
	 * even when Stage 13 ECR cutover is still off.
	 *
	 * @var list<string>
	 */
	private const LEGACY_SERVING_FLAGS = [
		'enable_product_delivery_selector',
		'enable_cart_delivery_selection_capture',
		'enable_checkout_delivery_selection_validation',
		'enable_woocommerce_shipping_rate_calculation',
		'enable_order_delivery_snapshot_persistence',
	];

	public function __construct(
		private readonly FeatureFlags $feature_flags,
		private readonly SiteWideDefaultsSettings $settings,
		private readonly SetupWizardProgress $progress,
		private readonly ClassicCheckoutRuntimeActivation $runtime,
		private readonly SiteWideDefaultSummary $summaries
	) {
	}

	public function current(): OperationalState {
		$setup_complete   = $this->settings->is_setup_complete();
		$prior_install    = $this->progress->has_prior_operational_install();
		$ecr_active       = $this->runtime->is_active();
		$legacy_serving   = ! $ecr_active && $this->legacy_customer_runtime_active();
		$defaults_applied = $setup_complete && $this->configured_profile_count() > 0;

		if ( $ecr_active ) {
			$customer_runtime = OperationalState::CUSTOMER_RUNTIME_ECR;
		} elseif ( $legacy_serving || $prior_install ) {
			$customer_runtime = OperationalState::CUSTOMER_RUNTIME_LEGACY;
		} else {
			$customer_runtime = OperationalState::CUSTOMER_RUNTIME_NONE;
		}

		$scan_as_customer = OperationalState::CUSTOMER_RUNTIME_ECR === $customer_runtime;

		return new OperationalState(
			$customer_runtime,
			$setup_complete,
			$prior_install,
			$ecr_active,
			$legacy_serving,
			$this->overview_tone( $customer_runtime, $setup_complete, $prior_install ),
			$this->overview_title( $customer_runtime, $setup_complete, $prior_install ),
			$this->overview_text( $customer_runtime, $setup_complete, $prior_install ),
			$this->settings_status_label( $customer_runtime ),
			$this->settings_status_detail( $customer_runtime, $setup_complete ),
			$this->products_defaults_label( $defaults_applied ),
			$defaults_applied,
			$scan_as_customer
		);
	}

	public function configured_profile_count(): int {
		$state  = $this->settings->read();
		$active = $state['active_profiles'] ?: FulfilmentProfileRegistry::keys();
		$count  = 0;
		foreach ( $active as $key ) {
			if ( $this->summaries->for_profile( (string) $key )['configured'] ) {
				++$count;
			}
		}

		return $count;
	}

	private function legacy_customer_runtime_active(): bool {
		foreach ( self::LEGACY_SERVING_FLAGS as $flag ) {
			if ( $this->feature_flags->is_enabled( $flag ) ) {
				return true;
			}
		}

		return false;
	}

	private function overview_tone( string $customer_runtime, bool $setup_complete, bool $prior_install ): string {
		if ( OperationalState::CUSTOMER_RUNTIME_ECR === $customer_runtime && $setup_complete ) {
			return 'ready';
		}

		if ( OperationalState::CUSTOMER_RUNTIME_LEGACY === $customer_runtime || $prior_install ) {
			return 'attention';
		}

		if ( ! $setup_complete ) {
			return 'attention';
		}

		return 'ready';
	}

	private function overview_title( string $customer_runtime, bool $setup_complete, bool $prior_install ): string {
		if ( OperationalState::CUSTOMER_RUNTIME_ECR === $customer_runtime && $setup_complete ) {
			return __( 'Delivery system active', 'cetech-woocommerce-delivery-engine' );
		}

		if ( OperationalState::CUSTOMER_RUNTIME_LEGACY === $customer_runtime || $prior_install ) {
			return __( 'Existing delivery configuration is still serving customers', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ! $setup_complete ) {
			return __( 'Setup is not finished', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Delivery Engine ready to activate', 'cetech-woocommerce-delivery-engine' );
	}

	private function overview_text( string $customer_runtime, bool $setup_complete, bool $prior_install ): string {
		if ( OperationalState::CUSTOMER_RUNTIME_ECR === $customer_runtime && $setup_complete ) {
			return __( 'Site-wide Defaults are applied and the supported checkout runtime is active.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( OperationalState::CUSTOMER_RUNTIME_LEGACY === $customer_runtime || $prior_install ) {
			return __( 'Existing delivery configuration is still serving customers while you finish Delivery Engine setup.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ! $setup_complete ) {
			return __( 'Continue setup to activate site-wide defaults safely.', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Finish activation so customers can use Site-wide Defaults at checkout.', 'cetech-woocommerce-delivery-engine' );
	}

	private function settings_status_label( string $customer_runtime ): string {
		return match ( $customer_runtime ) {
			OperationalState::CUSTOMER_RUNTIME_ECR => __( 'Active', 'cetech-woocommerce-delivery-engine' ),
			OperationalState::CUSTOMER_RUNTIME_LEGACY => __( 'Existing configuration active', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Not active', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	private function settings_status_detail( string $customer_runtime, bool $setup_complete ): string {
		unset( $setup_complete );
		if ( OperationalState::CUSTOMER_RUNTIME_ECR === $customer_runtime ) {
			return __( 'Customers use Site-wide Defaults through the supported checkout runtime.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( OperationalState::CUSTOMER_RUNTIME_LEGACY === $customer_runtime ) {
			return __( 'Existing delivery configuration is still serving customers while you finish Delivery Engine setup.', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'No supported Delivery Engine runtime is currently serving customers.', 'cetech-woocommerce-delivery-engine' );
	}

	private function products_defaults_label( bool $defaults_applied ): string {
		if ( $defaults_applied ) {
			return __( 'Products using Site-wide Defaults', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Products eligible for Site-wide Defaults', 'cetech-woocommerce-delivery-engine' );
	}
}
