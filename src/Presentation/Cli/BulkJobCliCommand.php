<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Cli;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;

/**
 * WP-CLI surface for the same BulkJobEngine used by admin UI.
 */
final class BulkJobCliCommand {

	public function __construct(
		private readonly BulkJobEngine $engine,
		private readonly ConfigurationExporter $exporter
	) {
	}

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		$engine   = $this->engine;
		$exporter = $this->exporter;
		\WP_CLI::add_command(
			'cetech-de bulk',
			new class( $engine ) {
				public function __construct( private BulkJobEngine $engine ) {
				}
				/**
				 * @param list<string>         $args
				 * @param array<string, string> $assoc
				 */
				public function preview( array $args, array $assoc ): void {
					$ids = array_map( 'intval', explode( ',', (string) ( $assoc['ids'] ?? '' ) ) );
					$job = $this->engine->create_preview(
						BulkOperationType::CatalogUpdate,
						get_current_user_id(),
						[
							'scope'            => BulkTargetScope::SelectedIds->value,
							'selected_ids'     => array_values( array_filter( $ids ) ),
							'variation_policy' => $assoc['variation-policy'] ?? BulkVariationPolicy::PreserveOverrides->value,
						],
						[
							'field_actions' => json_decode( (string) ( $assoc['actions'] ?? '[]' ), true ) ?: [],
						]
					);
					\WP_CLI::success( $job->job_code . ' ' . $job->status->value );
				}
				/**
				 * @param list<string> $args
				 */
				public function apply( array $args ): void {
					$job = $this->engine->apply( (int) ( $args[0] ?? 0 ), get_current_user_id() );
					\WP_CLI::success( $job->job_code . ' ' . $job->status->value );
				}
				/**
				 * @param list<string> $args
				 */
				public function status( array $args ): void {
					$job = $this->engine->find( (int) ( $args[0] ?? 0 ) );
					if ( ! $job ) {
						\WP_CLI::error( 'Unknown job.' );
					}
					\WP_CLI::log( $job->job_code . ' ' . $job->status->value . ' ' . $job->processed_count . '/' . $job->total_count );
				}
				/**
				 * @param list<string> $args
				 */
				public function cancel( array $args ): void {
					$job = $this->engine->cancel( (int) ( $args[0] ?? 0 ) );
					\WP_CLI::success( $job->job_code . ' ' . $job->status->value );
				}
				/**
				 * Process one bounded batch. Does not replace Action Scheduler.
				 *
				 * @param list<string> $args
				 */
				public function continue( array $args ): void {
					$job = $this->engine->continue_job( (int) ( $args[0] ?? 0 ) );
					\WP_CLI::success( $job->job_code . ' ' . $job->status->value . ' ' . $job->processed_count . '/' . $job->total_count );
				}
				/**
				 * @param list<string> $args
				 */
				public function rollback( array $args ): void {
					$job = $this->engine->rollback( (int) ( $args[0] ?? 0 ), get_current_user_id() );
					\WP_CLI::success( $job->job_code . ' ' . $job->status->value );
				}
			}
		);
		\WP_CLI::add_command(
			'cetech-de config',
			new class( $exporter, $engine ) {
				public function __construct(
					private ConfigurationExporter $exporter,
					private BulkJobEngine $engine
				) {
				}
				/**
				 * @param list<string>          $args
				 * @param array<string, string> $assoc
				 */
				public function export( array $args, array $assoc ): void {
					$private = ! empty( $assoc['include-private'] ) && current_user_can( 'manage_private_sources' );
					$package = $this->exporter->export( [], $private, $private );
					$file    = (string) ( $assoc['file'] ?? '' );
					$json    = $package->to_json();
					if ( '' !== $file ) {
						if ( false === file_put_contents( $file, $json ) ) {
							\WP_CLI::error( 'Unable to write export file.' );
						}
						\WP_CLI::success( $file );
						return;
					}
					\WP_CLI::log( $json );
				}

				/**
				 * @param list<string>          $args
				 * @param array<string, string> $assoc
				 */
				public function import( array $args, array $assoc ): void {
					$file = (string) ( $assoc['file'] ?? ( $args[0] ?? '' ) );
					if ( '' === $file || ! is_readable( $file ) ) {
						\WP_CLI::error( 'Provide --file=path-to-package.json' );
					}
					$json    = (string) file_get_contents( $file );
					$package = \CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage::from_json( $json );
					$job     = $this->engine->create_preview(
						BulkOperationType::ConfigImport,
						get_current_user_id(),
						[ 'scope' => BulkTargetScope::SelectedIds->value ],
						[
							'package'       => $package->to_array(),
							'conflict_mode' => (string) ( $assoc['mode'] ?? 'skip_conflicts' ),
						]
					);
					\WP_CLI::success( $job->job_code . ' ' . $job->status->value );
				}
			}
		);
	}
}
