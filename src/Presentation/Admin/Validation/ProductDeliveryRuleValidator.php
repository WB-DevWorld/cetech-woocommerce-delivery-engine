<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin\Validation;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;

final class ProductDeliveryRuleValidator {

	public function __construct(
		private ProductDeliveryRuleRepositoryInterface $rule_repository,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private LogisticsProfileRepositoryInterface $logistics_profile_repository,
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository,
		private ProductTargetResolver $target_resolver
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate( array $input, ?int $existing_id = null ): array {
		$errors = [];

		$target_type = sanitize_key( (string) ( $input['target_type'] ?? '' ) );
		$target_id   = isset( $input['target_id'] ) ? (int) $input['target_id'] : 0;

		if ( ! $this->is_valid_target_type( $target_type ) ) {
			$errors['target_type'] = __( 'Choose whether this rule is for a product, product variation, or product category.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $target_id <= 0 ) {
			$errors['target_id'] = __( 'Enter the WooCommerce ID of the product, product variation, or product category.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( $this->is_valid_target_type( $target_type ) ) {
			$target_error = $this->target_resolver->validate_target( $target_type, $target_id );

			if ( null !== $target_error ) {
				$errors['target_id'] = $target_error;
			}
		}

		$availability = (string) ( $input['fulfilment_availability'] ?? '' );
		$choice       = (string) ( $input['fulfilment_choice'] ?? '' );

		if ( ! $this->is_valid_availability( $availability ) ) {
			$errors['fulfilment_availability'] = __( 'Invalid fulfilment availability selected.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ! $this->is_valid_choice( $choice ) ) {
			$errors['fulfilment_choice'] = __( 'Invalid fulfilment choice selected.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( [] === $errors && ! $this->is_valid_availability_choice_pair( $availability, $choice ) ) {
			$errors['fulfilment_choice'] = __( 'This fulfilment choice is not allowed for the selected availability.', 'cetech-woocommerce-delivery-engine' );
		}

		$offer_ids = $this->normalize_offer_ids( $input['delivery_offer_ids'] ?? [] );

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			if ( [] !== $offer_ids ) {
				$errors['delivery_offer_ids'] = __( 'Delivery offers must be empty when fulfilment choice is store pickup.', 'cetech-woocommerce-delivery-engine' );
			}
		} elseif ( [] === $offer_ids ) {
			$errors['delivery_offer_ids'] = __( 'At least one delivery offer is required for delivery fulfilment.', 'cetech-woocommerce-delivery-engine' );
		} else {
			foreach ( $offer_ids as $offer_id ) {
				if ( null === $this->delivery_offer_repository->findById( $offer_id ) ) {
					$errors['delivery_offer_ids'] = __( 'One or more selected delivery offers do not exist.', 'cetech-woocommerce-delivery-engine' );
					break;
				}
			}
		}

		$logistics_profile_id = $this->nullable_positive_int( $input['logistics_profile_id'] ?? null );

		if ( null !== $logistics_profile_id && null === $this->logistics_profile_repository->findById( $logistics_profile_id ) ) {
			$errors['logistics_profile_id'] = __( 'Selected logistics profile does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$supplier_id = $this->nullable_positive_int( $input['supplier_id'] ?? null );

		if ( null !== $supplier_id && null === $this->supplier_repository->findById( $supplier_id ) ) {
			$errors['supplier_id'] = __( 'Selected supplier does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$origin_id = $this->nullable_positive_int( $input['origin_id'] ?? null );

		if ( null !== $origin_id ) {
			$origin = $this->origin_repository->findById( $origin_id );

			if ( null === $origin ) {
				$errors['origin_id'] = __( 'Selected origin does not exist.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( null !== $supplier_id && (int) ( $origin['supplier_id'] ?? 0 ) !== $supplier_id ) {
				$errors['origin_id'] = __( 'Selected origin does not belong to the selected supplier.', 'cetech-woocommerce-delivery-engine' );
			}
		}

		if ( ! isset( $input['priority'] ) || '' === (string) $input['priority'] ) {
			$errors['priority'] = __( 'Priority is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! is_numeric( $input['priority'] ) ) {
			$errors['priority'] = __( 'Priority must be an integer.', 'cetech-woocommerce-delivery-engine' );
		}

		$status = (string) ( $input['status'] ?? '' );

		if ( ! $this->is_valid_status( $status ) ) {
			$errors['status'] = __( 'Invalid status selected.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( [] === $errors && RecordStatus::Active->value === $status && $this->is_valid_target_type( $target_type ) ) {
			$duplicate_error = $this->check_active_duplicate( $target_type, $target_id, $availability, $existing_id );

			if ( null !== $duplicate_error ) {
				$errors['fulfilment_availability'] = $duplicate_error;
			}
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate_resolution_test_input( array $input ): array {
		$errors = [];

		$target_type = sanitize_key( (string) ( $input['test_target_type'] ?? '' ) );
		$target_id   = isset( $input['test_target_id'] ) ? (int) $input['test_target_id'] : 0;

		if ( ! $this->is_valid_target_type( $target_type ) ) {
			$errors['test_target_type'] = __( 'Choose a product, product variation, or product category.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $target_id <= 0 ) {
			$errors['test_target_id'] = __( 'Enter the WooCommerce ID of the item you want to check.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( $this->is_valid_target_type( $target_type ) ) {
			$target_error = $this->target_resolver->validate_target( $target_type, $target_id );

			if ( null !== $target_error ) {
				$errors['test_target_id'] = $target_error;
			}
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate_selection_test_input( array $input ): array {
		$errors      = [];
		$product_id  = isset( $input['test_product_id'] ) ? (int) $input['test_product_id'] : 0;
		$variation_id = isset( $input['test_variation_id'] ) ? (int) $input['test_variation_id'] : 0;
		$display_key = ProductDeliveryOptionsBuilder::normalizeDisplayKey(
			isset( $input['test_display_key'] ) ? (string) $input['test_display_key'] : ''
		);

		if ( $product_id <= 0 ) {
			$errors['test_product_id'] = __( 'Product ID is required.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $variation_id < 0 ) {
			$errors['test_variation_id'] = __( 'Variation ID must be zero or a positive integer.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( '' === $display_key ) {
			$errors['test_display_key'] = __( 'Enter a delivery choice identifier. Support staff may provide one for you.', 'cetech-woocommerce-delivery-engine' );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return list<int>
	 */
	public function normalize_offer_ids( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$ids = [];

		foreach ( $input as $value ) {
			$int = (int) $value;

			if ( $int > 0 ) {
				$ids[] = $int;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function resolve_target_label_snapshot( array $input ): ?string {
		$target_type = sanitize_key( (string) ( $input['target_type'] ?? '' ) );
		$target_id   = isset( $input['target_id'] ) ? (int) $input['target_id'] : 0;
		$manual      = trim( (string) ( $input['target_label_snapshot'] ?? '' ) );

		if ( '' !== $manual ) {
			return sanitize_text_field( $manual );
		}

		if ( ! $this->is_valid_target_type( $target_type ) || $target_id <= 0 ) {
			return null;
		}

		return $this->target_resolver->resolve_label( $target_type, $target_id );
	}

	private function check_active_duplicate(
		string $target_type,
		int $target_id,
		string $availability,
		?int $existing_id
	): ?string {
		foreach ( $this->rule_repository->findByTargetAndAvailability( $target_type, $target_id, $availability ) as $row ) {
			$row_id = (int) ( $row['id'] ?? 0 );

			if ( $row_id <= 0 ) {
				continue;
			}

			if ( null !== $existing_id && $row_id === $existing_id ) {
				continue;
			}

			if ( RecordStatus::Active->value === (string) ( $row['status'] ?? '' ) ) {
				return __(
					'An active product rule already exists for this target and fulfilment availability.',
					'cetech-woocommerce-delivery-engine'
				);
			}
		}

		return null;
	}

	private function is_valid_availability_choice_pair( string $availability, string $choice ): bool {
		if ( FulfilmentAvailability::InStore->value === $availability ) {
			return FulfilmentChoice::Delivery->value === $choice
				|| FulfilmentChoice::StorePickup->value === $choice;
		}

		return FulfilmentChoice::Delivery->value === $choice;
	}

	private function nullable_positive_int( mixed $value ): ?int {
		if ( null === $value || '' === $value || '0' === (string) $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}

	private function is_valid_target_type( string $target_type ): bool {
		foreach ( ProductTargetType::cases() as $case ) {
			if ( $case->value === $target_type ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_availability( string $availability ): bool {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $availability ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_choice( string $choice ): bool {
		foreach ( FulfilmentChoice::cases() as $case ) {
			if ( $case->value === $choice ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_status( string $status ): bool {
		foreach ( RecordStatus::cases() as $case ) {
			if ( $case->value === $status ) {
				return true;
			}
		}

		return false;
	}
}
