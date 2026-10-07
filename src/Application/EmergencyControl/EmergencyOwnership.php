<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

/** Ownership is independent of runtime module switches and available prices. */
enum EmergencyOwnership: string {
	case Managed = 'managed';
	case Unmanaged = 'unmanaged';
	case Unresolved = 'unresolved';
}
