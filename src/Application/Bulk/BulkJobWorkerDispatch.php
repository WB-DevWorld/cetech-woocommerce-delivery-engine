<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigImportConflictMode;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkMutator;

/**
 * Operation-type dispatch for the bulk worker. Catalog updates remain the default path.
 */
trait BulkJobWorkerDispatch {

	/**
	 * @return array<string, mixed>
	 */
	private function process_claimed_item( BulkJob $job, BulkJobItem $item, CatalogActionManifest $manifest, CatalogTargetDefinition $definition ): array {
		if ( BulkOperationType::RateCardUpdate === $job->operation_type ) {
			if ( ! $this->rate_mutator instanceof RateCardBulkMutator ) {
				return $this->missing_processor( 'rate_processor_unavailable', 'Rate Charge bulk processing is not available.' );
			}

			return $this->rate_mutator->process( $item->target_id, $job->action_manifest, $job->dry_run );
		}

		if ( BulkOperationType::ConfigImport === $job->operation_type ) {
			if ( ! $this->importer instanceof ConfigurationImporter ) {
				return $this->missing_processor( 'importer_unavailable', 'Configuration import is not available.' );
			}
			$mode          = ConfigImportConflictMode::tryFrom( (string) ( $job->action_manifest['conflict_mode'] ?? '' ) ) ?? ConfigImportConflictMode::SkipConflicts;
			$allow_private = ! empty( $job->action_manifest['include_private_sources'] );
			$row           = is_array( $item->result['row'] ?? null ) ? $item->result['row'] : [];
			$applied       = $this->importer->apply_item( $item->target_type, $row, $mode, $job->dry_run, $allow_private );

			return [
				'outcome'                  => $applied['outcome'],
				'error_code'               => $applied['error_code'],
				'error_summary'            => $applied['error_summary'],
				'warning'                  => false,
				'before_snapshot'          => [],
				'precondition_fingerprint' => '',
				'after_fingerprint'        => '',
				'result'                   => [ 'section' => $item->target_type, 'code' => $item->external_key ],
			];
		}

		$item_manifest = $manifest;
		$target_id     = $item->target_id;
		$parent        = $item->parent_target_id;
		if ( BulkOperationType::CatalogCsvImport === $job->operation_type ) {
			if ( array_key_exists( 'ok', $item->result ) && true !== $item->result['ok'] ) {
				$errors = is_array( $item->result['errors'] ?? null ) ? $item->result['errors'] : [];
				return $this->missing_processor(
					'csv_row_invalid',
					implode( ' ', array_map( 'strval', $errors ) ) ?: 'CSV row is invalid.'
				);
			}
			$row_actions   = is_array( $item->result['actions'] ?? null ) ? $item->result['actions'] : [];
			$item_manifest = CatalogActionManifest::from_array( [ 'field_actions' => $row_actions ] );
			$sku_type      = CatalogTargetDefinition::TARGET_VARIATION === $item->target_type
				? CatalogTargetDefinition::TARGET_VARIATION
				: CatalogTargetDefinition::TARGET_PRODUCT;
			if ( $target_id <= 0 && '' !== $item->external_key ) {
				$resolved = $this->targets->find_id_by_sku( $item->external_key, $sku_type );
				if ( null === $resolved ) {
					return $this->missing_processor( 'sku_not_found', 'No catalog item matches this SKU.' );
				}
				$target_id = $resolved;
			}
		}

		if ( CatalogTargetDefinition::TARGET_VARIATION === $item->target_type && null === $parent ) {
			$parent = $this->targets->parent_product_id( $target_id );
		}

		$dry_run = $job->dry_run || BulkOperationType::ValidationScan === $job->operation_type;

		return $this->mutator->process(
			$item->target_type,
			$target_id,
			$parent,
			$item_manifest,
			$dry_run
		);
	}

	private function enumerate_csv( BulkJob $job, float $started ): void {
		$csv = (string) ( $job->action_manifest['csv'] ?? '' );
		if ( ! $this->csv instanceof CatalogCsvMapper || '' === $csv ) {
			$job = $job->with_progress( $job->total_count, $job->enumerated_count, $job->processed_count, $job->changed_count, $job->skipped_count, $job->failed_count, $job->warning_count, true, $job->checkpoint_cursor, $job->summary );
			$this->jobs->save_job( $job );
			return;
		}
		$parsed = $this->csv->parse_csv( $csv );
		$rows   = $parsed['rows'];
		$after  = (int) $job->checkpoint_cursor;
		$limit  = $job->batch_size;
		$slice  = array_slice( $rows, $after, $limit );
		$items  = [];
		$index  = $after;
		foreach ( $slice as $row ) {
			++$index;
			$variation_id = (int) ( $row['variation_id'] ?? 0 );
			$product_id   = (int) ( $row['product_id'] ?? 0 );
			$target_id    = $variation_id > 0 ? $variation_id : $product_id;
			$items[]      = BulkJobItem::pending(
				(int) $job->id,
				(string) ( $row['target_type'] ?? 'product' ),
				$target_id,
				(string) ( $row['sku'] ?? '' )
			)->with( [ 'result' => $row ] );
		}
		if ( [] !== $items ) {
			$this->jobs->insert_items( $items );
		}
		$complete = count( $slice ) < $limit;
		$job      = $job->with_progress(
			count( $rows ),
			$after + count( $slice ),
			$job->processed_count,
			$job->changed_count,
			$job->skipped_count,
			$job->failed_count,
			$job->warning_count,
			$complete,
			(string) $index,
			$job->summary
		);
		$this->jobs->save_job( $job );
		$this->requeue_if_needed( $job, $started );
	}

	private function enumerate_config( BulkJob $job, float $started ): void {
		if ( ! $this->importer instanceof ConfigurationImporter ) {
			$job = $job->with_progress( 0, 0, 0, 0, 0, 0, 0, true, '0', $job->summary );
			$this->jobs->save_job( $job );
			return;
		}
		$package_payload = $job->action_manifest['package'] ?? null;
		if ( ! is_array( $package_payload ) ) {
			$job = $job->with_progress( 0, 0, 0, 0, 0, 0, 0, true, '0', $job->summary );
			$this->jobs->save_job( $job );
			return;
		}
		$package = new ConfigurationPackage(
			is_array( $package_payload['manifest'] ?? null ) ? $package_payload['manifest'] : [],
			is_array( $package_payload['sections'] ?? null ) ? $package_payload['sections'] : []
		);
		$flat  = $this->importer->flatten( $package );
		$after = (int) $job->checkpoint_cursor;
		$limit = $job->batch_size;
		$slice = array_slice( $flat, $after, $limit );
		$items = [];
		$index = $after;
		foreach ( $slice as $row ) {
			++$index;
			$items[] = BulkJobItem::pending(
				(int) $job->id,
				(string) $row['section'],
				$index,
				(string) $row['code']
			)->with( [ 'result' => [ 'row' => $row['row'] ] ] );
		}
		if ( [] !== $items ) {
			$this->jobs->insert_items( $items );
		}
		$complete = count( $slice ) < $limit;
		$job      = $job->with_progress(
			count( $flat ),
			$after + count( $slice ),
			$job->processed_count,
			$job->changed_count,
			$job->skipped_count,
			$job->failed_count,
			$job->warning_count,
			$complete,
			(string) $index,
			$job->summary
		);
		$this->jobs->save_job( $job );
		$this->requeue_if_needed( $job, $started );
	}

	private function enumerate_selected_ids( BulkJob $job, float $started, string $target_type ): void {
		$definition = CatalogTargetDefinition::from_array( $job->target_definition );
		$after      = (int) $job->checkpoint_cursor;
		$limit      = $job->batch_size;
		$page       = CatalogTargetDefinition::page_sorted_ids( $definition->selected_ids, $after, $limit );
		$items      = [];
		$last       = $after;
		foreach ( $page as $id ) {
			$items[] = BulkJobItem::pending( (int) $job->id, $target_type, $id, (string) $id );
			$last    = $id;
		}
		if ( [] !== $items ) {
			$this->jobs->insert_items( $items );
		}
		$complete = count( $page ) < $limit;
		$job      = $job->with_progress(
			$definition->selected_count(),
			$job->enumerated_count + count( $items ),
			$job->processed_count,
			$job->changed_count,
			$job->skipped_count,
			$job->failed_count,
			$job->warning_count,
			$complete,
			(string) $last,
			$job->summary
		);
		if ( $complete ) {
			$job = $this->release_selection_manifest( $job );
		}
		$this->jobs->save_job( $job );
		$this->requeue_if_needed( $job, $started );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function missing_processor( string $code, string $summary ): array {
		return [
			'outcome'                  => 'failed',
			'error_code'               => $code,
			'error_summary'            => $summary,
			'warning'                  => false,
			'before_snapshot'          => [],
			'precondition_fingerprint' => '',
			'after_fingerprint'        => '',
			'result'                   => [],
		];
	}
}
