<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

/**
 * Single authoritative operational state for Overview, Settings, Setup Guide,
 * Delivery Preview, Needs Attention, and Legacy messaging.
 *
 * Do not invent a second interpretation of the same flags elsewhere.
 */
final class OperationalState {

	public const CUSTOMER_RUNTIME_ECR    = 'ecr';
	public const CUSTOMER_RUNTIME_LEGACY = 'legacy';
	public const CUSTOMER_RUNTIME_NONE   = 'inactive';

	public function __construct(
		public readonly string $customer_runtime,
		public readonly bool $sitewide_setup_complete,
		public readonly bool $prior_install,
		public readonly bool $ecr_runtime_active,
		public readonly bool $legacy_customer_runtime_active,
		public readonly string $overview_tone,
		public readonly string $overview_title,
		public readonly string $overview_text,
		public readonly string $settings_status_label,
		public readonly string $settings_status_detail,
		public readonly string $products_defaults_label,
		public readonly bool $defaults_applied,
		public readonly bool $scan_catalog_as_customer_problems
	) {
	}

	public function customers_still_use_previous_rules(): bool {
		return self::CUSTOMER_RUNTIME_LEGACY === $this->customer_runtime;
	}

	public function customers_use_sitewide_runtime(): bool {
		return self::CUSTOMER_RUNTIME_ECR === $this->customer_runtime;
	}
}
