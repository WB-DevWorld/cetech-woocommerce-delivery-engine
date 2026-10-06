<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\DecisionContext;
use CetechDeliveryEngine\Domain\Contracts\DecisionTarget;
use CetechDeliveryEngine\Domain\Contracts\DecisionTrace;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DecisionContextTest extends TestCase {

	public function test_utc_time_and_revision_facts_are_detached_from_external_references(): void {
		$revision = 7;
		$fingerprint = str_repeat('a', 64);
		$reason = 'configuration_ready';
		$context = new DecisionContext('configuration.resolve', new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable('2026-10-06T18:00:00.123456+05:30'), 'available', [&$reason], ['global' => &$revision, 'product' => 0, 'variation' => 0, 'fingerprint' => &$fingerprint]);
		$revision = 'SQL token';
		$fingerprint = 'private address';
		$reason = 'renamed_private_data';
		self::assertSame('2026-10-06T12:30:00.123456Z', $context->evaluated_at());
		self::assertSame(7, $context->configuration_versions()['global']);
		self::assertSame(str_repeat('a', 64), $context->configuration_versions()['fingerprint']);
		self::assertSame(['configuration_ready'], $context->reason_codes());
	}

	public function test_unknown_nested_renamed_or_preencoded_reason_data_is_rejected_without_echo(): void {
		foreach ([['private_token'], [['configuration_ready' => ['address' => 'secret']]], ['{"supplier":123}'], [new \RuntimeException('SQL credentials')], array_fill(0, 65, 'configuration_ready'), ['renamed' => 'configuration_ready']] as $reasons) {
			try {
				new DecisionContext('configuration.resolve', new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable(), 'available', $reasons);
				self::fail('Unsafe reason input accepted.');
			} catch (InvalidArgumentException $error) {
				self::assertContains($error->getMessage(), ['Invalid decision reason list.', 'Invalid decision context declaration.']);
			}
		}
	}

	public function test_selected_version_schema_rejects_extra_nested_private_and_encoded_fields(): void {
		$valid = ['global' => 1, 'product' => 2, 'variation' => 0, 'fingerprint' => str_repeat('a', 64)];
		foreach ([$valid + ['metadata' => ['route' => 'private']], array_replace($valid, ['global' => ['value' => 1]]), array_replace($valid, ['global' => '1']), array_replace($valid, ['product' => -1]), array_replace($valid, ['fingerprint' => '{"credential":"secret"}']), ['global' => 1]] as $versions) {
			try {
				new DecisionContext('configuration.resolve', new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable(), 'available', ['configuration_ready'], $versions);
				self::fail('Unsafe version data accepted.');
			} catch (InvalidArgumentException $error) {
				self::assertSame('Invalid selected configuration versions.', $error->getMessage());
			}
		}
	}

	public function test_arbitrary_provenance_objects_and_arrays_are_not_projection_inputs(): void {
		foreach ([[['field_key' => 'supplier_id', 'value' => 99]], [(object) ['renamed' => 'SQL secret']], array_fill(0, 33, null)] as $provenance) {
			try {
				new DecisionContext('configuration.resolve', new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable(), 'available', [], null, $provenance);
				self::fail('Untyped provenance accepted.');
			} catch (InvalidArgumentException $error) {
				self::assertContains($error->getMessage(), ['Invalid typed decision provenance.', 'Invalid decision context declaration.']);
			}
		}
	}

	public function test_raw_context_serialization_is_refused_and_does_not_echo_private_target(): void {
		$context = new DecisionContext('configuration.resolve', new DecisionTarget(1, 987654, 876543, 'in_store'), RequestContext::create(), new DateTimeImmutable(), 'available', ['configuration_ready']);
		try {
			json_encode($context, JSON_THROW_ON_ERROR);
			self::fail('Raw context serialization accepted.');
		} catch (LogicException $error) {
			self::assertSame('Use an explicit purpose-specific decision projection.', $error->getMessage());
		}
	}

	public function test_upstream_incomplete_and_truncated_trace_remain_distinct_from_available_outcome(): void {
		$entry = ['stage' => 'configuration', 'reason_code' => 'configuration_ready', 'reference_kind' => null, 'reference_id' => null, 'reference_version' => null];
		$legacy = new DecisionContext('configuration.resolve', new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable(), 'available', ['configuration_ready'], trace: new DecisionTrace([$entry], false));
		$truncated = new DecisionContext('configuration.resolve', new DecisionTarget(1, 42), RequestContext::create(), new DateTimeImmutable(), 'available', ['configuration_ready'], trace: new DecisionTrace([$entry, $entry], true, 1));
		self::assertFalse($legacy->is_complete());
		self::assertFalse($legacy->trace()->was_truncated());
		self::assertFalse($truncated->is_complete());
		self::assertTrue($truncated->trace()->was_truncated());
		self::assertSame('available', $truncated->outcome());
	}
}
