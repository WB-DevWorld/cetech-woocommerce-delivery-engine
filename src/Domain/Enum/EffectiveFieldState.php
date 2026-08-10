<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum EffectiveFieldState: string {

	case Valid = 'valid';
	case Unresolved = 'unresolved';
	case Disabled = 'disabled';
	case Invalid = 'invalid';
}
