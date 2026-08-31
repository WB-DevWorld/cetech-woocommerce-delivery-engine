<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\StaffChargeSummary;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\ContextualEntityService;
use CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility;
use CetechDeliveryEngine\Application\Configuration\InStoreMethodSelection;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Shipping\WooCommerceShippingReadiness;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * WordPress-native first-time setup wizard. Coordinates existing domain services.
 */
final class SetupWizardPage {

	public const SLUG = 'cetech-delivery-engine-setup';

	public const ACTION_SAVE_STEP     = 'cetech_de_wizard_save_step';
	public const ACTION_SAVE_LATER    = 'cetech_de_wizard_save_later';
	public const ACTION_APPLY         = 'cetech_de_wizard_apply';
	public const ACTION_ACTIVATE      = 'cetech_de_wizard_activate';
	public const ACTION_CREATE_OPTION = 'cetech_de_wizard_create_option';
	public const ACTION_CREATE_CHARGE = 'cetech_de_wizard_create_charge';
	public const ACTION_CREATE_AREA   = 'cetech_de_wizard_create_area';
	public const ACTION_CREATE_PICKUP = 'cetech_de_wizard_create_pickup';

	public function __construct(
		private readonly SetupWizardProgress $progress,
		private readonly SiteWideDefaultsService $defaults,
		private readonly SiteWideDefaultsSettings $settings,
		private readonly SiteWideDefaultSummary $summaries,
		private readonly ContextualEntityService $entities,
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly DeliveryOfferRepositoryInterface $offers,
		private readonly DestinationZoneRepositoryInterface $zones,
		private readonly RateCardRepositoryInterface $rates,
		private readonly PickupLocationRepositoryInterface $pickups,
		private readonly NeedsAttentionQuery $needs_attention,
		private readonly AdminActionHandler $action_handler,
		private readonly ClassicCheckoutRuntimeActivation $runtime,
		private readonly WooCommerceShippingReadiness $shipping_readiness,
		private readonly OperationalStateService $operational_state
	) {
	}

	/**
	 * @return array{step: int, profile_index?: int}
	 */
	public static function continue_redirect_args( int $step, int $profile_index = 0, int $active_count = 1 ): array {
		return match ( $step ) {
			1 => [ 'step' => 2 ],
			2 => [ 'step' => 3, 'profile_index' => 0 ],
			3 => ( $profile_index + 1 ) < max( 1, $active_count )
				? [ 'step' => 3, 'profile_index' => $profile_index + 1 ]
				: [ 'step' => 4, 'profile_index' => 0 ],
			4 => [ 'step' => 5 ],
			5 => [ 'step' => 6 ],
			default => [ 'step' => 6 ],
		};
	}

	/**
	 * @return array{step: int, profile_index?: int}
	 */
	public static function back_redirect_args( int $step, int $profile_index = 0, int $active_count = 1 ): array {
		return match ( $step ) {
			2 => [ 'step' => 1 ],
			3 => $profile_index > 0
				? [ 'step' => 3, 'profile_index' => $profile_index - 1 ]
				: [ 'step' => 2 ],
			4 => [ 'step' => 3, 'profile_index' => max( 0, $active_count - 1 ) ],
			5 => [ 'step' => 4 ],
			6 => [ 'step' => 5 ],
			default => [ 'step' => 1 ],
		};
	}

	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['cetech_de_save_later'] ) ) {
			$this->verified( self::ACTION_SAVE_LATER ) && $this->handle_save_later();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );

		match ( $action ) {
			self::ACTION_SAVE_STEP => $this->verified( self::ACTION_SAVE_STEP ) && $this->handle_save_step(),
			self::ACTION_SAVE_LATER => $this->verified( self::ACTION_SAVE_LATER ) && $this->handle_save_later(),
			self::ACTION_APPLY => $this->verified( self::ACTION_APPLY ) && $this->handle_apply(),
			self::ACTION_ACTIVATE => $this->verified( self::ACTION_ACTIVATE ) && $this->handle_activate(),
			self::ACTION_CREATE_OPTION => $this->verified( self::ACTION_CREATE_OPTION ) && $this->handle_create_option(),
			self::ACTION_CREATE_CHARGE => $this->verified( self::ACTION_CREATE_CHARGE ) && $this->handle_create_charge(),
			self::ACTION_CREATE_AREA => $this->verified( self::ACTION_CREATE_AREA ) && $this->handle_create_area(),
			self::ACTION_CREATE_PICKUP => $this->verified( self::ACTION_CREATE_PICKUP ) && $this->handle_create_pickup(),
			default => null,
		};
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_settings' );
		$this->action_handler->notices()->render_notices();

		$state = $this->progress->read();
		$step  = $this->requested_step( $state );
		$profile_index = $this->requested_profile_index( $state );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			$this->page_title( $state, $step ),
			$this->page_subtitle( $state, $step )
		);

		if ( $this->should_show_prior_install_notice( $state ) ) {
			echo '<div class="notice notice-info" role="status"><p>' . esc_html( $this->prior_install_notice() ) . '</p></div>';
		}

		AdminPageLayout::render_step_indicator( $this->step_labels(), $step );

		match ( $step ) {
			1 => $this->render_step_store( $state ),
			2 => $this->render_step_types( $state ),
			3 => $this->render_step_defaults( $state, $profile_index ),
			4 => $this->render_step_areas(),
			5 => $this->render_step_apply( $state ),
			default => $this->render_step_finish( $state ),
		};

		AdminPageLayout::close_page();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_step_store( array $state ): void {
		$primary = (string) ( $state['draft']['primary_profile'] ?? $this->settings->primary_profile_key() ?? '' );

		echo '<form method="post" class="cetech-de-wizard-form">';
		$this->hidden_action( self::ACTION_SAVE_STEP, 1 );
		echo '<input type="hidden" name="setup_step" value="1" />';

		echo '<div class="cetech-de-form-panel">';
		echo '<h2>' . esc_html__( 'Store information', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'These values come from WooCommerce. Delivery Engine does not replace them.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<dl class="cetech-de-definition-list">';
		echo '<dt>' . esc_html__( 'Store country', 'cetech-woocommerce-delivery-engine' ) . '</dt>';
		echo '<dd>' . esc_html( $this->entities->store_country_label() ) . '</dd>';
		echo '<dt>' . esc_html__( 'Store currency', 'cetech-woocommerce-delivery-engine' ) . '</dt>';
		echo '<dd>' . esc_html( $this->entities->store_currency() ) . '</dd>';
		echo '</dl>';
		echo '</div>';

		$cards = [];
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			$cards[] = [
				'type'    => 'radio',
				'name'    => 'primary_profile',
				'value'   => $profile->key,
				'title'   => $profile->label,
				'text'    => $this->primary_choice_copy( $profile ),
				'icon'    => $this->profile_icon( $profile->key ),
				'checked' => $primary === $profile->key || ( '' === $primary && $profile === FulfilmentProfileRegistry::all()[0] ),
			];
		}

		AdminPageLayout::render_choice_cards(
			$cards,
			__( 'How will most products normally be fulfilled?', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_nav_buttons( __( 'Continue', 'cetech-woocommerce-delivery-engine' ), true );
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_step_types( array $state ): void {
		$selected = $state['draft']['active_profiles'];
		if ( [] === $selected ) {
			$selected = $this->settings->active_profile_keys();
		}
		if ( [] === $selected ) {
			$primary = (string) ( $state['draft']['primary_profile'] ?? '' );
			$selected = '' !== $primary ? [ $primary ] : [ FulfilmentProfileRegistry::all()[0]->key ];
		}

		echo '<form method="post" class="cetech-de-wizard-form">';
		$this->hidden_action( self::ACTION_SAVE_STEP, 2 );
		echo '<input type="hidden" name="setup_step" value="2" />';

		$cards = [];
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			$cards[] = [
				'type'    => 'checkbox',
				'name'    => 'active_profiles[]',
				'value'   => $profile->key,
				'title'   => $profile->label,
				'text'    => $profile->description,
				'icon'    => $this->profile_icon( $profile->key ),
				'checked' => in_array( $profile->key, $selected, true ),
			];
		}

		AdminPageLayout::render_choice_cards(
			$cards,
			__( 'Which fulfilment types does your store use?', 'cetech-woocommerce-delivery-engine' ),
			__( 'Select all that apply.', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_nav_buttons( __( 'Continue', 'cetech-woocommerce-delivery-engine' ), true, null, self::back_redirect_args( 2 ) );
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_step_defaults( array $state, int $profile_index ): void {
		$active = $this->active_profiles( $state );
		if ( [] === $active ) {
			AdminPageLayout::render_warning(
				__( 'Choose fulfilment types first', 'cetech-woocommerce-delivery-engine' ),
				__( 'Select at least one fulfilment type before setting site-wide defaults.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Back', 'cetech-woocommerce-delivery-engine' ),
				$this->url( [ 'step' => 2 ] )
			);
			return;
		}

		$profile_index = min( count( $active ) - 1, max( 0, $profile_index ) );
		$profile_key   = $active[ $profile_index ];
		$profile       = FulfilmentProfileRegistry::get( $profile_key );
		if ( ! $profile instanceof FulfilmentProfile ) {
			return;
		}

		$this->defaults->ensure_profile_scope( $profile_key );
		$scope = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$profile_key
		);

		$form_draft = is_array( $state['draft']['profile_forms'][ $profile_key ] ?? null )
			? $state['draft']['profile_forms'][ $profile_key ]
			: [];

		$choice = $scope?->scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ]->value ?? FulfilmentChoice::Delivery->value;
		$eta    = $scope?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value ?? '';
		$offers = $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members ?? [];
		$pickup_id = (int) ( $scope?->scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ]->value ?? 0 );
		if ( isset( $form_draft['delivery_option_ids'] ) && is_array( $form_draft['delivery_option_ids'] ) ) {
			$offers = array_map( 'intval', $form_draft['delivery_option_ids'] );
		}
		if ( isset( $form_draft['estimated_delivery'] ) && is_string( $form_draft['estimated_delivery'] ) ) {
			$eta = $form_draft['estimated_delivery'];
		}
		if ( isset( $form_draft['default_customer_choice'] ) && is_string( $form_draft['default_customer_choice'] ) ) {
			$choice = $form_draft['default_customer_choice'];
		} elseif ( isset( $form_draft['customer_fulfilment'] ) && is_string( $form_draft['customer_fulfilment'] ) ) {
			$choice = $form_draft['customer_fulfilment'];
		}
		if ( $pickup_id <= 0 ) {
			$pickup_id = (int) ( $state['draft']['pickup_location_ids'][ $profile_key ] ?? 0 );
		}
		$local_ids    = InStoreMethodSelection::local_delivery_offer_ids( is_array( $offers ) ? $offers : [], $this->offers );
		$has_delivery = [] !== $local_ids;
		$has_pickup   = $pickup_id > 0;
		if ( isset( $form_draft['available_methods'] ) && is_array( $form_draft['available_methods'] ) ) {
			$has_delivery = in_array( InStoreMethodSelection::METHOD_DELIVERY, $form_draft['available_methods'], true );
			$has_pickup   = in_array( InStoreMethodSelection::METHOD_STORE_PICKUP, $form_draft['available_methods'], true );
		} elseif ( ! $has_delivery && ! $has_pickup ) {
			$has_delivery = true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$validation = isset( $_GET['validation'] ) ? sanitize_key( wp_unslash( (string) $_GET['validation'] ) ) : '';

		echo '<p class="cetech-de-wizard-substep">' . esc_html(
			sprintf(
				/* translators: 1: current profile number, 2: total profiles, 3: profile name */
				__( 'Site-wide Defaults %1$d of %2$d — %3$s', 'cetech-woocommerce-delivery-engine' ),
				$profile_index + 1,
				count( $active ),
				$profile->label
			)
		) . '</p>';

		echo '<form method="post" class="cetech-de-wizard-form" id="cetech-de-wizard-defaults">';
		$this->hidden_action( self::ACTION_SAVE_STEP, 3 );
		echo '<input type="hidden" name="setup_step" value="3" />';
		echo '<input type="hidden" name="profile_key" value="' . esc_attr( $profile_key ) . '" />';
		echo '<input type="hidden" name="profile_index" value="' . esc_attr( (string) $profile_index ) . '" />';

		echo '<div class="cetech-de-form-panel">';
		echo '<h2>' . esc_html( sprintf( /* translators: %s fulfilment type */ __( 'Set up %s', 'cetech-woocommerce-delivery-engine' ), $profile->label ) ) . '</h2>';
		echo '<p>' . esc_html( $this->profile_setup_copy( $profile ) ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Fulfilment', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ';
		echo esc_html( $profile->customer_fulfilment_label ) . '</p>';

		if ( $profile->pickup_allowed ) {
			echo '<fieldset><legend>' . esc_html__( 'Available fulfilment methods', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
			echo '<p><label><input type="checkbox" name="available_methods[]" value="delivery"' . checked( $has_delivery, true, false ) . ' /> ';
			echo esc_html__( 'Delivery', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			echo '<p><label><input type="checkbox" name="available_methods[]" value="store_pickup"' . checked( $has_pickup, true, false ) . ' /> ';
			echo esc_html__( 'Store Pickup', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			echo '<p class="description">' . esc_html__( 'One or both methods may be enabled. These are availability settings, not an exclusive choice.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '</fieldset>';
			echo '<fieldset><legend>' . esc_html__( 'Default customer choice', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
			$default = FulfilmentChoice::StorePickup->value === (string) $choice && $has_pickup
				? FulfilmentChoice::StorePickup->value
				: FulfilmentChoice::Delivery->value;
			echo '<p><label><input type="radio" name="default_customer_choice" value="' . esc_attr( FulfilmentChoice::Delivery->value ) . '"' . checked( $default, FulfilmentChoice::Delivery->value, false ) . ' /> ';
			echo esc_html__( 'Delivery', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			echo '<p><label><input type="radio" name="default_customer_choice" value="' . esc_attr( FulfilmentChoice::StorePickup->value ) . '"' . checked( $default, FulfilmentChoice::StorePickup->value, false ) . ' /> ';
			echo esc_html__( 'Store Pickup', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			echo '</fieldset>';
		} else {
			echo '<p><strong>' . esc_html__( 'Available fulfilment methods', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ' . esc_html__( 'Delivery only', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '<input type="hidden" name="default_customer_choice" value="' . esc_attr( FulfilmentChoice::Delivery->value ) . '" />';
			echo '<input type="hidden" name="available_methods[]" value="delivery" />';
		}

		$compatible = $this->compatible_offers( $profile );
		echo '<fieldset class="cetech-de-option-list" id="cetech-de-option-list" tabindex="-1"><legend>' . esc_html( $this->options_legend( $profile ) ) . '</legend>';
		if ( $profile->pickup_allowed && ! $profile->air_sea_allowed ) {
			echo '<p class="description">' . esc_html__( 'Delivery Options are local doorstep services such as Standard Delivery. Store Pickup is selected separately.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		if ( 'delivery_options' === $validation ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'Select at least one Delivery Option before continuing.', 'cetech-woocommerce-delivery-engine' ) . '</p></div>';
		}
		if ( [] === $compatible ) {
			$this->render_guided_empty_options_state( $profile );
		} else {
			foreach ( $compatible as $offer ) {
				$id = (int) ( $offer['id'] ?? 0 );
				echo '<p><label><input type="checkbox" name="delivery_option_ids[]" value="' . esc_attr( (string) $id ) . '"' . checked( in_array( $id, $local_ids, true ), true, false ) . ' /> ';
				echo '<strong>' . esc_html( (string) ( $offer['public_label'] ?? $offer['internal_name'] ?? '' ) ) . '</strong>';
				$route_label = $this->route_label( (string) ( $offer['route'] ?? '' ) );
				if ( '' !== $route_label ) {
					echo ' — ' . esc_html( $route_label );
				}
				echo '</label></p>';
			}
		}
		echo '</fieldset>';
		echo '</div>';

		echo '<div class="cetech-de-form-panel">';
		echo '<p><label for="estimated_delivery">' . esc_html__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="estimated_delivery" name="estimated_delivery" value="' . esc_attr( is_string( $eta ) ? $eta : '' ) . '" placeholder="3–6 business days" /></p>';
		echo '<p class="description">' . esc_html__( 'Shown to customers when this fulfilment type is used.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</div>';

		if ( $profile->pickup_allowed ) {
			$this->render_pickup_selector( $pickup_id );
		}

		$continue_label = sprintf(
			/* translators: %s fulfilment type */
			__( 'Save %s Default & Continue', 'cetech-woocommerce-delivery-engine' ),
			$profile->label
		);
		$this->render_nav_buttons( $continue_label, true, null, self::back_redirect_args( 3, $profile_index, count( $active ) ) );
		echo '</form>';

		// Inline create panels must stay outside the save form (nested forms break Continue).
		$this->render_create_option_panel( $profile );
		$this->render_charge_selector( $compatible, $offers );
		$this->render_create_charge_panel( $compatible );
		if ( $profile->pickup_allowed ) {
			$this->render_create_pickup_panel();
		}
	}

	private function render_guided_empty_options_state( FulfilmentProfile $profile ): void {
		if ( $profile->air_sea_allowed ) {
			AdminPageLayout::render_empty_state(
				__( 'No international shipping options yet', 'cetech-woocommerce-delivery-engine' ),
				__( 'International fulfilment needs Air Shipping and/or Sea Shipping Delivery Options. Create one below, then select it to continue.', 'cetech-woocommerce-delivery-engine' )
			);
			echo '<p><button type="button" class="button button-secondary" data-cetech-de-open="cetech-de-create-option">' . esc_html__( 'Create Air or Sea Shipping option', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
			return;
		}

		AdminPageLayout::render_empty_state(
			__( 'No compatible delivery options yet', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Options are the choices customers can select, such as Standard Delivery, Air Shipping, or Sea Shipping.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<p><button type="button" class="button button-secondary" data-cetech-de-open="cetech-de-create-option">' . esc_html__( 'Create Delivery Option', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
	}

	private function render_step_areas(): void {
		$zones  = $this->zones->list( [ 'limit' => 100 ] );
		$offers = $this->offers->list( [ 'limit' => 100 ] );
		$rates  = $this->rates->list( [ 'limit' => 100 ] );

		echo '<div class="cetech-de-form-panel">';
		echo '<h2>' . esc_html__( 'Delivery Areas & Charges', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'This is a simple summary of where you deliver and how much customers pay. You can add more detail later.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

		if ( [] === $zones ) {
			AdminPageLayout::render_empty_state(
				__( 'No delivery areas yet', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery areas are the places you deliver to. Add a local area or an international area, then connect a delivery charge.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$rows = [];
			foreach ( $zones as $zone ) {
				$zone_id = (int) ( $zone['id'] ?? 0 );
				$linked  = $this->charges_for_zone( $rates, $zone_id, $offers );
				$rows[]  = [
					'<strong>' . esc_html( (string) ( $zone['public_label'] ?? $zone['internal_name'] ?? '' ) ) . '</strong>',
					esc_html( $linked['options'] ),
					esc_html( $linked['charge'] ),
					AdminUiHelper::record_status_badge( (string) ( $zone['status'] ?? '' ) ),
					'<a class="button" href="' . esc_url( AdminPageRenderer::edit_url( DestinationZonesPage::SLUG, $zone_id ) ) . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>',
				];
			}
			AdminPageRenderer::render_table(
				[
					__( 'Delivery Area', 'cetech-woocommerce-delivery-engine' ),
					__( 'Available Options', 'cetech-woocommerce-delivery-engine' ),
					__( 'Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
					__( 'Status', 'cetech-woocommerce-delivery-engine' ),
					__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
				],
				$rows,
				true
			);
		}
		echo '</div>';

		$open    = [] === $zones ? ' open' : '';
		$summary = [] === $zones
			? __( 'Add Delivery Area', 'cetech-woocommerce-delivery-engine' )
			: __( '+ Add another Delivery Area', 'cetech-woocommerce-delivery-engine' );
		echo '<form method="post" class="cetech-de-wizard-form">';
		$this->hidden_action( self::ACTION_CREATE_AREA, 4 );
		echo '<details class="cetech-de-inline-create" id="cetech-de-create-area"' . $open . '><summary>' . esc_html( $summary ) . '</summary>';
		echo '<div class="cetech-de-inline-create-body">';
		if ( [] === $zones ) {
			echo '<p class="cetech-de-empty-hint">' . esc_html__( 'You don\'t have a Delivery Area yet.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<p><label for="area_name">' . esc_html__( 'Area name', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="area_name" name="area_name" required /></p>';
		echo '<p><label for="area_city">' . esc_html__( 'City or region (optional)', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="area_city" name="area_city" /></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Save area', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</div></details></form>';

		$this->render_create_charge_panel( $offers, 4 );

		echo '<p class="cetech-de-button-group">';
		echo '<a class="button" href="' . esc_url( AdminPageRenderer::list_url( DestinationZonesPage::SLUG ) ) . '">' . esc_html__( 'Open full Delivery Areas page', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( AdminPageRenderer::list_url( RateCardsPage::SLUG ) ) . '">' . esc_html__( 'Open full Delivery Charges page', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</p>';
		$active_count = count( $this->active_profiles( $this->progress->read() ) );
		$this->render_standalone_footer( self::back_redirect_args( 4, 0, $active_count ), $this->url( self::continue_redirect_args( 4 ) ) );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_step_apply( array $state ): void {
		$preview = $this->defaults->preview();

		echo '<div class="cetech-de-form-panel">';
		echo '<h2>' . esc_html__( 'Apply Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your delivery defaults are ready. Products without their own delivery settings can automatically use them.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( $preview->published_products > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d catalog items assessed */
					_n( '%d catalog item assessed', '%d catalog items assessed', $preview->published_products, 'cetech-woocommerce-delivery-engine' ),
					$preview->published_products
				)
			) . '</p>';
		}

		echo '<div class="cetech-de-apply-panels">';
		$this->render_apply_panel( __( 'Safe to inherit', 'cetech-woocommerce-delivery-engine' ), (string) $preview->can_safely_inherit, '' );
		$this->render_apply_panel( __( 'Product exceptions', 'cetech-woocommerce-delivery-engine' ), (string) $preview->product_exceptions, __( 'Protected', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_apply_panel( __( 'Variation exceptions', 'cetech-woocommerce-delivery-engine' ), (string) $preview->variation_exceptions, __( 'Protected', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_apply_panel( __( 'Needs review', 'cetech-woocommerce-delivery-engine' ), (string) $preview->needs_review, '' );
		echo '</div>';
		echo '</div>';

		echo '<form method="post" class="cetech-de-wizard-form">';
		$this->hidden_action( self::ACTION_APPLY, 5 );
		$active  = $this->active_profiles( $state );
		$primary = $this->primary_profile( $state );
		echo '<input type="hidden" name="primary_profile" value="' . esc_attr( $primary ) . '" />';
		foreach ( $active as $key ) {
			echo '<input type="hidden" name="active_profiles[]" value="' . esc_attr( $key ) . '" />';
		}
		echo '<p class="description">' . esc_html__( 'These settings will apply to existing and future products that do not have their own delivery exceptions.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Product and variation exceptions will not be overwritten.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Future changes to inherited settings will update automatically.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->render_nav_buttons(
			__( 'Save & Apply Site-wide', 'cetech-woocommerce-delivery-engine' ),
			true,
			null,
			self::back_redirect_args( 5 ),
			SetupWizardProgress::STATUS_READY_TO_APPLY
		);
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_step_finish( array $state ): void {
		$primary          = FulfilmentProfileRegistry::get( $this->primary_profile( $state ) );
		$setup_complete   = $this->settings->is_setup_complete() && SetupWizardProgress::STATUS_COMPLETE === $state['status'];
		$shipping_ready   = $this->shipping_readiness->is_ready();
		$runtime_active   = $this->runtime->is_active();
		$attention        = $this->needs_attention->count();
		$config_ready     = $setup_complete && [] !== $this->configured_profile_labels( $state );
		$can_finish       = $setup_complete && $shipping_ready && $config_ready;

		echo '<div class="cetech-de-form-panel cetech-de-finish-panel">';
		if ( $can_finish ) {
			echo '<h2>' . esc_html__( 'Delivery setup complete', 'cetech-woocommerce-delivery-engine' ) . ' ✓</h2>';
		} else {
			echo '<h2>' . esc_html__( 'Almost ready', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
			echo '<p>' . esc_html__( 'A few items still need to be completed before Delivery Engine can serve customers.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}

		echo '<ul class="cetech-de-readiness-list">';
		$this->render_readiness_row(
			__( 'WooCommerce shipping', 'cetech-woocommerce-delivery-engine' ),
			$shipping_ready,
			$shipping_ready ? __( 'Ready', 'cetech-woocommerce-delivery-engine' ) : __( 'Action needed', 'cetech-woocommerce-delivery-engine' )
		);
		$this->render_readiness_row(
			__( 'Delivery configuration', 'cetech-woocommerce-delivery-engine' ),
			$config_ready,
			$config_ready ? __( 'Ready', 'cetech-woocommerce-delivery-engine' ) : __( 'Action needed', 'cetech-woocommerce-delivery-engine' )
		);
		$this->render_readiness_row(
			__( 'Checkout', 'cetech-woocommerce-delivery-engine' ),
			$runtime_active,
			$runtime_active ? __( 'Ready', 'cetech-woocommerce-delivery-engine' ) : __( 'Not active yet', 'cetech-woocommerce-delivery-engine' )
		);
		$this->render_readiness_row(
			__( 'Delivery Engine active', 'cetech-woocommerce-delivery-engine' ),
			$runtime_active,
			$runtime_active ? __( 'Ready', 'cetech-woocommerce-delivery-engine' ) : __( 'Not active yet', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</ul>';

		if ( ! $shipping_ready ) {
			echo '<p>' . esc_html( $this->shipping_readiness->explanation() ) . '</p>';
			echo '<p>' . esc_html__( 'Open the zone, click Add shipping method, and choose Delivery. You can do this before Activate Delivery Engine.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '<p><a class="button button-secondary" href="' . esc_url( $this->shipping_readiness->settings_url() ) . '">' . esc_html__( 'Configure WooCommerce Shipping', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
		}

		if ( $runtime_active ) {
			echo '<p class="cetech-de-engine-active"><strong>' . esc_html__( 'Delivery Engine active', 'cetech-woocommerce-delivery-engine' ) . ' ✓</strong></p>';
		} elseif ( $can_finish ) {
			echo '<form method="post">';
			$this->hidden_action( self::ACTION_ACTIVATE, 6 );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Activate Delivery Engine', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
			echo '</form>';
		}

		echo '<div class="cetech-de-finish-summary">';
		echo '<p><strong>' . esc_html__( 'Primary fulfilment', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ' . esc_html( $primary?->label ?? __( 'Not chosen', 'cetech-woocommerce-delivery-engine' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Site-wide defaults', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ' . esc_html( implode( ', ', $this->configured_profile_labels( $state ) ) ) . '</p>';
		if ( $attention > 0 ) {
			echo '<p class="cetech-de-finish-attention"><strong>' . esc_html__( 'Items needing attention', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ' . esc_html( (string) $attention ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Items needing attention', 'cetech-woocommerce-delivery-engine' ) . ':</strong> 0</p>';
		}
		echo '</div></div>';

		if ( $can_finish ) {
			echo '<p class="cetech-de-wizard-actions cetech-de-wizard-actions--finish">';
			echo '<a class="button button-primary" href="' . esc_url( AdminPageRenderer::list_url( AdminMenu::PARENT_SLUG ) ) . '">' . esc_html__( 'Go to Delivery Overview', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
			echo '<a class="button button-secondary" href="' . esc_url( AdminPageRenderer::list_url( NeedsAttentionPage::SLUG ) ) . '">' . esc_html__( 'Review Items Needing Attention', 'cetech-woocommerce-delivery-engine' ) . '</a>';
			echo '</p>';
		} else {
			echo '<p class="cetech-de-wizard-actions cetech-de-wizard-actions--finish">';
			echo '<a class="button button-secondary" href="' . esc_url( AdminPageRenderer::list_url( NeedsAttentionPage::SLUG ) ) . '">' . esc_html__( 'Review Items Needing Attention', 'cetech-woocommerce-delivery-engine' ) . '</a>';
			echo '</p>';
		}
	}

	private function render_readiness_row( string $label, bool $ready, string $status ): void {
		echo '<li class="' . esc_attr( $ready ? 'is-ready' : 'is-attention' ) . '">';
		echo '<strong>' . esc_html( $label ) . '</strong> ';
		echo esc_html( $status );
		if ( $ready ) {
			echo ' ✓';
		}
		echo '</li>';
	}

	private function render_apply_panel( string $label, string $count, string $note ): void {
		echo '<div class="cetech-de-apply-panel">';
		echo '<p class="cetech-de-apply-panel-label">' . esc_html( $label ) . '</p>';
		echo '<p class="cetech-de-apply-panel-count">' . esc_html( $count ) . '</p>';
		if ( '' !== $note ) {
			echo '<p class="cetech-de-apply-panel-note">' . esc_html( $note ) . '</p>';
		}
		echo '</div>';
	}

	private function handle_save_step(): void {
		$step = isset( $_POST['setup_step'] ) ? absint( wp_unslash( $_POST['setup_step'] ) ) : 1;
		$state = $this->progress->read();
		$draft = $state['draft'];

		if ( 1 === $step ) {
			$primary = isset( $_POST['primary_profile'] ) ? sanitize_key( wp_unslash( (string) $_POST['primary_profile'] ) ) : '';
			if ( ! FulfilmentProfileRegistry::has( $primary ) ) {
				$this->action_handler->notices()->flash_error( __( 'Choose how most products are fulfilled.', 'cetech-woocommerce-delivery-engine' ) );
				$this->action_handler->redirect( self::SLUG, [ 'step' => 1 ] );
			}
			$draft['primary_profile'] = $primary;
			if ( [] === $draft['active_profiles'] ) {
				$draft['active_profiles'] = [ $primary ];
			}
			$this->persist_progress( SetupWizardProgress::STATUS_IN_PROGRESS, 2, 0, $draft, $state['review_mode'] );
			$this->action_handler->redirect( self::SLUG, self::continue_redirect_args( 1 ) );
		}

		if ( 2 === $step ) {
			$active = $this->posted_profiles();
			if ( [] === $active ) {
				$this->action_handler->notices()->flash_error( __( 'Choose at least one fulfilment type.', 'cetech-woocommerce-delivery-engine' ) );
				$this->action_handler->redirect( self::SLUG, [ 'step' => 2 ] );
			}
			$draft['active_profiles'] = $active;
			if ( ! in_array( (string) $draft['primary_profile'], $active, true ) ) {
				$draft['primary_profile'] = $active[0];
			}
			$this->persist_progress( SetupWizardProgress::STATUS_IN_PROGRESS, 3, 0, $draft, $state['review_mode'] );
			$this->action_handler->redirect( self::SLUG, self::continue_redirect_args( 2 ) );
		}

		if ( 3 === $step ) {
			$profile_key   = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['profile_key'] ) ) : '';
			$profile_index = isset( $_POST['profile_index'] ) ? absint( wp_unslash( $_POST['profile_index'] ) ) : 0;
			$result        = $this->save_profile_from_post( $profile_key, $draft );
			if ( [] !== $result['errors'] ) {
				$draft = $result['draft'];
				$this->persist_progress( SetupWizardProgress::STATUS_IN_PROGRESS, 3, $profile_index, $draft, $state['review_mode'] );
				$this->action_handler->notices()->flash_error( implode( ' ', $result['errors'] ) );
				$this->action_handler->redirect(
					self::SLUG,
					[
						'step'          => 3,
						'profile_index' => $profile_index,
						'focus'         => 'option-list',
						'validation'    => 'delivery_options',
					]
				);
			}

			$draft      = $this->clear_profile_form_draft( $profile_key, $result['draft'] );
			$active     = $this->active_profiles( [ 'draft' => $draft ] );
			$next       = self::continue_redirect_args( 3, $profile_index, count( $active ) );
			$next_step  = (int) $next['step'];
			$next_index = (int) ( $next['profile_index'] ?? 0 );
			$this->persist_progress( SetupWizardProgress::STATUS_IN_PROGRESS, $next_step, $next_index, $draft, $state['review_mode'] );
			$this->action_handler->notices()->flash_success( __( 'Site-wide default saved.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, $next );
		}

		$this->action_handler->redirect( self::SLUG, [ 'step' => $step ] );
	}

	private function handle_save_later(): void {
		$step = isset( $_POST['setup_step'] ) ? absint( wp_unslash( $_POST['setup_step'] ) ) : 1;
		$index = isset( $_POST['profile_index'] ) ? absint( wp_unslash( $_POST['profile_index'] ) ) : 0;
		$status = isset( $_POST['wizard_status'] ) ? sanitize_key( wp_unslash( (string) $_POST['wizard_status'] ) ) : SetupWizardProgress::STATUS_IN_PROGRESS;
		$state = $this->progress->read();
		$this->persist_progress( $status, $step, $index, $state['draft'], $state['review_mode'] );
		$this->action_handler->notices()->flash_success( __( 'Progress saved. You can continue setup from Delivery Engine.', 'cetech-woocommerce-delivery-engine' ) );
		$this->action_handler->redirect( AdminMenu::PARENT_SLUG );
	}

	private function handle_apply(): void {
		$state   = $this->progress->read();
		$active  = $this->posted_profiles();
		$active  = [] !== $active ? $active : $this->active_profiles( $state );
		$primary = isset( $_POST['primary_profile'] ) ? sanitize_key( wp_unslash( (string) $_POST['primary_profile'] ) ) : $this->primary_profile( $state );

		try {
			$result = $this->defaults->apply_site_wide( $active, $primary, true );
		} catch ( \InvalidArgumentException $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG, [ 'step' => 5 ] );
			return;
		}

		$draft = $state['draft'];
		$draft['active_profiles'] = $active;
		$draft['primary_profile'] = $primary;
		$this->progress->save(
			[
				'status'       => SetupWizardProgress::STATUS_COMPLETE,
				'step'         => 6,
				'review_mode'  => false,
				'draft'        => $draft,
			]
		);

		if ( $this->shipping_readiness->is_ready() ) {
			$this->runtime->activate();
		}

		$this->action_handler->notices()->flash_success(
			sprintf(
				/* translators: 1: products converted to inherit, 2: exceptions kept */
				__( 'Site-wide defaults are active. %1$d matching saved product values now inherit the defaults. %2$d product exceptions were left unchanged. Legacy rules were not overwritten.', 'cetech-woocommerce-delivery-engine' ),
				$result['converted_products'],
				$result['skipped_exceptions']
			)
		);
		$this->action_handler->redirect( self::SLUG, [ 'step' => 6 ] );
	}

	private function handle_activate(): void {
		if ( ! $this->settings->is_setup_complete() ) {
			$this->action_handler->notices()->flash_error( __( 'Finish delivery setup before activating the Delivery Engine for customers.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'step' => 6 ] );
			return;
		}

		if ( ! $this->shipping_readiness->is_ready() ) {
			$this->action_handler->notices()->flash_error( $this->shipping_readiness->explanation() );
			$this->action_handler->redirect( self::SLUG, [ 'step' => 6 ] );
			return;
		}

		if ( ! $this->runtime->activate() ) {
			$this->action_handler->notices()->flash_error( __( 'Delivery Engine could not be activated until setup is complete.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'step' => 6 ] );
			return;
		}

		$this->action_handler->notices()->flash_success( __( 'Delivery Engine is active for Classic Checkout.', 'cetech-woocommerce-delivery-engine' ) );
		$this->action_handler->redirect( self::SLUG, [ 'step' => 6 ] );
	}

	private function handle_create_option(): void {
		$step  = isset( $_POST['setup_step'] ) ? absint( wp_unslash( $_POST['setup_step'] ) ) : 3;
		$index = isset( $_POST['profile_index'] ) ? absint( wp_unslash( $_POST['profile_index'] ) ) : 0;
		$active = $this->active_profiles( $this->progress->read() );
		$profile_key = $active[ $index ] ?? '';
		$profile = FulfilmentProfileRegistry::get( $profile_key );
		$allowed_routes = $profile instanceof FulfilmentProfile
			? DeliveryOptionCompatibility::allowed_routes( $profile )
			: [];
		$route = sanitize_key( wp_unslash( (string) ( $_POST['option_route'] ?? '' ) ) );
		if ( '' === $route || ( [] !== $allowed_routes && ! in_array( $route, $allowed_routes, true ) ) ) {
			$route = $allowed_routes[0] ?? DeliveryRoute::LocalDelivery->value;
		}

		$result = $this->entities->create_delivery_option(
			[
				'name'                => sanitize_text_field( wp_unslash( (string) ( $_POST['option_name'] ?? '' ) ) ),
				'description'         => sanitize_textarea_field( wp_unslash( (string) ( $_POST['option_description'] ?? '' ) ) ),
				'estimated_delivery'  => sanitize_text_field( wp_unslash( (string) ( $_POST['option_eta'] ?? '' ) ) ),
				'route'               => $route,
				'active'              => isset( $_POST['option_active'] ) ? '1' : '0',
			]
		);

		if ( [] !== $result['errors'] ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $result['errors'] ) );
		} else {
			$this->action_handler->notices()->flash_success( __( 'Delivery option created and ready to select.', 'cetech-woocommerce-delivery-engine' ) );
			$this->select_new_option( $result['id'], $index );
		}

		$this->action_handler->redirect( self::SLUG, [ 'step' => $step, 'profile_index' => $index, 'focus' => 'option-list' ] );
	}

	private function handle_create_charge(): void {
		$step  = isset( $_POST['setup_step'] ) ? absint( wp_unslash( $_POST['setup_step'] ) ) : 3;
		$index = isset( $_POST['profile_index'] ) ? absint( wp_unslash( $_POST['profile_index'] ) ) : 0;
		$result = $this->entities->create_delivery_charge(
			[
				'name'               => sanitize_text_field( wp_unslash( (string) ( $_POST['charge_name'] ?? '' ) ) ),
				'charge_style'       => sanitize_key( wp_unslash( (string) ( $_POST['charge_style'] ?? 'flat' ) ) ),
				'amount'             => sanitize_text_field( wp_unslash( (string) ( $_POST['charge_amount'] ?? '' ) ) ),
				'delivery_option_id' => absint( wp_unslash( $_POST['charge_option_id'] ?? 0 ) ),
				'delivery_area_id'   => absint( wp_unslash( $_POST['charge_area_id'] ?? 0 ) ),
			]
		);

		if ( [] !== $result['errors'] ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $result['errors'] ) );
		} else {
			$this->action_handler->notices()->flash_success( __( 'Delivery charge created.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG, [ 'step' => $step, 'profile_index' => $index ] );
	}

	private function handle_create_area(): void {
		$result = $this->entities->create_delivery_area(
			[
				'name'    => sanitize_text_field( wp_unslash( (string) ( $_POST['area_name'] ?? '' ) ) ),
				'city'    => sanitize_text_field( wp_unslash( (string) ( $_POST['area_city'] ?? '' ) ) ),
				'country' => $this->entities->store_country(),
			]
		);

		if ( [] !== $result['errors'] ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $result['errors'] ) );
		} else {
			$this->action_handler->notices()->flash_success( __( 'Delivery area created.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG, [ 'step' => 4 ] );
	}

	private function handle_create_pickup(): void {
		$index = isset( $_POST['profile_index'] ) ? absint( wp_unslash( $_POST['profile_index'] ) ) : 0;
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['profile_key'] ) ) : '';
		$result = $this->entities->create_pickup_location(
			[
				'name'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pickup_name'] ?? '' ) ) ),
				'address'    => sanitize_text_field( wp_unslash( (string) ( $_POST['pickup_address'] ?? '' ) ) ),
				'city'       => sanitize_text_field( wp_unslash( (string) ( $_POST['pickup_city'] ?? '' ) ) ),
				'ready_time' => sanitize_text_field( wp_unslash( (string) ( $_POST['pickup_ready'] ?? '' ) ) ),
			]
		);

		if ( [] !== $result['errors'] ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $result['errors'] ) );
		} else {
			$state = $this->progress->read();
			$draft = $state['draft'];
			if ( '' !== $profile_key ) {
				$draft['pickup_location_ids'][ $profile_key ] = $result['id'];
			}
			$this->persist_progress( $state['status'], 3, $index, $draft, $state['review_mode'] );
			$this->action_handler->notices()->flash_success( __( 'Pickup location created and selected.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG, [ 'step' => 3, 'profile_index' => $index, 'focus' => 'pickup' ] );
	}

	/**
	 * Shared Step 3 fulfilment-default validation contract for all profiles.
	 *
	 * @param list<int> $delivery_option_ids
	 *
	 * @return list<string>
	 */
	public static function validate_fulfilment_default_selection( FulfilmentProfile $profile, array $delivery_option_ids ): array {
		unset( $profile );
		$ids = [];
		foreach ( $delivery_option_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( [] === $ids ) {
			return [ __( 'Select at least one Delivery Option before continuing.', 'cetech-woocommerce-delivery-engine' ) ];
		}

		return [];
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array{errors: list<string>, draft: array<string, mixed>}
	 */
	private function save_profile_from_post( string $profile_key, array $draft ): array {
		$profile = FulfilmentProfileRegistry::get( $profile_key );
		if ( ! $profile instanceof FulfilmentProfile ) {
			return [ 'errors' => [ 'Unknown fulfilment type.' ], 'draft' => $draft ];
		}

		$mode   = isset( $_POST['default_customer_choice'] )
			? sanitize_key( wp_unslash( (string) $_POST['default_customer_choice'] ) )
			: ( isset( $_POST['customer_fulfilment'] ) ? sanitize_key( wp_unslash( (string) $_POST['customer_fulfilment'] ) ) : FulfilmentChoice::Delivery->value );
		$eta    = isset( $_POST['estimated_delivery'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['estimated_delivery'] ) ) : '';
		$ids    = [];
		if ( isset( $_POST['delivery_option_ids'] ) && is_array( $_POST['delivery_option_ids'] ) ) {
			foreach ( wp_unslash( $_POST['delivery_option_ids'] ) as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		$methods = [];
		if ( isset( $_POST['available_methods'] ) && is_array( $_POST['available_methods'] ) ) {
			foreach ( wp_unslash( $_POST['available_methods'] ) as $method ) {
				$methods[] = sanitize_key( (string) $method );
			}
		}
		$pickup_id = isset( $_POST['pickup_location_id'] ) ? absint( wp_unslash( $_POST['pickup_location_id'] ) ) : 0;

		$draft = $this->remember_profile_form_values( $profile_key, $draft, $mode, $ids, $eta, $methods );

		if ( $profile->pickup_allowed ) {
			$selection = InStoreMethodSelection::from_posted( $methods, $mode, $ids, $pickup_id, $this->offers );
			$errors    = $selection->validate( $this->pickups );
			if ( [] !== $errors ) {
				return [ 'errors' => $errors, 'draft' => $draft ];
			}
			if ( $selection->pickup_location_id > 0 ) {
				$draft['pickup_location_ids'][ $profile_key ] = $selection->pickup_location_id;
			}
			$fields = array_merge(
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => [
						'mode'  => 'override',
						'value' => $eta,
					],
				],
				$selection->field_payloads()
			);
		} else {
			$errors = self::validate_fulfilment_default_selection( $profile, $ids );
			if ( [] !== $errors ) {
				return [ 'errors' => $errors, 'draft' => $draft ];
			}
			$fields = [
				ConfigurationFieldKey::FULFILMENT_CHOICE => [
					'mode'  => 'override',
					'value' => FulfilmentChoice::Delivery->value,
				],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
					'mode'    => 'replace',
					'members' => $ids,
				],
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [
					'mode'  => 'override',
					'value' => $eta,
				],
			];
		}

		try {
			$this->defaults->save_profile_defaults( $profile_key, $fields );
		} catch ( \Throwable $exception ) {
			return [ 'errors' => [ $exception->getMessage() ], 'draft' => $draft ];
		}

		return [ 'errors' => [], 'draft' => $draft ];
	}

	/**
	 * @param array<string, mixed> $draft
	 * @param list<int>            $ids
	 *
	 * @return array<string, mixed>
	 */
	private function remember_profile_form_values( string $profile_key, array $draft, string $mode, array $ids, string $eta, array $methods = [] ): array {
		if ( ! isset( $draft['profile_forms'] ) || ! is_array( $draft['profile_forms'] ) ) {
			$draft['profile_forms'] = [];
		}
		$draft['profile_forms'][ $profile_key ] = [
			'default_customer_choice' => $mode,
			'customer_fulfilment'     => $mode,
			'available_methods'       => array_values( array_unique( $methods ) ),
			'delivery_option_ids'     => array_values( array_unique( array_map( 'intval', $ids ) ) ),
			'estimated_delivery'      => $eta,
		];

		return $draft;
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function clear_profile_form_draft( string $profile_key, array $draft ): array {
		if ( isset( $draft['profile_forms'] ) && is_array( $draft['profile_forms'] ) ) {
			unset( $draft['profile_forms'][ $profile_key ] );
		}

		return $draft;
	}

	private function select_new_option( int $option_id, int $profile_index ): void {
		$state  = $this->progress->read();
		$active = $this->active_profiles( $state );
		if ( ! isset( $active[ $profile_index ] ) ) {
			return;
		}

		$profile_key = $active[ $profile_index ];
		$this->defaults->ensure_profile_scope( $profile_key );
		$scope = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$profile_key
		);
		$existing = $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members ?? [];
		if ( ! in_array( $option_id, $existing, true ) ) {
			$existing[] = $option_id;
		}

		$eta = $scope?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value ?? '';
		$choice = $scope?->scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ]->value ?? FulfilmentChoice::Delivery->value;
		$this->defaults->save_profile_defaults(
			$profile_key,
			[
				ConfigurationFieldKey::FULFILMENT_CHOICE => [
					'mode'  => 'override',
					'value' => $choice,
				],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
					'mode'    => 'replace',
					'members' => $existing,
				],
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [
					'mode'  => 'override',
					'value' => is_string( $eta ) ? $eta : '',
				],
			]
		);
	}

	private function render_create_option_panel( FulfilmentProfile $profile ): void {
		$routes   = $this->route_choices_for_profile( $profile );
		$existing = $this->compatible_offers( $profile );
		$open     = [] === $existing ? ' open' : '';
		$summary  = [] === $existing
			? ( $profile->air_sea_allowed
				? __( 'Create Air or Sea Shipping option', 'cetech-woocommerce-delivery-engine' )
				: __( 'Create Delivery Option', 'cetech-woocommerce-delivery-engine' ) )
			: __( '+ Create another Delivery Option', 'cetech-woocommerce-delivery-engine' );
		echo '<details class="cetech-de-inline-create" id="cetech-de-create-option"' . $open . '><summary>' . esc_html( $summary ) . '</summary>';
		echo '<div class="cetech-de-inline-create-body">';
		if ( [] === $existing ) {
			echo '<p class="cetech-de-empty-hint">' . esc_html(
				$profile->air_sea_allowed
					? __( 'Create an Air Shipping or Sea Shipping Delivery Option for International fulfilment.', 'cetech-woocommerce-delivery-engine' )
					: __( 'You don\'t have a compatible Delivery Option yet.', 'cetech-woocommerce-delivery-engine' )
			) . '</p>';
		}
		echo '<form method="post">';
		$this->hidden_action( self::ACTION_CREATE_OPTION, 3 );
		echo '<input type="hidden" name="profile_index" value="' . esc_attr( (string) $this->current_profile_index() ) . '" />';
		echo '<p><label for="option_name">' . esc_html__( 'Name', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="option_name" name="option_name" required /></p>';
		echo '<p><label for="option_description">' . esc_html__( 'Customer description', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea class="large-text" id="option_description" name="option_description" rows="2"></textarea></p>';
		echo '<p><label for="option_eta">' . esc_html__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="option_eta" name="option_eta" placeholder="3–6 business days" /></p>';
		if ( count( $routes ) > 1 ) {
			$route_legend = $profile->air_sea_allowed
				? __( 'International shipping option', 'cetech-woocommerce-delivery-engine' )
				: __( 'Route', 'cetech-woocommerce-delivery-engine' );
			echo '<p><label for="option_route">' . esc_html( $route_legend ) . '</label><br /><select id="option_route" name="option_route">';
			foreach ( $routes as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		} else {
			$route = array_key_first( $routes ) ?? DeliveryRoute::LocalDelivery->value;
			echo '<input type="hidden" name="option_route" value="' . esc_attr( $route ) . '" />';
		}
		echo '<p><label><input type="checkbox" name="option_active" value="1" checked /> ' . esc_html__( 'Active', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Create and select', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form></div></details>';
	}

	/**
	 * @param list<array<string, mixed>> $compatible
	 * @param list<mixed>                $selected_ids
	 */
	private function render_charge_selector( array $compatible, array $selected_ids ): void {
		$rates = $this->rates->list( [ 'limit' => 100 ] );
		$offer_map = [];
		$offer_ids = [];
		$has_pickup = false;
		foreach ( $compatible as $offer ) {
			$id = (int) ( $offer['id'] ?? 0 );
			$offer_map[ $id ] = $offer;
			$offer_ids[]      = $id;
			if ( DeliveryRoute::StorePickup->value === (string) ( $offer['route'] ?? '' ) ) {
				$has_pickup = true;
			}
		}

		echo '<div class="cetech-de-form-panel">';
		echo '<h3>' . esc_html__( 'Delivery Charge', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		$shown = 0;
		foreach ( $rates as $rate ) {
			$offer_id = (int) ( $rate['delivery_offer_id'] ?? 0 );
			if ( [] !== $offer_ids && ! in_array( $offer_id, $offer_ids, true ) ) {
				continue;
			}
			$offer = $offer_map[ $offer_id ] ?? null;
			if ( is_array( $offer ) && DeliveryRoute::StorePickup->value === (string) ( $offer['route'] ?? '' ) ) {
				continue;
			}
			++$shown;
			echo '<p>' . esc_html( StaffChargeSummary::line( $rate, is_array( $offer ) ? $offer : null ) );
			if ( in_array( $offer_id, $selected_ids, true ) ) {
				echo ' <span class="cetech-de-badge cetech-de-badge--ready">' . esc_html__( 'Linked to selected option', 'cetech-woocommerce-delivery-engine' ) . '</span>';
			}
			echo '</p>';
		}
		if ( $has_pickup ) {
			++$shown;
			echo '<p>' . esc_html( StaffChargeSummary::pickup_none() ) . '</p>';
		}
		if ( 0 === $shown ) {
			echo '<p>' . esc_html__( 'No delivery charges are linked yet.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param list<array<string, mixed>> $compatible
	 */
	private function render_create_charge_panel( array $compatible, int $step = 3 ): void {
		$areas    = $this->zones->list( [ 'limit' => 100 ] );
		$rates    = $this->rates->list( [ 'limit' => 100 ] );
		$existing = [] !== $rates;
		$open     = $existing ? '' : ' open';
		$summary  = $existing
			? __( '+ Create another Delivery Charge', 'cetech-woocommerce-delivery-engine' )
			: __( 'Create Delivery Charge', 'cetech-woocommerce-delivery-engine' );
		echo '<details class="cetech-de-inline-create" id="cetech-de-create-charge"' . $open . '><summary>' . esc_html( $summary ) . '</summary>';
		echo '<div class="cetech-de-inline-create-body">';
		if ( ! $existing ) {
			echo '<p class="cetech-de-empty-hint">' . esc_html__( 'You don\'t have a compatible Delivery Charge yet.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<form method="post">';
		$this->hidden_action( self::ACTION_CREATE_CHARGE, $step );
		echo '<input type="hidden" name="profile_index" value="' . esc_attr( (string) $this->current_profile_index() ) . '" />';
		echo '<p><label for="charge_name">' . esc_html__( 'Charge name', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="charge_name" name="charge_name" required /></p>';
		echo '<fieldset><legend>' . esc_html__( 'How should this delivery charge work?', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p><label><input type="radio" name="charge_style" value="flat" checked /> ' . esc_html__( 'Flat amount per delivery', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="charge_style" value="per_item" /> ' . esc_html__( 'Amount per item', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '<p><label><input type="radio" name="charge_style" value="advanced" /> ' . esc_html__( 'Advanced pricing rules', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '</fieldset>';
		echo '<p><label for="charge_amount">' . esc_html__( 'Amount', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="charge_amount" name="charge_amount" required /> ';
		echo '<span class="description">' . esc_html( $this->entities->store_currency() ) . '</span></p>';
		echo '<p><label for="charge_option_id">' . esc_html__( 'Delivery option', 'cetech-woocommerce-delivery-engine' ) . '</label><br /><select id="charge_option_id" name="charge_option_id">';
		foreach ( $compatible as $offer ) {
			$id = (int) ( $offer['id'] ?? 0 );
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( (string) ( $offer['public_label'] ?? $offer['internal_name'] ?? '' ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="charge_area_id">' . esc_html__( 'Delivery area', 'cetech-woocommerce-delivery-engine' ) . '</label><br /><select id="charge_area_id" name="charge_area_id">';
		echo '<option value="0">' . esc_html__( 'Use or create a default area', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $areas as $area ) {
			$id = (int) ( $area['id'] ?? 0 );
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( (string) ( $area['public_label'] ?? $area['internal_name'] ?? '' ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Advanced pricing can be refined later on the Delivery Charges page.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Create charge', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form></div></details>';
	}

	private function render_pickup_selector( int $selected_id ): void {
		$locations = $this->pickups->list( [ 'limit' => 100 ] );
		echo '<div class="cetech-de-form-panel">';
		echo '<h3>' . esc_html__( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		$active = [];
		foreach ( $locations as $location ) {
			if ( RecordStatus::Active->value === (string) ( $location['status'] ?? '' ) ) {
				$active[] = $location;
			}
		}
		if ( [] === $active ) {
			echo '<p>' . esc_html__( 'No active pickup locations have been added yet. Store Pickup cannot be offered until one exists.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		} else {
			echo '<p id="cetech-de-pickup-list"><label for="pickup_location_id">' . esc_html__( 'Select a pickup location', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
			echo '<select id="pickup_location_id" name="pickup_location_id">';
			echo '<option value="0">' . esc_html__( 'Choose a location', 'cetech-woocommerce-delivery-engine' ) . '</option>';
			foreach ( $active as $location ) {
				$id    = (int) ( $location['id'] ?? 0 );
				$ready = trim( (string) ( $location['readiness_estimate'] ?? '' ) );
				$label = (string) ( $location['location_name'] ?? '' );
				if ( '' !== $ready ) {
					$label .= ' — ' . $ready;
				}
				echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $selected_id, $id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '</div>';
	}

	private function render_create_pickup_panel(): void {
		$locations = $this->pickups->list( [ 'limit' => 100 ] );
		$open      = [] === $locations ? ' open' : '';
		$summary   = [] === $locations
			? __( 'Add Pickup Location', 'cetech-woocommerce-delivery-engine' )
			: __( '+ Add another Pickup Location', 'cetech-woocommerce-delivery-engine' );
		echo '<details class="cetech-de-inline-create" id="cetech-de-create-pickup"' . $open . '><summary>' . esc_html( $summary ) . '</summary>';
		echo '<div class="cetech-de-inline-create-body">';
		if ( [] === $locations ) {
			echo '<p class="cetech-de-empty-hint">' . esc_html__( 'You don\'t have a pickup location yet.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<form method="post">';
		$this->hidden_action( self::ACTION_CREATE_PICKUP, 3 );
		echo '<input type="hidden" name="profile_index" value="' . esc_attr( (string) $this->current_profile_index() ) . '" />';
		echo '<input type="hidden" name="profile_key" value="' . esc_attr( (string) $this->current_profile_key() ) . '" />';
		echo '<p><label for="pickup_name">' . esc_html__( 'Location name', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="pickup_name" name="pickup_name" required /></p>';
		echo '<p><label for="pickup_address">' . esc_html__( 'Public address', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="pickup_address" name="pickup_address" /></p>';
		echo '<p><label for="pickup_city">' . esc_html__( 'City', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="pickup_city" name="pickup_city" /></p>';
		echo '<p><label for="pickup_ready">' . esc_html__( 'Pickup readiness', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="pickup_ready" name="pickup_ready" placeholder="Ready in 2 hours" /></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Create and select', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form></div></details>';
	}

	private function render_nav_buttons( string $primary_label, bool $save_later, ?int $back_step = null, ?array $back_args = null, string $later_status = SetupWizardProgress::STATUS_IN_PROGRESS ): void {
		$step = $this->requested_step( $this->progress->read() );
		echo '<div class="cetech-de-wizard-actions">';
		echo '<div class="cetech-de-wizard-actions-left">';
		if ( $save_later ) {
			echo '<input type="hidden" name="wizard_status" value="' . esc_attr( $later_status ) . '" />';
			echo '<input type="hidden" name="profile_index" value="' . esc_attr( (string) $this->current_profile_index() ) . '" />';
			wp_nonce_field( self::ACTION_SAVE_LATER, 'cetech_de_save_later_nonce' );
			echo '<button type="submit" name="cetech_de_save_later" value="1" class="button button-secondary">' . esc_html__( 'Save & finish later', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		}
		echo '</div><div class="cetech-de-wizard-actions-right">';
		if ( null !== $back_args ) {
			echo '<a class="button button-secondary" href="' . esc_url( $this->url( $back_args ) ) . '">' . esc_html__( 'Back', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
		} elseif ( null !== $back_step ) {
			echo '<a class="button button-secondary" href="' . esc_url( $this->url( [ 'step' => $back_step ] ) ) . '">' . esc_html__( 'Back', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
		}
		echo '<button type="submit" class="button button-primary">' . esc_html( $primary_label ) . '</button>';
		echo '</div></div>';
	}

	private function render_standalone_footer( array $back_args, string $continue_url, string $later_status = SetupWizardProgress::STATUS_IN_PROGRESS ): void {
		$current_step = $this->requested_step( $this->progress->read() );
		echo '<form method="post" class="cetech-de-wizard-actions">';
		echo '<div class="cetech-de-wizard-actions-left">';
		$this->hidden_action( self::ACTION_SAVE_LATER, $current_step );
		echo '<input type="hidden" name="wizard_status" value="' . esc_attr( $later_status ) . '" />';
		echo '<input type="hidden" name="profile_index" value="' . esc_attr( (string) $this->current_profile_index() ) . '" />';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Save & finish later', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		echo '</div><div class="cetech-de-wizard-actions-right">';
		echo '<a class="button button-secondary" href="' . esc_url( $this->url( $back_args ) ) . '">' . esc_html__( 'Back', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
		echo '<a class="button button-primary" href="' . esc_url( $continue_url ) . '">' . esc_html__( 'Continue', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';
	}

	private function hidden_action( string $action, int $step ): void {
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="setup_step" value="' . esc_attr( (string) $step ) . '" />';
		AdminFormHelper::nonce_field( $action );
	}

	private function verified( string $action ): bool {
		if ( self::ACTION_SAVE_LATER === $action && isset( $_POST['cetech_de_save_later_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( (string) $_POST['cetech_de_save_later_nonce'] ) );

			return (bool) wp_verify_nonce( $nonce, self::ACTION_SAVE_LATER ) && current_user_can( 'manage_delivery_settings' );
		}

		return $this->action_handler->verify_post( $action, $action, 'manage_delivery_settings', self::SLUG );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function requested_step( array $state ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['step'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return min( 6, max( 1, absint( wp_unslash( $_GET['step'] ) ) ) );
		}

		return min( 6, max( 1, (int) $state['step'] ) );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function requested_profile_index( array $state ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['profile_index'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return max( 0, absint( wp_unslash( $_GET['profile_index'] ) ) );
		}

		return max( 0, (int) $state['profile_index'] );
	}

	private function current_profile_index(): int {
		return $this->requested_profile_index( $this->progress->read() );
	}

	private function current_profile_key(): string {
		$state  = $this->progress->read();
		$active = $this->active_profiles( $state );
		$index  = $this->current_profile_index();

		return $active[ $index ] ?? '';
	}

	/**
	 * @param array<string, mixed> $state
	 *
	 * @return list<string>
	 */
	private function active_profiles( array $state ): array {
		$active = $state['draft']['active_profiles'] ?? [];
		if ( [] === $active ) {
			$active = $this->settings->active_profile_keys();
		}

		return array_values( array_filter( $active, static fn ( $key ): bool => FulfilmentProfileRegistry::has( (string) $key ) ) );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function primary_profile( array $state ): string {
		$primary = (string) ( $state['draft']['primary_profile'] ?? '' );
		if ( FulfilmentProfileRegistry::has( $primary ) ) {
			return $primary;
		}

		return (string) ( $this->settings->primary_profile_key() ?? '' );
	}

	/**
	 * @return list<string>
	 */
	private function posted_profiles(): array {
		$active = [];
		if ( isset( $_POST['active_profiles'] ) && is_array( $_POST['active_profiles'] ) ) {
			foreach ( wp_unslash( $_POST['active_profiles'] ) as $key ) {
				$key = sanitize_key( (string) $key );
				if ( FulfilmentProfileRegistry::has( $key ) ) {
					$active[] = $key;
				}
			}
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * @param array<string, mixed> $draft
	 */
	private function persist_progress( string $status, int $step, int $profile_index, array $draft, bool $review_mode ): void {
		$this->progress->save(
			[
				'status'        => $status,
				'step'          => $step,
				'profile_index' => $profile_index,
				'review_mode'   => $review_mode,
				'draft'         => $draft,
			]
		);
	}

	/**
	 * @return list<array{number: int, label: string}>
	 */
	private function step_labels(): array {
		return [
			[ 'number' => 1, 'label' => __( 'Store Setup', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'number' => 2, 'label' => __( 'Fulfilment Types', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'number' => 3, 'label' => __( 'Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'number' => 4, 'label' => __( 'Areas & Charges', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'number' => 5, 'label' => __( 'Apply to Products', 'cetech-woocommerce-delivery-engine' ) ],
			[ 'number' => 6, 'label' => __( 'Finish', 'cetech-woocommerce-delivery-engine' ) ],
		];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function page_title( array $state, int $step ): string {
		if ( ! empty( $state['review_mode'] ) ) {
			return __( 'Review delivery setup', 'cetech-woocommerce-delivery-engine' );
		}

		return AdminLanguage::wizard_title();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function page_subtitle( array $state, int $step ): string {
		if ( ! empty( $state['review_mode'] ) ) {
			return __( 'You are reviewing the current store configuration. Existing product and variation exceptions stay protected.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $this->should_show_prior_install_notice( $state ) ) {
			return $this->prior_install_notice();
		}

		return __( 'Set up how products are fulfilled and delivered across your store. We’ll guide you through the essentials. You can change these settings later.', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function should_show_prior_install_notice( array $state ): bool {
		if ( ! empty( $state['review_mode'] ) ) {
			return false;
		}

		$op = $this->operational_state->current();

		return $op->prior_install && ! $op->sitewide_setup_complete;
	}

	private function prior_install_notice(): string {
		return __( 'This is an existing Delivery Engine installation. Existing delivery configuration is still serving customers while you finish Delivery Engine setup.', 'cetech-woocommerce-delivery-engine' );
	}

	private function primary_choice_copy( FulfilmentProfile $profile ): string {
		return match ( $profile->key ) {
			'in_warehouse' => __( 'Products are stored locally and delivered to customers.', 'cetech-woocommerce-delivery-engine' ),
			'in_store' => __( 'Products are available from a store and may support delivery or pickup.', 'cetech-woocommerce-delivery-engine' ),
			'international_fulfilment' => __( 'Products require international Air and/or Sea delivery.', 'cetech-woocommerce-delivery-engine' ),
			default => $profile->description,
		};
	}

	private function profile_setup_copy( FulfilmentProfile $profile ): string {
		return match ( $profile->key ) {
			'in_warehouse' => __( 'These settings become the normal delivery rules for warehouse products unless a product has its own exception.', 'cetech-woocommerce-delivery-engine' ),
			'in_store' => __( 'These settings apply to products physically available from your stores.', 'cetech-woocommerce-delivery-engine' ),
			'international_fulfilment' => __( 'These defaults apply to products that require international fulfilment.', 'cetech-woocommerce-delivery-engine' ),
			default => $profile->description,
		};
	}

	private function options_legend( FulfilmentProfile $profile ): string {
		if ( $profile->air_sea_allowed ) {
			return __( 'International shipping options', 'cetech-woocommerce-delivery-engine' );
		}

		return __( 'Delivery Options', 'cetech-woocommerce-delivery-engine' );
	}

	private function profile_icon( string $key ): string {
		return match ( $key ) {
			'in_warehouse' => 'dashicons-building',
			'in_store' => 'dashicons-store',
			'international_fulfilment' => 'dashicons-airplane',
			default => 'dashicons-admin-site-alt3',
		};
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function compatible_offers( FulfilmentProfile $profile ): array {
		return DeliveryOptionCompatibility::filter_offers(
			$this->offers->list( [ 'limit' => 200 ] ),
			$profile
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function route_choices_for_profile( FulfilmentProfile $profile ): array {
		$map = [
			DeliveryRoute::LocalDelivery->value => __( 'Local delivery', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::StorePickup->value => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Air->value => __( 'Air Shipping', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Sea->value => __( 'Sea Shipping', 'cetech-woocommerce-delivery-engine' ),
		];

		$choices = [];
		foreach ( DeliveryOptionCompatibility::allowed_routes( $profile ) as $route ) {
			if ( isset( $map[ $route ] ) ) {
				$choices[ $route ] = $map[ $route ];
			}
		}

		return $choices;
	}

	private function route_label( string $route ): string {
		return match ( $route ) {
			DeliveryRoute::LocalDelivery->value => __( 'Local delivery', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::StorePickup->value => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Air->value => __( 'Air Shipping', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Sea->value => __( 'Sea Shipping', 'cetech-woocommerce-delivery-engine' ),
			default => '',
		};
	}

	/**
	 * @param list<mixed> $selected_ids
	 */
	private function infer_instore_mode( string $choice, array $selected_ids ): string {
		$has_delivery = false;
		$has_pickup   = false;
		foreach ( $selected_ids as $id ) {
			$offer = $this->offers->findById( (int) $id );
			$route = (string) ( $offer['route'] ?? '' );
			if ( DeliveryRoute::StorePickup->value === $route ) {
				$has_pickup = true;
			} else {
				$has_delivery = true;
			}
		}

		if ( $has_delivery && $has_pickup ) {
			return 'both';
		}

		return FulfilmentChoice::StorePickup->value === $choice
			? FulfilmentChoice::StorePickup->value
			: FulfilmentChoice::Delivery->value;
	}

	/**
	 * @param list<array<string, mixed>> $rates
	 * @param list<array<string, mixed>> $offers
	 *
	 * @return array{options: string, charge: string}
	 */
	private function charges_for_zone( array $rates, int $zone_id, array $offers ): array {
		$option_names = [];
		$charge_names = [];
		$offer_map    = [];
		foreach ( $offers as $offer ) {
			$offer_map[ (int) ( $offer['id'] ?? 0 ) ] = $offer;
		}

		foreach ( $rates as $rate ) {
			if ( (int) ( $rate['destination_zone_id'] ?? 0 ) !== $zone_id ) {
				continue;
			}
			$offer_id = (int) ( $rate['delivery_offer_id'] ?? 0 );
			$offer    = $offer_map[ $offer_id ] ?? null;
			if ( is_array( $offer ) ) {
				$option_label = StaffChargeSummary::option_label( $offer );
				if ( '' !== $option_label ) {
					$option_names[] = $option_label;
				}
			}
			$charge_names[] = StaffChargeSummary::heading( $rate, is_array( $offer ) ? $offer : null );
		}

		return [
			'options' => [] === $option_names ? '—' : implode( ', ', array_unique( $option_names ) ),
			'charge'  => [] === $charge_names ? '—' : implode( ', ', array_unique( $charge_names ) ),
		];
	}

	/**
	 * @param array<string, mixed> $state
	 *
	 * @return list<string>
	 */
	private function configured_profile_labels( array $state ): array {
		$labels = [];
		foreach ( $this->active_profiles( $state ) as $key ) {
			$summary = $this->summaries->for_profile( $key );
			if ( $summary['configured'] ) {
				$labels[] = (string) $summary['label'];
			}
		}

		return [] === $labels ? [ __( 'None configured yet', 'cetech-woocommerce-delivery-engine' ) ] : $labels;
	}

	/**
	 * @param array<string, scalar> $args
	 */
	public function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::SLUG ], $args ),
			admin_url( 'admin.php' )
		);
	}
}
