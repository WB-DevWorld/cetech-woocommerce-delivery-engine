<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum BulkVariationPolicy: string {

	case PreserveOverrides = 'preserve_overrides';
	case ParentOnly = 'parent_only';
	case ParentAndInheriting = 'parent_and_inheriting';
	case ResetVariationsToParent = 'reset_variations_to_parent';
	case SelectedVariations = 'selected_variations';
}
