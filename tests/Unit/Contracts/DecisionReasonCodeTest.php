<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\DecisionReasonCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DecisionReasonCodeTest extends TestCase {

	public function test_known_internal_reasons_project_to_fixed_safe_categories_and_keys(): void {
		foreach (['INVALID_REFERENCE' => 'configuration_unavailable', 'CONSTRAINT_ROUTE_FILTERED' => 'delivery_options_updated', 'no_matching_rate_card' => 'quote_unavailable', 'destination_not_canonical' => 'destination_required', 'quote_available' => 'quote_available', 'selected_descendant' => 'coverage_available'] as $reason => $category) {
			self::assertTrue(DecisionReasonCode::is_known($reason));
			self::assertSame($category, DecisionReasonCode::public_category($reason));
			self::assertSame('cetech.decision.' . $category, DecisionReasonCode::message_key($reason));
		}
	}

	public function test_alias_nested_encoding_and_oversized_private_reason_never_become_public_words(): void {
		foreach (['origin_alias_123', '{"code":"quote_available","token":"secret"}', "quote_available\nSQL", str_repeat('private', 1000), "\xff"] as $reason) {
			self::assertFalse(DecisionReasonCode::is_known($reason));
			try { DecisionReasonCode::message_key($reason); self::fail('Caller text became a message key.'); }
			catch (InvalidArgumentException $exception) { self::assertSame('Unknown decision reason code.', $exception->getMessage()); }
		}
	}
}
