<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\FieldEditViewModel;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationEditViewModel;
use CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Focused product/variation customize UI. Maps to existing inherit/override/replace storage.
 */
final class StaffDeliveryCustomizeView {

	public function __construct(
		private readonly DeliveryOfferRepositoryInterface $offers
	) {
	}

	public function render( ScopedConfigurationEditViewModel $model ): void {
		$is_variation = 'variation' === $model->scope_type;
		$name         = $is_variation
			? ( $model->variation_label ?: $model->product_label ?: __( 'this variation', 'cetech-woocommerce-delivery-engine' ) )
			: ( $model->product_label ?: __( 'this product', 'cetech-woocommerce-delivery-engine' ) );
		$profile      = $this->effective_profile( $model );
		$profile_label = $profile?->label ?? __( 'Site-wide Default', 'cetech-woocommerce-delivery-engine' );

		echo '<div class="cetech-de-form-panel cetech-de-customize-panel">';
		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s product or variation name */
				__( 'Customize delivery for %s', 'cetech-woocommerce-delivery-engine' ),
				$name
			)
		) . '</h2>';
		if ( $is_variation ) {
			echo '<p>' . esc_html__( 'A variation normally follows Product Settings. Only change the parts that should be different.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Product currently uses:', 'cetech-woocommerce-delivery-engine' ) . ' ';
			echo esc_html( $this->product_context_line( $model, $profile_label ) ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s fulfilment type */
					__( 'This product normally follows %s Site-wide Defaults. Only change the parts that should be different.', 'cetech-woocommerce-delivery-engine' ),
					$profile_label
				)
			) . '</p>';
		}
		echo '</div>';

		echo '<form method="post" class="cetech-de-customize-form" data-cetech-de-customize="1">';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( ScopedConfigurationPage::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="scope_type" value="' . esc_attr( $model->scope_type ) . '" />';
		echo '<input type="hidden" name="scope_id" value="' . esc_attr( (string) $model->scope_id ) . '" />';
		echo '<input type="hidden" name="slice_key" value="' . esc_attr( $model->slice_key ) . '" />';
		echo '<input type="hidden" name="customize" value="1" />';
		if ( null !== $model->parent_product_id ) {
			echo '<input type="hidden" name="parent_product_id" value="' . esc_attr( (string) $model->parent_product_id ) . '" />';
		}
		AdminFormHelper::nonce_field( ScopedConfigurationPage::ACTION_SAVE );

		$this->render_scalar_control(
			$model,
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			__( 'Fulfilment', 'cetech-woocommerce-delivery-engine' ),
			$is_variation,
			$profile_label,
			__( 'Set a different fulfilment', 'cetech-woocommerce-delivery-engine' )
		);
		$this->render_scalar_control(
			$model,
			ConfigurationFieldKey::FULFILMENT_CHOICE,
			__( 'Default customer choice', 'cetech-woocommerce-delivery-engine' ),
			$is_variation,
			$profile_label,
			__( 'Set a different default customer choice', 'cetech-woocommerce-delivery-engine' )
		);
		if ( $profile?->pickup_allowed ) {
			$this->render_pickup_location_control( $model, $is_variation, $profile_label );
		}
		$this->render_scalar_control(
			$model,
			ConfigurationFieldKey::ESTIMATED_DELIVERY,
			__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ),
			$is_variation,
			$profile_label,
			__( 'Use a different estimate', 'cetech-woocommerce-delivery-engine' ),
			true
		);
		$this->render_options_control( $model, $is_variation, $profile_label, $profile );

		echo '<p class="cetech-de-button-group cetech-de-customize-actions">';
		echo '<button type="submit" class="button button-primary">' . esc_html(
			$is_variation
				? __( 'Save Variation Delivery Settings', 'cetech-woocommerce-delivery-engine' )
				: __( 'Save Product Delivery Settings', 'cetech-woocommerce-delivery-engine' )
		) . '</button></p></form>';

		$confirm = $is_variation
			? __( 'Reset this variation to Product Settings? This does not change other variations.', 'cetech-woocommerce-delivery-engine' )
			: __( 'Reset this product to Site-wide Defaults? This does not change other products.', 'cetech-woocommerce-delivery-engine' );
		echo '<form method="post" class="cetech-de-reset-form" onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');">';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( ScopedConfigurationPage::ACTION_RESET ) . '" />';
		echo '<input type="hidden" name="scope_type" value="' . esc_attr( $model->scope_type ) . '" />';
		echo '<input type="hidden" name="scope_id" value="' . esc_attr( (string) $model->scope_id ) . '" />';
		echo '<input type="hidden" name="slice_key" value="' . esc_attr( $model->slice_key ) . '" />';
		echo '<input type="hidden" name="customize" value="1" />';
		if ( null !== $model->parent_product_id ) {
			echo '<input type="hidden" name="parent_product_id" value="' . esc_attr( (string) $model->parent_product_id ) . '" />';
		}
		AdminFormHelper::nonce_field( ScopedConfigurationPage::ACTION_RESET );
		echo '<p><button type="submit" class="button">' . esc_html(
			$is_variation
				? __( 'Reset to Product Settings', 'cetech-woocommerce-delivery-engine' )
				: __( 'Reset to Site-wide Defaults', 'cetech-woocommerce-delivery-engine' )
		) . '</button></p></form>';
	}

	private function render_scalar_control(
		ScopedConfigurationEditViewModel $model,
		string $field_key,
		string $legend,
		bool $is_variation,
		string $profile_label,
		string $override_label,
		bool $free_text = false
	): void {
		$field = $this->field( $model, $field_key );
		if ( null === $field ) {
			return;
		}

		$inherited = $this->display_scalar( $field, $field->inherited_value ?? $field->effective_value );
		$current   = 'override' === $field->current_mode ? 'override' : 'inherit';
		$inherit_label = $is_variation
			? sprintf(
				/* translators: %s inherited value */
				__( 'Use Product Setting: %s', 'cetech-woocommerce-delivery-engine' ),
				'' !== $inherited ? $inherited : '—'
			)
			: sprintf(
				/* translators: 1: fulfilment type, 2: inherited value */
				__( 'Use Site-wide Default: %2$s', 'cetech-woocommerce-delivery-engine' ),
				$profile_label,
				'' !== $inherited ? $inherited : $profile_label
			);

		echo '<fieldset class="cetech-de-customize-field" data-field="' . esc_attr( $field_key ) . '">';
		echo '<legend>' . esc_html( $legend ) . '</legend>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( $field_key ) . '][mode]" value="inherit"' . checked( $current, 'inherit', false ) . ' /> ';
		echo esc_html( $inherit_label ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( $field_key ) . '][mode]" value="override"' . checked( $current, 'override', false ) . ' /> ';
		echo esc_html( $override_label ) . '</label></p>';
		echo '<div class="cetech-de-customize-override">';
		if ( $free_text ) {
			$value = is_scalar( $field->configured_value ) && 'override' === $field->current_mode
				? (string) $field->configured_value
				: ( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' );
			echo '<p><input type="text" class="regular-text" name="fields[' . esc_attr( $field_key ) . '][value]" value="' . esc_attr( $value ) . '" placeholder="10–14 business days" /></p>';
		} else {
			$selected = is_scalar( $field->configured_value ) && 'override' === $field->current_mode
				? (string) $field->configured_value
				: ( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' );
			echo '<p><select name="fields[' . esc_attr( $field_key ) . '][value]"' . ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ? ' data-cetech-de-fulfilment-select' : '' ) . '>';
			foreach ( $field->enum_options ?? [] as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '</div></fieldset>';
	}

	private function render_pickup_location_control(
		ScopedConfigurationEditViewModel $model,
		bool $is_variation,
		string $profile_label
	): void {
		$field = $this->field( $model, ConfigurationFieldKey::PICKUP_LOCATION_ID );
		if ( null === $field ) {
			return;
		}

		$inherited = $this->display_scalar( $field, $field->inherited_value ?? $field->effective_value );
		$current   = $field->current_mode;
		if ( ! in_array( $current, [ 'inherit', 'override', 'disable' ], true ) ) {
			$current = 'inherit';
		}
		$inherit_label = $is_variation
			? sprintf(
				/* translators: %s inherited location */
				__( 'Use Product Setting: %s', 'cetech-woocommerce-delivery-engine' ),
				'' !== $inherited ? $inherited : '—'
			)
			: sprintf(
				/* translators: %s inherited location */
				__( 'Use Site-wide Default: %s', 'cetech-woocommerce-delivery-engine' ),
				'' !== $inherited ? $inherited : $profile_label
			);

		echo '<fieldset class="cetech-de-customize-field" data-field="' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '">';
		echo '<legend>' . esc_html__( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="inherit"' . checked( $current, 'inherit', false ) . ' /> ';
		echo esc_html( $inherit_label ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="override"' . checked( $current, 'override', false ) . ' /> ';
		echo esc_html__( 'Use a different Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="disable"' . checked( $current, 'disable', false ) . ' /> ';
		echo esc_html__( 'Turn off Store Pickup for this product', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<div class="cetech-de-customize-override">';
		$selected = is_scalar( $field->configured_value ) && 'override' === $field->current_mode
			? (string) $field->configured_value
			: ( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' );
		echo '<p><select name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][value]">';
		echo '<option value="">' . esc_html__( 'Select a Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $field->selector_options ?? [] as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Store Pickup stays a fulfilment alternative. It is not a Delivery Option.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</div></fieldset>';
	}

	private function render_options_control(
		ScopedConfigurationEditViewModel $model,
		bool $is_variation,
		string $profile_label,
		?FulfilmentProfile $profile
	): void {
		$field = $this->field( $model, ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		if ( null === $field ) {
			return;
		}

		$inherited = $this->member_labels( $field, $field->inherited_members !== [] ? $field->inherited_members : $field->effective_members );
		$current   = in_array( $field->current_mode, [ 'add', 'remove', 'replace' ], true ) ? $field->current_mode : 'inherit';
		$inherit_label = $is_variation
			? sprintf(
				/* translators: %s inherited options */
				__( 'Use Product Setting: %s', 'cetech-woocommerce-delivery-engine' ),
				'' !== $inherited ? $inherited : '—'
			)
			: sprintf(
				/* translators: %s inherited options */
				__( 'Use Site-wide Default: %s', 'cetech-woocommerce-delivery-engine' ),
				'' !== $inherited ? $inherited : ( $profile_label )
			);

		echo '<fieldset class="cetech-de-customize-field" data-field="' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '">';
		echo '<legend>' . esc_html__( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="inherit"' . checked( $current, 'inherit', false ) . ' /> ';
		echo esc_html( $inherit_label ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="replace"' . checked( $current, 'replace', false ) . ' /> ';
		echo esc_html__( 'Use only these options', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="add"' . checked( $current, 'add', false ) . ' /> ';
		echo esc_html__( 'Add options', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="remove"' . checked( $current, 'remove', false ) . ' /> ';
		echo esc_html__( 'Remove options', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';

		$all_offers = $this->offers->list( [ 'limit' => 200 ] );
		$selected   = 'inherit' === $current ? $field->effective_members : $field->configured_members;
		echo '<div class="cetech-de-customize-override cetech-de-compatible-options">';
		foreach ( $all_offers as $offer ) {
			$id    = (int) ( $offer['id'] ?? 0 );
			$route = (string) ( $offer['route'] ?? '' );
			$label = (string) ( $offer['public_label'] ?? $offer['internal_name'] ?? '' );
			$compatible_now = null === $profile || DeliveryOptionCompatibility::offer_is_compatible( $offer, $profile );
			$hidden = $compatible_now ? '' : ' hidden';
			$profiles = [];
			foreach ( FulfilmentProfileRegistry::all() as $candidate ) {
				if ( DeliveryOptionCompatibility::offer_is_compatible( $offer, $candidate ) ) {
					$profiles[] = $candidate->key;
				}
			}
			echo '<p class="cetech-de-compatible-option"' . $hidden . ' data-profiles="' . esc_attr( implode( ',', $profiles ) ) . '" data-route="' . esc_attr( $route ) . '">';
			echo '<label><input type="checkbox" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][members][]" value="' . esc_attr( (string) $id ) . '"' . checked( in_array( $id, $selected, true ), true, false ) . ' /> ';
			echo esc_html( $label );
			$route_label = $this->route_label( $route );
			if ( '' !== $route_label && $route_label !== $label ) {
				echo ' — ' . esc_html( $route_label );
			}
			echo '</label></p>';
		}
		echo '</div></fieldset>';
	}

	private function field( ScopedConfigurationEditViewModel $model, string $field_key ): ?FieldEditViewModel {
		foreach ( $model->fields as $field ) {
			if ( $field->field_key === $field_key ) {
				return $field;
			}
		}

		return null;
	}

	private function effective_profile( ScopedConfigurationEditViewModel $model ): ?FulfilmentProfile {
		$field = $this->field( $model, ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		$value = is_scalar( $field?->effective_value ) ? (string) $field->effective_value : '';

		return FulfilmentProfileRegistry::get( $value );
	}

	private function display_scalar( FieldEditViewModel $field, mixed $value ): string {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return '';
		}
		$key = (string) $value;
		if ( is_array( $field->enum_options ) && isset( $field->enum_options[ $key ] ) ) {
			return (string) $field->enum_options[ $key ];
		}
		if ( is_array( $field->selector_options ) ) {
			$id = (int) $key;
			if ( isset( $field->selector_options[ $id ] ) ) {
				return (string) $field->selector_options[ $id ];
			}
			if ( isset( $field->selector_options[ $key ] ) ) {
				return (string) $field->selector_options[ $key ];
			}
		}

		return $key;
	}

	/**
	 * @param list<int|string> $members
	 */
	private function member_labels( FieldEditViewModel $field, array $members ): string {
		$labels = [];
		foreach ( $members as $id ) {
			$id = (int) $id;
			if ( is_array( $field->selector_options ) && isset( $field->selector_options[ $id ] ) ) {
				$labels[] = (string) $field->selector_options[ $id ];
			}
		}

		return implode( ', ', $labels );
	}

	private function product_context_line( ScopedConfigurationEditViewModel $model, string $profile_label ): string {
		$choice = $this->field( $model, ConfigurationFieldKey::FULFILMENT_CHOICE );
		$method = '';
		if ( null !== $choice ) {
			$method = $this->display_scalar( $choice, $choice->inherited_value ?? $choice->effective_value );
		}

		$method_label = '' !== $method ? $method : __( 'Delivery', 'cetech-woocommerce-delivery-engine' );

		return sprintf(
			/* translators: 1: fulfilment type, 2: delivery method */
			__( '%1$s Site-wide Default → Product Settings → %2$s', 'cetech-woocommerce-delivery-engine' ),
			$profile_label,
			$method_label
		);
	}

	private function route_label( string $route ): string {
		return match ( $route ) {
			DeliveryRoute::LocalDelivery->value => __( 'Local delivery', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::StorePickup->value => __( 'Store Pickup', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Air->value => __( 'Air Shipping', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Sea->value => __( 'Sea Shipping', 'cetech-woocommerce-delivery-engine' ),
			default => '',
		};
	}
}
