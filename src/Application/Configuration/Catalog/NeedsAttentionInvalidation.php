<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\CatalogReadinessSignal;

/**
 * Marks the Needs Attention catalog snapshot stale on every write that can
 * change what the catalog scan reports:
 *
 * - scoped configuration, delivery options and pickup locations (the
 *   persistence layer fires CatalogReadinessSignal; Bulk Tools apply and
 *   rollback write through the same repository);
 * - site-wide defaults and the runtime flags that decide whether the catalog
 *   is scanned as customer problems (option hooks);
 * - products entering or leaving the published catalog, being deleted, or
 *   changing product type (the scan covers published simple and variable
 *   products only).
 *
 * There are no category scopes, so term assignment other than product type
 * does not affect the result. Anything that bypasses these paths (direct SQL)
 * is caught by the snapshot TTL.
 */
final class NeedsAttentionInvalidation {

	public function __construct(
		private readonly NeedsAttentionSnapshotService $snapshot
	) {
	}

	/**
	 * @return list<string>
	 */
	public static function watched_options(): array {
		$options = [ SiteWideDefaultsSettings::OPTION_NAME ];
		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$options[] = FeatureFlags::OPTION_PREFIX . $flag;
		}

		return $options;
	}

	public function register(): void {
		$mark = [ $this, 'mark' ];

		add_action( CatalogReadinessSignal::ACTION, $mark );

		foreach ( self::watched_options() as $option ) {
			add_action( 'add_option_' . $option, $mark );
			add_action( 'update_option_' . $option, $mark );
			add_action( 'delete_option_' . $option, $mark );
		}

		add_action( 'transition_post_status', [ $this, 'on_post_status' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 10, 2 );
		add_action( 'set_object_terms', [ $this, 'on_object_terms' ], 10, 6 );
		add_action( 'shutdown', [ $this->snapshot, 'flush' ] );
	}

	public function mark(): void {
		$this->snapshot->mark_dirty();
	}

	public function on_post_status( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( $new_status === $old_status || ! $this->is_product( $post ) ) {
			return;
		}

		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			$this->snapshot->mark_dirty();
		}
	}

	public function on_deleted_post( mixed $post_id, mixed $post = null ): void {
		if ( $this->is_product( $post ) ) {
			$this->snapshot->mark_dirty();
		}
	}

	/**
	 * @param mixed $tt_ids     New term taxonomy ids.
	 * @param mixed $old_tt_ids Previous term taxonomy ids.
	 */
	public function on_object_terms( mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy, mixed $append = false, mixed $old_tt_ids = [] ): void {
		if ( 'product_type' !== $taxonomy ) {
			return;
		}

		$new = array_map( 'intval', is_array( $tt_ids ) ? $tt_ids : [] );
		$old = array_map( 'intval', is_array( $old_tt_ids ) ? $old_tt_ids : [] );
		sort( $new );
		sort( $old );

		if ( $new !== $old ) {
			$this->snapshot->mark_dirty();
		}
	}

	private function is_product( mixed $post ): bool {
		return is_object( $post ) && isset( $post->post_type ) && 'product' === $post->post_type;
	}
}
