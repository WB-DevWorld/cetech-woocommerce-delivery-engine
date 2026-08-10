<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;

/**
 * Result of a scoped configuration write attempt.
 */
final class ScopedConfigurationWriteResult {

	/**
	 * @param list<string>         $errors
	 * @param array<string, mixed> $audit_summary
	 */
	public function __construct(
		public readonly bool $success,
		public readonly array $errors = [],
		public readonly ?ScopedConfiguration $saved = null,
		public readonly bool $version_changed = false,
		public readonly int $version_before = 0,
		public readonly int $version_after = 0,
		public readonly bool $audit_recorded = false,
		public readonly array $audit_summary = []
	) {
	}

	/**
	 * @param list<string> $errors
	 */
	public static function failure( array $errors ): self {
		return new self( false, array_values( $errors ) );
	}

	/**
	 * @param array<string, mixed> $audit_summary
	 */
	public static function success(
		ScopedConfiguration $saved,
		bool $version_changed,
		int $version_before,
		int $version_after,
		bool $audit_recorded,
		array $audit_summary = []
	): self {
		return new self(
			true,
			[],
			$saved,
			$version_changed,
			$version_before,
			$version_after,
			$audit_recorded,
			$audit_summary
		);
	}
}
