<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\Runtime\LegacyCategoryRuntimeCompatibilityGuardInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use PHPUnit\Framework\TestCase;

final class ProductDeliveryRuntimeConfigurationRouterTest extends TestCase {

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
		$this->types                       = new FixedProductTypeInspector( [ 101 => 'simple', 202 => 'variable', 303 => 'variation' ] );
	}

	public function test_flag_off_always_uses_legacy(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, false );
		$this->category_guard->dependent = true;

		$router = $this->router();
		$resolution = $router->resolve( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::LEGACY, $resolution->source );
		self::assertSame( 1, $this->legacy->calls );
		self::assertSame( 0, $this->ecr->calls );
	}

	public function test_flag_on_simple_product_without_category_uses_ecr(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->category_guard->dependent = false;

		$router = $this->router();
		self::assertSame( RuntimeConfigurationSource::ECR, $router->decide( ProductTargetType::Product->value, 101 ) );

		$resolution = $router->resolve( ProductTargetType::Product->value, 101 );
		self::assertSame( RuntimeConfigurationSource::ECR, $resolution->source );
		self::assertSame( 1, $this->ecr->calls );
		self::assertSame( 0, $this->legacy->calls );
	}

	public function test_flag_on_category_dependent_uses_legacy_compatibility(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->category_guard->dependent = true;

		$router = $this->router();
		self::assertSame(
			RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY,
			$router->decide( ProductTargetType::Product->value, 101 )
		);

		$resolution = $router->resolve( ProductTargetType::Product->value, 101 );
		self::assertSame( RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY, $resolution->source );
		self::assertSame( 1, $this->legacy_category->calls );
		self::assertSame( 0, $this->ecr->calls );
	}

	public function test_flag_on_variable_product_stays_legacy(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->category_guard->dependent = false;

		$router = $this->router();
		self::assertSame( RuntimeConfigurationSource::LEGACY, $router->decide( ProductTargetType::Product->value, 202 ) );
	}

	public function test_flag_on_variation_target_stays_legacy(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );

		$router = $this->router();
		self::assertSame( RuntimeConfigurationSource::LEGACY, $router->decide( ProductTargetType::Variation->value, 303 ) );
	}

	public function test_ecr_errors_do_not_silently_fall_back_to_legacy(): void {
		$this->flags->set( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG, true );
		$this->category_guard->dependent = false;
		$this->ecr->force_failure        = true;

		$resolution = $this->router()->resolve( ProductTargetType::Product->value, 101 );

		self::assertSame( RuntimeConfigurationSource::ECR, $resolution->source );
		self::assertFalse( $resolution->result->success );
		self::assertSame( 0, $this->legacy->calls );
		self::assertSame( 0, $this->legacy_category->calls );
	}

	public function test_cutover_flag_defaults_off(): void {
		self::assertFalse( $this->flags->is_enabled( ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG ) );
		self::assertArrayHasKey(
			ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG,
			$this->flags->defaults()
		);
		self::assertFalse( $this->flags->defaults()[ ProductDeliveryRuntimeConfigurationRouter::CUTOVER_FLAG ] );
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

final class RecordingSource implements ProductDeliveryConfigurationSourceInterface {

	public int $calls = 0;

	public bool $force_failure = false;

	public function __construct( private string $source ) {
	}

	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
		++$this->calls;

		$result = $this->force_failure
			? ProductRuleResolutionResult::failure( $target_type, $target_id, 'ecr_forced_failure' )
			: new ProductRuleResolutionResult(
				true,
				null,
				$target_type,
				$target_id,
				null,
				[],
				'',
				[],
				[],
				[],
				[],
				[],
				null
			);

		return new ProductDeliveryRuntimeResolution( $result, $this->source );
	}
}

final class FixedCategoryGuard implements LegacyCategoryRuntimeCompatibilityGuardInterface {

	public bool $dependent = false;

	public function depends_on_legacy_category_rule( int $product_id ): bool {
		return $this->dependent;
	}
}
