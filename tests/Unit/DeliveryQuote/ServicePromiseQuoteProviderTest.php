<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyFixedBaseQuoteProvider,QuoteProviderRegistry,ServicePromiseQuoteProvider};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture as F;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

final class ServicePromiseQuoteProviderTest extends TestCase {
	public function test_new_profile_preserves_original_native_money_provider_and_returns_the_exact_prepared_packet(): void {
		$context = F::context(); $terms = F::terms(); $provider = new ServicePromiseQuoteProvider( F::owner(), $context, $terms ); $registry = new QuoteProviderRegistry( [ $provider ] );
		self::assertSame( LegacyFixedBaseQuoteProvider::CODE, $provider->code() ); self::assertSame( 'service_promise_v1', $provider->profile() ); self::assertSame( 1, $provider->profile_version() ); self::assertSame( $terms->to_private_json(), $registry->capture( $provider->code(), 1, $provider->profile(), 1, $context )->to_private_json() ); self::assertSame( F::base_terms()->to_private_json(), $provider->capture( $context )->base_terms()->to_private_json() ); self::assertSame( F::base_context()->to_private_json(), $context->base_context()->to_private_json() );
	}
	public function test_legacy_context_cannot_be_promoted_to_new_profile_by_a_provider_alias(): void { $this->expectException( \InvalidArgumentException::class ); new ServicePromiseQuoteProvider( F::owner(), F::base_context(), F::base_terms() ); }
	public function test_changed_promise_capture_cannot_trigger_a_recalculation_or_issue_successor_facts(): void { $provider = new ServicePromiseQuoteProvider( F::owner(), F::context(), F::terms() ); $changed = F::context( F::input( [ 'anchor' => 'order_accepted' ], F::time()->plus_seconds( 60 )->sql() ) ); $this->expectException( \InvalidArgumentException::class ); $provider->capture( $changed ); }
	public function test_new_provider_registration_does_not_satisfy_a_legacy_profile_request(): void { $provider = new ServicePromiseQuoteProvider( F::owner(), F::context(), F::terms() ); $registry = new QuoteProviderRegistry( [ $provider ] ); $this->expectException( \InvalidArgumentException::class ); $registry->get( $provider->code(), 1, 'legacy_fixed_base_v1', 1 ); }
	public function test_generic_serialization_does_not_export_private_capture(): void { $provider = new ServicePromiseQuoteProvider( F::owner(), F::context(), F::terms() ); $this->expectException( \LogicException::class ); serialize( $provider ); }
}
