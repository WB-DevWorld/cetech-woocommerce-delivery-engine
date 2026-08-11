<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use WC_Product;

/**
 * Product-page delivery selector (feature-flagged, off by default).
 *
 * Display-only when cart capture is disabled. Radio submission inside add-to-cart form when capture is enabled.
 * Stage 6A: variable products render an AJAX-driven shell when variable ECR flags are enabled.
 * Does not calculate shipping prices or write order meta.
 */
final class ProductDeliverySelectorRenderer {

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ProductDeliveryConfigurationSourceInterface $configuration_source,
		private ProductDeliveryOptionsBuilder $options_builder
	) {
	}

	public function register(): void {
		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			return;
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return;
		}

		if ( $this->is_capture_enabled() ) {
			add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_form_controls' ], 10 );
			add_action( 'woocommerce_single_product_summary', [ $this, 'render_variable_notice' ], 25 );
		} else {
			add_action( 'woocommerce_single_product_summary', [ $this, 'render' ], 25 );
		}
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
			if ( $interactive && $this->is_variable_ecr_enabled() ) {
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
			$this->render_interactive_options( $options );
		} else {
			$this->render_display_options( $options );
		}
	}

	private function render_variable_shell(): void {
		$product = $this->resolve_product();
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;

		echo '<div class="cetech-de-product-delivery-selector cetech-de-product-delivery-selector--variable" data-cetech-de-variable-selector="1" data-product-id="' . esc_attr( (string) $product_id ) . '">';
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
		$groups = $this->group_options( $options );

		echo '<div class="cetech-de-product-delivery-selector">';
		echo '<h3 class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</h3>';

		foreach ( $groups as $group ) {
			echo '<div class="cetech-de-delivery-availability">';
			echo '<h4 class="cetech-de-delivery-availability__heading">' . esc_html( $group['availability_label'] ) . '</h4>';
			echo '<p class="cetech-de-delivery-availability__choice"><em>' . esc_html( $group['choice_label'] ) . '</em></p>';
			echo '<ul class="cetech-de-delivery-options">';

			foreach ( $group['options'] as $option ) {
				$this->render_display_option( $option );
			}

			echo '</ul>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render_interactive_options( array $options ): void {
		$available = array_filter(
			$options,
			static fn ( ProductDeliveryOption $option ): bool => $option->is_available
		);

		if ( [] === $available ) {
			echo '<p class="cetech-de-delivery-selector__notice">';
			echo esc_html__( 'Delivery is currently unavailable for this product.', 'cetech-woocommerce-delivery-engine' );
			echo '</p>';

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display-only repopulation of customer choice.
		$selected = isset( $_POST[ CartDeliverySelectionCapture::POST_FIELD ] )
			? ProductDeliveryOptionsBuilder::normalizeDisplayKey( wp_unslash( (string) $_POST[ CartDeliverySelectionCapture::POST_FIELD ] ) )
			: '';

		echo '<fieldset class="cetech-de-product-delivery-selector cetech-de-product-delivery-selector--interactive">';
		echo '<legend class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</legend>';

		foreach ( $available as $option ) {
			$this->render_radio_option( $option, $selected );
		}

		echo '</fieldset>';
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return array<string, array{availability_label: string, choice_label: string, options: list<ProductDeliveryOption>}>
	 */
	private function group_options( array $options ): array {
		$groups = [];

		foreach ( $options as $option ) {
			$group_key = $option->fulfilment_availability . ':' . $option->fulfilment_choice;
			$groups[ $group_key ]   = $groups[ $group_key ] ?? [
				'availability_label' => $option->fulfilment_availability_label,
				'choice_label'       => $option->fulfilment_choice_label,
				'options'            => [],
			];
			$groups[ $group_key ]['options'][] = $option;
		}

		return $groups;
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
		echo '<span class="cetech-de-delivery-option__label">' . esc_html( $label ) . '</span>';

		if ( null !== $option->delivery_offer_public_description && '' !== $option->delivery_offer_public_description ) {
			echo '<span class="cetech-de-delivery-option__description"> ' . esc_html( $option->delivery_offer_public_description ) . '</span>';
		}

		if ( null !== $option->estimate_text && '' !== $option->estimate_text ) {
			echo '<span class="cetech-de-delivery-option__estimate"> ' . esc_html( $option->estimate_text ) . '</span>';
		}

		if ( ! $option->is_available && null !== $option->unavailable_reason && '' !== $option->unavailable_reason ) {
			echo '<span class="cetech-de-delivery-option__unavailable-reason"> ' . esc_html( $option->unavailable_reason ) . '</span>';
		}

		echo '</li>';
	}

	private function render_radio_option( ProductDeliveryOption $option, string $selected ): void {
		$label = $option->delivery_offer_public_label ?? '';

		if ( '' === $label ) {
			return;
		}

		$input_id = 'cetech-de-delivery-option-' . sanitize_html_class( $option->display_key );
		$checked  = $selected === $option->display_key;

		echo '<p class="cetech-de-delivery-option cetech-de-delivery-option--radio">';
		echo '<label for="' . esc_attr( $input_id ) . '">';
		echo '<input type="radio" name="' . esc_attr( CartDeliverySelectionCapture::POST_FIELD ) . '" id="' . esc_attr( $input_id ) . '" value="' . esc_attr( $option->display_key ) . '"' . ( $checked ? ' checked="checked"' : '' ) . ' required="required" /> ';
		echo '<span class="cetech-de-delivery-option__label">' . esc_html( $label ) . '</span>';

		if ( null !== $option->delivery_offer_public_description && '' !== $option->delivery_offer_public_description ) {
			echo '<span class="cetech-de-delivery-option__description"> ' . esc_html( $option->delivery_offer_public_description ) . '</span>';
		}

		if ( null !== $option->estimate_text && '' !== $option->estimate_text ) {
			echo '<span class="cetech-de-delivery-option__estimate"> ' . esc_html( $option->estimate_text ) . '</span>';
		}

		echo '</label>';
		echo '</p>';
	}

	private function render_notice( string $message ): void {
		echo '<div class="cetech-de-product-delivery-selector">';
		echo '<h3 class="cetech-de-delivery-selector__title">' . esc_html__( 'Delivery options', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="cetech-de-delivery-selector__notice">' . esc_html( $message ) . '</p>';
		echo '</div>';
	}
}
