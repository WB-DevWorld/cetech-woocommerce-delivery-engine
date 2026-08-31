<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core;

/**
 * WooCommerce feature compatibility declarations.
 */
final class FeaturesCompatibility {

	private static bool $hpos_hook_registered = false;

	private static bool $blocks_declared = false;

	public static function register_hpos_declaration( string $plugin_file ): void {
		if ( self::$hpos_hook_registered ) {
			return;
		}

		self::$hpos_hook_registered = true;

		add_action(
			'before_woocommerce_init',
			static function () use ( $plugin_file ): void {
				self::declare_hpos_compatibility( $plugin_file );
			}
		);
	}

	public static function declare_hpos_compatibility( string $plugin_file ): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			$plugin_file,
			true
		);
		self::declare_blocks_compatibility( $plugin_file );
	}

	public static function declare_blocks_compatibility( string $plugin_file ): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			$plugin_file,
			true
		);
		self::$blocks_declared = true;
	}

	public static function hpos_declaration_attempted(): bool {
		return self::$hpos_hook_registered;
	}

	public static function blocks_declaration_attempted(): bool {
		return self::$hpos_hook_registered;
	}
}
