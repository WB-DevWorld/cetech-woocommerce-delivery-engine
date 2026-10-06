<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationChangeAuditorInterface;
use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class ScopedConfigurationCompletionTest extends TestCase {

	public function test_rejected_audit_does_not_report_a_successful_save(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$audit      = new class() implements ConfigurationChangeAuditorInterface {
			public int $calls = 0;

			public function log( string $action, string $entity_type, int $entity_id, ?array $previous = null, ?array $new = null ): bool {
				++$this->calls;

				return 1 === $this->calls;
			}

			public function recorded_completion( string $request_token ): ?array {
				return null;
			}
		};
		$service = $this->service( $repository, $audit );
		$first   = $service->save( $this->command( '5', null, 0 ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );

		$second = $service->save( $this->command( '9', null, $first->version_after ) );

		self::assertFalse( $second->success );
		self::assertStringContainsString( 'Settings were not saved.', implode( ' ', $second->errors ) );
		$stored = $repository->getGlobalConfiguration();
		self::assertNotNull( $stored );
		self::assertSame( 5, $stored->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertSame( $first->version_after, $stored->scope->config_version );
	}

	public function test_outdated_expected_revision_does_not_overwrite_the_accepted_settings(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$audit      = new RecordingConfigurationAuditLogger();
		$service    = $this->service( $repository, $audit );
		$first      = $service->save( $this->command( '5', null, 0 ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );

		$stale = $service->save( $this->command( '9', null, 0 ) );

		self::assertFalse( $stale->success );
		self::assertStringContainsString( 'out of date', implode( ' ', $stale->errors ) );
		self::assertSame( 5, $repository->getGlobalConfiguration()->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertCount( 1, $audit->calls );
	}

	public function test_replay_of_an_accepted_request_does_not_apply_a_later_edit(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$audit      = new RecordingConfigurationAuditLogger();
		$service    = $this->service( $repository, $audit );
		$first      = $service->save( $this->command( '5', 'accepted-request', 0 ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );

		$mismatch = $service->save( $this->command( '9', 'accepted-request', $first->version_before ) );
		self::assertFalse( $mismatch->success );
		self::assertStringContainsString( 'does not match the saved operation', implode( ' ', $mismatch->errors ) );
		self::assertSame( 5, $repository->getGlobalConfiguration()->scalars[ ConfigurationFieldKey::PRIORITY ]->value );

		$replay = $service->save( $this->command( '5', 'accepted-request', $first->version_before ) );

		self::assertTrue( $replay->success, implode( ' ', $replay->errors ) );
		self::assertTrue( $replay->replayed );
		self::assertFalse( $replay->version_changed );
		self::assertSame( $first->version_after, $replay->version_after );
		self::assertSame( 5, $repository->getGlobalConfiguration()->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertCount( 1, $audit->calls );
	}

	public function test_a_rejected_request_can_apply_once_on_retry(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$audit      = new RecordingConfigurationAuditLogger();
		$service    = $this->service( $repository, $audit );
		$first      = $service->save( $this->command( '5', 'baseline', 0 ) );
		self::assertTrue( $first->success, implode( ' ', $first->errors ) );
		$rejecting = new class() implements ConfigurationChangeAuditorInterface {
			public int $calls = 0;

			/** @var list<array{previous: ?array, new: ?array}> */
			public array $accepted = [];

			public function log( string $action, string $entity_type, int $entity_id, ?array $previous = null, ?array $new = null ): bool {
				++$this->calls;
				if ( 1 === $this->calls ) {
					return false;
				}
				$this->accepted[] = [ 'previous' => $previous, 'new' => $new ];

				return true;
			}

			public function recorded_completion( string $request_token ): ?array {
				foreach ( array_reverse( $this->accepted ) as $call ) {
					$new = $call['new'];
					if ( is_array( $new ) && (string) ( $new['request_token'] ?? '' ) === $request_token ) {
						return [
							'version_before' => (int) ( $call['previous']['config_version'] ?? 0 ),
							'version_after'  => (int) ( $new['config_version'] ?? 0 ),
						];
					}
				}

				return null;
			}
		};
		$retry_service = $this->service( $repository, $rejecting );
		$failed        = $retry_service->save( $this->command( '9', 'retry-request', $first->version_after ) );
		self::assertFalse( $failed->success );

		$retried = $retry_service->save( $this->command( '9', 'retry-request', $first->version_after ) );

		self::assertTrue( $retried->success, implode( ' ', $retried->errors ) );
		self::assertFalse( $retried->replayed );
		self::assertSame( 9, $repository->getGlobalConfiguration()->scalars[ ConfigurationFieldKey::PRIORITY ]->value );
		self::assertCount( 1, $rejecting->accepted );
	}

	private function service( InMemoryScopedConfigurationRepository $repository, ConfigurationChangeAuditorInterface $audit ): ScopedConfigurationAdminService {
		return new ScopedConfigurationAdminService(
			$repository,
			new EffectiveConfigurationResolver(
				$repository,
				new EffectiveConfigurationValidator(),
				new PassthroughFulfilmentConstraintService()
			),
			new ScopedConfigurationSubmissionParser(),
			new ProductVariationScopeGuard(),
			new EntityLabelResolver(),
			new LegacyCategoryConfigurationInspector(),
			$audit
		);
	}

	private function command( string $priority, ?string $request_token = null, ?int $expected_revision = null ): ScopedConfigurationWriteCommand {
		return new ScopedConfigurationWriteCommand(
			ConfigurationScopeType::Global,
			0,
			'',
			null,
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'override', 'value' => FulfilmentAvailability::InStore->value ],
				ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'override', 'value' => FulfilmentChoice::Delivery->value ],
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => '10' ],
				ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => '20' ],
				ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'override', 'value' => '30' ],
				ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => $priority ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ '1' ] ],
			],
			false,
			$expected_revision,
			$request_token
		);
	}
}
