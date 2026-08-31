<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

/**
 * Detects whether this site’s assigned Cart / Checkout pages use WooCommerce Blocks.
 */
final class BlocksUsageDetector {

	public function is_in_use(): bool {
		return $this->cart_block_in_use() || $this->checkout_block_in_use();
	}

	public function cart_block_in_use(): bool {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' )
			&& is_callable( [ '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils', 'is_cart_block_default' ] )
		) {
			return (bool) \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_cart_block_default();
		}

		return $this->page_has_block( 'woocommerce_cart_page_id', 'woocommerce/cart' );
	}

	public function checkout_block_in_use(): bool {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' )
			&& is_callable( [ '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils', 'is_checkout_block_default' ] )
		) {
			return (bool) \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
		}

		return $this->page_has_block( 'woocommerce_checkout_page_id', 'woocommerce/checkout' );
	}

	private function page_has_block( string $option, string $block_name ): bool {
		$page_id = (int) get_option( $option, 0 );

		if ( $page_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'has_block' ) && has_block( $block_name, $page_id ) ) {
			return true;
		}

		$post = function_exists( 'get_post' ) ? get_post( $page_id ) : null;

		if ( ! is_object( $post ) || ! isset( $post->post_content ) ) {
			return false;
		}

		$content = (string) $post->post_content;

		return str_contains( $content, '<!-- wp:' . $block_name )
			|| str_contains( $content, '"blockName":"' . $block_name . '"' );
	}
}
