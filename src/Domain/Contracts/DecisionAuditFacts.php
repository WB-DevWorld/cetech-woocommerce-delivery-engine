<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use InvalidArgumentException;

/** Facts supplied by an accepted owned mutation, never inferred from a diagnostic log. */
final readonly class DecisionAuditFacts {

	private array $changed_fields;

	/** @param list<string> $changed_fields */
	public function __construct(
		public DecisionTarget $target,
		public OperationIdentity $identity,
		public OperationOutcome $completion,
		public int $actor_id,
		public string $reason_code,
		public int $before_revision,
		public int $after_revision,
		array $changed_fields
	) {
		if (true !== $completion->mutation_accepted || $actor_id < 1 || $before_revision < 0 || $after_revision <= $before_revision
			|| $identity->site_id !== $target->site_id || $identity->target_key !== $target->key()
			|| !in_array($identity->operation, ['configuration.save', 'configuration.reset'], true)
			|| !in_array($reason_code, ['settings_changed', 'settings_reset'], true)
			|| ('configuration.save' === $identity->operation) !== ('settings_changed' === $reason_code)
			|| !array_is_list($changed_fields) || [] === $changed_fields || count($changed_fields) > count(ConfigurationFieldKey::all())) {
			throw new InvalidArgumentException('Invalid accepted mutation facts.');
		}
		$copy = [];
		foreach ($changed_fields as $field) {
			if (!is_string($field) || !in_array($field, ConfigurationFieldKey::all(), true) || in_array($field, $copy, true)) {
				throw new InvalidArgumentException('Invalid accepted mutation fields.');
			}
			$copy[] = $field;
		}
		$this->changed_fields = $copy;
	}

	public function changed_fields(): array {
		return $this->changed_fields;
	}
}
