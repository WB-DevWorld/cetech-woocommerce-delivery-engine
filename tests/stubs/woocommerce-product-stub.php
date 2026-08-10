<?php

declare(strict_types=1);

/**
 * Minimal WooCommerce product stub for unit tests (not under PSR-4 Tests namespace).
 */

if ( ! class_exists( 'WC_Product', false ) ) {
	class WC_Product {
		/** @param array{id?:int,type?:string,name?:string,parent_id?:int,category_ids?:list<int>} $data */
		public function __construct( private array $data = [] ) {
		}

		public function get_id(): int {
			return (int) ( $this->data['id'] ?? 0 );
		}

		public function get_name(): string {
			return (string) ( $this->data['name'] ?? '' );
		}

		public function get_parent_id(): int {
			return (int) ( $this->data['parent_id'] ?? 0 );
		}

		/**
		 * @return list<int>
		 */
		public function get_category_ids(): array {
			$ids = $this->data['category_ids'] ?? [];

			return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : [];
		}

		public function is_type( string $type ): bool {
			return ( $this->data['type'] ?? '' ) === $type;
		}
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @return WC_Product|false
	 */
	function wc_get_product( $product_id ) {
		$map = $GLOBALS['cetech_de_test_wc_products'] ?? [];
		$id  = (int) $product_id;

		if ( ! array_key_exists( $id, $map ) ) {
			return false;
		}

		$product = $map[ $id ];

		return $product instanceof WC_Product ? $product : false;
	}
}
