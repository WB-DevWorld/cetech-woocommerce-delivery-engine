<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

/**
 * Explicit internal inventory. Registration does not publish an adapter or API.
 * Schema, policy and ownership values are bounded references, not raw payloads.
 */
final readonly class ContractCatalog {

	private const FIELDS = [
		'surface', 'version', 'classification', 'owner', 'callers',
		'input_schema', 'output_schema', 'authorization', 'projection',
		'compatibility', 'deprecation', 'cost_budget_owner',
	];

	/** @var array<string, array<string, mixed>> */
	private array $catalog;

	/** @param list<array<string, mixed>> $entries */
	public function __construct( array $entries = [] ) {
		if ( ! array_is_list( $entries ) || count( $entries ) > 1024 ) {
			throw new \InvalidArgumentException( 'Invalid contract catalog.' );
		}

		$catalog = [];
		foreach ( $entries as $entry ) {
			self::validate_entry( $entry );
			$key = self::key( $entry['surface'], $entry['version'] );
			if ( isset( $catalog[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Duplicate contract version.' );
			}
			$catalog[ $key ] = self::copy_entry( $entry );
		}

		foreach ( $catalog as $key => $entry ) {
			self::validate_replacement( $key, $entry, $catalog );
		}
		$this->catalog = $catalog;
	}

	/** @return array<string, mixed> */
	public function entry( string $surface, int $version ): array {
		$key = self::key( $surface, $version );
		if ( ! isset( $this->catalog[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Contract is not classified.' );
		}
		return $this->catalog[ $key ];
	}

	/** @return list<array<string, mixed>> */
	public function entries(): array {
		return array_values( $this->catalog );
	}

	public function assert_export_classified( string $surface, int $version ): void {
		$this->entry( $surface, $version );
	}

	/** Internal classification is not a public stability promise. */
	public function assert_public_export( string $surface, int $version ): void {
		if ( 'internal' === $this->entry( $surface, $version )['classification'] ) {
			throw new \InvalidArgumentException( 'Internal contract cannot be publicly exported.' );
		}
	}

	private static function key( string $surface, int $version ): string {
		if ( ! self::valid_reference( $surface ) || $version < 1 ) {
			throw new \InvalidArgumentException( 'Invalid contract identity.' );
		}
		return $surface . '@' . $version;
	}

	private static function validate_entry( mixed $entry ): void {
		if ( ! is_array( $entry ) || ! self::has_exact_fields( $entry, self::FIELDS ) ) {
			throw new \InvalidArgumentException( 'Contract declaration fields are incomplete or unknown.' );
		}
		if ( ! is_int( $entry['version'] ) || $entry['version'] < 1
			|| ! in_array( $entry['classification'], [ 'stable', 'experimental', 'internal' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid contract version or classification.' );
		}
		foreach ( [ 'surface', 'owner', 'input_schema', 'output_schema', 'authorization', 'projection', 'compatibility', 'cost_budget_owner' ] as $field ) {
			if ( ! self::valid_reference( $entry[ $field ] ) ) {
				throw new \InvalidArgumentException( 'Invalid contract declaration reference.' );
			}
		}
		if ( ! self::valid_references( $entry['callers'] ) ) {
			throw new \InvalidArgumentException( 'Invalid contract caller declaration.' );
		}
		if ( null === $entry['deprecation'] ) {
			return;
		}
		$deprecation = $entry['deprecation'];
		if ( ! is_array( $deprecation ) || ! self::has_exact_fields( $deprecation, [ 'replacement_surface', 'replacement_version', 'compatibility_window', 'reviewed_consumers' ] )
			|| ! self::valid_reference( $deprecation['replacement_surface'] )
			|| ! is_int( $deprecation['replacement_version'] ) || $deprecation['replacement_version'] < 1
			|| ! self::valid_reference( $deprecation['compatibility_window'] )
			|| ! self::valid_references( $deprecation['reviewed_consumers'] ) ) {
			throw new \InvalidArgumentException( 'Invalid contract deprecation declaration.' );
		}
	}

	/** Detach caller-owned PHP references as well as ordinary array copies. */
	private static function copy_entry( array $entry ): array {
		$copy = [];
		foreach ( self::FIELDS as $field ) {
			$copy[ $field ] = $entry[ $field ];
		}
		$copy['callers'] = array_map( static fn( string $caller ): string => $caller, $entry['callers'] );
		if ( null !== $entry['deprecation'] ) {
			$deprecation = $entry['deprecation'];
			$copy['deprecation'] = [
				'replacement_surface' => $deprecation['replacement_surface'],
				'replacement_version' => $deprecation['replacement_version'],
				'compatibility_window' => $deprecation['compatibility_window'],
				'reviewed_consumers' => array_map( static fn( string $consumer ): string => $consumer, $deprecation['reviewed_consumers'] ),
			];
		}
		return $copy;
	}

	/** @param array<string, array<string, mixed>> $catalog */
	private static function validate_replacement( string $key, array $entry, array $catalog ): void {
		$visited = [ $key => true ];
		$current = $entry;
		while ( null !== $current['deprecation'] ) {
			$deprecation = $current['deprecation'];
			$replacement_key = self::key( $deprecation['replacement_surface'], $deprecation['replacement_version'] );
			if ( ! isset( $catalog[ $replacement_key ] ) || isset( $visited[ $replacement_key ] )
				|| ( $current['surface'] === $deprecation['replacement_surface'] && $deprecation['replacement_version'] <= $current['version'] )
				|| ( 'stable' === $current['classification'] && 'stable' !== $catalog[ $replacement_key ]['classification'] ) ) {
				throw new \InvalidArgumentException( 'Invalid contract deprecation replacement.' );
			}
			$visited[ $replacement_key ] = true;
			$current = $catalog[ $replacement_key ];
		}
	}

	/** @param list<string> $fields */
	private static function has_exact_fields( array $value, array $fields ): bool {
		return count( $value ) === count( $fields ) && [] === array_diff( $fields, array_keys( $value ) );
	}

	private static function valid_reference( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 128
			&& 1 === preg_match( '/\A[a-zA-Z][a-zA-Z0-9_.:\\\\-]*\z/D', $value );
	}

	private static function valid_references( mixed $values ): bool {
		if ( ! is_array( $values ) || ! array_is_list( $values ) || [] === $values || count( $values ) > 64 ) {
			return false;
		}
		foreach ( $values as $value ) {
			if ( ! self::valid_reference( $value ) ) {
				return false;
			}
		}
		return count( array_unique( $values ) ) === count( $values );
	}
}
