<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseCalendarReference;
use CetechDeliveryEngine\Domain\ServicePromise\PromisePolicyReference;
use PHPUnit\Framework\TestCase;

final class PromiseReferencesTest extends TestCase {
	/** @dataProvider reference_classes */
	public function test_references_are_site_scoped_versioned_detached_and_private( string $class, string $field ): void {
		$data = [ 'format_version' => 1, 'site_id' => 'site-1', $field => 'local-1', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ]; $reference = $class::from_array( $data );
		self::assertSame( str_repeat( 'a', 64 ), $reference->content_digest() ); self::assertSame( 'site-1', $reference->site_id() ); self::assertSame( 1, $reference->version() ); self::assertSame( $reference->to_private_json(), $class::from_json( $reference->to_private_json() )->to_private_json() );
		$data['site_id'] = 'site-2'; self::assertNotSame( $reference->digest(), $class::from_array( $data )->digest() ); $copy = $reference->private_facts(); $copy['digest'] = str_repeat( 'b', 64 ); self::assertSame( str_repeat( 'a', 64 ), $reference->content_digest() );
		$this->expectException( \LogicException::class ); json_encode( $reference, JSON_THROW_ON_ERROR );
	}
	/** @dataProvider invalid_reference */
	public function test_invalid_reference_cannot_be_interpreted( string $class, string $field, string $case ): void {
		$data = [ 'format_version' => 1, 'site_id' => 'site-1', $field => 'local-1', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ];
		switch ( $case ) { case 'version': $data['format_version'] = 2; break; case 'string': $data['version'] = '1'; break; case 'zero': $data['version'] = 0; break; case 'overflow': $data['version'] = 1000001; break; case 'extra': $data['customer_label'] = 'not authority'; break; case 'digest': $data['digest'] = str_repeat( 'A', 64 ); break; case 'site': $data['site_id'] = 1; break; }
		$this->expectException( \InvalidArgumentException::class ); $class::from_array( $data );
	}
	public static function reference_classes(): array { return [ [ PromiseCalendarReference::class, 'calendar_id' ], [ PromisePolicyReference::class, 'policy_id' ] ]; }
	/** @dataProvider reference_classes */
	public function test_reference_cannot_implicitly_serialize( string $class, string $field ): void { $value = $class::from_array( [ 'format_version' => 1, 'site_id' => 'site-1', $field => 'local-1', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ] ); $this->expectException( \LogicException::class ); serialize( $value ); }
	/** @dataProvider reference_classes */
	public function test_unserialize_cannot_bypass_reference_factory( string $class, string $field ): void { $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	public static function invalid_reference(): array { $out = []; foreach ( self::reference_classes() as [ $class, $field ] ) { foreach ( [ 'version', 'string', 'zero', 'overflow', 'extra', 'digest', 'site' ] as $case ) { $out[] = [ $class, $field, $case ]; } } return $out; }
}
