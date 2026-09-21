<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

/**
 * Per-line Classic cart forms rendered after WooCommerce's cart/checkout form.
 *
 * Visible controls stay beside the cart line and use HTML form="id". Nested
 * <form> elements inside woocommerce-cart-form are invalid.
 */
final class CartExternalFormBuffer {

	/** @var array<string, string> */
	private static array $forms = [];

	private function __construct() {
	}

	public static function queue( string $form_id, string $html ): void {
		if ( '' === $form_id || '' === $html ) {
			return;
		}

		self::$forms[ $form_id ] = $html;
	}

	public static function drain(): string {
		if ( [] === self::$forms ) {
			return '';
		}

		$html = '<div class="cetech-de-cart-external-forms" data-cetech-de-cart-external-forms="1">';
		foreach ( self::$forms as $form_html ) {
			$html .= $form_html;
		}
		$html .= '</div>';
		self::$forms = [];

		return $html;
	}

	public static function reset(): void {
		self::$forms = [];
	}

	/**
	 * @return list<string>
	 */
	public static function queued_ids(): array {
		return array_keys( self::$forms );
	}
}
