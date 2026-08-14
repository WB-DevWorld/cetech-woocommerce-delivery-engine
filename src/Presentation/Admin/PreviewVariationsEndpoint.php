<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogIndexInterface;

/**
 * Admin AJAX: load WooCommerce variations for Delivery Settings Preview.
 */
final class PreviewVariationsEndpoint {

	public const ACTION = 'cetech_de_preview_variations';

	public function __construct(
		private readonly CatalogIndexInterface $catalog,
		private readonly ScopedConfigurationAuthorization $authorization
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Security check failed.', 'cetech-woocommerce-delivery-engine' ) ],
				403
			);
		}

		if ( ! $this->authorization->can_preview() ) {
			wp_send_json_error(
				[ 'message' => __( 'You are not allowed to preview delivery settings.', 'cetech-woocommerce-delivery-engine' ) ],
				403
			);
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		wp_send_json_success( $this->build_payload( $product_id ) );
	}

	/**
	 * @return array{product_id: int, variable: bool, variations: list<array{id: int, label: string}>}
	 */
	public function build_payload( int $product_id ): array {
		if ( $product_id <= 0 || ! $this->catalog->is_variable( $product_id ) ) {
			return [
				'product_id'  => $product_id,
				'variable'    => false,
				'variations'  => [],
			];
		}

		$variations = [];
		foreach ( $this->catalog->variation_ids( $product_id ) as $id ) {
			$variations[] = [
				'id'    => $id,
				'label' => $this->catalog->variation_label( $id ),
			];
		}

		return [
			'product_id' => $product_id,
			'variable'   => true,
			'variations' => $variations,
		];
	}
}
