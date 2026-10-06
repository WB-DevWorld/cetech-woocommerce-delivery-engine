<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\DecisionContext;
use CetechDeliveryEngine\Domain\Contracts\DecisionProjection;
use CetechDeliveryEngine\Domain\Contracts\DecisionProvenance;
use CetechDeliveryEngine\Domain\Contracts\DecisionTarget;
use CetechDeliveryEngine\Domain\Contracts\DecisionTrace;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DecisionProjectionTest extends TestCase {

	private function context(?DecisionTarget $target = null, string $cache = 'not_recorded'): DecisionContext {
		return new DecisionContext('configuration.resolve', $target ?? new DecisionTarget(1, 987654, 876543, 'in_store'), RequestContext::create(), new DateTimeImmutable('2026-10-06T18:00:00Z'), 'unavailable', ['INVALID_REFERENCE'], ['global' => 7, 'product' => 8, 'variation' => 9, 'fingerprint' => str_repeat('a', 64)], [new DecisionProvenance('supplier_id', EffectiveFieldState::Invalid, ConfigurationScopeType::Variation, 'variation', 765432, 9)], new DecisionTrace([['stage' => 'quote', 'reason_code' => 'no_matching_rate_card', 'reference_kind' => 'rate_card', 'reference_id' => 654321, 'reference_version' => null]], false), $cache);
	}

	public function test_shopper_and_log_allowlists_exclude_every_private_identity_and_provenance(): void {
		$context = $this->context();
		$shopper = DecisionProjection::for_shopper($context);
		$log = DecisionProjection::for_diagnostic_log($context, 'warning');
		self::assertSame(['contract_version', 'decision_kind', 'status', 'reasons', 'recovery_action', 'correlation_id', 'complete'], array_keys($shopper));
		self::assertSame('configuration', $shopper['decision_kind']);
		self::assertSame([['category' => 'configuration_unavailable', 'message_key' => 'cetech.decision.configuration_unavailable']], $shopper['reasons']);
		self::assertSame('unavailable', $log['outcome']);
		self::assertSame(['global' => 7, 'product' => 8, 'variation' => 9], $log['configuration_revisions']);
		$encoded = json_encode([$shopper, $log], JSON_THROW_ON_ERROR);
		foreach (['987654', '876543', '765432', '654321', 'supplier_id', 'scope_row_id', 'rate_card', 'fingerprint', 'delivery_address', 'coordinates', 'private_completion_identity', 'actor_id'] as $private) {
			self::assertStringNotContainsString($private, $encoded);
		}
	}

	public function test_current_capability_parent_and_variation_permissions_precede_sensitive_loader(): void {
		$target = new DecisionTarget(1, 42, 43, 'in_store');
		$loads = 0;
		$load = function () use ($target, &$loads): DecisionContext { ++$loads; return $this->context($target); };
		foreach ([[false, true, true], [true, false, true], [true, true, false]] as [$capability, $parent, $variation]) {
			$result = DecisionProjection::for_admin($target, RequestContext::create(), static fn() => $capability && $parent && $variation, $load);
			self::assertInstanceOf(ContractError::class, $result);
			self::assertSame('not_authorized', $result->code);
		}
		self::assertSame(0, $loads);
	}

	public function test_cached_repeat_rechecks_revoked_permission_and_uses_current_attempt_ids(): void {
		$context = $this->context(cache: 'hit');
		$allowed = true;
		$loads = 0;
		$authorize = static function (DecisionTarget $target, string $purpose) use (&$allowed): bool { return $allowed && 1 === $target->site_id && 'admin_explanation' === $purpose; };
		$load = static function () use ($context, &$loads): DecisionContext { ++$loads; return $context; };
		$request = RequestContext::create();
		$first = DecisionProjection::for_admin($context->target(), $request, $authorize, $load);
		self::assertSame(765432, $first['provenance'][0]['scope_row_id']);
		self::assertSame($request->request_id, $first['request_id']);
		self::assertNotSame($context->request()->request_id, $first['request_id']);
		$allowed = false;
		$second = DecisionProjection::for_admin($context->target(), RequestContext::create(), $authorize, $load);
		self::assertInstanceOf(ContractError::class, $second);
		self::assertSame('not_authorized', $second->code);
		self::assertSame(1, $loads);
	}

	public function test_revocation_during_load_denies_disclosure(): void {
		$context = $this->context();
		$allowed = true;
		$result = DecisionProjection::for_admin($context->target(), RequestContext::create(), static function () use (&$allowed): bool { return $allowed; }, static function () use ($context, &$allowed): DecisionContext { $allowed = false; return $context; });
		self::assertInstanceOf(ContractError::class, $result);
		self::assertSame('not_authorized', $result->code);
	}

	public function test_site_parent_variation_and_slice_mismatch_never_discloses_loaded_context(): void {
		$requested = new DecisionTarget(1, 42, 43, 'in_store');
		foreach ([new DecisionTarget(2, 42, 43, 'in_store'), new DecisionTarget(1, 44, 43, 'in_store'), new DecisionTarget(1, 42, 45, 'in_store'), new DecisionTarget(1, 42, 43, 'in_warehouse')] as $other) {
			$result = DecisionProjection::for_admin($requested, RequestContext::create(), static fn() => true, fn() => $this->context($other));
			self::assertInstanceOf(ContractError::class, $result);
			self::assertSame('not_authorized', $result->code);
		}
	}

	public function test_renamed_private_loader_data_and_raw_exception_are_safe_failures(): void {
		$target = new DecisionTarget(1, 42);
		foreach ([static fn() => ['metadata' => ['credentials' => 'secret']], static function (): never { throw new \RuntimeException('SQL credentials 12345'); }] as $load) {
			$result = DecisionProjection::for_admin($target, RequestContext::create(), static fn() => true, $load);
			self::assertInstanceOf(ContractError::class, $result);
			self::assertStringNotContainsString('secret', json_encode($result->to_array(), JSON_THROW_ON_ERROR));
			self::assertStringNotContainsString('12345', json_encode($result->to_array(), JSON_THROW_ON_ERROR));
		}
	}

	public function test_diagnostic_result_cannot_be_presented_as_material_audit(): void {
		$context = $this->context();
		$result = DecisionProjection::for_material_audit($context->target(), RequestContext::create(), static fn() => true, static fn() => $context);
		self::assertInstanceOf(ContractError::class, $result);
		self::assertSame('invalid_input', $result->code);
		self::assertArrayNotHasKey('accepted_change', DecisionProjection::for_diagnostic_log($context));
	}

	public function test_private_data_cannot_be_encoded_as_diagnostic_severity(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid diagnostic severity.');
		DecisionProjection::for_diagnostic_log($this->context(), '{"renamed":"private address"}');
	}

	public function test_source_specific_available_reasons_are_not_checkout_acceptance(): void {
		foreach (['configuration.resolve' => ['configuration_ready', 'configuration'], 'coverage.match' => ['coverage_matched', 'coverage'], 'rate.quote' => ['quote_available', 'quote']] as $operation => [$reason, $kind]) {
			$context = new DecisionContext($operation, new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable(), 'available', [$reason]);
			$result = DecisionProjection::for_shopper($context);
			self::assertSame($kind, $result['decision_kind']);
			self::assertSame('available', $result['status']);
			self::assertArrayNotHasKey('accepted_change', $result);
			self::assertNotSame('delivery_available', $result['reasons'][0]['category']);
		}
	}
}
