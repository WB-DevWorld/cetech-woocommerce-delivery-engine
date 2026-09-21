<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core;

/**
 * Environment requirement checks and notice copy.
 */
final class Requirements {

	public function minimum_php_version(): string {
		return '8.3';
	}

	public function is_php_version_supported(): bool {
		return version_compare( PHP_VERSION, $this->minimum_php_version(), '>=' );
	}

	public function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function php_version_notice_message(): string {
		return sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__(
				'CETECH WooCommerce Delivery Engine requires PHP %1$s or higher. This site is running PHP %2$s.',
				'cetech-woocommerce-delivery-engine'
			),
			$this->minimum_php_version(),
			PHP_VERSION
		);
	}

	public function woocommerce_missing_notice_message(): string {
		return __(
			'CETECH WooCommerce Delivery Engine requires WooCommerce to be installed and active.',
			'cetech-woocommerce-delivery-engine'
		);
	}
}
