<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum ConfigurationFieldValueType: string {

	case String = 'string';
	case Int = 'int';
	case Bool = 'bool';
	case IntList = 'int_list';
}
