<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\CustomerContext\CustomerBrowsingLocationStore;
use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\MatchingLocationOptionsEndpoint;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Product;

/**
 * Product-page delivery selector (feature-flagged, off by default).
 *
 * Display-only when cart capture is disabled. Radio submission inside add-to-cart form when capture is enabled.
 * Stage 6A: variable products render an AJAX-driven shell when variable ECR flags are enabled.
 * Stage 13F: compact public hierarchy (label + estimate). Does not calculate shipping prices or write order meta.
 */
final class ProductDeliverySelectorRenderer {

	public const STYLE_HANDLE = 'cetech-de-product-delivery-selector';

	public const SCRIPT_HANDLE = 'cetech-de-product-delivery-selector';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ProductDeliveryConfigurationSourceInterface $configuration_source,
		private ProductDeliveryOptionsBuilder $options_builder,
		private ?CustomerBrowsingLocationStore $browsing_store = null,
		private ?LocationAwareDeliveryOptions $location_options = null
	) {
	}

	public function register(): void {
		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			return;
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		if ( $this->is_capture_enabled() ) {
			add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_form_controls' ], 10 );
			add_action( 'woocommerce_single_product_summary', [ $this, 'render_variable_notice' ], 25 );
		} else {
			add_action( 'woocommerce_single_product_summary', [ $this, 'render' ], 25 );
		}
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '1.0.0-rc.3';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';
		$deps    = [];

		if ( function_exists( 'wp_script_is' ) && wp_script_is( 'wc-country-select', 'registered' ) ) {
			$deps[] = 'wc-country-select';
			wp_enqueue_script( 'wc-country-select' );
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . 'assets/frontend/product-delivery-selector.css',
			[],
			$version
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$base . 'assets/frontend/product-delivery-selector.js',
			$deps,
			$version,
			true
		);

		$product    = $this->resolve_product();
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'cetechDeMatchingLocation',
			[
				'ajaxUrl'   => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
				'action'    => MatchingLocationOptionsEndpoint::ACTION,
				'nonce'     => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( MatchingLocationOptionsEndpoint::ACTION ) : '',
				'productId' => $product_id,
				'i18n'      => [
					'needLocation' => __( 'Enter your delivery location to see delivery options.', 'cetech-woocommerce-delivery-engine' ),
					'unavailable' => __( 'Delivery is not available to this location.', 'cetech-woocommerce-delivery-engine' ),
					'loading'     => __( 'Updating delivery options…', 'cetech-woocommerce-delivery-engine' ),
					'error'       => __( 'Delivery options are temporarily unavailable. Please try again.', 'cetech-woocommerce-delivery-engine' ),
					'delivery'    => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
					'storePickup' => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
					'estimated'   => __( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ),
				],
				'postField' => CartDeliverySelectionCapture::POST_FIELD,
			]
		);
	}

	public function render_variable_notice(): void {
		if ( ! $this->is_capture_enabled() ) {
			return;
		}

		if ( $this->is_variable_ecr_enabled() ) {
			// Interactive shell is rendered inside the add-to-cart form.
			return;
		}

		$product = $this->resolve_product();

		if ( null === $product || ! $product->is_type( 'variable' ) ) {
			return;
		}

		$this->render_notice(
			__(
				'Delivery options may update after selecting a product option.',
				'cetech-woocommerce-delivery-engine'
			)
		);
	}

	public function render(): void {
		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) || $this->is_capture_enabled() ) {
			return;
		}

		$this->render_for_product( false );
	}

	public function render_form_controls(): void {
		if ( ! $this->is_capture_enabled() ) {
			return;
		}

		$this->render_for_product( true );
	}

	private function is_capture_enabled(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' );
	}

	private function is_variable_ecr_enabled(): bool {
		return $this->feature_flags->is_enabled( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG )
			&& $this->feature_flags->is_enabled( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG );
	}

	private function render_for_product( bool $interactive ): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = $this->resolve_product();

		if ( null === $product ) {
			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			if ( $this->is_variable_ecr_enabled() ) {
				$this->render_variable_shell();

				return;
			}

			$this->render_notice(
				__(
					'Delivery options may update after selecting a product option.',
					'cetech-woocommerce-delivery-engine'
				)
			);

			return;
		}

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
			return;
		}

		$target_type = $product->is_type( 'variation' )
			? ProductTargetType::Variation->value
			: ProductTargetType::Product->value;
		$target_id = (int) $product->get_id();

		$result = $this->configuration_source->resolve( $target_type, $target_id )->result;

		if ( ! $result->success ) {
			return;
		}

		$options = $this->options_builder->buildFromResolution( $result );

		if ( [] === $options ) {
			$this->render_notice(
				__(
					'Delivery options are not available for this product.',
					'cetech-woocommerce-delivery-engine'
				)
			);

			return;
		}

		if ( $interactive ) {
			$this->render_interactive_options( $options, (int) $product->get_id() );
		} else {
			$this->render_display_options( $options );
		}
	}

	private function render_variable_shell(): void {
		$product = $this->resolve_product();
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;

		echo '<div class="cetech-de-product-delivery-selector cetech-de-product-delivery-selector--variable" data-cetech-de-variable-selector="1" data-cetech-de-selector="1" data-product-id="' . esc_attr( (string) $product_id ) . '">';
		echo '<fieldset class="cetech-de-delivery-selector__fieldset">';
		echo '<legend class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<div class="cetech-de-delivery-selector__status" role="status" aria-live="polite" data-cetech-de-status>';
		echo esc_html__( 'Select your product options to see delivery choices.', 'cetech-woocommerce-delivery-engine' );
		echo '</div>';
		echo '<div class="cetech-de-delivery-selector__options" data-cetech-de-options></div>';
		echo '<input type="hidden" name="' . esc_attr( CartDeliverySelectionCapture::POST_VARIATION_FIELD ) . '" value="" data-cetech-de-variation-id disabled="disabled" />';
		echo '</fieldset>';
		echo '</div>';
	}

	private function resolve_product(): ?WC_Product {
		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$post_id = get_the_ID();
		$loaded  = is_int( $post_id ) ? wc_get_product( $post_id ) : false;

		return $loaded instanceof WC_Product ? $loaded : null;
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render_display_options( array $options ): void {
		echo '<div class="cetech-de-product-delivery-selector">';
		echo '<h3 class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<ul class="cetech-de-delivery-options">';

		foreach ( $options as $option ) {
			$this->render_display_option( $option );
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render_interactive_options( array $options, int $product_id = 0 ): void {
		$available = array_values(
			array_filter(
				$options,
				static fn ( ProductDeliveryOption $option ): bool => $option->is_available
			)
		);

		if ( [] === $available ) {
			echo '<p class="cetech-de-delivery-selector__notice">';
			echo esc_html__( 'Delivery is currently unavailable for this product.', 'cetech-woocommerce-delivery-engine' );
			echo '</p>';

			return;
		}

		$browsing  = $this->browsing_store instanceof CustomerBrowsingLocationStore ? $this->browsing_store->get() : null;
		$requires  = $this->location_options instanceof LocationAwareDeliveryOptions
			? $this->location_options->delivery_requires_matching_location( $available )
			: false;
		$currency  = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'GHS';
		$visible   = $available;
		if ( $this->location_options instanceof LocationAwareDeliveryOptions && $requires ) {
			$visible = $this->location_options->filter( $available, $browsing, $currency );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display-only repopulation of customer choice.
		$posted = isset( $_POST[ CartDeliverySelectionCapture::POST_FIELD ] )
			? ProductDeliveryOptionsBuilder::normalizeDisplayKey( wp_unslash( (string) $_POST[ CartDeliverySelectionCapture::POST_FIELD ] ) )
			: '';
		$selected = '' !== $posted ? $posted : ProductDeliveryOptionsBuilder::defaultDisplayKey( $visible );
		$groups     = ProductDeliveryOptionsBuilder::groupByChoice( $visible );
		$has_switch = [] !== $groups[ FulfilmentChoice::Delivery->value ]
			&& [] !== $groups[ FulfilmentChoice::StorePickup->value ];
		$active_choice = $this->active_choice( $visible !== [] ? $visible : $available, $selected, $has_switch || [] !== $groups[ FulfilmentChoice::StorePickup->value ] );

		echo '<fieldset class="cetech-de-product-delivery-selector cetech-de-product-delivery-selector--interactive" data-cetech-de-selector="1" data-product-id="' . esc_attr( (string) $product_id ) . '">';
		echo '<legend class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</legend>';

		if ( $requires ) {
			echo MatchingLocationFieldRenderer::render( $browsing ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns escaped HTML.
		}

		echo '<div class="cetech-de-delivery-selector__status" role="status" aria-live="polite" data-cetech-de-status>';
		if ( $requires && ( ! $browsing instanceof MatchingLocation || ! $browsing->isPresent() ) ) {
			echo esc_html__( 'Enter your delivery location to see delivery options.', 'cetech-woocommerce-delivery-engine' );
		}
		echo '</div>';
		echo '<div class="cetech-de-delivery-selector__options" data-cetech-de-options>';

		if ( $has_switch ) {
			$this->render_choice_switch( $active_choice );
		}

		if ( [] !== $groups[ FulfilmentChoice::Delivery->value ] ) {
			$hidden = $has_switch && FulfilmentChoice::Delivery->value !== $active_choice;
			echo '<div class="cetech-de-delivery-option-group" data-cetech-de-choice-panel="' . esc_attr( FulfilmentChoice::Delivery->value ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
			foreach ( $groups[ FulfilmentChoice::Delivery->value ] as $option ) {
				$this->render_radio_option( $option, $selected, $hidden );
			}
			echo '</div>';
		}

		if ( [] !== $groups[ FulfilmentChoice::StorePickup->value ] ) {
			$hidden = $has_switch && FulfilmentChoice::StorePickup->value !== $active_choice;
			echo '<div class="cetech-de-delivery-option-group cetech-de-delivery-option-group--pickup" data-cetech-de-choice-panel="' . esc_attr( FulfilmentChoice::StorePickup->value ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
			foreach ( $groups[ FulfilmentChoice::StorePickup->value ] as $option ) {
				$this->render_radio_option( $option, $selected, $hidden );
				$this->render_pickup_details( $option );
			}
			echo '</div>';
		}

		echo '</div></fieldset>';
	}

	/**
	 * @param list<ProductDeliveryOption> $available
	 */
	private function active_choice( array $available, string $selected, bool $has_switch ): string {
		if ( '' !== $selected ) {
			foreach ( $available as $option ) {
				if ( $option->display_key === $selected ) {
					return $option->fulfilment_choice;
				}
			}
		}

		if ( $has_switch ) {
			return FulfilmentChoice::Delivery->value;
		}

		return $available[0]->fulfilment_choice;
	}

	private function render_choice_switch( string $active_choice ): void {
		echo '<div class="cetech-de-fulfilment-choice" role="radiogroup" aria-label="' . esc_attr__( 'Fulfilment', 'cetech-woocommerce-delivery-engine' ) . '">';
		foreach (
			[
				FulfilmentChoice::Delivery->value    => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
				FulfilmentChoice::StorePickup->value => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
			] as $value => $label
		) {
			$id      = 'cetech-de-fulfilment-ui-' . sanitize_html_class( $value );
			$checked = $active_choice === $value;
			echo '<p class="cetech-de-fulfilment-choice__option">';
			echo '<label for="' . esc_attr( $id ) . '">';
			echo '<input type="radio" name="cetech_de_fulfilment_ui" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" data-cetech-de-choice-switch="1"' . ( $checked ? ' checked="checked"' : '' ) . ' /> ';
			echo esc_html( $label );
			echo '</label></p>';
		}
		echo '</div>';
	}

	private function render_display_option( ProductDeliveryOption $option ): void {
		$label = $option->delivery_offer_public_label ?? '';

		if ( '' === $label ) {
			return;
		}

		$class = $option->is_available
			? 'cetech-de-delivery-option'
			: 'cetech-de-delivery-option cetech-de-delivery-option--unavailable';

		echo '<li class="' . esc_attr( $class ) . '">';
		echo '<div class="cetech-de-delivery-option__body">';
		echo '<span class="cetech-de-delivery-option__label">' . esc_html( $label ) . '</span>';
		$this->render_estimate_line( $option );
		echo '</div>';

		if ( ! $option->is_available && null !== $option->unavailable_reason && '' !== $option->unavailable_reason ) {
			echo '<span class="cetech-de-delivery-option__unavailable-reason">' . esc_html( $option->unavailable_reason ) . '</span>';
		}

		echo '</li>';
	}

	private function render_radio_option( ProductDeliveryOption $option, string $selected, bool $disabled = false ): void {
		$label = $option->delivery_offer_public_label ?? '';

		if ( '' === $label ) {
			return;
		}

		$input_id = 'cetech-de-delivery-option-' . sanitize_html_class( $option->display_key );
		$checked  = $selected === $option->display_key;
		$choice   = sanitize_html_class( $option->fulfilment_choice );

		echo '<p class="cetech-de-delivery-option cetech-de-delivery-option--radio" data-cetech-de-choice="' . esc_attr( $choice ) . '">';
		echo '<label for="' . esc_attr( $input_id ) . '" class="cetech-de-delivery-option__label-wrap">';
		echo '<input type="radio" name="' . esc_attr( CartDeliverySelectionCapture::POST_FIELD ) . '" id="' . esc_attr( $input_id ) . '" value="' . esc_attr( $option->display_key ) . '"' . ( $checked ? ' checked="checked"' : '' ) . ( $disabled ? ' disabled="disabled"' : '' ) . ' required="required" />';
		echo '<span class="cetech-de-delivery-option__body">';
		echo '<span class="cetech-de-delivery-option__label">' . esc_html( $label ) . '</span>';
		if ( FulfilmentChoice::StorePickup->value !== $option->fulfilment_choice ) {
			$this->render_estimate_line( $option );
		}
		echo '</span>';
		echo '</label>';
		echo '</p>';
	}

	private function render_pickup_details( ProductDeliveryOption $option ): void {
		if ( FulfilmentChoice::StorePickup->value !== $option->fulfilment_choice ) {
			return;
		}

		echo '<div class="cetech-de-pickup-details">';

		if ( null !== $option->pickup_location_label && '' !== $option->pickup_location_label ) {
			echo '<p class="cetech-de-pickup-details__row"><span class="cetech-de-pickup-details__label">' . esc_html( DeliveryPresentationLabels::pickup_location() ) . '</span> ';
			echo '<span class="cetech-de-pickup-details__value">' . esc_html( $option->pickup_location_label ) . '</span></p>';
		}

		if ( null !== $option->pickup_address && '' !== $option->pickup_address ) {
			$address = PickupLocationAddressFormatter::format( $option->pickup_address );
			if ( '' !== $address ) {
				echo '<p class="cetech-de-pickup-details__row"><span class="cetech-de-pickup-details__label">' . esc_html( DeliveryPresentationLabels::pickup_address() ) . '</span> ';
				echo '<span class="cetech-de-pickup-details__value">' . esc_html( $address ) . '</span></p>';
			}
		}

		if ( null !== $option->estimate_text && '' !== trim( $option->estimate_text ) ) {
			echo '<p class="cetech-de-pickup-details__row"><span class="cetech-de-pickup-details__label">' . esc_html( DeliveryPresentationLabels::ready_for_pickup() ) . '</span> ';
			echo '<span class="cetech-de-pickup-details__value">' . esc_html( DeliveryPresentationLabels::strip_estimated_prefix( $option->estimate_text ) ) . '</span></p>';
		}

		if ( null !== $option->pickup_instructions && '' !== $option->pickup_instructions ) {
			echo '<p class="cetech-de-pickup-details__row"><span class="cetech-de-pickup-details__label">' . esc_html( DeliveryPresentationLabels::pickup_instructions() ) . '</span> ';
			echo '<span class="cetech-de-pickup-details__value">' . esc_html( $option->pickup_instructions ) . '</span></p>';
		}

		echo '</div>';
	}

	private function render_estimate_line( ProductDeliveryOption $option ): void {
		if ( null === $option->estimate_text || '' === trim( $option->estimate_text ) ) {
			return;
		}

		$line = DeliveryPresentationLabels::format_product_estimate_line(
			$option->estimate_text,
			$option->fulfilment_choice
		);

		if ( '' === $line ) {
			return;
		}

		echo '<span class="cetech-de-delivery-option__estimate">' . esc_html( $line ) . '</span>';
	}

	private function render_notice( string $message ): void {
		echo '<div class="cetech-de-product-delivery-selector">';
		echo '<h3 class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="cetech-de-delivery-selector__notice">' . esc_html( $message ) . '</p>';
		echo '</div>';
	}
}
