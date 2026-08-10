<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;

/**
 * Bounded entity label lookups for admin selectors (IDs remain authoritative).
 */
final class EntityLabelResolver {

	private const LIST_LIMIT = 200;

	/** @var array<string, array<int, string>> */
	private array $cache = [];

	public function __construct(
		private readonly ?DeliveryOfferRepositoryInterface $delivery_offers = null,
		private readonly ?LogisticsProfileRepositoryInterface $logistics_profiles = null,
		private readonly ?SupplierRepositoryInterface $suppliers = null,
		private readonly ?OriginRepositoryInterface $origins = null
	) {
	}

	/**
	 * @return array<int, string> id => label
	 */
	public function options_for( string $entity_kind ): array {
		if ( isset( $this->cache[ $entity_kind ] ) ) {
			return $this->cache[ $entity_kind ];
		}

		$options = match ( $entity_kind ) {
			'delivery_offer' => $this->map_named_records( $this->delivery_offers?->list( [ 'limit' => self::LIST_LIMIT ] ) ?? [] ),
			'logistics_profile' => $this->map_named_records( $this->logistics_profiles?->list( [ 'limit' => self::LIST_LIMIT ] ) ?? [] ),
			'supplier' => $this->map_named_records( $this->suppliers?->list( [ 'limit' => self::LIST_LIMIT ] ) ?? [] ),
			'origin' => $this->map_named_records( $this->origins?->list( [ 'limit' => self::LIST_LIMIT ] ) ?? [] ),
			default => [],
		};

		$this->cache[ $entity_kind ] = $options;

		return $options;
	}

	public function label( string $entity_kind, int $id ): string {
		$options = $this->options_for( $entity_kind );

		return $options[ $id ] ?? sprintf( '#%d', $id );
	}

	/**
	 * @param list<array<string, mixed>> $records
	 *
	 * @return array<int, string>
	 */
	private function map_named_records( array $records ): array {
		$options = [];

		foreach ( $records as $record ) {
			$id = (int) ( $record['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$name = trim( (string) ( $record['name'] ?? $record['label'] ?? $record['code'] ?? '' ) );
			$options[ $id ] = '' !== $name ? sprintf( '%s (#%d)', $name, $id ) : sprintf( '#%d', $id );
		}

		return $options;
	}
}
