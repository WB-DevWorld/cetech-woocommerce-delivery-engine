<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkQueueHealth;
use CetechDeliveryEngine\Application\Bulk\Queue\ActionSchedulerQueue;
use CetechDeliveryEngine\Application\Bulk\Queue\BackgroundQueueInterface;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Infrastructure\Persistence\BulkJobSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Presentation\Admin\BulkJobProgressEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real plugin container against the disposable WP/wpdb harness.
 * Proves Action Scheduler absence fails safe, the tick/progress hooks exist,
 * and when AS functions are present the live gateway enqueues async without
 * walking the catalog in the creating request.
 */
final class BulkBackgroundPortabilityIntegrationTest extends TestCase {

	protected function setUp(): void {
		LifecycleHarness::reset();
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_caps']['manage_product_delivery_rules'] = true;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_plugin_boot_registers_bulk_tick_and_progress_without_action_scheduler(): void {
		Plugin::instance()->boot();

		$hooks = array_keys( $GLOBALS['cetech_de_test_actions'] ?? [] );
		self::assertContains( ActionSchedulerQueue::HOOK, $hooks );
		self::assertContains( 'wp_ajax_' . BulkJobProgressEndpoint::ACTION, $hooks );

		global $wpdb;
		self::assertTrue( $wpdb->has_table( TableNames::for( BulkJobSchema::JOBS_SUFFIX ) ) );
		self::assertTrue( $wpdb->has_table( TableNames::for( BulkJobSchema::ITEMS_SUFFIX ) ) );

		$container = Plugin::instance()->container();
		$queue     = $container->get( BackgroundQueueInterface::class );
		self::assertInstanceOf( ActionSchedulerQueue::class, $queue );
		self::assertFalse( $queue->is_available() );

		$health = $container->get( BulkQueueHealth::class )->snapshot();
		self::assertFalse( $health['available'] );
		self::assertSame( 'unavailable', $health['scheduler_health'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_create_preview_fails_safe_when_the_booted_queue_is_unavailable(): void {
		Plugin::instance()->boot();
		$engine = Plugin::instance()->container()->get( BulkJobEngine::class );
		$job    = $engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			[
				'scope'            => BulkTargetScope::SelectedIds->value,
				'selected_ids'     => [ 1 ],
				'variation_policy' => BulkVariationPolicy::PreserveOverrides->value,
			],
			[ 'field_actions' => [ [ 'field_key' => 'fulfilment_availability', 'action' => 'set_override', 'value' => 'international_fulfilment' ] ] ]
		);

		self::assertSame( BulkJobStatus::Failed, $job->status );
		self::assertSame( 'background_queue_unavailable', $job->error_code );
		self::assertSame( 0, $job->processed_count );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_booted_plugin_enqueues_async_and_does_not_process_the_catalog_in_the_creating_request(): void {
		require_once __DIR__ . '/stubs/action-scheduler-functions.php';
		LifecycleHarness::reset();
		$GLOBALS['cetech_de_test_is_admin']                          = true;
		$GLOBALS['cetech_de_test_caps']['manage_product_delivery_rules'] = true;
		$GLOBALS['cetech_de_test_as_calls']                          = [];

		Plugin::instance()->boot();

		$queue = Plugin::instance()->container()->get( BackgroundQueueInterface::class );
		self::assertTrue( $queue->is_available() );

		$health = Plugin::instance()->container()->get( BulkQueueHealth::class )->snapshot();
		self::assertTrue( $health['available'] );
		self::assertTrue( $health['async_enqueue_supported'] );

		$engine = Plugin::instance()->container()->get( BulkJobEngine::class );
		$job    = $engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			[
				'scope'            => BulkTargetScope::SelectedIds->value,
				'selected_ids'     => [ 1 ],
				'variation_policy' => BulkVariationPolicy::PreserveOverrides->value,
			],
			[ 'field_actions' => [ [ 'field_key' => 'fulfilment_availability', 'action' => 'set_override', 'value' => 'international_fulfilment' ] ] ]
		);

		self::assertSame( BulkJobStatus::Previewing, $job->status );
		self::assertNull( $job->error_code );
		self::assertSame( 0, $job->processed_count );
		self::assertSame( 0, $job->enumerated_count );
		self::assertNotEmpty( $GLOBALS['cetech_de_test_as_calls']['async'] ?? [] );
		$bulk_calls = array_values(
			array_filter(
				$GLOBALS['cetech_de_test_as_calls']['async'],
				static fn ( array $call ): bool => ActionSchedulerQueue::HOOK === (string) ( $call['hook'] ?? '' )
			)
		);
		self::assertNotSame( [], $bulk_calls, 'Bulk preview must enqueue the catalog tick even when schema-6 continuation is also queued.' );
		self::assertSame( [ 'job_id' => (int) $job->id ], $bulk_calls[0]['args'] );
		self::assertSame( [ 'maybe_dispatch' ], $GLOBALS['cetech_de_test_as_calls']['kick'] ?? [] );
		self::assertArrayNotHasKey( 'schedule', $GLOBALS['cetech_de_test_as_calls'] );
	}
}
