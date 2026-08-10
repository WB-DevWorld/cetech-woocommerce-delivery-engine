<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WC_Product;

/**
 * Regression for undefined array key "error_code" on successful product context.
 */
final class ProductDeliverySelectionValidatorContractTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_wc_products'] = [];
		$GLOBALS['cetech_de_test_options']     = [
			'cetech_de_enable_product_delivery_selector' => 1,
		];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		set_error_handler(
			static function ( int $severity, string $message ): bool {
				if ( $severity & ( E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE ) ) {
					throw new \ErrorException( $message, 0, $severity );
				}

				return false;
			}
		);
	}

	protected function tearDown(): void {
		restore_error_handler();
		$GLOBALS['cetech_de_test_wc_products'] = [];
	}

	public function test_success_context_includes_null_error_code_without_warning(): void {
		$GLOBALS['cetech_de_test_wc_products'][5] = new WC_Product(
			[
				'id'   => 5,
				'type' => 'simple',
				'name' => 'Simple',
			]
		);

		$validator = $this->validator();
		$method    = $this->accessible( 'resolve_product_context' );

		/** @var array<string, mixed> $context */
		$context = $method->invoke( $validator, 5, null );

		self::assertArrayHasKey( 'error_code', $context );
		self::assertNull( $context['error_code'] );
		self::assertArrayHasKey( 'error_message', $context );
		self::assertNull( $context['error_message'] );
		self::assertInstanceOf( WC_Product::class, $context['product'] );
	}

	public function test_failure_context_retains_stable_error_code_and_message(): void {
		$validator = $this->validator();
		$method    = $this->accessible( 'resolve_product_context' );

		/** @var array<string, mixed> $missing */
		$missing = $method->invoke( $validator, 404, null );
		self::assertSame( 'product_not_found', $missing['error_code'] );
		self::assertNotSame( '', (string) $missing['error_message'] );

		$GLOBALS['cetech_de_test_wc_products'][8] = new WC_Product(
			[
				'id'   => 8,
				'type' => 'variable',
				'name' => 'Variable parent',
			]
		);
		/** @var array<string, mixed> $variable */
		$variable = $method->invoke( $validator, 8, null );
		self::assertSame( 'invalid_product_context', $variable['error_code'] );
		self::assertStringContainsString( 'variation ID', (string) $variable['error_message'] );
	}

	public function test_validate_product_not_found_does_not_emit_warning(): void {
		$validator = $this->validator();
		$result    = $validator->validate( 999888, null, 'in_stock:standard:1' );

		self::assertFalse( $result->valid );
		self::assertSame( 'product_not_found', $result->error_code );
		self::assertNotNull( $result->error_message );
	}

	private function validator(): ProductDeliverySelectionValidator {
		$source = new class() implements ProductDeliveryConfigurationSourceInterface {
			public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
				return new ProductDeliveryRuntimeResolution(
					ProductRuleResolutionResult::failure( $target_type, $target_id, 'unused' ),
					RuntimeConfigurationSource::LEGACY,
					null
				);
			}
		};

		$builder = ( new ReflectionClass( ProductDeliveryOptionsBuilder::class ) )->newInstanceWithoutConstructor();

		return new ProductDeliverySelectionValidator(
			new FeatureFlags(),
			new Requirements(),
			$source,
			$builder
		);
	}

	private function accessible( string $method ): ReflectionMethod {
		$reflection = new ReflectionMethod( ProductDeliverySelectionValidator::class, $method );
		if ( \PHP_VERSION_ID < 80500 ) {
			$reflection->setAccessible( true );
		}

		return $reflection;
	}
}
