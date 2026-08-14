<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/**
 * Human summary of one fulfilment profile's site-wide defaults.
 */
final class SiteWideDefaultSummary {

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly ?DeliveryOfferRepositoryInterface $offers = null,
		private readonly ?RateCardRepositoryInterface $rates = null,
		private readonly ?EntityLabelResolver $labels = null
	) {
	}

	/**
	 * @return array{
	 *     key: string,
	 *     label: string,
	 *     configured: bool,
	 *     delivery_method: string,
	 *     delivery_options: string,
	 *     estimated_delivery: string,
	 *     rate_summary: string
	 * }
	 */
	public function for_profile( string $profile_key ): array {
		$profile = FulfilmentProfileRegistry::get( $profile_key );
		$scope   = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$profile_key
		);

		if ( ! $profile instanceof FulfilmentProfile ) {
			return [
				'key'                 => $profile_key,
				'label'               => $profile_key,
				'configured'          => false,
				'delivery_method'     => '',
				'delivery_options'    => '',
				'estimated_delivery'  => '',
				'rate_summary'        => '',
				'air_sea'             => false,
			];
		}

		$configured = $scope instanceof ScopedConfiguration && $this->has_useful_defaults( $scope );

		return [
			'key'                => $profile->key,
			'label'              => $profile->label,
			'configured'         => $configured,
			'delivery_method'    => $this->delivery_method_label( $profile, $scope ),
			'delivery_options'   => $this->offer_labels( $scope ),
			'estimated_delivery' => $this->eta_label( $scope ),
			'rate_summary'       => $this->rate_summary( $scope ),
			'air_sea'            => $profile->air_sea_allowed,
		];
	}

	private function has_useful_defaults( ScopedConfiguration $scope ): bool {
		$offers = $scope->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;

		return null !== $offers
			&& CollectionConfigurationMode::Inherit !== $offers->mode
			&& [] !== $offers->members;
	}

	private function delivery_method_label( FulfilmentProfile $profile, ?ScopedConfiguration $scope ): string {
		if ( $profile->pickup_allowed && $profile->delivery_allowed ) {
			$choice = $scope?->scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ] ?? null;
			if ( null !== $choice && ScalarConfigurationMode::Override === $choice->mode ) {
				$options = ConfigurationFieldCatalog::enum_options( ConfigurationFieldKey::FULFILMENT_CHOICE );

				return $options[ (string) $choice->value ] ?? 'Delivery + Store Pickup';
			}

			return 'Delivery + Store Pickup';
		}

		return 'Delivery only';
	}

	private function offer_labels( ?ScopedConfiguration $scope ): string {
		$offers = $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;
		if ( null === $offers || [] === $offers->members ) {
			return 'Not set';
		}

		$names = [];
		foreach ( $offers->members as $id ) {
			$names[] = $this->offer_label( (int) $id );
		}

		return implode( ' + ', $names );
	}

	private function offer_label( int $id ): string {
		if ( null !== $this->labels ) {
			$label = $this->labels->label( 'delivery_offer', $id );
			if ( '' !== $label ) {
				return $label;
			}
		}

		if ( null !== $this->offers ) {
			$row = $this->offers->findById( $id );
			if ( is_array( $row ) ) {
				$label = (string) ( $row['public_label'] ?? $row['internal_name'] ?? '' );
				if ( '' !== $label ) {
					return $label;
				}
			}
		}

		return 'Delivery option';
	}

	private function eta_label( ?ScopedConfiguration $scope ): string {
		$eta = $scope?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ] ?? null;
		if ( null !== $eta && ScalarConfigurationMode::Override === $eta->mode && is_string( $eta->value ) && '' !== $eta->value ) {
			return $eta->value;
		}

		$offers = $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;
		if ( null === $offers || null === $this->offers || [] === $offers->members ) {
			return '';
		}

		$row = $this->offers->findById( (int) $offers->members[0] );
		if ( ! is_array( $row ) ) {
			return '';
		}

		$min = (int) ( $row['default_processing_min'] ?? 0 ) + (int) ( $row['default_transit_min'] ?? 0 );
		$max = (int) ( $row['default_processing_max'] ?? 0 ) + (int) ( $row['default_transit_max'] ?? 0 );

		if ( $min <= 0 && $max <= 0 ) {
			return '';
		}

		if ( $min === $max || $max <= 0 ) {
			return $min . ' days';
		}

		return $min . '–' . $max . ' days';
	}

	private function rate_summary( ?ScopedConfiguration $scope ): string {
		$offers = $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;
		if ( null === $offers || null === $this->rates || [] === $offers->members ) {
			return '';
		}

		$cards = $this->rates->list( [ 'limit' => 50 ] );
		foreach ( $cards as $card ) {
			$offer_id = (int) ( $card['delivery_offer_id'] ?? 0 );
			if ( in_array( $offer_id, $offers->members, true ) && isset( $card['base_amount'] ) ) {
				return (string) $card['base_amount'];
			}
		}

		return '';
	}
}
