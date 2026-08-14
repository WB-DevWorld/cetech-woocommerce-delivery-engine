<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use PHPUnit\Framework\TestCase;

final class SetupWizardProgressTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
	}

	public function test_default_state_is_not_started(): void {
		$progress = new SetupWizardProgress( new SiteWideDefaultsSettings() );
		$state    = $progress->read();

		self::assertSame( SetupWizardProgress::STATUS_NOT_STARTED, $state['status'] );
		self::assertSame( 1, $state['step'] );
		self::assertTrue( $progress->is_incomplete() );
		self::assertTrue( $progress->should_open_on_entry() );
		self::assertTrue( $progress->is_fresh_install() );
		self::assertSame( 'Not started', $progress->status_label() );
	}

	public function test_save_and_resume_keeps_step_and_draft(): void {
		$progress = new SetupWizardProgress( new SiteWideDefaultsSettings() );
		$progress->save(
			[
				'status'        => SetupWizardProgress::STATUS_IN_PROGRESS,
				'step'          => 3,
				'profile_index' => 1,
				'draft'         => [
					'primary_profile' => FulfilmentAvailability::InWarehouse->value,
					'active_profiles' => [
						FulfilmentAvailability::InWarehouse->value,
						FulfilmentAvailability::InStore->value,
					],
				],
			]
		);

		$state = $progress->read();
		self::assertSame( SetupWizardProgress::STATUS_IN_PROGRESS, $state['status'] );
		self::assertSame( 3, $state['step'] );
		self::assertSame( 1, $state['profile_index'] );
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $state['draft']['primary_profile'] );
		self::assertSame( 'In progress', $progress->status_label() );
		self::assertTrue( $progress->should_open_on_entry() );
	}

	public function test_complete_setup_does_not_reopen_wizard(): void {
		$settings = new SiteWideDefaultsSettings();
		$settings->save(
			[
				'setup_completed' => true,
				'active_profiles' => [ FulfilmentAvailability::InWarehouse->value ],
				'primary_profile' => FulfilmentAvailability::InWarehouse->value,
			]
		);
		$progress = new SetupWizardProgress( $settings );
		$progress->mark_complete();

		self::assertFalse( $progress->is_incomplete() );
		self::assertFalse( $progress->should_open_on_entry() );
		self::assertSame( SetupWizardProgress::STATUS_COMPLETE, $progress->read()['status'] );
	}

	public function test_review_mode_reopens_without_resetting_policy(): void {
		$settings = new SiteWideDefaultsSettings();
		$settings->save(
			[
				'setup_completed' => true,
				'active_profiles' => [ FulfilmentAvailability::InStore->value ],
				'primary_profile' => FulfilmentAvailability::InStore->value,
			]
		);
		$progress = new SetupWizardProgress( $settings );
		$progress->mark_complete();
		$progress->begin_review();

		$state = $progress->read();
		self::assertTrue( $state['review_mode'] );
		self::assertSame( 1, $state['step'] );
		self::assertSame( FulfilmentAvailability::InStore->value, $state['draft']['primary_profile'] );
		self::assertTrue( $settings->is_setup_complete() );
		self::assertTrue( $progress->should_open_on_entry() );
		self::assertFalse( $progress->is_incomplete() );
	}

	public function test_existing_rc2_runtime_is_not_treated_as_fresh_setup(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_product_delivery_selector'] = 1;
		$progress = new SetupWizardProgress( new SiteWideDefaultsSettings() );

		self::assertTrue( $progress->has_prior_operational_install() );
		self::assertFalse( $progress->is_fresh_install() );
		self::assertFalse( $progress->is_incomplete() );
		self::assertFalse( $progress->should_open_on_entry() );
		self::assertFalse( ( new SiteWideDefaultsSettings() )->is_setup_complete() );
		self::assertSame( SetupWizardProgress::STATUS_NOT_STARTED, $progress->read()['status'] );
	}
}
