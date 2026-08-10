<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WC_Product;

/**
 * Regression coverage for FLAIROC Fatal #1: wc_get_product() returns false, not null.
 */
final class WooCommerceProductLoadSafetyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_wc_products'] = [];
		$GLOBALS['cetech_de_test_options']     = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_wc_products'] = [];
	}

	public function test_preview_category_ids_safe_for_missing_zero_and_false_product(): void {
		$page   = $this->unconstructed( EffectiveConfigurationPreviewPage::class );
		$method = $this->accessible( EffectiveConfigurationPreviewPage::class, 'resolve_product_category_ids' );

		self::assertSame( [], $method->invoke( $page, 0 ) );
		self::assertSame( [], $method->invoke( $page, 999001 ) );

		$GLOBALS['cetech_de_test_wc_products'][999002] = false;
		self::assertSame( [], $method->invoke( $page, 999002 ) );
	}

	public function test_preview_category_ids_for_valid_simple_product(): void {
		$GLOBALS['cetech_de_test_wc_products'][42] = new WC_Product(
			[
				'id'           => 42,
				'type'         => 'simple',
				'name'         => 'Test Simple',
				'category_ids' => [ 7, 9 ],
			]
		);

		$page   = $this->unconstructed( EffectiveConfigurationPreviewPage::class );
		$method = $this->accessible( EffectiveConfigurationPreviewPage::class, 'resolve_product_category_ids' );

		self::assertSame( [ 7, 9 ], $method->invoke( $page, 42 ) );
	}

	public function test_preview_product_exists_rejects_false_and_accepts_wc_product(): void {
		$page   = $this->unconstructed( EffectiveConfigurationPreviewPage::class );
		$method = $this->accessible( EffectiveConfigurationPreviewPage::class, 'product_exists' );

		self::assertFalse( $method->invoke( $page, 0 ) );
		self::assertFalse( $method->invoke( $page, 55 ) );

		$GLOBALS['cetech_de_test_wc_products'][55] = new WC_Product(
			[
				'id'   => 55,
				'type' => 'simple',
				'name' => 'Exists',
			]
		);
		self::assertTrue( $method->invoke( $page, 55 ) );
	}

	public function test_scoped_configuration_category_ids_safe_when_wc_returns_false(): void {
		$page   = $this->unconstructed( ScopedConfigurationPage::class );
		$method = $this->accessible( ScopedConfigurationPage::class, 'resolve_product_category_ids' );

		self::assertSame( [], $method->invoke( $page, 0 ) );
		self::assertSame( [], $method->invoke( $page, 888001 ) );

		$GLOBALS['cetech_de_test_wc_products'][70] = new WC_Product(
			[
				'id'           => 70,
				'type'         => 'simple',
				'category_ids' => [ 3 ],
			]
		);
		self::assertSame( [ 3 ], $method->invoke( $page, 70 ) );
	}

	public function test_product_target_resolver_handles_false_without_fatal(): void {
		$resolver = new ProductTargetResolver( new Requirements() );

		self::assertFalse( $resolver->target_exists( ProductTargetType::Product->value, 101 ) );
		self::assertNull( $resolver->resolve_label( ProductTargetType::Product->value, 101 ) );
		self::assertNotNull( $resolver->validate_target( ProductTargetType::Product->value, 101 ) );

		$GLOBALS['cetech_de_test_wc_products'][101] = new WC_Product(
			[
				'id'   => 101,
				'type' => 'simple',
				'name' => 'Parent',
			]
		);
		self::assertTrue( $resolver->target_exists( ProductTargetType::Product->value, 101 ) );
		self::assertSame( 'Parent', $resolver->resolve_label( ProductTargetType::Product->value, 101 ) );
	}

	public function test_product_variation_scope_guard_rejects_false_and_wrong_parent(): void {
		$resolver = new ProductTargetResolver( new Requirements() );
		$guard    = new ProductVariationScopeGuard( $resolver );

		self::assertNotSame(
			[],
			$guard->validate( ConfigurationScopeType::Product, 404, null )
		);

		$GLOBALS['cetech_de_test_wc_products'][10] = new WC_Product(
			[
				'id'   => 10,
				'type' => 'simple',
				'name' => 'Parent A',
			]
		);
		$GLOBALS['cetech_de_test_wc_products'][11] = new WC_Product(
			[
				'id'   => 11,
				'type' => 'simple',
				'name' => 'Parent B',
			]
		);
		$GLOBALS['cetech_de_test_wc_products'][20] = new WC_Product(
			[
				'id'        => 20,
				'type'      => 'variation',
				'parent_id' => 10,
				'name'      => 'Var',
			]
		);

		self::assertSame(
			[],
			$guard->validate( ConfigurationScopeType::Variation, 20, 10 )
		);
		self::assertNotSame(
			[],
			$guard->validate( ConfigurationScopeType::Variation, 20, 11 )
		);
		self::assertNotSame(
			[],
			$guard->validate( ConfigurationScopeType::Variation, 999, 10 )
		);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function unconstructed( string $class ): object {
		return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}

	private function accessible( string $class, string $method ): ReflectionMethod {
		$reflection = new ReflectionMethod( $class, $method );
		if ( \PHP_VERSION_ID < 80500 ) {
			$reflection->setAccessible( true );
		}

		return $reflection;
	}
}
