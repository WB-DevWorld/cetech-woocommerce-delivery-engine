<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlResponse;
use PHPUnit\Framework\TestCase;

final class EmergencyControlResponseTest extends TestCase {
	public function test_order_pay_notice_redirects_only_to_server_checkout_then_terminates(): void {
		$events = [];
		$response = new EmergencyControlResponse(
			static function ( string $message, string $kind ) use ( &$events ): void { $events[] = [ 'notice', $message, $kind ]; },
			static fn(): string => 'https://shop.example/checkout/',
			static function ( string $url, int $status ) use ( &$events ): bool { $events[] = [ 'redirect', $url, $status ]; return true; },
			static function ( bool $redirected, string $message ) use ( &$events ): never { $events[] = [ 'stop', $redirected, $message ]; throw new EmergencyProofStop(); }
		);
		try { $response->refuse_order_pay(); self::fail( 'Order-pay refusal must terminate.' ); } catch ( EmergencyProofStop ) {}
		self::assertSame( [ [ 'notice', EmergencyControlResponse::revalidation_message(), 'error' ], [ 'redirect', 'https://shop.example/checkout/', 303 ], [ 'stop', true, EmergencyControlResponse::revalidation_message() ] ], $events );
	}
	public function test_missing_unsafe_or_rejected_redirect_terminates_without_gateway_fallthrough(): void {
		foreach ( [ null, '/checkout/', 'javascript:alert(1)', 'https://shop.example/checkout/#secret', 'https://user:private@shop.example/checkout/', "https://shop.example/checkout/\r\nInjected: secret", str_repeat( 'x', 2049 ) ] as $url ) {
			$redirects = 0; $stops = 0;
			$response = new EmergencyControlResponse( static function (): void {}, static fn() => $url,
				static function () use ( &$redirects ): bool { ++$redirects; return true; },
				static function ( bool $redirected, string $message ) use ( &$stops ): never { ++$stops; self::assertFalse( $redirected ); self::assertSame( EmergencyControlResponse::revalidation_message(), $message ); throw new EmergencyProofStop(); } );
			try { $response->refuse_order_pay(); self::fail(); } catch ( EmergencyProofStop ) {}
			self::assertSame( 0, $redirects ); self::assertSame( 1, $stops );
		}
	}
	public function test_notice_or_redirect_failure_has_only_safe_recovery_copy(): void {
		foreach ( [ 'notice', 'redirect' ] as $fault ) {
			$response = new EmergencyControlResponse(
				static function () use ( $fault ): void { if ( 'notice' === $fault ) { throw new \RuntimeException( 'credential=secret' ); } },
				static fn() => 'https://shop.example/checkout/',
				static function () use ( $fault ): bool { if ( 'redirect' === $fault ) { throw new \RuntimeException( 'private=token' ); } return true; },
				static function ( bool $redirected, string $message ): never { self::assertFalse( $redirected ); self::assertSame( EmergencyControlResponse::revalidation_message(), $message ); self::assertStringNotContainsString( 'secret', $message ); throw new EmergencyProofStop(); } );
			try { $response->refuse_order_pay(); self::fail(); } catch ( EmergencyProofStop ) {}
		}
	}
	public function test_classic_final_refusal_is_safe_and_catchable_by_woo_checkout(): void {
		$this->expectException( \Exception::class ); $this->expectExceptionMessage( EmergencyControlResponse::message() ); EmergencyControlResponse::reject_classic();
	}
}

final class EmergencyProofStop extends \RuntimeException {}
