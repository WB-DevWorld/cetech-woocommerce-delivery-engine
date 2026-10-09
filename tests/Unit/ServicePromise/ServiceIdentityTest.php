<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\ServiceIdentity;
use PHPUnit\Framework\TestCase;

final class ServiceIdentityTest extends TestCase {
	public function test_builtin_and_merchant_identity_remain_separate_from_customer_label(): void {
		$standard = ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard' ] );
		$merchant = ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => 'merchant', 'code' => 'merchant-fast', 'customer_label' => 'Standard' ] );
		self::assertNotSame( $standard->digest(), $merchant->digest() ); self::assertSame( $standard->private_facts(), ServiceIdentity::from_json( $standard->to_private_json() )->private_facts() );
		$renamed = $standard->private_facts(); $renamed['customer_label'] = 'Livraison standard'; self::assertNotSame( $standard->digest(), ServiceIdentity::from_array( $renamed )->digest() );
	}
	/** @dataProvider malformed */
	public function test_service_label_or_kind_never_supplies_missing_authority( array $facts ): void { $this->expectException( \InvalidArgumentException::class ); ServiceIdentity::from_array( $facts ); }
	public static function malformed(): array {
		$base = [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard' ]; return [ [ array_replace( $base, [ 'kind' => 'merchant' ] ) ], [ array_replace( $base, [ 'code' => 'invented_builtin' ] ) ], [ array_replace( $base, [ 'format_version' => 2 ] ) ], [ array_replace( $base, [ 'customer_label' => '<script>' ] ) ], [ $base + [ 'policy' => 'secret' ] ] ];
	}
	public function test_implicit_public_serialization_refuses(): void { $service = ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'same_day', 'customer_label' => 'Same Day' ] ); $this->expectException( \LogicException::class ); json_encode( $service, JSON_THROW_ON_ERROR ); }
	public function test_native_serialization_does_not_publish_private_facts(): void { $service = ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'same_day', 'customer_label' => 'Same Day' ] ); $this->expectException( \LogicException::class ); serialize( $service ); }
	public function test_native_unserialization_cannot_construct_unvalidated_carrier(): void { $name = ServiceIdentity::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $name ) . ':"' . $name . '":0:{}' ); }
}
