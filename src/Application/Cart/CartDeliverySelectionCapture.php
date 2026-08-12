<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use WC_Product;

/**
 * Validates and stores product delivery selections in WooCommerce cart item data.
 *
 * Does not calculate shipping, alter checkout, or write order data.
 */
final class CartDeliverySelectionCapture {

	public const POST_FIELD = 'cetech_de_delivery_option_key';

	public const POST_VARIATION_FIELD = 'cetech_de_delivery_variation_id';

	public const CART_SELECTION_KEY = 'cetech_de_delivery_selection';

	public const CART_SUMMARY_KEY = 'cetech_de_delivery_selection_summary';

	public const CART_HASH_KEY = 'cetech_de_delivery_selection_hash';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ProductDeliveryConfigurationSourceInterface $configuration_source,
		private ProductDeliveryOptionsBuilder $options_builder,
		private ProductDeliverySelectionValidator $selection_validator
	) {
	}

	public function register(): void {
		if ( ! $this->is_capture_enabled() ) {
			return;
		}

		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'restore_cart_item_from_session' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_data' ], 10, 2 );
	}

	public function is_capture_enabled(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	/**
	 * @param array<string, mixed> $cart_item_data
	 */
	public function validate_add_to_cart(
		bool $passed,
		int $product_id,
		int $quantity,
		int $variation_id = 0,
		array $variations = [],
		array $cart_item_data = []
	): bool {
		if ( ! $passed || ! $this->is_capture_enabled() || ! $this->should_apply_capture( $product_id, $variation_id ) ) {
			return $passed;
		}

		$assessment = $this->assess_product_selection( $product_id, $variation_id );

		if ( 'none' === $assessment['requirement'] ) {
			return $passed;
		}

		if ( 'blocked' === $assessment['requirement'] ) {
			wc_add_notice(
				__( 'Delivery is currently unavailable for this product.', 'cetech-woocommerce-delivery-engine' ),
				'error'
			);

			return false;
		}

		$display_key = $this->read_submitted_display_key();

		if ( '' === $display_key ) {
			wc_add_notice(
				__( 'Please select a delivery option for this product.', 'cetech-woocommerce-delivery-engine' ),
				'error'
			);

			return false;
		}

		if ( $variation_id > 0 && ! $this->submitted_variation_matches( $variation_id ) ) {
			wc_add_notice(
				__( 'Your selected delivery option is no longer available. Please choose again.', 'cetech-woocommerce-delivery-engine' ),
				'error'
			);

			return false;
		}

		$result = $this->selection_validator->validate(
			$product_id,
			$variation_id > 0 ? $variation_id : null,
			$display_key
		);

		if ( ! $result->valid ) {
			wc_add_notice(
				__( 'The selected delivery option is no longer available. Please choose another option.', 'cetech-woocommerce-delivery-engine' ),
				'error'
			);

			return false;
		}

		return $passed;
	}

	/**
	 * @param array<string, mixed> $cart_item_data
	 *
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
		if ( ! $this->is_capture_enabled() || ! $this->should_apply_capture( $product_id, $variation_id ) ) {
			return $cart_item_data;
		}

		$assessment = $this->assess_product_selection( $product_id, $variation_id );

		if ( 'required' !== $assessment['requirement'] ) {
			return $cart_item_data;
		}

		$display_key = $this->read_submitted_display_key();

		if ( '' === $display_key ) {
			return $cart_item_data;
		}

		$result = $this->selection_validator->validate(
			$product_id,
			$variation_id > 0 ? $variation_id : null,
			$display_key
		);

		if ( ! $result->valid || ! is_array( $result->intent ) || ! is_array( $result->matched_option ) ) {
			return $cart_item_data;
		}

		$summary = self::buildPublicSummary( $result->matched_option );

		if ( [] === $summary ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_SELECTION_KEY ] = $result->intent;
		$cart_item_data[ self::CART_SUMMARY_KEY ]   = $summary;
		$cart_item_data[ self::CART_HASH_KEY ]      = CartDeliverySelectionFingerprint::fromIntent( $result->intent );

		return $cart_item_data;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $values
	 *
	 * @return array<string, mixed>
	 */
	public function restore_cart_item_from_session( array $cart_item, array $values, string $cart_item_key ): array {
		unset( $cart_item_key );

		if ( ! $this->is_capture_enabled() ) {
			return $cart_item;
		}

		$restored = CartDeliverySelectionSessionData::restoreFromSession( $values );

		if ( null === $restored ) {
			return CartDeliverySelectionSessionData::stripSelectionKeys( $cart_item );
		}

		$cart_item[ self::CART_SELECTION_KEY ] = $restored['intent'];
		$cart_item[ self::CART_SUMMARY_KEY ]   = $restored['summary'];
		$cart_item[ self::CART_HASH_KEY ]      = $restored['hash'];

		return $cart_item;
	}

	/**
	 * @param array<int, array<string, mixed>> $item_data
	 * @param array<string, mixed>             $cart_item
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		if ( ! $this->is_capture_enabled() ) {
			return $item_data;
		}

		$summary = CartDeliverySelectionSessionData::normalizeSummary(
			$cart_item[ self::CART_SUMMARY_KEY ] ?? null
		);

		if ( null === $summary ) {
			return $item_data;
		}

		$intent = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item[ self::CART_SELECTION_KEY ] ?? null
		);
		$choice = is_array( $intent ) ? (string) ( $intent['fulfilment_choice'] ?? '' ) : '';

		$rows = self::formatPublicSummaryRows( $summary, '' !== $choice ? $choice : null );

		if ( [] === $rows ) {
			return $item_data;
		}

		foreach ( $rows as $row ) {
			$item_data[] = [
				'key'   => esc_html( $row['key'] ),
				'value' => esc_html( $row['value'] ),
			];
		}

		return $item_data;
	}

	/**
	 * @return array{requirement: string, options: list<ProductDeliveryOption>}
	 */
	public function assess_product_selection( int $product_id, int $variation_id ): array {
		$empty = [
			'requirement' => 'none',
			'options'     => [],
		];

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return $empty;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $empty;
		}

		if ( $product->is_type( 'variable' ) && $variation_id <= 0 ) {
			return $empty;
		}

		$context = $this->resolve_target_context( $product, $product_id, $variation_id );

		if ( null === $context ) {
			return $empty;
		}

		$runtime = $this->configuration_source->resolve( $context['target_type'], $context['target_id'] );
		$result  = $runtime->result;

		if ( ! $result->success || [] === $result->chosen_rules ) {
			return $empty;
		}

		$options = $this->options_builder->buildFromResolution( $result );

		if ( [] === $options ) {
			return $empty;
		}

		$available = array_filter(
			$options,
			static fn ( ProductDeliveryOption $option ): bool => $option->is_available
		);

		if ( [] === $available ) {
			return [
				'requirement' => 'blocked',
				'options'     => $options,
			];
		}

		return [
			'requirement' => 'required',
			'options'     => $options,
		];
	}

	/**
	 * @param array<string, mixed> $matched_option
	 *
	 * @return array<string, string|null>
	 */
	public static function buildPublicSummary( array $matched_option ): array {
		$raw = [
			'fulfilment_availability_label' => isset( $matched_option['fulfilment_availability_label'] )
				? (string) $matched_option['fulfilment_availability_label']
				: null,
			'fulfilment_choice_label'       => isset( $matched_option['fulfilment_choice_label'] )
				? (string) $matched_option['fulfilment_choice_label']
				: null,
			'delivery_offer_public_label'   => isset( $matched_option['delivery_offer_public_label'] )
				? (string) $matched_option['delivery_offer_public_label']
				: null,
			'estimate_text'                 => isset( $matched_option['estimate_text'] )
				? (string) $matched_option['estimate_text']
				: null,
		];

		return CartDeliverySelectionSessionData::normalizeSummary( $raw ) ?? [];
	}

	/**
	 * @param array<string, string|null> $summary
	 *
	 * @return list<array{key: string, value: string}>
	 */
	public static function formatPublicSummaryRows( array $summary, ?string $fulfilment_choice = null ): array {
		return DeliveryPresentationLabels::format_public_summary_rows( $summary, $fulfilment_choice );
	}

	/**
	 * @param array<string, string|null> $summary
	 *
	 * @return list<string>
	 *
	 * @deprecated Use formatPublicSummaryRows() for distinct customer labels.
	 */
	public static function formatPublicSummaryLines( array $summary ): array {
		$rows  = self::formatPublicSummaryRows( $summary );
		$lines = [];

		foreach ( $rows as $row ) {
			$lines[] = $row['value'];
		}

		return $lines;
	}

	/**
	 * @param array<string, mixed> $intent
	 */
	public static function buildSelectionHash( array $intent ): string {
		return CartDeliverySelectionFingerprint::fromIntent( $intent );
	}

	private function read_submitted_display_key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart form; validated server-side.
		if ( ! isset( $_POST[ self::POST_FIELD ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wp_unslash( (string) $_POST[ self::POST_FIELD ] );

		return ProductDeliveryOptionsBuilder::normalizeDisplayKey( $raw );
	}

	/**
	 * Capture applies to simple products only in Phase 2E1 (variable forms deferred).
	 */
	public function should_apply_capture_to_line( int $product_id, int $variation_id ): bool {
		return $this->should_apply_capture( $product_id, $variation_id );
	}

	/**
	 * Capture applies to simple products always (when capture flags are on).
	 * Variable products require both ECR flags and a selected variation.
	 */
	private function should_apply_capture( int $product_id, int $variation_id ): bool {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( $product->is_type( 'simple' ) ) {
			return true;
		}

		if ( $product->is_type( 'variable' ) ) {
			return $variation_id > 0 && $this->is_variable_ecr_enabled();
		}

		if ( $product->is_type( 'variation' ) && $variation_id <= 0 ) {
			return true;
		}

		return false;
	}

	private function is_variable_ecr_enabled(): bool {
		return $this->feature_flags->is_enabled( 'enable_effective_configuration_runtime' )
			&& $this->feature_flags->is_enabled( 'enable_variable_product_ecr_runtime' );
	}

	private function submitted_variation_matches( int $variation_id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart form; validated server-side.
		if ( ! isset( $_POST[ self::POST_VARIATION_FIELD ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = (int) wp_unslash( (string) $_POST[ self::POST_VARIATION_FIELD ] );

		return $submitted > 0 && $submitted === $variation_id;
	}

	/**
	 * @return array{target_type: string, target_id: int}|null
	 */
	private function resolve_target_context( WC_Product $product, int $product_id, int $variation_id ): ?array {
		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
				return null;
			}

			$parent_id = (int) $variation->get_parent_id();

			if ( $parent_id > 0 && $parent_id !== $product_id ) {
				return null;
			}

			return [
				'target_type' => ProductTargetType::Variation->value,
				'target_id'   => $variation_id,
			];
		}

		if ( $product->is_type( 'variable' ) ) {
			return null;
		}

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
			return null;
		}

		$target_type = $product->is_type( 'variation' )
			? ProductTargetType::Variation->value
			: ProductTargetType::Product->value;

		return [
			'target_type' => $target_type,
			'target_id'   => (int) $product->get_id(),
		];
	}
}
