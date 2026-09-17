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
			'location_key' => CartDeliverySelectionCapture::POST_MATCHING_LOCATION_KEY,
		];
	}

	public static function render( ?MatchingLocation $location, string $id_prefix = 'cetech-de-matching', bool $use_woocommerce_fields = true, bool $show_intro = false, bool $default_to_base_country = true ): string {
		$names   = self::default_names();
		$country = $location instanceof MatchingLocation ? $location->country : '';
		$state   = $location instanceof MatchingLocation ? $location->state : '';
		$city    = $location instanceof MatchingLocation ? $location->city : '';
		$postcode = $location instanceof MatchingLocation ? $location->postcode : '';
		$location_key = $location instanceof MatchingLocation ? $location->canonical_location_key : '';

		if (
			$default_to_base_country
			&& '' === $country
			&& function_exists( 'WC' )
			&& is_object( WC() )
			&& isset( WC()->countries )
			&& is_object( WC()->countries )
			&& method_exists( WC()->countries, 'get_base_country' )
		) {
			$country = (string) WC()->countries->get_base_country();
		}

		$has_country = '' !== $country;
		$has_region  = '' !== $state;
		$html        = '<fieldset class="cetech-de-matching-location" data-cetech-de-matching-location="1">';
		$html       .= '<legend class="screen-reader-text">' . esc_html__( 'Delivery location', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		if ( $show_intro ) {
			$html .= '<p class="cetech-de-matching-location__intro">'
				. esc_html( \CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy::where_do_you_want_this_item() )
				. '</p>';
		}
		$html .= '<input type="hidden" name="' . esc_attr( $names['location_key'] ) . '" value="' . esc_attr( $location_key ) . '" data-cetech-de-location-key="1" autocomplete="off" />';

		$region_hidden   = $has_country ? '' : ' hidden';
		$locality_hidden = $has_region ? '' : ' hidden';
		$postcode_hidden = ( '' !== $postcode ) ? '' : ' hidden';

		if ( $use_woocommerce_fields && function_exists( 'woocommerce_form_field' ) ) {
			ob_start();
			echo '<div class="cetech-de-matching-location__field" data-cetech-de-field="country">';
			woocommerce_form_field(
				$names['country'],
				[
					'type'        => 'country',
					'label'       => __( 'Country', 'cetech-woocommerce-delivery-engine' ),
					'required'    => false,
					'class'       => [ 'form-row-wide', 'cetech-de-matching-location__control' ],
					'priority'    => 10,
					'autocomplete'=> 'country',
				],
				$country
			);
			echo '</div>';
			echo '<div class="cetech-de-matching-location__field" data-cetech-de-field="region" data-cetech-de-reveal="region"' . $region_hidden . '>';
			woocommerce_form_field(
				$names['state'],
				[
					'type'          => 'state',
					'label'         => __( 'Region', 'cetech-woocommerce-delivery-engine' ),
					'required'      => false,
					'class'         => [ 'form-row-wide', 'cetech-de-matching-location__control' ],
					'country_field' => $names['country'],
					'country'       => $country,
					'priority'      => 20,
					'autocomplete'  => 'address-level1',
				],
				$state
			);
			echo '</div>';
			echo '<div class="cetech-de-matching-location__field" data-cetech-de-field="locality" data-cetech-de-reveal="locality"' . $locality_hidden . '>';
			woocommerce_form_field(
				$names['city'],
				[
					'type'         => 'text',
					'label'        => __( 'City / Town', 'cetech-woocommerce-delivery-engine' ),
					'required'     => false,
					'class'        => [ 'form-row-wide', 'cetech-de-matching-location__control' ],
					'priority'     => 30,
					'autocomplete' => 'address-level2',
					'custom_attributes' => [
						'data-cetech-de-locality-input' => '1',
						'role'                          => 'combobox',
						'aria-autocomplete'             => 'list',
						'aria-expanded'                 => 'false',
					],
				],
				$city
			);
			echo '</div>';
			echo '<div class="cetech-de-matching-location__field" data-cetech-de-field="postcode" data-cetech-de-reveal="postcode"' . $postcode_hidden . '>';
			woocommerce_form_field(
				$names['postcode'],
				[
					'type'         => 'text',
					'label'        => __( 'Postcode', 'cetech-woocommerce-delivery-engine' ),
					'required'     => false,
					'class'        => [ 'form-row-wide', 'cetech-de-matching-location__control' ],
					'priority'     => 40,
					'autocomplete' => 'postal-code',
				],
				$postcode
			);
			echo '</div>';
			$html .= (string) ob_get_clean();
			$html .= '</fieldset>';

			return $html;
		}

		$html .= self::select_or_input( $id_prefix . '-country', $names['country'], __( 'Country', 'cetech-woocommerce-delivery-engine' ), $country, self::countries(), 'country', false );
		$html .= self::select_or_input( $id_prefix . '-state', $names['state'], __( 'Region', 'cetech-woocommerce-delivery-engine' ), $state, self::states( $country ), 'region', ! $has_country );
		$html .= self::locality_input( $id_prefix . '-city', $names['city'], $city, ! $has_region );
		$html .= self::text_input( $id_prefix . '-postcode', $names['postcode'], __( 'Postcode', 'cetech-woocommerce-delivery-engine' ), $postcode, 'postcode', '' === $postcode );
		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * @param array<string, string> $choices
	 */
	private static function select_or_input( string $id, string $name, string $label, string $value, array $choices, string $field = '', bool $hidden = false ): string {
		if ( [] === $choices ) {
			return self::text_input( $id, $name, $label, $value, $field, $hidden );
		}

		$hidden_attr = $hidden ? ' hidden' : '';
		$field_attr  = '' !== $field ? ' data-cetech-de-field="' . esc_attr( $field ) . '"' : '';
		$reveal_attr = in_array( $field, [ 'region', 'locality', 'postcode' ], true ) ? ' data-cetech-de-reveal="' . esc_attr( $field ) . '"' : '';
		$html        = '<p class="cetech-de-matching-location__field"' . $field_attr . $reveal_attr . $hidden_attr . '>';
		$html       .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$html       .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		$html       .= '<option value="">' . esc_html__( 'Select…', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $choices as $code => $choice_label ) {
			$selected = strtoupper( (string) $code ) === strtoupper( $value ) ? ' selected="selected"' : '';
			$html    .= '<option value="' . esc_attr( (string) $code ) . '"' . $selected . '>' . esc_html( $choice_label ) . '</option>';
		}
		$html .= '</select></p>';

		return $html;
	}

	private static function locality_input( string $id, string $name, string $value, bool $hidden ): string {
		$hidden_attr = $hidden ? ' hidden' : '';
		$list_id     = $id . '-list';

		return '<p class="cetech-de-matching-location__field" data-cetech-de-field="locality" data-cetech-de-reveal="locality"' . $hidden_attr . '>'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'City / Town', 'cetech-woocommerce-delivery-engine' ) . '</label>'
			. '<input type="search" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-cetech-de-locality-input="1" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $list_id ) . '" autocomplete="off" />'
			. '<ul id="' . esc_attr( $list_id ) . '" class="cetech-de-locality-results" role="listbox" hidden></ul>'
			. '</p>';
	}

	private static function text_input( string $id, string $name, string $label, string $value, string $field = '', bool $hidden = false ): string {
		$hidden_attr = $hidden ? ' hidden' : '';
		$field_attr  = '' !== $field ? ' data-cetech-de-field="' . esc_attr( $field ) . '"' : '';
		$reveal_attr = in_array( $field, [ 'region', 'locality', 'postcode' ], true ) ? ' data-cetech-de-reveal="' . esc_attr( $field ) . '"' : '';

		return '<p class="cetech-de-matching-location__field"' . $field_attr . $reveal_attr . $hidden_attr . '>'
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
