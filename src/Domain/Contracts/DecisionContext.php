<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/** Internal explanation facts, not an evaluator or a transport serializer. */
final readonly class DecisionContext implements JsonSerializable {

	public const CONTRACT_VERSION = 1;
	private string $utc_evaluated_at;
	private array $reason_codes;
	private ?array $configuration_versions;
	private array $provenance;
	private DecisionTrace $trace;

	/** @param list<string> $reason_codes @param list<DecisionProvenance> $provenance */
	public function __construct(
		private string $operation,
		private DecisionTarget $target,
		private RequestContext $request,
		DateTimeImmutable $evaluated_at,
		private string $outcome,
		array $reason_codes,
		?array $configuration_versions = null,
		array $provenance = [],
		?DecisionTrace $trace = null,
		private string $cache_state = 'not_recorded',
		private ?DecisionAuditFacts $material_change = null
	) {
		if (!in_array($operation, ['configuration.resolve', 'coverage.match', 'rate.quote', 'configuration.save', 'configuration.reset'], true)
			|| !in_array($outcome, in_array($operation, ['configuration.save', 'configuration.reset'], true)
				? ['accepted', 'rejected', 'pending', 'unconfirmed', 'not_applicable']
				: ['available', 'unavailable', 'disabled', 'unconfirmed', 'not_applicable'], true)
			|| !in_array($cache_state, ['hit', 'miss', 'not_recorded'], true)
			|| !array_is_list($reason_codes) || count($reason_codes) > 64
			|| !array_is_list($provenance) || count($provenance) > 32) {
			throw new InvalidArgumentException('Invalid decision context declaration.');
		}
		$codes = [];
		foreach ($reason_codes as $code) {
			if (!is_string($code) || !DecisionReasonCode::is_known($code)) {
				throw new InvalidArgumentException('Invalid decision reason list.');
			}
			if (!in_array($code, $codes, true)) {
				$codes[] = $code;
			}
		}
		$fields = [];
		foreach ($provenance as $field) {
			if (!$field instanceof DecisionProvenance) {
				throw new InvalidArgumentException('Invalid typed decision provenance.');
			}
			$fields[] = $field;
		}
		$this->reason_codes = $codes;
		$this->provenance = $fields;
		$this->configuration_versions = self::copy_versions($configuration_versions);
		$this->trace = $trace ?? new DecisionTrace();
		$this->utc_evaluated_at = DateTimeImmutable::createFromInterface($evaluated_at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
		if (null !== $material_change && (!$material_change->target->equals($target) || $material_change->identity->operation !== $operation)) {
			throw new InvalidArgumentException('Mutation facts do not belong to this decision.');
		}
		if (null !== $material_change && ('unconfirmed' === $material_change->completion->state) !== ('unconfirmed' === $outcome)) {
			throw new InvalidArgumentException('Decision outcome contradicts mutation completion.');
		}
		if (('accepted' === $outcome && null === $material_change)
			|| (null !== $material_change && !in_array($outcome, ['accepted', 'unconfirmed'], true))) {
			throw new InvalidArgumentException('Decision outcome requires consistent accepted mutation facts.');
		}
	}

	public function operation(): string { return $this->operation; }
	public function target(): DecisionTarget { return $this->target; }
	public function request(): RequestContext { return $this->request; }
	public function evaluated_at(): string { return $this->utc_evaluated_at; }
	public function outcome(): string { return $this->outcome; }
	public function reason_codes(): array { return $this->reason_codes; }
	public function configuration_versions(): ?array { return $this->configuration_versions; }
	public function provenance(): array { return $this->provenance; }
	public function trace(): DecisionTrace { return $this->trace; }
	public function cache_state(): string { return $this->cache_state; }
	public function material_change(): ?DecisionAuditFacts { return $this->material_change; }
	public function is_complete(): bool { return $this->trace->is_complete(); }

	public function jsonSerialize(): never {
		throw new LogicException('Use an explicit purpose-specific decision projection.');
	}

	private static function copy_versions(?array $versions): ?array {
		if (null === $versions) { return null; }
		if (count($versions) !== 4 || [] !== array_diff(['global', 'product', 'variation', 'fingerprint'], array_keys($versions))) {
			throw new InvalidArgumentException('Invalid selected configuration versions.');
		}
		$copy = [];
		foreach (['global', 'product', 'variation'] as $scope) {
			$value = $versions[$scope];
			if (!is_int($value) || $value < 0) {
				throw new InvalidArgumentException('Invalid selected configuration versions.');
			}
			$copy[$scope] = $value;
		}
		if (!is_string($versions['fingerprint']) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $versions['fingerprint'])) {
			throw new InvalidArgumentException('Invalid selected configuration versions.');
		}
		$copy['fingerprint'] = (string) $versions['fingerprint'];
		return $copy;
	}
}
