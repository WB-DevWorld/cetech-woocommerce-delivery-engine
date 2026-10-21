<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedDeliveryPromotion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixedDeliveryPromotionTest extends TestCase {
	public function test_explicit_amount_version_and_funder_are_preserved_without_defaults(): void {
		$rule = FixedDeliveryPromotion::from_array( self::facts() );
		self::assertSame( 'fixture_fixed_delivery', $rule->code() );
		self::assertSame( 4, $rule->version() );
		self::assertSame( '3.00', $rule->discount()->amount() );
		self::assertSame( [ 'code' => 'private_promotion_funder', 'version' => 7 ], $rule->private_facts()['funding_source'] );
		self::assertSame( $rule->digest(), FixedDeliveryPromotion::from_array( array_reverse( self::facts(), true ) )->digest() );
		$data = self::facts();
		++$data['version'];
		self::assertNotSame( $rule->digest(), FixedDeliveryPromotion::from_array( $data )->digest() );
	}

	public function test_source_and_export_references_cannot_change_the_frozen_rule(): void {
		$data = self::facts();
		$funding = $data['funding_source'];
		$data['funding_source'] = &$funding;
		$rule = FixedDeliveryPromotion::from_array( $data );
		$before = $rule->to_private_json();
		$funding['code'] = 'replacement';
		$export = $rule->private_facts();
		$export['discount']['amount'] = '100.00';
		self::assertSame( $before, $rule->to_private_json() );
	}

	#[DataProvider( 'invalid_rules' )]
	public function test_partial_coerced_or_unsupported_rules_refuse( string $edit ): void {
		$data = self::facts();
		switch ( $edit ) {
			case 'format': $data['format_version'] = 2; break;
			case 'coerced_format': $data['format_version'] = '1'; break;
			case 'missing_discount': unset( $data['discount'] ); break;
			case 'scalar_discount': $data['discount'] = '3'; break;
			case 'float_amount': $data['discount']['amount'] = 3.0; break;
			case 'negative_amount': $data['discount']['amount'] = '-3'; break;
			case 'extra_precision': $data['discount']['amount'] = '3.001'; break;
			case 'missing_funder': unset( $data['funding_source'] ); break;
			case 'null_funder': $data['funding_source'] = null; break;
			case 'array_funding_code': $data['funding_source']['code'] = [ 'merchant' ]; break;
			case 'coerced_version': $data['version'] = '4'; break;
			case 'zero_funding_version': $data['funding_source']['version'] = 0; break;
			case 'percentage': $data['percentage'] = 20; break;
			case 'stacking': $data['stacking'] = 'best_price'; break;
		}
		$this->expectException( \InvalidArgumentException::class );
		FixedDeliveryPromotion::from_array( $data );
	}
	public static function invalid_rules(): array {
		return array_map( static fn( string $edit ): array => [ $edit ], [ 'format', 'coerced_format', 'missing_discount', 'scalar_discount', 'float_amount', 'negative_amount', 'extra_precision', 'missing_funder', 'null_funder', 'array_funding_code', 'coerced_version', 'zero_funding_version', 'percentage', 'stacking' ] );
	}

	#[DataProvider( 'private_exports' )]
	public function test_implicit_export_refuses_and_debugging_redacts( string $kind ): void {
		$rule = FixedDeliveryPromotion::from_array( self::facts() );
		if ( 'debug' === $kind ) {
			ob_start(); var_dump( $rule ); $output = (string) ob_get_clean();
			self::assertStringContainsString( 'redacted', $output );
			self::assertStringNotContainsString( 'private_promotion_funder', $output );
			self::assertStringNotContainsString( '3.00', $output );
			return;
		}
		$this->expectException( \LogicException::class );
		if ( 'json' === $kind ) { json_encode( $rule, JSON_THROW_ON_ERROR ); }
		elseif ( 'php' === $kind ) { serialize( $rule ); }
		else { $class = FixedDeliveryPromotion::class; unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	}
	public static function private_exports(): array { return [ [ 'json' ], [ 'php' ], [ 'unserialize' ], [ 'debug' ] ]; }

	private static function facts(): array {
		return [ 'format_version' => 1, 'code' => 'fixture_fixed_delivery', 'version' => 4,
			'discount' => [ 'amount' => '3', 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'private_promotion_funder', 'version' => 7 ] ];
	}
}
