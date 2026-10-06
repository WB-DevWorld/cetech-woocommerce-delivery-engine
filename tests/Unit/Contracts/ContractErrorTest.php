<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContractErrorTest extends TestCase {

	public function test_localized_wording_does_not_change_code_completion_or_request_identity(): void {
		$context = RequestContext::create();
		$error = new ContractError('stale_revision', $context, 'reload_and_submit');
		$english = [$error->message_key => 'Reload current settings.'];
		$french = [$error->message_key => 'Rechargez les réglages.'];
		self::assertNotSame($english[$error->message_key], $french[$error->message_key]);
		self::assertSame('stale_revision', $error->to_array()['code']);
		self::assertSame('rejected', $error->completion_outcome);
		self::assertSame($context->request_id, $error->to_array()['request_id']);
		self::assertSame($context->correlation_id, $error->to_array()['correlation_id']);
		self::assertArrayNotHasKey('message', $error->to_array());
	}

	public function test_unknown_outcome_requires_reconciliation_of_original_request(): void {
		$error = new ContractError('outcome_unknown', RequestContext::create(), 'reconcile_original_request');
		self::assertSame('unconfirmed', $error->completion_outcome);
		self::assertSame('reconcile_original_request', $error->recovery_action);
		$this->expectException(InvalidArgumentException::class);
		new ContractError('outcome_unknown', RequestContext::create(), 'retry_original_request');
	}

	public function test_safe_parameters_reject_private_nested_renamed_and_exception_values(): void {
		$invalid = [
			['sql' => 'SELECT private_token'],
			['private_token' => 'secret'],
			['retry_after_seconds' => ['value' => 1]],
			['retry_after_seconds' => new \RuntimeException('SQL credential')],
			['retry_after_seconds' => '1'],
			['retry_after_seconds' => -1],
			['retry_after_seconds' => 86401],
			['renamed_retry' => 1],
		];
		foreach ($invalid as $parameters) {
			try {
				new ContractError('temporarily_unavailable', RequestContext::create(), 'retry_original_request', $parameters);
				self::fail('Unsafe error parameters accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Invalid safe error parameters.', $exception->getMessage());
			}
		}
		self::assertSame(['retry_after_seconds' => 0], (new ContractError('temporarily_unavailable', RequestContext::create(), 'retry_original_request', ['retry_after_seconds' => 0]))->parameters);
	}

	public function test_field_violation_schema_rejects_submitted_values_unknown_fields_and_unbounded_lists(): void {
		$invalid = [
			[['field' => 'private_token', 'code' => 'required']],
			[['field' => 'semantic_payload', 'code' => 'SELECT secret']],
			[['field' => 'semantic_payload', 'code' => 'required', 'value' => 'secret']],
			[['field' => ['private' => 1], 'code' => 'required']],
			array_fill(0, 17, ['field' => 'operation', 'code' => 'required']),
			['field' => 'operation', 'code' => 'required'],
		];
		foreach ($invalid as $violations) {
			try {
				new ContractError('invalid_input', RequestContext::create(), 'reload_and_submit', [], $violations);
				self::fail('Unsafe field violations accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Invalid safe field violations.', $exception->getMessage());
			}
		}
	}

	public function test_validated_error_detaches_external_php_references(): void {
		$retry = 5;
		$field = 'operation';
		$code = 'required';
		$error = new ContractError('temporarily_unavailable', RequestContext::create(), 'retry_original_request', ['retry_after_seconds' => &$retry]);
		$invalid = new ContractError('invalid_input', RequestContext::create(), 'reload_and_submit', [], [['field' => &$field, 'code' => &$code]]);
		$retry = 'SELECT secret';
		$field = 'private_token';
		$code = 'private_value';
		self::assertSame(['retry_after_seconds' => 5], $error->to_array()['parameters']);
		self::assertSame([['field' => 'operation', 'code' => 'required']], $invalid->to_array()['field_violations']);
	}

	public function test_unknown_code_and_incompatible_recovery_are_not_serializable(): void {
		foreach ([['raw_exception', 'contact_support'], ['stale_revision', 'retry_original_request']] as [$code, $recovery]) {
			try {
				new ContractError($code, RequestContext::create(), $recovery);
				self::fail('Invalid error vocabulary accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Invalid internal contract error declaration.', $exception->getMessage());
			}
		}
	}
}
