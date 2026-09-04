<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;

/**
 * Matching-location fields (country/state/city/postcode) for Classic PDP and cart.
 *
 * Never collects a street address. Uses WooCommerce country/state data when available.
 */
final class MatchingLocationFieldRenderer {

	/**
	 * @return array<string, string>
	 */
	public static function default_names(): array {
		return [
			'country'  => CartDeliverySelectionCapture::POST_MATCHING_COUNTRY,
			'state'    => CartDeliverySelectionCapture::POST_MATCHING_STATE,
			'city'     => CartDeliverySelectionCapture::POST_MATCHING_CITY,
			'postcode' => CartDeliverySelectionCapture::POST_MATCHING_POSTCODE,
		];
	}

	public static function render( ?MatchingLocation $location, string $id_prefix = 'cetech-de-matching', bool $use_woocommerce_fields = true, bool $show_intro = false ): string {
		$names   = self::default_names();
		$country = $location instanceof MatchingLocation ? $location->country : '';
		$state   = $location instanceof MatchingLocation ? $location->state : '';
		$city    = $location instanceof MatchingLocation ? $location->city : '';
		$postcode = $location instanceof MatchingLocation ? $location->postcode : '';

		if ( '' === $country && function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_base_country' ) ) {
			$country = (string) WC()->countries->get_base_country();
		}

		$html = '<div class="cetech-de-matching-location" data-cetech-de-matching-location="1">';
		if ( $show_intro ) {
			$html .= '<p class="cetech-de-matching-location__intro">'
				. esc_html( \CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy::where_do_you_want_this_item() )
				. '</p>';
		}

		if ( $use_woocommerce_fields && function_exists( 'woocommerce_form_field' ) ) {
			ob_start();
			woocommerce_form_field(
				$names['country'],
				[
					'type'        => 'country',
					'label'       => __( 'Country', 'cetech-woocommerce-delivery-engine' ),
					'required'    => false,
					'class'       => [ 'form-row-wide', 'cetech-de-matching-location__field' ],
					'priority'    => 10,
					'autocomplete'=> 'country',
				],
				$country
			);
			woocommerce_form_field(
				$names['state'],
				[
					'type'          => 'state',
					'label'         => __( 'State / Region', 'cetech-woocommerce-delivery-engine' ),
					'required'      => false,
					'class'         => [ 'form-row-wide', 'cetech-de-matching-location__field' ],
					'country_field' => $names['country'],
					'country'       => $country,
					'priority'      => 20,
					'autocomplete'  => 'address-level1',
				],
				$state
			);
			woocommerce_form_field(
				$names['city'],
				[
					'type'         => 'text',
					'label'        => __( 'City', 'cetech-woocommerce-delivery-engine' ),
					'required'     => false,
					'class'        => [ 'form-row-wide', 'cetech-de-matching-location__field' ],
					'priority'     => 30,
					'autocomplete' => 'address-level2',
				],
				$city
			);
			woocommerce_form_field(
				$names['postcode'],
				[
					'type'         => 'text',
					'label'        => __( 'Postcode', 'cetech-woocommerce-delivery-engine' ),
					'required'     => false,
					'class'        => [ 'form-row-wide', 'cetech-de-matching-location__field' ],
					'priority'     => 40,
					'autocomplete' => 'postal-code',
				],
				$postcode
			);
			$html .= (string) ob_get_clean();
			$html .= '</div>';

			return $html;
		}

		$html .= self::select_or_input( $id_prefix . '-country', $names['country'], __( 'Country', 'cetech-woocommerce-delivery-engine' ), $country, self::countries() );
		$html .= self::select_or_input( $id_prefix . '-state', $names['state'], __( 'State / Region', 'cetech-woocommerce-delivery-engine' ), $state, self::states( $country ) );
		$html .= self::text_input( $id_prefix . '-city', $names['city'], __( 'City', 'cetech-woocommerce-delivery-engine' ), $city );
		$html .= self::text_input( $id_prefix . '-postcode', $names['postcode'], __( 'Postcode', 'cetech-woocommerce-delivery-engine' ), $postcode );
		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, string> $choices
	 */
	private static function select_or_input( string $id, string $name, string $label, string $value, array $choices ): string {
		if ( [] === $choices ) {
			return self::text_input( $id, $name, $label, $value );
		}

		$html  = '<p class="cetech-de-matching-location__field">';
		$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		$html .= '<option value="">' . esc_html__( 'Select…', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $choices as $code => $choice_label ) {
			$selected = strtoupper( (string) $code ) === strtoupper( $value ) ? ' selected="selected"' : '';
			$html    .= '<option value="' . esc_attr( (string) $code ) . '"' . $selected . '>' . esc_html( $choice_label ) . '</option>';
		}
		$html .= '</select></p>';

		return $html;
	}

	private static function text_input( string $id, string $name, string $label, string $value ): string {
		return '<p class="cetech-de-matching-location__field">'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />'
			. '</p>';
	}

	/**
	 * @return array<string, string>
	 */
	private static function countries(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) || ! is_object( WC()->countries ) ) {
			return [];
		}

		$countries = WC()->countries->get_countries();

		return is_array( $countries ) ? $countries : [];
	}

	/**
	 * @return array<string, string>
	 */
	private static function states( string $country ): array {
		if ( '' === $country || ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) || ! is_object( WC()->countries ) ) {
			return [];
		}

		if ( ! method_exists( WC()->countries, 'get_states' ) ) {
			return [];
		}

		$states = WC()->countries->get_states( $country );

		return is_array( $states ) ? $states : [];
	}
}
