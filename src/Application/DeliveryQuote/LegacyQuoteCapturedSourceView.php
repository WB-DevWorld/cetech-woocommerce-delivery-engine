<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationVersionInfo;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;

/** The retained resolvers read one bounded physical universe, never an open-ended live query. */
final readonly class LegacyQuoteCapturedSourceView implements \JsonSerializable {
	public function __construct( private LegacyQuoteSourceSnapshot $snapshot ) {}
	public function jsonSerialize(): never { throw new \LogicException( 'Captured source views are private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Captured source views are private.' ); }
	public function offers(): DeliveryOfferRepositoryInterface {
		$rows = $this->snapshot->rows_for( 'offers' ); if ( count( $rows ) > 200 ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $known = []; foreach ( $rows as $row ) { $known[(int) $row['id']] = $row; }
		$ids = []; $decoder = new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository(); foreach ( $this->snapshot->rows_for( 'legacy_rules' ) as $rule ) { foreach ( $decoder->decode_offer_ids( $rule['delivery_offer_ids'] ?? null ) as $id ) { $ids[$id] = true; } if ( count( $ids ) > 200 ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } }
		foreach ( $this->snapshot->rows_for( 'scope_collections' ) as $collection ) { if ( 'delivery_offer_ids' !== $collection['field_key'] ) { continue; } $instruction = CollectionFieldInstruction::fromStorage( $collection['field_key'], $collection['mode'], $collection['members_json'] ); foreach ( $instruction->members as $id ) { $ids[$id] = true; } if ( count( $ids ) > 200 ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } } foreach ( $ids as $id => $_ ) { if ( ! isset( $known[$id] ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } }
		foreach ( $this->snapshot->rows_for( 'scope_fields' ) as $field ) { if ( 'pickup_location_id' === $field['field_key'] && null !== $field['value_text'] && '' !== $field['value_text'] && '0' !== $field['value_text'] ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } }
		return new class( $known ) implements DeliveryOfferRepositoryInterface {
			public function __construct( private array $rows ) {} public function findById( int $id ): ?array { if ( ! isset( $this->rows[$id] ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } return $this->rows[$id]; } public function findByCode( string $code ): ?array { foreach ( $this->rows as $row ) { if ( $row['internal_code'] === $code ) { return $row; } } return null; } public function list( array $criteria = [] ): array { return array_values( $this->rows ); } public function count_all(): int { return count( $this->rows ); } public function save( array $data ): int { throw new \LogicException( 'Captured sources cannot write.' ); } public function softDelete( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); } public function hardDelete( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); }
		};
	}
	public function legacy_rules(): ProductDeliveryRuleRepositoryInterface {
		return new class( $this->snapshot->rows_for( 'legacy_rules' ) ) implements ProductDeliveryRuleRepositoryInterface {
			public function __construct( private array $rows ) {}
			public function findActiveByTargets( array $targets ): array { $rows = array_values( array_filter( $this->rows, static function ( array $row ) use ( $targets ): bool { foreach ( $targets as $target ) { if ( 'active' === $row['status'] && $row['target_type'] === $target['target_type'] && (int) $row['target_id'] === $target['target_id'] ) { return true; } } return false; } ) ); usort( $rows, static fn( array $a, array $b ): int => [ (int) $a['priority'], (int) $a['id'] ] <=> [ (int) $b['priority'], (int) $b['id'] ] ); return $rows; }
			public function findById( int $id ): ?array { foreach ( $this->rows as $row ) { if ( (int) $row['id'] === $id ) { return $row; } } return null; }
			public function findByTarget( string $type, int $id ): array { throw new \LogicException( 'Unsupported captured source read.' ); } public function findByTargetAndAvailability( string $type, int $id, string $availability ): array { throw new \LogicException( 'Unsupported captured source read.' ); }
			public function list( array $filters = [] ): array { throw new \LogicException( 'Unsupported captured source read.' ); } public function listActive( array $filters = [] ): array { throw new \LogicException( 'Unsupported captured source read.' ); } public function save( array $data ): int { throw new \LogicException( 'Captured sources cannot write.' ); } public function deactivate( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); } public function hardDelete( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); } public function count_all(): int { return count( $this->rows ); } public function countBySupplierId( int $id ): int { throw new \LogicException( 'Unsupported captured source read.' ); } public function countByOriginId( int $id ): int { throw new \LogicException( 'Unsupported captured source read.' ); } public function countByLogisticsProfileId( int $id ): int { throw new \LogicException( 'Unsupported captured source read.' ); }
		};
	}
	public function zones(): DestinationZoneRepositoryInterface {
		return new class( $this->snapshot->rows_for( 'zones' ) ) implements DestinationZoneRepositoryInterface {
			public function __construct( private array $rows ) {}
			public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array { $rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => (int) $row['id'] > $after_id && ( ! isset( $criteria['status'] ) || $row['status'] === $criteria['status'] ) ) ); usort( $rows, static fn( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] ); return array_slice( $rows, 0, min( 200, max( 1, $limit ) ) ); }
			public function findById( int $id ): ?array { foreach ( $this->rows as $row ) { if ( (int) $row['id'] === $id ) { return $row; } } return null; } public function findByCode( string $code ): ?array { foreach ( $this->rows as $row ) { if ( $row['internal_code'] === $code ) { return $row; } } return null; } public function list( array $criteria = [] ): array { return $this->page_after( 0, 200, $criteria ); } public function count_all(): int { return count( $this->rows ); } public function save( array $data ): int { throw new \LogicException( 'Captured sources cannot write.' ); } public function softDelete( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); } public function hardDelete( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); }
		};
	}
	public function zone_rules(): DestinationRuleRepositoryInterface {
		return new class( $this->snapshot->rows_for( 'zone_rules' ) ) implements DestinationRuleRepositoryInterface {
			public function __construct( private array $rows ) {}
			public function listByZoneId( int $id ): array { $rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => (int) $row['zone_id'] === $id ) ); usort( $rows, static fn( array $a, array $b ): int => [ (int) $a['priority'], (int) $a['id'] ] <=> [ (int) $b['priority'], (int) $b['id'] ] ); return $rows; }
			public function list( int $limit = 500 ): array { return array_slice( $this->rows, 0, max( 0, $limit ) ); } public function count_all(): int { return count( $this->rows ); } public function deleteByZoneId( int $id ): bool { throw new \LogicException( 'Captured sources cannot write.' ); } public function replaceForZone( int $id, array $rules ): bool { throw new \LogicException( 'Captured sources cannot write.' ); }
		};
	}
	public function scopes(): ScopedConfigurationRepositoryInterface {
		$scopes = []; foreach ( $this->snapshot->rows_for( 'scopes' ) as $row ) {
			$type = ConfigurationScopeType::from( $row['scope_type'] ); $scope = new ConfigurationScope( (int) $row['id'], $type, (int) $row['scope_id'], $row['slice_key'], null === $row['parent_product_id'] ? null : (int) $row['parent_product_id'], RecordStatus::from( $row['status'] ), (int) $row['config_version'], ConfigurationSource::from( $row['source'] ), null === $row['legacy_rule_id'] ? null : (int) $row['legacy_rule_id'], $row['created_at'], $row['updated_at'] );
			$scalars = []; foreach ( $this->snapshot->rows_for( 'scope_fields' ) as $field ) { if ( (int) $field['scope_row_id'] !== $scope->id ) { continue; } if ( ! ConfigurationFieldRegistry::has( $field['field_key'] ) || isset( $scalars[$field['field_key']] ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $scalars[$field['field_key']] = ScalarFieldInstruction::fromStorage( $field['field_key'], $field['mode'], $field['value_text'], $field['value_type'] ); }
			$collections = []; foreach ( $this->snapshot->rows_for( 'scope_collections' ) as $field ) { if ( (int) $field['scope_row_id'] !== $scope->id ) { continue; } if ( ! ConfigurationFieldRegistry::has( $field['field_key'] ) || isset( $collections[$field['field_key']] ) ) { throw new \RuntimeException( 'Delivery quote source unavailable.' ); } $collections[$field['field_key']] = CollectionFieldInstruction::fromStorage( $field['field_key'], $field['mode'], $field['members_json'] ); } $scopes[] = new ScopedConfiguration( $scope, $scalars, $collections );
		}
		$version = 0; foreach ( $this->snapshot->rows_for( 'options' ) as $option ) { if ( 'cetech_de_global_configuration_version' === $option['option_name'] ) { $version = (int) $option['option_value']; } }
		return new class( $scopes, $version ) implements ScopedConfigurationRepositoryInterface {
			public function __construct( private array $scopes, private int $version ) {}
			public function findByScope( ConfigurationScopeType $type, int $id ): array { $rows = array_values( array_filter( $this->scopes, static fn( ScopedConfiguration $row ): bool => $row->scope->scope_type === $type && $row->scope->scope_id === $id ) ); usort( $rows, static fn( ScopedConfiguration $a, ScopedConfiguration $b ): int => [ $a->scope->slice_key, $a->scope->id ] <=> [ $b->scope->slice_key, $b->scope->id ] ); return $rows; }
			public function findByScopeAndSlice( ConfigurationScopeType $type, int $id, string $slice ): ?ScopedConfiguration { foreach ( $this->findByScope( $type, $id ) as $row ) { if ( $row->scope->slice_key === $slice ) { return $row; } } return null; } public function getGlobalConfiguration(): ?ScopedConfiguration { return $this->findByScopeAndSlice( ConfigurationScopeType::Global, 0, '' ); } public function getGlobalVersion(): int { return $this->getGlobalConfiguration()?->scope->config_version ?? $this->version; }
			public function findByLegacyRuleId( int $id ): ?ScopedConfiguration { foreach ( $this->scopes as $row ) { if ( $row->scope->legacy_rule_id === $id ) { return $row; } } return null; } public function findByParentProductId( int $id ): array { return array_values( array_filter( $this->scopes, static fn( ScopedConfiguration $row ): bool => $row->scope->parent_product_id === $id ) ); } public function getVersionInfo( ConfigurationScopeType $type, int $id, string $slice = '' ): ?ConfigurationVersionInfo { $row = $this->findByScopeAndSlice( $type, $id, $slice ); return null === $row ? null : new ConfigurationVersionInfo( $row->scope->id, $type->value, $id, $slice, $row->scope->config_version ); }
			public function ensureGlobalScope(): ScopedConfiguration { throw new \LogicException( 'Captured sources cannot write.' ); } public function saveScopedConfiguration( ScopedConfiguration $configuration, bool $publish_revision = true ): ScopedConfiguration { throw new \LogicException( 'Captured sources cannot write.' ); } public function completeLocalUnit( callable $work ): mixed { throw new \LogicException( 'Captured sources cannot write.' ); } public function publishAcceptedRevision( ScopedConfiguration $configuration ): bool { throw new \LogicException( 'Captured sources cannot write.' ); } public function lockScopeIdentity( ConfigurationScopeType $type, int $id, string $slice ): ?array { throw new \LogicException( 'Captured sources cannot lock.' ); } public function deleteScope( ConfigurationScopeType $type, int $id, string $slice = '' ): bool { throw new \LogicException( 'Captured sources cannot write.' ); }
		};
	}
}
