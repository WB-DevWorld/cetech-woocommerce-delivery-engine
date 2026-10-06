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
		CatalogTargetFilters::assert_supported( $definition->filters );
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			if ( $definition->selected_ids_materialized || [] === $definition->selected_ids || ! $this->wpdb_ready() ) {
				return $definition->selected_count();
			}

			return $this->count_existing_selected( $definition );
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

	public function catalog_ceiling( CatalogTargetDefinition $definition ): int {
		CatalogTargetFilters::assert_supported( $definition->filters );
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return [] === $definition->selected_ids ? 0 : max( $definition->selected_ids );
		}
		if ( ! $this->wpdb_ready() ) {
			throw new \RuntimeException( 'Catalog scan failed. The database connection is not ready.' );
		}
		global $wpdb;
		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type;
		$type         = $is_variation ? 'product_variation' : 'product';
		$sql          = "SELECT MAX(p.ID) FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ('publish','private','draft')";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$max = $wpdb->get_var( $wpdb->prepare( $sql, $type ) );
		$this->throw_if_query_failed();

		return max( 0, (int) $max );
	}

	public function scan_page( CatalogTargetDefinition $definition, int $after_id, int $limit, int $high_water ): array {
		CatalogTargetFilters::assert_supported( $definition->filters );
		$limit = max( 1, min( 250, $limit ) );
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return $this->scan_selected_page( $definition, $after_id, $limit, $high_water );
		}
		if ( ! $this->wpdb_ready() ) {
			throw new \RuntimeException( 'Catalog scan failed. The database connection is not ready.' );
		}

		$candidates = $this->checked_ids( ...$this->candidate_identity_sql( $definition, $after_id, $limit, $high_water ) );
		$accepted_ids = $this->accept_candidate_ids( $definition, $candidates );
		$accepted     = [];
		foreach ( $candidates as $id ) {
			if ( ! in_array( $id, $accepted_ids, true ) ) {
				continue;
			}
			$accepted[] = new CatalogTarget(
				$definition->target_type,
				$id,
				$this->sku_for( $definition->target_type, $id ),
				CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type ? $this->parent_product_id( $id ) : null
			);
		}
		$cursor = [] === $candidates ? $after_id : (int) $candidates[ count( $candidates ) - 1 ];

		return CatalogTargetDefinition::candidate_page( $accepted, $cursor, count( $candidates ), count( $candidates ) < $limit );
	}

	public function membership( CatalogTargetDefinition $definition, int $target_id ): string {
		CatalogTargetFilters::assert_supported( $definition->filters );
		if ( $target_id <= 0 ) {
			return 'unavailable';
		}
		if ( BulkTargetScope::SelectedIds === $definition->scope && ! $definition->selected_ids_materialized && ! in_array( $target_id, $definition->selected_ids, true ) ) {
			return 'rejected';
		}
		if ( ! $this->wpdb_ready() ) {
			throw new \RuntimeException( 'Catalog scan failed. The database connection is not ready.' );
		}
		$present = $this->checked_ids( ...$this->candidate_identity_sql( $definition, $target_id - 1, 1, $target_id ) );
		if ( ! in_array( $target_id, $present, true ) ) {
			return 'unavailable';
		}
		$accepted = $this->accept_candidate_ids( $definition, [ $target_id ] );

		return in_array( $target_id, $accepted, true ) ? 'accepted' : 'rejected';
	}

	/**
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function candidate_identity_sql( CatalogTargetDefinition $definition, int $after_id, int $limit, int $high_water ): array {
		global $wpdb;
		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type;
		$type         = $is_variation ? 'product_variation' : 'product';
		$sql          = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ('publish','private','draft') AND p.ID > %d AND p.ID <= %d ORDER BY p.ID ASC LIMIT %d";

		return [ $sql, [ $type, $after_id, $high_water, max( 1, $limit ) ] ];
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function accept_candidate_ids( CatalogTargetDefinition $definition, array $ids ): array {
		if ( [] === $ids ) {
			return [];
		}
		if ( BulkTargetScope::MatchingFilters === $definition->scope && ! $definition->has_matching_criteria() ) {
			return [];
		}
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			return $ids;
		}
		[ $sql, $args ] = $this->id_sql( $definition, 0, count( $ids ), false );
		$in             = implode( ',', array_map( 'intval', $ids ) );
		$sql            = (string) preg_replace( '/ ORDER BY p\.ID ASC LIMIT %d$/', " AND p.ID IN ({$in}) ORDER BY p.ID ASC LIMIT %d", $sql );
		$matched        = $this->checked_ids( $sql, $args );
		if ( ! CatalogTargetFilters::requires_effective_eval( $definition->filters ) ) {
			return $matched;
		}
		$kept = [];
		foreach ( $matched as $id ) {
			if ( $this->passes_effective( $definition, $id, true ) ) {
				$kept[] = $id;
			}
		}

		return $kept;
	}

	private function scan_selected_page( CatalogTargetDefinition $definition, int $after_id, int $limit, int $high_water ): array {
		$ids = [];
		foreach ( $definition->selected_ids as $id ) {
			$id = (int) $id;
			if ( $id > $after_id && $id <= $high_water ) {
				$ids[] = $id;
			}
		}
		sort( $ids, SORT_NUMERIC );
		$slice = array_slice( $ids, 0, $limit );
		$present = [] === $slice || ! $this->wpdb_ready()
			? []
			: $this->checked_ids( ...$this->selected_presence_sql( $definition, $slice ) );
		$accepted = [];
		$cursor   = $after_id;
		foreach ( $slice as $id ) {
			$cursor = $id;
			if ( ! in_array( $id, $present, true ) ) {
				continue;
			}
			$accepted[] = new CatalogTarget(
				$definition->target_type,
				$id,
				$this->sku_for( $definition->target_type, $id ),
				CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type ? $this->parent_product_id( $id ) : null
			);
		}

		return CatalogTargetDefinition::candidate_page( $accepted, $cursor, count( $slice ), count( $ids ) <= $limit );
	}

	/**
	 * @param list<int> $ids
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function selected_presence_sql( CatalogTargetDefinition $definition, array $ids ): array {
		global $wpdb;
		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type;
		$type         = $is_variation ? 'product_variation' : 'product';
		$in           = implode( ',', array_map( 'intval', $ids ) );
		$sql          = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ('publish','private','draft') AND p.ID IN ({$in})";

		return [ $sql, [ $type ] ];
	}

	/**
	 * @param list<mixed> $args
	 * @return list<int>
	 */
	private function checked_ids( string $sql, array $args ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
		$this->throw_if_query_failed();

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	private function throw_if_query_failed(): void {
		global $wpdb;
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		if ( '' !== $error ) {
			throw new \RuntimeException( 'Catalog scan failed. ' . $error );
		}
	}

	/**
	 * @return list<int>
	 */
	private function matching_ids( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		CatalogTargetFilters::assert_supported( $definition->filters );
		if ( BulkTargetScope::SelectedIds === $definition->scope ) {
			if ( $definition->selected_ids_materialized || [] === $definition->selected_ids || ! $this->wpdb_ready() ) {
				return CatalogTargetDefinition::page_sorted_ids( $definition->selected_ids, $after_id, $limit );
			}

			return $this->existing_selected_ids( $definition, $after_id, $limit );
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
		while ( count( $collected ) < $limit ) {
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
			$alias     = 'tax_' . $filter_key;
			$tax_alias = $alias . '_tt';
			$object    = $is_variation && 'product_shipping_class' !== $taxonomy ? 'p.post_parent' : 'p.ID';
			$join     .= " INNER JOIN {$wpdb->term_relationships} {$alias} ON {$alias}.object_id = {$object} ";
			$join     .= " INNER JOIN {$wpdb->term_taxonomy} {$tax_alias} ON {$tax_alias}.term_taxonomy_id = {$alias}.term_taxonomy_id ";
			$where    .= " AND {$tax_alias}.taxonomy = %s AND {$tax_alias}.term_id = %d";
			$args[]    = $taxonomy;
			$args[]    = $term_id;
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
			[ $offer_sql, $offer_args ] = $this->offer_membership_sql( $is_variation, $scopes, $collections, $scope_type, $offer_id );
			$where                     .= $offer_sql;
			foreach ( $offer_args as $arg ) {
				$args[] = $arg;
			}
		}

		$pickup = (int) ( $filters[ CatalogTargetFilters::PICKUP_LOCATION_ID ] ?? 0 );
		if ( $pickup > 0 ) {
			[ $pickup_sql, $pickup_args ] = $this->configured_field_exists_sql( $scopes, $fields, $scope_type, ConfigurationFieldKey::PICKUP_LOCATION_ID, (string) $pickup );
			$where                       .= $pickup_sql;
			foreach ( $pickup_args as $arg ) {
				$args[] = $arg;
			}
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

	private function wpdb_ready(): bool {
		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->posts );
	}

	private function count_existing_selected( CatalogTargetDefinition $definition ): int {
		[ $sql, $args ] = $this->selected_identity_sql( $definition, 0, 0, true );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

		return (int) $count;
	}

	/**
	 * @return list<int>
	 */
	private function existing_selected_ids( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		[ $sql, $args ] = $this->selected_identity_sql( $definition, $after_id, $limit, false );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function selected_identity_sql( CatalogTargetDefinition $definition, int $after_id, int $limit, bool $count ): array {
		global $wpdb;

		$ids = [];
		foreach ( $definition->selected_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( [] === $ids ) {
			return [ 'SELECT 0', [] ];
		}

		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $definition->target_type;
		$type         = $is_variation ? 'product_variation' : 'product';
		$in           = implode( ',', $ids );
		$select       = $count ? 'COUNT(DISTINCT p.ID)' : 'DISTINCT p.ID';
		$sql          = "SELECT {$select} FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ('publish','private','draft') AND p.ID > %d AND p.ID IN ({$in})";
		$args         = [ $type, $after_id ];
		if ( ! $count ) {
			$sql   .= ' ORDER BY p.ID ASC LIMIT %d';
			$args[] = max( 1, min( 250, $limit ) );
		}

		return [ $sql, $args ];
	}

	/**
	 * Add/Replace exact membership, or inherited Add/Replace when this scope does not Replace or Remove that member.
	 *
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function offer_membership_sql( bool $is_variation, string $scopes, string $collections, string $scope_type, int $offer_id ): array {
		$member = json_encode( $offer_id, JSON_THROW_ON_ERROR );
		$slice  = ConfigurationScope::DEFAULT_SLICE_KEY;
		$field  = ConfigurationFieldKey::DELIVERY_OFFER_IDS;
		$args   = [ $scope_type, $slice, $field, $member, $scope_type, $slice, $field, $member ];
		$local  = $this->offer_include_exists_sql( 'cs_of', 'cc_of', $scopes, $collections, 'p.ID' );
		$block  = $this->offer_block_exists_sql( 'cs_blk', 'cc_blk', $scopes, $collections, 'p.ID' );
		if ( $is_variation ) {
			$inherited = '(' . $this->offer_include_exists_sql( 'cs_parent', 'cc_parent', $scopes, $collections, 'p.post_parent', true )
				. ' OR (NOT ' . $this->offer_block_exists_sql( 'cs_pblk', 'cc_pblk', $scopes, $collections, 'p.post_parent', true )
				. ' AND ' . $this->global_offer_include_sql( $scopes, $collections ) . '))';
			array_push(
				$args,
				ConfigurationScopeType::Product->value,
				$slice,
				$field,
				$member,
				ConfigurationScopeType::Product->value,
				$slice,
				$field,
				$member
			);
		} else {
			$inherited = $this->global_offer_include_sql( $scopes, $collections );
		}
		$args[] = ConfigurationScopeType::Global->value;
		$args[] = ConfigurationScope::GLOBAL_SCOPE_ID;
		$args[] = $slice;
		$args[] = $field;
		$args[] = $member;

		return [ ' AND (' . $local . ' OR (NOT ' . $block . ' AND ' . $inherited . '))', $args ];
	}

	private function global_offer_include_sql( string $scopes, string $collections ): string {
		return 'EXISTS (SELECT 1 FROM `' . $scopes . '` cs_g INNER JOIN `' . $collections . '` cc_g ON cc_g.scope_row_id = cs_g.id'
			. " WHERE cs_g.scope_type = %s AND cs_g.scope_id = %d AND cs_g.slice_key = %s AND cs_g.status = 'active'"
			. " AND cc_g.field_key = %s AND cc_g.mode IN ('add','replace') AND " . $this->member_contains( 'cc_g.members_json' ) . ')';
	}

	private function offer_include_exists_sql( string $scope_alias, string $collection_alias, string $scopes, string $collections, string $scope_id_sql, bool $require_positive_parent = false ): string {
		$parent = $require_positive_parent ? ' AND p.post_parent > 0' : '';

		return 'EXISTS (SELECT 1 FROM `' . $scopes . '` ' . $scope_alias . ' INNER JOIN `' . $collections . '` ' . $collection_alias
			. ' ON ' . $collection_alias . '.scope_row_id = ' . $scope_alias . '.id'
			. ' WHERE ' . $scope_alias . '.scope_type = %s AND ' . $scope_alias . '.scope_id = ' . $scope_id_sql . $parent
			. ' AND ' . $scope_alias . ".slice_key = %s AND " . $scope_alias . ".status = 'active'"
			. ' AND ' . $collection_alias . ".field_key = %s AND " . $collection_alias . ".mode IN ('add','replace')"
			. ' AND ' . $this->member_contains( $collection_alias . '.members_json' ) . ')';
	}

	private function offer_block_exists_sql( string $scope_alias, string $collection_alias, string $scopes, string $collections, string $scope_id_sql, bool $require_positive_parent = false ): string {
		$parent = $require_positive_parent ? ' AND p.post_parent > 0' : '';

		return 'EXISTS (SELECT 1 FROM `' . $scopes . '` ' . $scope_alias . ' INNER JOIN `' . $collections . '` ' . $collection_alias
			. ' ON ' . $collection_alias . '.scope_row_id = ' . $scope_alias . '.id'
			. ' WHERE ' . $scope_alias . '.scope_type = %s AND ' . $scope_alias . '.scope_id = ' . $scope_id_sql . $parent
			. ' AND ' . $scope_alias . ".slice_key = %s AND " . $scope_alias . ".status = 'active'"
			. ' AND ' . $collection_alias . '.field_key = %s AND (' . $collection_alias . ".mode = 'replace' OR ("
			. $collection_alias . ".mode = 'remove' AND " . $this->member_contains( $collection_alias . '.members_json' ) . ')))';
	}

	private function member_contains( string $column ): string {
		return 'JSON_VALID(' . $column . ") AND JSON_CONTAINS(" . $column . ", %s, '\$')";
	}

	private function passes_effective( CatalogTargetDefinition $definition, int $id, bool $strict = false ): bool {
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
		} catch ( \Throwable $exception ) {
			if ( $strict ) {
				throw new \RuntimeException( 'Catalog scan failed. ' . $exception->getMessage(), 0, $exception );
			}

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
