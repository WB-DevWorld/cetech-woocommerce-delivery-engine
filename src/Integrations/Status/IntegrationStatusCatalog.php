<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Status;

use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutAdapter;
use CetechDeliveryEngine\Integrations\Blocks\BlocksUsageDetector;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;

/**
 * Builds honest operator-facing integration statuses from runtime detection.
 */
final class IntegrationStatusCatalog {

	/** @var array<string, list<string>> */
	private const PLUGIN_FILES = [
		'wpml'    => [ 'sitepress-multilingual-cms/sitepress.php' ],
		'wcml'    => [ 'woocommerce-multilingual/wpml-woocommerce.php' ],
		'wcfm'    => [ 'wc-frontend-manager/wc_frontend_manager.php' ],
		'vitepos' => [ 'vitepos/vitepos.php', 'vite-for-woocommerce-pos/vitepos.php' ],
	];

	public function __construct(
		private IntegrationRegistry $registry,
		private BlocksUsageDetector $blocks_usage,
		private ?BlocksCheckoutAdapter $blocks_adapter = null
	) {
	}

	/**
	 * @return list<IntegrationStatus>
	 */
	public function all(): array {
		return [
			$this->wpml(),
			$this->wcml(),
			$this->woodmart(),
			$this->wcfm(),
			$this->vitepos(),
			$this->blocks(),
		];
	}

	public function by_key( string $key ): ?IntegrationStatus {
		foreach ( $this->all() as $status ) {
			if ( $status->key === $key ) {
				return $status;
			}
		}

		return null;
	}

	public function wpml(): IntegrationStatus {
		return $this->stub_plugin_status(
			'wpml',
			__( 'WPML', 'cetech-woocommerce-delivery-engine' ),
			$this->defined_version( 'ICL_SITEPRESS_VERSION' ),
			(bool) ( $this->registry->get_detection_statuses()['wpml'] ?? false ),
			__( 'No Delivery Engine translation adapter is implemented in this release. Translated WooCommerce products remain separate product IDs.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	public function wcml(): IntegrationStatus {
		$active = (bool) ( $this->registry->get_detection_statuses()['wcml'] ?? false );
		$version = $this->defined_version( 'WCML_VERSION' );

		return $this->stub_plugin_status(
			'wcml',
			__( 'WooCommerce Multilingual', 'cetech-woocommerce-delivery-engine' ),
			$version,
			$active,
			__( 'No Delivery Engine multi-currency adapter is implemented in this release. Quotes still match the store currency exactly and fail closed on mismatch.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	public function woodmart(): IntegrationStatus {
		$detected = (bool) ( $this->registry->get_detection_statuses()['woodmart'] ?? false );
		$version  = $this->woodmart_version();

		if ( ! $detected ) {
			return new IntegrationStatus(
				'woodmart',
				__( 'WoodMart theme', 'cetech-woocommerce-delivery-engine' ),
				IntegrationStatus::STATE_NOT_INSTALLED,
				null,
				false,
				false,
				false,
				false,
				__( 'Not the active theme', 'cetech-woocommerce-delivery-engine' ),
				__( 'No dedicated WoodMart adapter is required for Classic WooCommerce checkout. Core delivery uses generic WooCommerce hooks.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$summary = __( 'Supported through generic WooCommerce checkout', 'cetech-woocommerce-delivery-engine' );

		return new IntegrationStatus(
			'woodmart',
			__( 'WoodMart theme', 'cetech-woocommerce-delivery-engine' ),
			IntegrationStatus::STATE_SUPPORTED,
			$version,
			true,
			true,
			false,
			true,
			$summary,
			__( 'Detected as the active theme. There is no dedicated WoodMart adapter. Classic checkout uses generic WooCommerce hooks. Do not edit the WoodMart parent theme.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	public function wcfm(): IntegrationStatus {
		$label  = __( 'WCFM Marketplace', 'cetech-woocommerce-delivery-engine' );
		$detail = __(
			'Supported for administrative isolation. WCFM vendors are kept outside Delivery Engine administration. Vendor-specific Delivery Engine fulfilment controls are not provided.',
			'cetech-woocommerce-delivery-engine'
		);
		$version = $this->defined_version( 'WCFM_VERSION' );
		$active  = (bool) ( $this->registry->get_detection_statuses()['wcfm'] ?? false );
		$installed = $active || $this->plugin_file_installed( 'wcfm' );

		if ( ! $installed ) {
			return new IntegrationStatus(
				'wcfm',
				$label,
				IntegrationStatus::STATE_NOT_INSTALLED,
				null,
				false,
				false,
				false,
				false,
				__( 'Not installed', 'cetech-woocommerce-delivery-engine' ),
				$detail
			);
		}

		if ( ! $active ) {
			return new IntegrationStatus(
				'wcfm',
				$label,
				IntegrationStatus::STATE_INSTALLED_INACTIVE,
				$version,
				true,
				false,
				false,
				false,
				__( 'Installed but inactive', 'cetech-woocommerce-delivery-engine' ),
				$detail
			);
		}

		return new IntegrationStatus(
			'wcfm',
			$label,
			IntegrationStatus::STATE_SUPPORTED,
			$version,
			true,
			true,
			false,
			true,
			__( 'Supported for administrative isolation', 'cetech-woocommerce-delivery-engine' ),
			$detail
		);
	}

	public function vitepos(): IntegrationStatus {
		$version = $this->defined_version( 'VITEPOS_VERSION' );

		return $this->stub_plugin_status(
			'vitepos',
			__( 'VitePOS', 'cetech-woocommerce-delivery-engine' ),
			$version,
			(bool) ( $this->registry->get_detection_statuses()['vitepos'] ?? false ),
			__( 'No point-of-sale adapter is implemented. REST presence is not checkout compatibility. In-register flows are not qualified.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	public function blocks(): IntegrationStatus {
		$detected = (bool) ( $this->registry->get_detection_statuses()['wc_blocks'] ?? false )
			|| $this->store_api_available();
		$implemented = null !== $this->blocks_adapter && $this->blocks_adapter->is_implemented();
		$in_use      = $this->blocks_usage->is_in_use();
		$version     = $this->blocks_version();

		if ( ! $detected && ! $implemented ) {
			return new IntegrationStatus(
				'blocks',
				__( 'WooCommerce Cart & Checkout Blocks', 'cetech-woocommerce-delivery-engine' ),
				IntegrationStatus::STATE_NOT_INSTALLED,
				null,
				false,
				false,
				$implemented,
				false,
				__( 'WooCommerce Blocks / Store API was not detected', 'cetech-woocommerce-delivery-engine' ),
				__( 'Classic checkout remains the supported path when Cart and Checkout Blocks are not present.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$state = $implemented
			? ( $in_use ? IntegrationStatus::STATE_ACTIVE : IntegrationStatus::STATE_SUPPORTED )
			: IntegrationStatus::STATE_ADAPTER_NOT_IMPLEMENTED;

		$summary = $implemented
			? __( 'Supported', 'cetech-woocommerce-delivery-engine' )
			: __( 'Adapter not implemented', 'cetech-woocommerce-delivery-engine' );

		return new IntegrationStatus(
			'blocks',
			__( 'WooCommerce Cart & Checkout Blocks', 'cetech-woocommerce-delivery-engine' ),
			$state,
			$version,
			true,
			$detected,
			$implemented,
			$in_use,
			$summary,
			$implemented
				? __( 'Store API cart, checkout validation, snapshots, and customer-safe package data are implemented. Classic checkout is unchanged.', 'cetech-woocommerce-delivery-engine' )
				: __( 'WooCommerce Blocks was detected, but this Delivery Engine build does not include a Blocks adapter.', 'cetech-woocommerce-delivery-engine' )
		);
	}

	private function stub_plugin_status(
		string $key,
		string $label,
		?string $version,
		bool $active,
		string $detail
	): IntegrationStatus {
		$installed = $active || $this->plugin_file_installed( $key );

		if ( ! $installed ) {
			return new IntegrationStatus(
				$key,
				$label,
				IntegrationStatus::STATE_NOT_INSTALLED,
				null,
				false,
				false,
				false,
				false,
				__( 'Not installed', 'cetech-woocommerce-delivery-engine' ),
				$detail
			);
		}

		if ( ! $active ) {
			return new IntegrationStatus(
				$key,
				$label,
				IntegrationStatus::STATE_INSTALLED_INACTIVE,
				$version,
				true,
				false,
				false,
				false,
				__( 'Installed but inactive', 'cetech-woocommerce-delivery-engine' ),
				$detail
			);
		}

		$state = null !== $version && '' !== $version
			? IntegrationStatus::STATE_DETECTED
			: IntegrationStatus::STATE_ADAPTER_NOT_IMPLEMENTED;

		return new IntegrationStatus(
			$key,
			$label,
			IntegrationStatus::STATE_ADAPTER_NOT_IMPLEMENTED === $state
				? IntegrationStatus::STATE_ADAPTER_NOT_IMPLEMENTED
				: IntegrationStatus::STATE_DETECTED,
			$version,
			true,
			true,
			false,
			false,
			__( 'Adapter not implemented', 'cetech-woocommerce-delivery-engine' ),
			$detail
		);
	}

	private function plugin_file_installed( string $key ): bool {
		$files = self::PLUGIN_FILES[ $key ] ?? [];

		if ( [] === $files ) {
			return false;
		}

		if ( function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();

			foreach ( $files as $file ) {
				if ( isset( $plugins[ $file ] ) ) {
					return true;
				}
			}
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			foreach ( $files as $file ) {
				if ( is_readable( WP_PLUGIN_DIR . '/' . $file ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function defined_version( string $constant ): ?string {
		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = constant( $constant );

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$version = trim( (string) $value );

		return '' !== $version ? $version : null;
	}

	private function woodmart_version(): ?string {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return null;
		}

		$theme = wp_get_theme();
		$version = trim( (string) $theme->get( 'Version' ) );

		if ( 'woodmart' !== strtolower( (string) $theme->get_template() )
			&& 'woodmart' !== strtolower( (string) $theme->get_stylesheet() )
		) {
			$parent = $theme->parent();

			if ( $parent ) {
				$version = trim( (string) $parent->get( 'Version' ) );
			}
		}

		return '' !== $version ? $version : null;
	}

	private function store_api_available(): bool {
		return function_exists( 'woocommerce_store_api_register_endpoint_data' )
			|| class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' );
	}

	private function blocks_version(): ?string {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' )
			&& is_callable( [ '\Automattic\WooCommerce\Blocks\Package', 'get_version' ] )
		) {
			$version = \Automattic\WooCommerce\Blocks\Package::get_version();

			if ( is_scalar( $version ) && '' !== trim( (string) $version ) ) {
				return trim( (string) $version );
			}
		}

		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}

		return null;
	}
}
