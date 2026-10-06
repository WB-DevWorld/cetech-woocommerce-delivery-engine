<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use InvalidArgumentException;

/** Internal safe error envelope. It is not an existing route or notice serializer. */
final readonly class ContractError {

	public const CONTRACT_VERSION = 1;
	private const RECOVERY = [
		'invalid_input'         => ['reload_and_submit'],
		'not_authorized'        => ['contact_support'],
		'stale_revision'        => ['reload_and_submit'],
		'intent_conflict'       => ['reload_and_submit'],
		'outcome_unknown'       => ['reconcile_original_request'],
		'temporarily_unavailable' => ['retry_original_request', 'contact_support'],
		'unsupported_contract' => ['contact_support'],
	];
	private const FIELDS = ['operation', 'operation_version', 'target_identity', 'preconditions', 'semantic_payload', 'contract_version', 'request_id', 'correlation_id', 'idempotency_key'];
	private const VIOLATIONS = ['required', 'invalid_type', 'invalid_format', 'out_of_range', 'unsupported_value'];

	public string $message_key;
	public string $completion_outcome;
	public array $parameters;
	public array $field_violations;

	/** @param array<string, int> $parameters @param list<array{field: string, code: string}> $field_violations */
	public function __construct(
		public string $code,
		public RequestContext $context,
		public string $recovery_action,
		array $parameters = [],
		array $field_violations = []
	) {
		if (!isset(self::RECOVERY[$code]) || !in_array($recovery_action, self::RECOVERY[$code], true)) {
			throw new InvalidArgumentException('Invalid internal contract error declaration.');
		}
		$allowed_parameter = match ($code) {
			'unsupported_contract' => 'required_contract_version',
			'temporarily_unavailable' => 'retry_after_seconds',
			default => null,
		};
		$safe_parameters = [];
		foreach ($parameters as $key => $value) {
			if ($key !== $allowed_parameter || !is_int($value) || $value < ('required_contract_version' === $key ? 1 : 0) || $value > 86400) {
				throw new InvalidArgumentException('Invalid safe error parameters.');
			}
			$safe_parameters[$key] = $value;
		}
		if (!array_is_list($field_violations) || count($field_violations) > 16 || ([] !== $field_violations && 'invalid_input' !== $code)) {
			throw new InvalidArgumentException('Invalid safe field violations.');
		}
		$safe_violations = [];
		foreach ($field_violations as $violation) {
			if (!is_array($violation) || count($violation) !== 2 || !isset($violation['field'], $violation['code'])
				|| !in_array($violation['field'], self::FIELDS, true) || !in_array($violation['code'], self::VIOLATIONS, true)) {
				throw new InvalidArgumentException('Invalid safe field violations.');
			}
			$safe_violations[] = ['field' => (string) $violation['field'], 'code' => (string) $violation['code']];
		}
		$this->parameters = $safe_parameters;
		$this->field_violations = $safe_violations;
		$this->message_key = 'cetech.contract.' . $code;
		$this->completion_outcome = 'outcome_unknown' === $code ? 'unconfirmed' : 'rejected';
	}

	/** @return array<string, mixed> Explicit projection; contains no exception, token or submitted value. */
	public function to_array(): array {
		return [
			'contract_version' => self::CONTRACT_VERSION,
			'code' => $this->code,
			'message_key' => $this->message_key,
			'parameters' => $this->parameters,
			'field_violations' => $this->field_violations,
			'request_id' => $this->context->request_id,
			'correlation_id' => $this->context->correlation_id,
			'completion_outcome' => $this->completion_outcome,
			'recovery_action' => $this->recovery_action,
		];
	}
}
