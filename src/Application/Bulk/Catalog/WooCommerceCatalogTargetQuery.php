<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/**
 * WooCommerce catalog targeting with keyset pagination on posts.ID.
 * Never loads the full catalog into PHP memory.
 */
final class WooCommerceCatalogTargetQuery implements CatalogTargetQueryInterface {

	private const SQL_CANDIDATE_PAGE = 100;

	public function __construct(
		private readonly ?EffectiveConfigurationResolver $resolver = null,
		private readonly ?OperationalReadinessAssessor $readiness = null
	) {
	}

	public function count( CatalogTargetDefinition $definition ): int {
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return $definition->selected_count();
		}
		if ( BulkTargetScope::MatchingFilters === $definition->scope && ! $definition->has_matching_criteria() ) {
			return 0;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		[ $sql, $args ] = $this->id_sql( $definition, 0, 0, true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

		return (int) $count;
	}

	public function page_after( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		$limit   = max( 1, min( 250, $limit ) );
		$ids     = $this->matching_ids( $definition, $after_id, $limit );
		$targets = [];
		foreach ( $ids as $id ) {
			$targets[] = new CatalogTarget(
				$definition->target_type,
				$id,
				$this->sku_for( $definition->target_type, $id ),
				CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type ? $this->parent_product_id( $id ) : null
			);
		}

		return $targets;
	}

	public function sku_for( string $target_type, int $target_id ): string {
		if ( $target_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $target_id );

		return is_object( $product ) && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
	}

	public function parent_product_id( int $variation_id ): ?int {
		if ( $variation_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $variation_id );
		if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) ) {
			$parent = (int) $product->get_parent_id();
			return $parent > 0 ? $parent : null;
		}

		return null;
	}

	public function find_id_by_sku( string $sku, string $target_type ): ?int {
		$sku = trim( $sku );
		if ( '' === $sku ) {
			return null;
		}
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( $sku );
			if ( $id > 0 ) {
				return $id;
			}
		}

		global $wpdb;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
				'_sku',
				$sku
			)
		);

		return $id > 0 ? $id : null;
	}

	/**
	 * @return list<int>
	 */
	private function matching_ids( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return CatalogTargetDefinition::page_sorted_ids( $definition->selected_ids, $after_id, $limit );
		}

		if ( BulkTargetScope::MatchingFilters === $definition->scope && ! $definition->has_matching_criteria() ) {
			return [];
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}

		$need_eval = CatalogTargetFilters::requires_effective_eval( $definition->filters );
		if ( ! $need_eval ) {
			return $this->sql_ids( $definition, $after_id, $limit );
		}

		$collected = [];
		$cursor    = $after_id;
		$guard     = 0;
		while ( count( $collected ) < $limit && $guard < 400 ) {
			++$guard;
			$candidates = $this->sql_ids( $definition, $cursor, self::SQL_CANDIDATE_PAGE );
			if ( [] === $candidates ) {
				break;
			}
			foreach ( $candidates as $id ) {
				$cursor = $id;
				if ( ! $this->passes_effective( $definition, $id ) ) {
					continue;
				}
				$collected[] = $id;
				if ( count( $collected ) >= $limit ) {
					break;
				}
			}
			if ( count( $candidates ) < self::SQL_CANDIDATE_PAGE ) {
				break;
			}
		}

		return $collected;
	}

	/**
	 * @return list<int>
	 */
	private function sql_ids( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		[ $sql, $args ] = $this->id_sql( $definition, $after_id, $limit, false );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function id_sql( CatalogTargetDefinition $definition, int $after_id, int $limit, bool $count ): array {
		global $wpdb;

		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type;
		$type         = $is_variation ? 'product_variation' : 'product';
		$scope_type   = $is_variation ? ConfigurationScopeType::Variation->value : ConfigurationScopeType::Product->value;
		$args         = [ $type, $after_id ];
		$join         = '';
		$where        = "p.post_type = %s AND p.post_status IN ('publish','private','draft') AND p.ID > %d";

		if ( [] !== $definition->skus ) {
			$placeholders = implode( ',', array_fill( 0, count( $definition->skus ), '%s' ) );
			$join        .= " INNER JOIN {$wpdb->postmeta} sku_meta ON sku_meta.post_id = p.ID AND sku_meta.meta_key = '_sku' ";
			$where       .= " AND sku_meta.meta_value IN ({$placeholders})";
			foreach ( $definition->skus as $sku ) {
				$args[] = $sku;
			}
		}

		$filters = $definition->filters;
		$search  = trim( (string) ( $filters[ CatalogTargetFilters::SEARCH ] ?? '' ) );
		if ( '' !== $search ) {
			$join  .= " LEFT JOIN {$wpdb->postmeta} search_sku ON search_sku.post_id = p.ID AND search_sku.meta_key = '_sku' ";
			$where .= ' AND (p.post_title LIKE %s OR search_sku.meta_value LIKE %s)';
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}

		$product_type = sanitize_key( (string) ( $filters[ CatalogTargetFilters::PRODUCT_TYPE ] ?? '' ) );
		if ( '' !== $product_type ) {
			$type_object = $is_variation ? 'p.post_parent' : 'p.ID';
			$join       .= " INNER JOIN {$wpdb->term_relationships} ptype_rel ON ptype_rel.object_id = {$type_object} ";
			$join       .= " INNER JOIN {$wpdb->term_taxonomy} ptype_tax ON ptype_tax.term_taxonomy_id = ptype_rel.term_taxonomy_id AND ptype_tax.taxonomy = 'product_type' ";
			$join       .= " INNER JOIN {$wpdb->terms} ptype_term ON ptype_term.term_id = ptype_tax.term_id AND ptype_term.slug = %s ";
			$args[]      = $product_type;
		}

		$stock = sanitize_key( (string) ( $filters[ CatalogTargetFilters::STOCK_STATUS ] ?? '' ) );
		if ( '' !== $stock ) {
			$join  .= " INNER JOIN {$wpdb->postmeta} stock_meta ON stock_meta.post_id = p.ID AND stock_meta.meta_key = '_stock_status' ";
			$where .= ' AND stock_meta.meta_value = %s';
			$args[] = $stock;
		}

		foreach ( [
			CatalogTargetFilters::CATEGORY_ID       => 'product_cat',
			CatalogTargetFilters::TAG_ID            => 'product_tag',
			CatalogTargetFilters::SHIPPING_CLASS_ID => 'product_shipping_class',
		] as $filter_key => $taxonomy ) {
			$term_id = (int) ( $filters[ $filter_key ] ?? 0 );
			if ( $term_id <= 0 ) {
				continue;
			}
			$alias   = 'tax_' . $filter_key;
			$object  = $is_variation && 'product_shipping_class' !== $taxonomy ? 'p.post_parent' : 'p.ID';
			$join   .= " INNER JOIN {$wpdb->term_relationships} {$alias} ON {$alias}.object_id = {$object} ";
			$where  .= " AND {$alias}.term_taxonomy_id = %d";
			$args[]  = $term_id;
		}

		$scopes      = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$fields      = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
		$collections = TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );

		$exception = (string) ( $filters[ CatalogTargetFilters::EXCEPTION_STATE ] ?? '' );
		if ( CatalogTargetFilters::EXCEPTION_PRODUCT === $exception || CatalogTargetFilters::VARIATION_OVERRIDE === (string) ( $filters[ CatalogTargetFilters::VARIATION_STATE ] ?? '' ) ) {
			$where .= " AND EXISTS (SELECT 1 FROM `{$scopes}` cs_ex WHERE cs_ex.scope_type = %s AND cs_ex.scope_id = p.ID AND cs_ex.slice_key = %s AND cs_ex.status = 'active')";
			$args[] = $scope_type;
			$args[] = ConfigurationScope::DEFAULT_SLICE_KEY;
		} elseif ( CatalogTargetFilters::EXCEPTION_SITE_WIDE === $exception || CatalogTargetFilters::VARIATION_INHERIT === (string) ( $filters[ CatalogTargetFilters::VARIATION_STATE ] ?? '' ) ) {
			$where .= " AND NOT EXISTS (SELECT 1 FROM `{$scopes}` cs_sw WHERE cs_sw.scope_type = %s AND cs_sw.scope_id = p.ID AND cs_sw.slice_key = %s AND cs_sw.status = 'active')";
			$args[] = $scope_type;
			$args[] = ConfigurationScope::DEFAULT_SLICE_KEY;
		}

		$configured = (string) ( $filters[ CatalogTargetFilters::CONFIGURED_FULFILMENT ] ?? '' );
		if ( '' !== $configured ) {
			[ $exists_sql, $exists_args ] = $this->configured_field_exists_sql( $scopes, $fields, $scope_type, ConfigurationFieldKey::FULFILMENT_AVAILABILITY, $configured );
			$where                       .= $exists_sql;
			foreach ( $exists_args as $arg ) {
				$args[] = $arg;
			}
		}

		$logistics = (int) ( $filters[ CatalogTargetFilters::LOGISTICS_PROFILE_ID ] ?? 0 );
		if ( $logistics > 0 ) {
			[ $exists_sql, $exists_args ] = $this->configured_field_exists_sql( $scopes, $fields, $scope_type, ConfigurationFieldKey::LOGISTICS_PROFILE_ID, (string) $logistics );
			$where                       .= $exists_sql;
			foreach ( $exists_args as $arg ) {
				$args[] = $arg;
			}
		}

		$supplier = (int) ( $filters[ CatalogTargetFilters::SUPPLIER_ID ] ?? 0 );
		if ( $supplier > 0 ) {
			[ $exists_sql, $exists_args ] = $this->configured_field_exists_sql( $scopes, $fields, $scope_type, ConfigurationFieldKey::SUPPLIER_ID, (string) $supplier );
			$where                       .= $exists_sql;
			foreach ( $exists_args as $arg ) {
				$args[] = $arg;
			}
		}

		$origin = (int) ( $filters[ CatalogTargetFilters::ORIGIN_ID ] ?? 0 );
		if ( $origin > 0 ) {
			[ $exists_sql, $exists_args ] = $this->configured_field_exists_sql( $scopes, $fields, $scope_type, ConfigurationFieldKey::ORIGIN_ID, (string) $origin );
			$where                       .= $exists_sql;
			foreach ( $exists_args as $arg ) {
				$args[] = $arg;
			}
		}

		$offer_id = (int) ( $filters[ CatalogTargetFilters::DELIVERY_OPTION_ID ] ?? 0 );
		if ( $offer_id > 0 ) {
			$where .= " AND EXISTS (SELECT 1 FROM `{$scopes}` cs_of INNER JOIN `{$collections}` cc_of ON cc_of.scope_row_id = cs_of.id WHERE cs_of.scope_type = %s AND cs_of.scope_id = p.ID AND cs_of.slice_key = %s AND cs_of.status = 'active' AND cc_of.field_key = %s AND (cc_of.members_json = %s OR cc_of.members_json LIKE %s OR cc_of.members_json LIKE %s OR cc_of.members_json LIKE %s))";
			$args[] = $scope_type;
			$args[] = ConfigurationScope::DEFAULT_SLICE_KEY;
			$args[] = ConfigurationFieldKey::DELIVERY_OFFER_IDS;
			$args[] = '[' . $offer_id . ']';
			$args[] = '[' . $offer_id . ',%';
			$args[] = '%,' . $offer_id . ',%';
			$args[] = '%,' . $offer_id . ']';
		}

		$pickup = (int) ( $filters[ CatalogTargetFilters::PICKUP_LOCATION_ID ] ?? 0 );
		if ( $pickup > 0 ) {
			$pickups = TableNames::for( 'pickup_locations' );
			$offers  = TableNames::for( 'delivery_offers' );
			$where  .= " AND EXISTS (SELECT 1 FROM `{$pickups}` pl_f WHERE pl_f.id = %d) AND (";
			$args[]  = $pickup;
			[ $in_store_sql, $in_store_args ] = $this->configured_field_exists_sql( $scopes, $fields, $scope_type, ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value );
			$where .= substr( $in_store_sql, 5 );
			foreach ( $in_store_args as $arg ) {
				$args[] = $arg;
			}
			$where .= " OR EXISTS (SELECT 1 FROM `{$scopes}` cs_pk INNER JOIN `{$collections}` cc_pk ON cc_pk.scope_row_id = cs_pk.id INNER JOIN `{$offers}` do_pk ON cc_pk.members_json LIKE CONCAT('%', do_pk.id, '%') WHERE cs_pk.scope_type = %s AND cs_pk.scope_id = p.ID AND cs_pk.slice_key = %s AND cs_pk.status = 'active' AND cc_pk.field_key = %s AND do_pk.route = %s)";
			$args[] = $scope_type;
			$args[] = ConfigurationScope::DEFAULT_SLICE_KEY;
			$args[] = ConfigurationFieldKey::DELIVERY_OFFER_IDS;
			$args[] = 'store_pickup';
			$where .= ')';
		}

		$select = $count ? 'COUNT(DISTINCT p.ID)' : 'DISTINCT p.ID';
		$sql    = "SELECT {$select} FROM {$wpdb->posts} p {$join} WHERE {$where}";
		if ( ! $count ) {
			$sql   .= ' ORDER BY p.ID ASC LIMIT %d';
			$args[] = max( 1, min( 250, $limit ) );
		}

		return [ $sql, $args ];
	}

	/**
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function configured_field_exists_sql( string $scopes, string $fields, string $scope_type, string $field_key, string $value ): array {
		$sql = " AND EXISTS (SELECT 1 FROM `{$scopes}` cs_cf INNER JOIN `{$fields}` cf_cf ON cf_cf.scope_row_id = cs_cf.id WHERE cs_cf.scope_type = %s AND cs_cf.scope_id = p.ID AND cs_cf.slice_key = %s AND cs_cf.status = 'active' AND cf_cf.field_key = %s AND cf_cf.mode = %s AND cf_cf.value_text = %s)";

		return [
			$sql,
			[
				$scope_type,
				ConfigurationScope::DEFAULT_SLICE_KEY,
				$field_key,
				ScalarConfigurationMode::Override->value,
				$value,
			],
		];
	}

	private function passes_effective( CatalogTargetDefinition $definition, int $id ): bool {
		if ( ! $this->resolver instanceof EffectiveConfigurationResolver ) {
			return true;
		}

		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type;
		$parent       = $is_variation ? $this->parent_product_id( $id ) : null;
		$product_id   = $is_variation ? ( $parent ?? $id ) : $id;
		$variation_id = $is_variation ? $id : null;

		try {
			$config = $this->resolver->resolve(
				new EffectiveConfigurationRequest( $product_id, $variation_id, ConfigurationScope::DEFAULT_SLICE_KEY, $parent )
			);
		} catch ( \Throwable ) {
			return false;
		}

		$filters = $definition->filters;
		$wanted  = (string) ( $filters[ CatalogTargetFilters::EFFECTIVE_FULFILMENT ] ?? '' );
		if ( '' !== $wanted ) {
			$field = $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
			$have  = is_object( $field ) ? (string) $field->value : '';
			if ( $have !== $wanted ) {
				return false;
			}
		}

		if ( ! empty( $filters[ CatalogTargetFilters::INVALID_EFFECTIVE ] ) ) {
			if ( EffectiveFieldState::Invalid !== $config->state && EffectiveFieldState::Unresolved !== $config->state ) {
				return false;
			}
		}

		if ( ! empty( $filters[ CatalogTargetFilters::MISSING_USABLE_RATE ] ) ) {
			if ( ! $this->readiness instanceof OperationalReadinessAssessor ) {
				$offers = $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
				if ( null !== $offers && EffectiveFieldState::Valid === $offers->state && [] !== $offers->members ) {
					return false;
				}
			} else {
				$reason = $this->readiness->reason_for( $product_id, $variation_id );
				if ( null === $reason || ( ! str_contains( strtolower( $reason ), 'option' ) && ! str_contains( strtolower( $reason ), 'missing' ) ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
