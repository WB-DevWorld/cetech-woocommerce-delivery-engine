<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

/**
 * Feature flags stored in wp_options with the cetech_de_ prefix.
 */
final class FeatureFlags {

	public const OPTION_PREFIX = 'cetech_de_';

	/** @var array<string, bool> */
	private const DEFAULTS = [
		'enable_product_delivery_selector'         => false,
		'enable_cart_delivery_selection_capture'   => false,
		'enable_checkout_delivery_selection_validation' => false,
		'enable_woocommerce_shipping_rate_calculation' => false,
		'enable_order_delivery_snapshot_persistence' => false,
		'enable_customer_order_delivery_summary' => false,
		'enable_customer_email_delivery_summary' => false,
		'enable_shipment_records'          => false,
		'enable_customer_timeline'         => false,
		'enable_tracking_links'            => false,
		'enable_wpml_adapter'              => false,
		'enable_wcml_adapter'              => false,
		'enable_woodmart_adapter'          => false,
		'enable_wcfm_adapter'              => false,
		'enable_vitepos_adapter'           => false,
		'enable_bulk_import'               => false,
		'enable_classic_checkout_adapter'  => true,
		'enable_blocks_adapter'            => false,
		'enable_category_rules'            => false,
		'enable_site_fallback_rule'        => false,
		'enable_effective_configuration_runtime' => false,
		'demo_data_on_activation'          => false,
	];

	/** @var array<string, bool>|null */
	private ?array $cache = null;

	public function option_name( string $flag ): string {
		return self::OPTION_PREFIX . $flag;
	}

	/**
	 * @return array<string, bool>
	 */
	public function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * @return array<string, bool>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$flags = [];

		foreach ( self::DEFAULTS as $flag => $default ) {
			$flags[ $flag ] = $this->get( $flag );
		}

		$this->cache = $flags;

		return $flags;
	}

	public function is_enabled( string $flag ): bool {
		return $this->get( $flag );
	}

	public function get( string $flag ): bool {
		if ( ! array_key_exists( $flag, self::DEFAULTS ) ) {
			return false;
		}

		$stored = get_option( $this->option_name( $flag ), null );

		if ( null === $stored ) {
			return self::DEFAULTS[ $flag ];
		}

		return (bool) (int) $stored;
	}

	public function set( string $flag, bool $value ): void {
		if ( ! array_key_exists( $flag, self::DEFAULTS ) ) {
			return;
		}

		update_option( $this->option_name( $flag ), $value ? 1 : 0, false );
		$this->cache = null;
	}

	/**
	 * Persist default values for flags that are not yet stored.
	 */
	public function ensure_defaults(): void {
		foreach ( self::DEFAULTS as $flag => $default ) {
			$option = $this->option_name( $flag );

			if ( null === get_option( $option, null ) ) {
				add_option( $option, $default ? 1 : 0, '', false );
			}
		}

		$this->cache = null;
	}
}
