<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests: Stage 5 simple product ECR routing is unaffected
 * by the Stage 6A variable-product ECR flag (VARIABLE_CUTOVER_FLAG).
 */
final class SimpleProductNonRegressionTest extends TestCase {

	private FeatureFlags $flags;
	private RecordingSource $legacy;
	private RecordingSource $ecr;
	private RecordingSource $legacy_category;
	private FixedCategoryGuard $category_guard;
	private FixedProductTypeInspector $types;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		$this->flags                       = new FeatureFlags();
		$this->legacy                      = new RecordingSource( RuntimeConfigurationSource::LEGACY );
		$this->ecr                         = new RecordingSource( RuntimeConfigurationSource::ECR );
		$this->legacy_category             = new RecordingSource( RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY );
		$this->category_guard              = new FixedCategoryGuard();
		// Only simple product in the type map.
		$this->types = new FixedProductTypeInspector( [ 101 => 'simple' ] );
	}

	public function test_simple_product_uses_ecr_when_main_flag_on_and_variable_flag_on(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG, true );
		$this->category_guard->dependent = false;

		$decision = $this->router()->decide( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::ECR, $decision );
	}

	public function test_simple_product_uses_ecr_when_main_flag_on_and_variable_flag_off(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG, false );
		$this->category_guard->dependent = false;

		$decision = $this->router()->decide( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::ECR, $decision );
	}

	public function test_simple_product_uses_legacy_when_main_flag_off_regardless_of_variable_flag(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, false );
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG, true );
		$this->category_guard->dependent = false;

		$decision = $this->router()->decide( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::LEGACY, $decision );
	}

	public function test_simple_product_category_dependent_uses_legacy_compat_regardless_of_variable_flag(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG, true );
		$this->category_guard->dependent = true;

		$decision = $this->router()->decide( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY, $decision );
	}

	public function test_variable_flag_alone_does_not_affect_simple_ecr_path(): void {
		// With both flags on, simple product route is ECR.
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG, true );
		$this->category_guard->dependent = false;

		$with_variable_flag = $this->router()->decide( ProductTargetType::Product->value, 101 );

		// Flip variable flag off; simple product route must be identical.
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::VARIABLE_CUTOVER_FLAG, false );
		$without_variable_flag = $this->router()->decide( ProductTargetType::Product->value, 101 );

		self::assertSame( $with_variable_flag, $without_variable_flag );
		self::assertSame( RuntimeConfigurationSource::ECR, $with_variable_flag );
	}

	private function router(): ProductDeliveryRuntimeConfigurationRouter {
		return new ProductDeliveryRuntimeConfigurationRouter(
			$this->flags,
			$this->legacy,
			$this->ecr,
			$this->category_guard,
			$this->types,
			$this->legacy_category
		);
	}
}
