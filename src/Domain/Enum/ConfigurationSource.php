<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Internal provenance for configuration scopes (never customer-facing).
 */
enum ConfigurationSource: string {

	case Native = 'native';
	case Migrated = 'migrated';
}
