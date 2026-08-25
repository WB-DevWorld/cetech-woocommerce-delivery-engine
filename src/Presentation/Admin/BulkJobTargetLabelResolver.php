<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/**
 * Page-bounded product/variation display names for Bulk Tools job items.
 *
 * Loads only the IDs on the current admin page. Never walks the whole job.
 */
final class BulkJobTargetLabelResolver {

	public const MAX_PAGE_IDS = 200;

	public const VARIATION_COUNT_CAP = 2000;

	/**
	 * @param array<int, array{title: string, sku: string, parent_id: int, parent_title: string, is_variation: bool}> $catalog
	 * @param array<int, array{inherit: int, override: int}> $variation_counts
	 */
	public function __construct(
		private readonly array $catalog = [],
		private readonly array $variation_counts = []
	) {
	}

	/**
	 * @param list<BulkJobItem> $items
	 */
	public static function for_page( array $items ): self {
		$ids = self::collect_ids( $items );
		if ( [] === $ids || ! function_exists( 'get_posts' ) ) {
			return new self();
		}

		$posts = get_posts(
			[
				'post_type'              => [ 'product', 'product_variation' ],
				'post_status'            => 'any',
				'post__in'               => $ids,
				'posts_per_page'         => count( $ids ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$by_id = [];
		foreach ( is_array( $posts ) ? $posts : [] as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
				continue;
			}
			$id = (int) $post->ID;
			$by_id[ $id ] = [
				'title'        => (string) ( $post->post_title ?? '' ),
				'sku'          => '',
				'parent_id'    => (int) ( $post->post_parent ?? 0 ),
				'parent_title' => '',
				'is_variation' => 'product_variation' === (string) ( $post->post_type ?? '' ),
			];
		}

		$skus = self::load_skus( array_keys( $by_id ) );
		foreach ( $skus as $id => $sku ) {
			if ( isset( $by_id[ $id ] ) ) {
				$by_id[ $id ]['sku'] = $sku;
			}
		}

		foreach ( $by_id as $id => $row ) {
			$parent_id = (int) $row['parent_id'];
			if ( $parent_id > 0 && isset( $by_id[ $parent_id ] ) ) {
				$by_id[ $id ]['parent_title'] = (string) $by_id[ $parent_id ]['title'];
			}
		}

		$parent_ids = [];
		foreach ( $items as $item ) {
			if ( 'variation' !== $item->target_type ) {
				$parent_ids[] = $item->target_id;
			}
		}

		return new self( $by_id, self::load_variation_counts( array_values( array_unique( $parent_ids ) ) ) );
	}

	/**
	 * @param list<BulkJobItem> $items
	 *
	 * @return list<int>
	 */
	public static function collect_ids( array $items ): array {
		$ids = [];
		foreach ( $items as $item ) {
			if ( $item->target_id > 0 ) {
				$ids[] = $item->target_id;
			}
			if ( null !== $item->parent_target_id && $item->parent_target_id > 0 ) {
				$ids[] = $item->parent_target_id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( count( $ids ) > self::MAX_PAGE_IDS ) {
			$ids = array_slice( $ids, 0, self::MAX_PAGE_IDS );
		}

		return $ids;
	}

	/**
	 * @return array{primary: string, secondary: string, raw_id: string}
	 */
	public function display( BulkJobItem $item ): array {
		$row = $this->catalog[ $item->target_id ] ?? null;
		$sku = '' !== $item->external_key ? $item->external_key : (string) ( $row['sku'] ?? '' );

		if ( null === $row ) {
			$primary = '' !== $sku ? $sku : sprintf( '#%d', $item->target_id );

			return [
				'primary'   => $primary,
				'secondary' => sprintf(
					/* translators: %d: WooCommerce product ID */
					__( 'Product #%d', 'cetech-woocommerce-delivery-engine' ),
					$item->target_id
				),
				'raw_id'    => (string) $item->target_id,
			];
		}

		$title = trim( (string) $row['title'] );
		if ( $row['is_variation'] ) {
			$parent = trim( (string) $row['parent_title'] );
			$variation_bit = '' !== $title ? $title : sprintf( '#%d', $item->target_id );
			$primary = '' !== $parent ? $parent . ' — ' . $variation_bit : $variation_bit;
			$secondary_parts = [];
			if ( '' !== $sku ) {
				$secondary_parts[] = sprintf(
					/* translators: %s: SKU */
					__( 'Variation SKU: %s', 'cetech-woocommerce-delivery-engine' ),
					$sku
				);
			}
			$secondary_parts[] = sprintf(
				/* translators: %d: variation ID */
				__( 'Variation #%d', 'cetech-woocommerce-delivery-engine' ),
				$item->target_id
			);

			return [
				'primary'   => $primary,
				'secondary' => implode( ' · ', $secondary_parts ),
				'raw_id'    => (string) $item->target_id,
			];
		}

		$primary = '' !== $title ? $title : ( '' !== $sku ? $sku : sprintf( '#%d', $item->target_id ) );
		$secondary_parts = [];
		if ( '' !== $sku ) {
			$secondary_parts[] = sprintf(
				/* translators: %s: SKU */
				__( 'SKU: %s', 'cetech-woocommerce-delivery-engine' ),
				$sku
			);
		}
		$secondary_parts[] = sprintf(
			/* translators: %d: WooCommerce product ID */
			__( 'Product #%d', 'cetech-woocommerce-delivery-engine' ),
			$item->target_id
		);

		return [
			'primary'   => $primary,
			'secondary' => implode( ' · ', $secondary_parts ),
			'raw_id'    => (string) $item->target_id,
		];
	}

	/**
	 * @return array{inherit: int, override: int}
	 */
	public function variation_counts( int $product_id ): array {
		return $this->variation_counts[ $product_id ] ?? [ 'inherit' => 0, 'override' => 0 ];
	}

	/**
	 * @param list<int> $ids
	 *
	 * @return array<int, string>
	 */
	private static function load_skus( array $ids ): array {
		global $wpdb;
		if ( [] === $ids || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND post_id IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$ids ), ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$id = (int) ( $row['post_id'] ?? 0 );
			if ( $id > 0 ) {
				$out[ $id ] = (string) ( $row['meta_value'] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * @param list<int> $parent_ids
	 *
	 * @return array<int, array{inherit: int, override: int}>
	 */
	private static function load_variation_counts( array $parent_ids ): array {
		$parent_ids = array_values( array_filter( $parent_ids, static fn ( int $id ): bool => $id > 0 ) );
		if ( [] === $parent_ids || ! function_exists( 'get_posts' ) ) {
			return [];
		}

		$variations = get_posts(
			[
				'post_type'              => 'product_variation',
				'post_status'            => 'any',
				'post_parent__in'        => $parent_ids,
				'posts_per_page'         => self::VARIATION_COUNT_CAP,
				'fields'                 => 'id=>parent',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		if ( ! is_array( $variations ) || [] === $variations ) {
			return [];
		}

		$by_parent = [];
		$var_ids   = [];
		foreach ( $variations as $variation_id => $parent_id ) {
			$variation_id = (int) $variation_id;
			$parent_id    = (int) $parent_id;
			if ( $variation_id <= 0 || $parent_id <= 0 ) {
				continue;
			}
			$var_ids[] = $variation_id;
			$by_parent[ $parent_id ]['ids'][] = $variation_id;
		}

		$override_ids = self::variation_ids_with_exception( $var_ids );
		$out          = [];
		foreach ( $by_parent as $parent_id => $group ) {
			$all      = $group['ids'] ?? [];
			$override = 0;
			foreach ( $all as $vid ) {
				if ( isset( $override_ids[ $vid ] ) ) {
					++$override;
				}
			}
			$out[ $parent_id ] = [
				'inherit'  => max( 0, count( $all ) - $override ),
				'override' => $override,
			];
		}

		return $out;
	}

	/**
	 * @param list<int> $variation_ids
	 *
	 * @return array<int, true>
	 */
	private static function variation_ids_with_exception( array $variation_ids ): array {
		global $wpdb;
		if ( [] === $variation_ids || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}

		$table        = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );
		$sql          = "SELECT scope_id FROM `{$table}` WHERE scope_type = %s AND slice_key = %s AND scope_id IN ({$placeholders})";
		$args         = array_merge( [ ConfigurationScopeType::Variation->value, ConfigurationScope::DEFAULT_SLICE_KEY ], $variation_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $id ) {
			$out[ (int) $id ] = true;
		}

		return $out;
	}
}
