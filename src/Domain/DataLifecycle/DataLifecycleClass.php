<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;

/** Immutable registered metadata; declarations cannot introduce executable selectors. */
final readonly class DataLifecycleClass implements \JsonSerializable {

	private function __construct(
		public string $class_id, public string $owner, public string $storage_adapter, public string $storage_key,
		public string $site_scope, public int $format, public string $accepted_format, public string $selector,
		public DataLifecyclePolicy $normal_policy, public DataLifecyclePolicy $default_uninstall_policy,
		public DataLifecyclePolicy $explicit_uninstall_policy, public ?int $expires_after_seconds,
		public array $protections, public ?string $owned_hook, public ?string $owned_group,
		public string $diagnostic_projection, public array $source_paths, public array $proof_cases
	) {
	}

	/** @param array<string, mixed> $definition */
	public static function from_definition( array $definition ): self {
		$id = $definition['class_id'] ?? null;
		if ( ! is_string( $id ) || strlen( $id ) > 192 ) { self::invalid(); }
		$canonical = null;
		foreach ( DataLifecycleManifest::definitions() as $entry ) {
			if ( $id === $entry['class_id'] ) { $canonical = $entry; break; }
		}
		if ( null === $canonical || count( $definition ) !== count( $canonical ) || [] !== array_diff( array_keys( $canonical ), array_keys( $definition ) ) ) { self::invalid(); }
		foreach ( $canonical as $field => $value ) {
			if ( $definition[$field] !== $value ) { self::invalid(); }
		}
		// Build only from package-owned scalar declarations. Caller PHP references
		// are not retained, even if a malicious array aliases a nested list.
		return new self(
			$canonical['class_id'], $canonical['owner'], $canonical['storage_adapter'], $canonical['storage_key'],
			$canonical['site_scope'], $canonical['format'], $canonical['accepted_format'], $canonical['selector'],
			DataLifecyclePolicy::from( $canonical['normal_policy'] ), DataLifecyclePolicy::from( $canonical['default_uninstall_policy'] ),
			DataLifecyclePolicy::from( $canonical['explicit_uninstall_policy'] ), $canonical['expires_after_seconds'],
			$canonical['protections'], $canonical['owned_hook'], $canonical['owned_group'], $canonical['diagnostic_projection'],
			$canonical['source_paths'], $canonical['proof_cases']
		);
	}

	public function cleanup_eligible(): bool {
		return DataLifecycleManifest::CACHE_CLASS === $this->class_id
			&& $this->normal_policy->cleanup_eligible();
	}

	/** @return array<string, mixed> Package-owned internal metadata only. */
	public function definition(): array {
		return [
			'class_id' => $this->class_id, 'owner' => $this->owner, 'storage_adapter' => $this->storage_adapter,
			'storage_key' => $this->storage_key, 'site_scope' => $this->site_scope, 'format' => $this->format,
			'accepted_format' => $this->accepted_format, 'selector' => $this->selector,
			'normal_policy' => $this->normal_policy->value, 'default_uninstall_policy' => $this->default_uninstall_policy->value,
			'explicit_uninstall_policy' => $this->explicit_uninstall_policy->value, 'expires_after_seconds' => $this->expires_after_seconds,
			'protections' => $this->protections, 'owned_hook' => $this->owned_hook, 'owned_group' => $this->owned_group,
			'diagnostic_projection' => $this->diagnostic_projection, 'source_paths' => $this->source_paths, 'proof_cases' => $this->proof_cases,
		];
	}

	/** No paths, storage keys, caller content or exceptions enter a public diagnostic. */
	public function diagnostics(): array {
		return [
			'format' => $this->format, 'class' => $this->class_id, 'owner' => $this->owner,
			'normal_policy' => $this->normal_policy->value,
			'default_uninstall_policy' => $this->default_uninstall_policy->value,
			'explicit_uninstall_policy' => $this->explicit_uninstall_policy->value,
			'cleanup_eligible' => $this->cleanup_eligible(),
		];
	}

	public function jsonSerialize(): never {
		throw new \LogicException( 'Data lifecycle declarations require an explicit projection.' );
	}

	private static function invalid(): never {
		throw new \InvalidArgumentException( 'Invalid data lifecycle declaration.' );
	}
}
