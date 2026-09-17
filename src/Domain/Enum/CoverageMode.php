<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum CoverageMode: string {

	case EntireArea = 'entire_area';
	case SelectedDescendants = 'selected_descendants';
	case EntireExcept = 'entire_except';
}
