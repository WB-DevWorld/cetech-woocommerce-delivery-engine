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

	/** @var array<string, mixed> */
	private array $envelope = [];

	/**
	 * @param array<string, mixed> $envelope
	 */
	public function render( ScopedConfigurationEditViewModel $model, array $envelope = [] ): void {
		$this->envelope = $envelope;
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
		} else {
			echo '<p>' . esc_html__( 'Products follow Site-wide Defaults unless customised below. Change only the parts that should be different.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		if ( null === $profile ) {
			echo '<p class="description">' . esc_html__( 'Inherited delivery and pickup details are confirmed after saving.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '</div>';

		echo '<form method="post" class="cetech-de-customize-form" data-cetech-de-customize="1" data-unsaved-label="' . esc_attr__( 'Unsaved changes', 'cetech-woocommerce-delivery-engine' ) . '">';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( ScopedConfigurationPage::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="scope_type" value="' . esc_attr( $model->scope_type ) . '" />';
		echo '<input type="hidden" name="scope_id" value="' . esc_attr( (string) $model->scope_id ) . '" />';
		echo '<input type="hidden" name="slice_key" value="' . esc_attr( $model->slice_key ) . '" />';
		echo '<input type="hidden" name="customize" value="1" />';
		$this->guard_inputs( $model, 'save' );
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
		echo '<span class="cetech-de-unsaved-status" role="status" hidden></span> ';
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
		$this->guard_inputs( $model, 'reset' );
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

		$inherited = 'inherit' === $field->current_mode ? $this->display_scalar( $field, $field->inherited_value ?? $field->effective_value ) : '';
		$draft     = $this->draft_field( $field_key );
		$current   = is_array( $draft ) && isset( $draft['mode'] ) ? (string) $draft['mode'] : $field->current_mode;
		$current   = 'override' === $current ? 'override' : 'inherit';
		$inherit_label = $this->inherit_label( $is_variation, $inherited );

		$input_id = 'cetech_de_customize_' . $field_key;
		echo '<fieldset class="cetech-de-customize-field" data-field="' . esc_attr( $field_key ) . '"' . ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key && 'inherit' === $field->current_mode ? ' data-inherited-fulfilment="' . esc_attr( (string) ( $field->inherited_value ?? $field->effective_value ) ) . '"' : '' ) . '>';
		echo '<legend>' . esc_html( $legend ) . '</legend>';
		$this->render_saved_state( $field, $input_id . '_state' );
		echo '<p><label><input type="radio" name="fields[' . esc_attr( $field_key ) . '][mode]" value="inherit"' . checked( $current, 'inherit', false ) . ' /> ';
		echo esc_html( $inherit_label ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( $field_key ) . '][mode]" value="override"' . checked( $current, 'override', false ) . ' /> ';
		echo esc_html( $override_label ) . '</label></p>';
		echo '<div class="cetech-de-customize-override" data-show-for="override">';
		echo '<p><label for="' . esc_attr( $input_id ) . '">' . esc_html( $legend ) . '</label></p>';
		if ( $free_text ) {
			$value = is_array( $draft ) && isset( $draft['value'] )
				? (string) $draft['value']
				: ( is_scalar( $field->configured_value ) && 'override' === $field->current_mode
					? (string) $field->configured_value
					: ( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' ) );
			echo '<p><input type="text" class="regular-text" id="' . esc_attr( $input_id ) . '" name="fields[' . esc_attr( $field_key ) . '][value]" value="' . esc_attr( $value ) . '" aria-describedby="' . esc_attr( $input_id . '_state ' . $input_id . '_help' ) . '" placeholder="10–14 business days" /></p>';
			echo '<p class="description" id="' . esc_attr( $input_id . '_help' ) . '">' . esc_html__( 'Include the duration and unit, for example 10–14 business days. This is an estimate.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		} else {
			$selected = is_array( $draft ) && isset( $draft['value'] )
				? (string) $draft['value']
				: ( is_scalar( $field->configured_value ) && 'override' === $field->current_mode
					? (string) $field->configured_value
					: ( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' ) );
			echo '<p><select id="' . esc_attr( $input_id ) . '" name="fields[' . esc_attr( $field_key ) . '][value]" aria-describedby="' . esc_attr( $input_id . '_state' ) . '"' . ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $field_key ? ' data-cetech-de-fulfilment-select' : '' ) . '>';
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

		$inherited = 'inherit' === $field->current_mode ? $this->display_scalar( $field, $field->inherited_value ?? $field->effective_value ) : '';
		$draft     = $this->draft_field( ConfigurationFieldKey::PICKUP_LOCATION_ID );
		$current   = is_array( $draft ) && isset( $draft['mode'] ) ? (string) $draft['mode'] : $field->current_mode;
		if ( ! in_array( $current, [ 'inherit', 'override', 'disable' ], true ) ) {
			$current = 'inherit';
		}
		$inherit_label = $this->inherit_label( $is_variation, $inherited );

		$input_id = 'cetech_de_customize_' . ConfigurationFieldKey::PICKUP_LOCATION_ID;
		echo '<fieldset class="cetech-de-customize-field" data-field="' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '">';
		echo '<legend>' . esc_html__( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		$this->render_saved_state( $field, $input_id . '_state' );
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="inherit"' . checked( $current, 'inherit', false ) . ' /> ';
		echo esc_html( $inherit_label ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="override"' . checked( $current, 'override', false ) . ' /> ';
		echo esc_html__( 'Use a different Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="disable"' . checked( $current, 'disable', false ) . ' /> ';
		echo esc_html( $is_variation ? __( 'Turn off Store Pickup for this variation', 'cetech-woocommerce-delivery-engine' ) : __( 'Turn off Store Pickup for this product', 'cetech-woocommerce-delivery-engine' ) ) . '</label></p>';
		echo '<div class="cetech-de-customize-override" data-show-for="override">';
		echo '<p><label for="' . esc_attr( $input_id ) . '">' . esc_html__( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		$selected = is_array( $draft ) && array_key_exists( 'value', $draft )
			? (string) $draft['value']
			: ( is_scalar( $field->configured_value ) && 'override' === $field->current_mode
				? (string) $field->configured_value
				: ( is_scalar( $field->effective_value ) ? (string) $field->effective_value : '' ) );
		echo '<p><select id="' . esc_attr( $input_id ) . '" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][value]" aria-describedby="' . esc_attr( $input_id . '_state ' . $input_id . '_help' ) . '">';
		echo '<option value="">' . esc_html__( 'Select a Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $field->selector_options ?? [] as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description" id="' . esc_attr( $input_id . '_help' ) . '">' . esc_html__( 'Store Pickup stays a fulfilment alternative. It is not a Delivery Option.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
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

		$inherited = 'inherit' === $field->current_mode ? $this->member_labels( $field, $field->effective_members ) : '';
		$draft     = $this->draft_field( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		$current   = is_array( $draft ) && isset( $draft['mode'] ) ? (string) $draft['mode'] : $field->current_mode;
		$current   = in_array( $current, [ 'add', 'remove', 'replace' ], true ) ? $current : 'inherit';
		$inherit_label = $this->inherit_label( $is_variation, $inherited );

		$input_id = 'cetech_de_customize_' . ConfigurationFieldKey::DELIVERY_OFFER_IDS;
		echo '<fieldset class="cetech-de-customize-field" data-field="' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '">';
		echo '<legend>' . esc_html__( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		$this->render_saved_state( $field, $input_id . '_state' );
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="inherit"' . checked( $current, 'inherit', false ) . ' /> ';
		echo esc_html( $inherit_label ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="replace"' . checked( $current, 'replace', false ) . ' /> ';
		echo esc_html__( 'Use only these options', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="add"' . checked( $current, 'add', false ) . ' /> ';
		echo esc_html__( 'Add options', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="remove"' . checked( $current, 'remove', false ) . ' /> ';
		echo esc_html__( 'Remove options', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';

		$all_offers = $this->offers->list( [ 'limit' => 200 ] );
		$selected   = is_array( $draft ) && isset( $draft['members'] ) && is_array( $draft['members'] )
			? array_map( static fn ( $member ): int => (int) $member, $draft['members'] )
			: ( 'inherit' === $current ? $field->effective_members : $field->configured_members );
		// Keep the bounded page. Retain submitted selections without extra per-ID reads.
		$loaded_ids = array_map( static fn ( array $offer ): int => (int) ( $offer['id'] ?? 0 ), $all_offers );
		echo '<div class="cetech-de-customize-override cetech-de-compatible-options cetech-de-option-search" data-show-for="add,remove,replace">';
		echo '<p class="description" id="' . esc_attr( $input_id . '_help' ) . '">' . esc_html__( 'Add keeps inherited options; Remove excludes the selected options; Use only these options replaces the inherited list. An empty replacement means no delivery options.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( null === $profile ) {
			echo '<p class="description">' . esc_html__( 'Inherited fulfilment is confirmed after saving. The loaded options remain visible for review; the server validates your choices.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<div class="cetech-de-option-search-controls" hidden><label for="' . esc_attr( $input_id . '_search' ) . '">' . esc_html__( 'Find a loaded delivery option', 'cetech-woocommerce-delivery-engine' ) . '</label> ';
		echo '<input type="search" id="' . esc_attr( $input_id . '_search' ) . '" data-cetech-de-option-search autocomplete="off" aria-describedby="' . esc_attr( $input_id . '_search_help' ) . '" />';
		echo '<p class="description" id="' . esc_attr( $input_id . '_search_help' ) . '">' . esc_html__( 'Filters the loaded list only. Your selections stay unchanged.', 'cetech-woocommerce-delivery-engine' ) . '</p></div>';
		echo '<p class="description" data-cetech-de-search-empty role="status" hidden>' . esc_html__( 'No matching loaded options. Clear the search to see the list.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
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
			echo '<div class="cetech-de-option-search-row"><p class="cetech-de-compatible-option"' . $hidden . ' data-profiles="' . esc_attr( implode( ',', $profiles ) ) . '" data-route="' . esc_attr( $route ) . '">';
			echo '<label><input type="checkbox" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][members][]" value="' . esc_attr( (string) $id ) . '" aria-describedby="' . esc_attr( $input_id . '_help' ) . '"' . checked( in_array( $id, $selected, true ), true, false ) . ' /> ';
			echo esc_html( $label );
			$route_label = $this->route_label( $route );
			if ( '' !== $route_label && $route_label !== $label ) {
				echo ' — ' . esc_html( $route_label );
			}
			echo '</label></p></div>';
		}
		$unloaded_ids = array_diff( $selected, $loaded_ids );
		if ( [] !== $unloaded_ids ) {
			echo '<p class="description">' . esc_html__( 'Review selections outside the loaded list. Their names and compatibility cannot be confirmed here; saving validates them.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		foreach ( $unloaded_ids as $id ) {
			echo '<div class="cetech-de-option-search-row"><p><label><input type="checkbox" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][members][]" value="' . esc_attr( (string) $id ) . '" checked="checked" aria-describedby="' . esc_attr( $input_id . '_help' ) . '" /> ';
			echo esc_html(
				sprintf(
					/* translators: %d selected delivery option ID */
					__( 'Selected option #%d — outside the loaded list', 'cetech-woocommerce-delivery-engine' ),
					$id
				)
			) . '</label></p></div>';
		}
		echo '</div></fieldset>';
	}

	private function guard_inputs( ScopedConfigurationEditViewModel $model, string $action ): void {
		$revision = array_key_exists( 'expected_revision', $this->envelope ) ? (string) $this->envelope['expected_revision'] : (string) $model->config_version;
		$row_id   = array_key_exists( 'expected_scope_row_id', $this->envelope ) ? (string) $this->envelope['expected_scope_row_id'] : (string) (int) ( $model->technical_details['scope_row_id'] ?? 0 );
		$token    = 'reset' === $action ? (string) ( $this->envelope['reset_token'] ?? '' ) : (string) ( $this->envelope['save_token'] ?? '' );
		echo '<input type="hidden" name="expected_revision" value="' . esc_attr( $revision ) . '" />';
		echo '<input type="hidden" name="expected_scope_row_id" value="' . esc_attr( $row_id ) . '" />';
		echo '<input type="hidden" name="request_token" value="' . esc_attr( $token ) . '" />';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function draft_field( string $field_key ): ?array {
		$fields = $this->envelope['fields'] ?? null;
		if ( ! is_array( $fields ) || ! isset( $fields[ $field_key ] ) || ! is_array( $fields[ $field_key ] ) ) {
			return null;
		}

		return $fields[ $field_key ];
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
		$draft = $this->draft_field( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		$mode  = is_array( $draft ) && isset( $draft['mode'] ) ? (string) $draft['mode'] : $field?->current_mode;
		$value = 'override' === $mode
			? ( is_array( $draft ) && isset( $draft['value'] ) ? $draft['value'] : $field?->effective_value )
			: ( $field?->inherited_value ?? ( 'inherit' === $field?->current_mode ? $field->effective_value : null ) );
		if ( 'override' !== $mode && 'inherit' !== $field?->current_mode ) {
			$value = null;
		}

		return FulfilmentProfileRegistry::get( is_scalar( $value ) ? (string) $value : '' );
	}

	private function render_saved_state( FieldEditViewModel $field, string $id ): void {
		$value = $field->is_collection
			? $this->member_labels( $field, $field->effective_members )
			: $this->display_scalar( $field, $field->effective_value );
		if ( 'disabled' === $field->effective_state ) {
			$value = __( 'Turned off', 'cetech-woocommerce-delivery-engine' );
		} elseif ( 'valid' !== $field->effective_state && '' === $value ) {
			$value = __( 'Needs configuration', 'cetech-woocommerce-delivery-engine' );
		} elseif ( $field->is_collection && [] !== $field->effective_members && count( array_intersect( array_map( 'intval', array_keys( $field->selector_options ?? [] ) ), array_map( 'intval', $field->effective_members ) ) ) < count( array_unique( array_map( 'intval', $field->effective_members ) ) ) ) {
			$value = sprintf(
				/* translators: %d number of resolved delivery options */
				__( '%d delivery options (some labels are outside the loaded list)', 'cetech-woocommerce-delivery-engine' ),
				count( $field->effective_members )
			);
		} elseif ( '' === $value ) {
			$value = $field->is_collection && [] === $field->effective_members
				? __( 'No delivery options', 'cetech-woocommerce-delivery-engine' )
				: __( 'Needs configuration', 'cetech-woocommerce-delivery-engine' );
		}
		echo '<p class="description cetech-de-customize-state" id="' . esc_attr( $id ) . '"><strong>' . esc_html__( 'Saved setting:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ' . esc_html( $value );
		if ( '' !== $field->effective_state_label ) {
			echo ' · ' . esc_html( $field->effective_state_label );
		}
		if ( '' !== $field->provenance_label ) {
			echo ' · ' . esc_html( $field->provenance_label );
		}
		echo '</p>';
		if ( 'inherit' !== $field->current_mode ) {
			echo '<p class="description">' . esc_html__( 'Inherited values are confirmed after saving.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
	}

	private function inherit_label( bool $is_variation, string $inherited ): string {
		$label = $is_variation
			? __( 'Use Product Setting', 'cetech-woocommerce-delivery-engine' )
			: __( 'Use Site-wide Default', 'cetech-woocommerce-delivery-engine' );

		return '' === $inherited ? $label : $label . ': ' . $inherited;
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
