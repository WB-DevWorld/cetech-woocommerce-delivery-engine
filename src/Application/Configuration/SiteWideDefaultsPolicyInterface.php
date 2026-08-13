<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

/**
 * Read-only site-wide default policy used by the resolver.
 */
interface SiteWideDefaultsPolicyInterface {

	public function primary_profile_key(): ?string;

	public function is_setup_complete(): bool;

	/**
	 * @return list<string>
	 */
	public function active_profile_keys(): array;

	public function is_active_profile( string $key ): bool;
}
