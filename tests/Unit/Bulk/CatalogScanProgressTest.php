<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetFilters;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetQueryInterface;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Catalog\WooCommerceCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class CatalogScanProgressTest extends TestCase {

	public function test_a_late_match_survives_more_than_40000_rejected_candidates(): void {
		$query = new InMemoryCatalogTargetQuery();
		for ( $id = 1; $id <= 40000; ++$id ) {
			$query->add(
				new CatalogTarget( 'product', $id, '' ),
				[ CatalogTargetFilters::PRODUCT_TYPE => 'grouped' ]
			);
		}
		$query->add(
			new CatalogTarget( 'product', 40001, 'late' ),
			[ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ]
		);
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, $query );
		$job    = $jobs->save_job( $this->filter_job( [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ], 100 ) );
		$worker->tick( (int) $job->id );
		$partial = $jobs->find_job( (int) $job->id );
		self::assertNotNull( $partial );
		self::assertFalse( $partial->enumeration_complete );
		self::assertSame( '100', $partial->checkpoint_cursor );
		self::assertSame( 100, (int) ( $partial->summary['preparation']['scanned'] ?? 0 ) );
		self::assertSame( 0, $partial->total_count );

		$saved = $this->finish( $worker, $jobs, (int) $job->id, 500 );
		$item  = $jobs->find_item( (int) $job->id, 'product', 40001, 'late' );

		self::assertTrue( $saved->enumeration_complete );
		self::assertSame( 'complete', $saved->summary['preparation']['state'] ?? null );
		self::assertSame( 40001, (int) $saved->summary['preparation']['high_water'] );
		self::assertSame( 40001, (int) $saved->summary['preparation']['scanned'] );
		self::assertSame( 1, (int) $saved->summary['preparation']['effective'] );
		self::assertSame( 1, $saved->total_count );
		self::assertSame( '40001', $saved->checkpoint_cursor );
		self::assertInstanceOf( BulkJobItem::class, $item );
		self::assertNull( $jobs->find_item( (int) $job->id, 'product', 1, '' ) );
	}

	public function test_resume_after_rejected_pages_does_not_accept_twice(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add( new CatalogTarget( 'product', 1, 'no' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'grouped' ] );
		$query->add( new CatalogTarget( 'product', 2, 'no-2' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'grouped' ] );
		$query->add( new CatalogTarget( 'product', 3, 'yes' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, $query );
		$job    = $jobs->save_job( $this->filter_job( [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ], 2 ) );
		$worker->tick( (int) $job->id );
		$mid = $jobs->find_job( (int) $job->id );
		self::assertNotNull( $mid );
		self::assertSame( '2', $mid->checkpoint_cursor );
		self::assertSame( 0, $jobs->count_items( (int) $job->id ) );

		$query->set_attributes( 1, [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$saved = $this->finish( $worker, $jobs, (int) $job->id, 5 );

		self::assertSame( 1, $saved->total_count );
		self::assertNull( $jobs->find_item( (int) $job->id, 'product', 1, 'no' ) );
		self::assertInstanceOf( BulkJobItem::class, $jobs->find_item( (int) $job->id, 'product', 3, 'yes' ) );
	}

	public function test_lower_id_backfill_is_included_only_ahead_of_the_cursor(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add( new CatalogTarget( 'product', 1, 'first' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'grouped' ] );
		$query->add( new CatalogTarget( 'product', 10, 'last' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, $query );
		$job    = $jobs->save_job( $this->filter_job( [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ], 1 ) );
		$worker->tick( (int) $job->id );
		$query->add( new CatalogTarget( 'product', 4, 'backfill' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$query->add( new CatalogTarget( 'product', 11, 'above' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$saved = $this->finish( $worker, $jobs, (int) $job->id, 20 );

		self::assertSame( 10, (int) $saved->summary['preparation']['high_water'] );
		self::assertInstanceOf( BulkJobItem::class, $jobs->find_item( (int) $job->id, 'product', 4, 'backfill' ) );
		self::assertInstanceOf( BulkJobItem::class, $jobs->find_item( (int) $job->id, 'product', 10, 'last' ) );
		self::assertNull( $jobs->find_item( (int) $job->id, 'product', 11, 'above' ) );
		self::assertSame( 2, $saved->total_count );
	}

	public function test_selected_ids_do_not_gain_an_unlisted_product(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add( new CatalogTarget( 'product', 2, 'kept' ) );
		$query->add( new CatalogTarget( 'product', 4, 'extra' ) );
		$query->add( new CatalogTarget( 'product', 5, 'also' ) );
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, $query );
		$job    = $jobs->save_job(
			BulkJob::create(
				BulkOperationType::CatalogUpdate,
				4,
				[
					'scope'        => BulkTargetScope::SelectedIds->value,
					'selected_ids' => [ 5, 2 ],
					'target_type'  => 'product',
				],
				[ 'field_actions' => [] ],
				true,
				25
			)
		);
		$saved = $this->finish( $worker, $jobs, (int) $job->id, 5 );

		self::assertSame( 5, (int) $saved->summary['preparation']['high_water'] );
		self::assertSame( 2, $saved->total_count );
		self::assertNull( $jobs->find_item( (int) $job->id, 'product', 4, 'extra' ) );
	}

	public function test_a_failed_scan_is_not_a_completed_zero_target_job(): void {
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, new ThrowingCatalogQuery() );
		$job    = $jobs->save_job( $this->filter_job( [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ], 25 ) );
		$worker->tick( (int) $job->id );
		$saved = $jobs->find_job( (int) $job->id );

		self::assertNotNull( $saved );
		self::assertSame( BulkJobStatus::Failed, $saved->status );
		self::assertFalse( $saved->enumeration_complete );
		self::assertSame( 'failed', $saved->summary['preparation']['state'] ?? null );
		self::assertStringContainsString( 'Catalog scan failed.', (string) $saved->error_summary );
		self::assertNotSame( BulkJobStatus::Ready, $saved->status );
		self::assertSame( 0, $jobs->count_items( (int) $job->id ) );
	}

	public function test_a_product_that_matches_after_preparation_is_not_applied(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add( new CatalogTarget( 'product', 8, 'kept' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, $query );
		$job    = $jobs->save_job( $this->filter_job( [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ], 25 ) );
		$ready  = $this->finish( $worker, $jobs, (int) $job->id, 5 );
		$query->add( new CatalogTarget( 'product', 9, 'later' ), [ CatalogTargetFilters::PRODUCT_TYPE => 'simple' ] );
		$query->set_attributes( 8, [ CatalogTargetFilters::PRODUCT_TYPE => 'grouped' ] );
		$apply = $ready->with(
			[
				'dry_run' => false,
				'status'  => BulkJobStatus::Queued,
			]
		);
		$jobs->save_job( $apply );
		$item = $jobs->find_item( (int) $job->id, 'product', 8, 'kept' );
		self::assertInstanceOf( BulkJobItem::class, $item );
		$jobs->save_item( $item->with( [ 'status' => BulkJobItemStatus::Pending, 'claim_token' => null ] ) );
		$worker->tick( (int) $job->id );
		$after = $jobs->find_item( (int) $job->id, 'product', 8, 'kept' );

		self::assertInstanceOf( BulkJobItem::class, $after );
		self::assertSame( 'stale_target', $after->error_code );
		self::assertNull( $jobs->find_item( (int) $job->id, 'product', 9, 'later' ) );
		self::assertSame( 1, $jobs->find_job( (int) $job->id )?->total_count );
	}

	public function test_wpdb_scan_walks_rejected_candidates_until_the_late_match(): void {
		$wpdb = new class() {
			public string $prefix = 'wp_';
			public string $posts = 'wp_posts';
			public string $postmeta = 'wp_postmeta';
			public string $term_relationships = 'wp_term_relationships';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $terms = 'wp_terms';
			public string $last_error = '';
			public int $candidate_queries = 0;
			public int $match = 2501;

			public function esc_like( string $text ): string {
				return $text;
			}

			public function prepare( string $query, mixed ...$args ): string {
				$index = 0;

				return (string) preg_replace_callback(
					'/%[sd]/',
					static function ( array $match ) use ( &$index, $args ): string {
						$value = $args[ $index++ ] ?? '';

						return '%d' === $match[0] ? (string) (int) $value : "'" . (string) $value . "'";
					},
					$query
				);
			}

			public function get_var( string $sql ): int {
				return $this->match;
			}

			/**
			 * @return list<int>
			 */
			public function get_col( string $sql ): array {
				if ( str_contains( $sql, 'stock_meta' ) ) {
					if ( 1 !== preg_match( '/IN \(([0-9,]+)\)/', $sql, $in ) ) {
						return [];
					}
					$ids = array_map( 'intval', explode( ',', $in[1] ) );

					return in_array( $this->match, $ids, true ) ? [ $this->match ] : [];
				}
				if ( 1 !== preg_match( '/p\.ID > (\d+) AND p\.ID <= (\d+) ORDER BY p\.ID ASC LIMIT (\d+)/', $sql, $page ) ) {
					return [];
				}
				++$this->candidate_queries;
				$start = (int) $page[1] + 1;
				$end   = min( (int) $page[2], $start + (int) $page[3] - 1 );
				if ( $start > $end ) {
					return [];
				}

				return range( $start, $end );
			}
		};
		$GLOBALS['wpdb'] = $wpdb;
		$query  = new WooCommerceCatalogTargetQuery();
		$jobs   = new InMemoryBulkJobRepository();
		$worker = $this->worker( $jobs, $query );
		$job    = $jobs->save_job( $this->filter_job( [ CatalogTargetFilters::STOCK_STATUS => 'instock' ], 100 ) );
		$saved  = $this->finish( $worker, $jobs, (int) $job->id, 40 );
		unset( $GLOBALS['wpdb'] );

		self::assertGreaterThan( 25, $wpdb->candidate_queries );
		self::assertSame( 2501, (int) $saved->summary['preparation']['scanned'] );
		self::assertSame( 1, $saved->total_count );
		self::assertSame( '2501', $saved->checkpoint_cursor );
		self::assertTrue( $saved->enumeration_complete );
	}

	private function worker( InMemoryBulkJobRepository $jobs, CatalogTargetQueryInterface $query ): BulkJobWorker {
		return new BulkJobWorker(
			$jobs,
			$query,
			new CatalogScopeMutator( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ),
			new InMemoryBoundedQueue(),
			30
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function filter_job( array $filters, int $batch ): BulkJob {
		return BulkJob::create(
			BulkOperationType::CatalogUpdate,
			4,
			[
				'scope'       => BulkTargetScope::MatchingFilters->value,
				'target_type' => CatalogTargetDefinition::TARGET_PRODUCT,
				'filters'     => $filters,
			],
			[ 'field_actions' => [] ],
			true,
			$batch
		);
	}

	private function finish( BulkJobWorker $worker, InMemoryBulkJobRepository $jobs, int $job_id, int $bound ): BulkJob {
		$saved = null;
		for ( $tick = 0; $tick < $bound; ++$tick ) {
			$worker->tick( $job_id );
			$saved = $jobs->find_job( $job_id );
			if ( ! $saved instanceof BulkJob || $saved->enumeration_complete || $saved->status->is_terminal() ) {
				break;
			}
		}
		self::assertInstanceOf( BulkJob::class, $saved );

		return $saved;
	}
}

final class ThrowingCatalogQuery implements CatalogTargetQueryInterface {

	public function count( CatalogTargetDefinition $definition ): int {
		return 999999;
	}

	public function page_after( CatalogTargetDefinition $definition, int $after_id, int $limit ): array {
		return [];
	}

	public function sku_for( string $target_type, int $target_id ): string {
		return '';
	}

	public function parent_product_id( int $variation_id ): ?int {
		return null;
	}

	public function find_id_by_sku( string $sku, string $target_type ): ?int {
		return null;
	}

	public function catalog_ceiling( CatalogTargetDefinition $definition ): int {
		return 10;
	}

	public function scan_page( CatalogTargetDefinition $definition, int $after_id, int $limit, int $high_water ): array {
		throw new \RuntimeException( 'Catalog scan failed. Simulated SQL failure.' );
	}

	public function membership( CatalogTargetDefinition $definition, int $target_id ): string {
		return 'rejected';
	}
}
