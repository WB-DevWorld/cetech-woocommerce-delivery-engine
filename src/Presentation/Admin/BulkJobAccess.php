<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;

/**
 * Rechecks existing wp-admin operation permissions against durable job content.
 * Actor attribution is not a grant; this does not define job delegation or CLI policy.
 */
final class BulkJobAccess {

	public function __construct( private readonly BulkJobEngine $engine ) {
	}

	public function require_action_access( BulkJob $job ): void {
		if ( ! $this->is_allowed( $job, false ) ) {
			throw new \RuntimeException( __( 'You do not have permission to access this bulk job.', 'cetech-woocommerce-delivery-engine' ) );
		}
	}

	public function can_access( BulkJob $job ): bool {
		return $this->is_allowed( $job, true );
	}

	private function is_allowed( BulkJob $job, bool $read_retained_payload ): bool {
		if ( AdminPageAccess::current_user_is_restricted() ) {
			return false;
		}

		$seen = [];
		while ( BulkOperationType::Rollback === $job->operation_type ) {
			if ( null === $job->id || isset( $seen[ $job->id ] ) || null === $job->parent_job_id || $job->parent_job_id <= 0 || ! $this->can_access_private_content( $job, $read_retained_payload ) ) {
				return false;
			}
			$seen[ $job->id ] = true;
			$parent          = $this->engine->find( $job->parent_job_id );
			if ( ! $parent instanceof BulkJob ) {
				return false;
			}
			$job = $parent;
		}

		$capability = match ( $job->operation_type ) {
			BulkOperationType::CatalogUpdate, BulkOperationType::ValidationScan => 'manage_product_delivery_rules',
			BulkOperationType::CatalogCsvImport, BulkOperationType::CatalogCsvExport, BulkOperationType::ConfigImport => 'import_delivery_data',
			BulkOperationType::ConfigExport => 'manage_delivery_settings',
			BulkOperationType::RateCardUpdate => 'manage_delivery_rate_cards',
			// These types have no supported initiating route or approved operation grant.
			default => null,
		};

		return null !== $capability && current_user_can( $capability ) && $this->can_access_private_content( $job, $read_retained_payload );
	}

	private function can_access_private_content( BulkJob $job, bool $read_retained_payload ): bool {
		$manifest = $job->action_manifest;
		$package  = is_array( $manifest['package'] ?? null ) ? $manifest['package'] : [];
		$sections = is_array( $package['sections'] ?? null ) ? $package['sections'] : [];
		$package_manifest = is_array( $package['manifest'] ?? null ) ? $package['manifest'] : [];
		// The worker uses the stored job flag as its private import permission.
		// A public-only import still skips supplied private sections. Reading its
		// retained package/results is a separate disclosure boundary.
		$private = ! empty( $manifest['include_private_sources'] );
		if ( $read_retained_payload ) {
			$private = $private || ! empty( $package_manifest['include_private_sources'] ) || ! empty( $sections['suppliers'] ) || ! empty( $sections['origins'] );
		}
		if ( $private ) {
			if ( ! current_user_can( 'manage_private_sources' ) ) {
				return false;
			}
		}

		// The existing catalog picker permits either of these capabilities to target
		// private sources. Recheck the same rule when reopening its stored filter.
		$filters = is_array( $job->target_definition['filters'] ?? null ) ? $job->target_definition['filters'] : [];
		if ( ! empty( $filters['supplier_id'] ) || ! empty( $filters['origin_id'] ) ) {
			if ( ! current_user_can( 'manage_private_sources' ) && ! current_user_can( 'view_private_origins' ) ) {
				return false;
			}
		}

		return true;
	}
}
