<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use InvalidArgumentException;

/** Internal completion facts. Localized prose and transport status are adapter concerns. */
final readonly class OperationOutcome {

	private function __construct(
		public string $state,
		public ?bool $mutation_accepted,
		public bool $publication_pending,
		public ?ContractError $error = null
	) {}

	public static function pending(): self {
		return new self('pending', null, false);
	}

	public static function accepted(): self {
		return new self('accepted', true, false);
	}

	public static function not_applicable(): self {
		return new self('not_applicable', false, false);
	}

	public static function rejected(ContractError $error): self {
		if ('rejected' !== $error->completion_outcome) {
			throw new InvalidArgumentException('Rejection requires a known rejected outcome.');
		}
		return new self('rejected', false, false, $error);
	}

	public static function unconfirmed(ContractError $error): self {
		self::assert_unconfirmed($error);
		return new self('unconfirmed', null, false, $error);
	}

	public static function awaiting_publication(ContractError $error): self {
		self::assert_unconfirmed($error);
		return new self('unconfirmed', true, true, $error);
	}

	public function is_complete(): bool {
		return in_array($this->state, ['accepted', 'rejected', 'not_applicable'], true);
	}

	private static function assert_unconfirmed(ContractError $error): void {
		if ('unconfirmed' !== $error->completion_outcome) {
			throw new InvalidArgumentException('Unconfirmed outcome requires original-request reconciliation.');
		}
	}
}
