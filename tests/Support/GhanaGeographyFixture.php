<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;

final class GhanaGeographyFixture {

	public CanonicalLocation $ghana;

	public CanonicalLocation $greater_accra;

	public CanonicalLocation $ashanti;

	public CanonicalLocation $accra;

	public CanonicalLocation $tema;

	public CanonicalLocation $madina;

	public CanonicalLocation $adenta;

	public CanonicalLocation $ada_foah;

	public CanonicalLocation $prampram;

	public CanonicalLocation $kumasi;

	public CanonicalLocation $ejisu;

	public CanonicalLocation $mampong;

	public InMemoryCanonicalLocationRepository $locations;

	public function __construct() {
		$this->locations      = new InMemoryCanonicalLocationRepository();
		$this->ghana          = $this->locations->seed( 'GH', GeographyLocationType::Country, 'Ghana', null, null, 'loc-gh' );
		$this->greater_accra  = $this->locations->seed( 'GH', GeographyLocationType::Administrative, 'Greater Accra', $this->ghana->id, 1, 'loc-ga' );
		$this->ashanti        = $this->locations->seed( 'GH', GeographyLocationType::Administrative, 'Ashanti', $this->ghana->id, 1, 'loc-ah' );
		$this->accra          = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Accra', $this->greater_accra->id, null, 'loc-accra' );
		$this->tema           = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Tema', $this->greater_accra->id, null, 'loc-tema' );
		$this->madina         = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Madina', $this->greater_accra->id, null, 'loc-madina' );
		$this->adenta         = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Adenta', $this->greater_accra->id, null, 'loc-adenta' );
		$this->ada_foah       = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Ada Foah', $this->greater_accra->id, null, 'loc-ada' );
		$this->prampram       = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Prampram', $this->greater_accra->id, null, 'loc-pram' );
		$this->kumasi         = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Kumasi', $this->ashanti->id, null, 'loc-kumasi' );
		$this->ejisu          = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Ejisu', $this->ashanti->id, null, 'loc-ejisu' );
		$this->mampong        = $this->locations->seed( 'GH', GeographyLocationType::Locality, 'Mampong', $this->ashanti->id, null, 'loc-mampong' );
		$this->locations->add_alias( $this->accra->id, 'Accra Metropolitan', 'accra metropolitan' );
		$this->locations->upsert( $this->ghana->id, GeographyProvider::WooCommerce, 'GH', null, 'woocommerce' );
		$this->locations->upsert( $this->greater_accra->id, GeographyProvider::WooCommerce, 'GH:AA', null, 'woocommerce' );
		$this->locations->add_alias( $this->greater_accra->id, 'AA', 'aa' );
		$this->locations->upsert( $this->ashanti->id, GeographyProvider::WooCommerce, 'GH:AH', null, 'woocommerce' );
		$this->locations->add_alias( $this->ashanti->id, 'AH', 'ah' );
	}

	/**
	 * @return list<CanonicalLocation>
	 */
	public function twenty_greater_accra_localities(): array {
		$names = [
			'Accra', 'Tema', 'Madina', 'Adenta', 'Teshie', 'Nungua', 'Ashaiman', 'Dodowa',
			'Amasaman', 'Dansoman', 'Kaneshie', 'Osu', 'Labadi', 'Spintex', 'East Legon',
			'Achimota', 'Dome', 'Haatso', 'Legon', 'Weija',
		];
		$out = [ $this->accra, $this->tema, $this->madina, $this->adenta ];
		foreach ( $names as $name ) {
			$existing = $this->locations->find_exact_child( 'GH', $this->greater_accra->id, strtolower( $name ), GeographyLocationType::Locality );
			if ( $existing instanceof CanonicalLocation ) {
				continue;
			}
			$out[] = $this->locations->seed( 'GH', GeographyLocationType::Locality, $name, $this->greater_accra->id );
		}

		return array_values( array_filter( $this->locations->list_children( $this->greater_accra->id, GeographyLocationType::Locality, 50, 0 ) ) );
	}
}
