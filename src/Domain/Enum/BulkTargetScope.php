<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum BulkTargetScope: string {

	case SelectedIds = 'selected_ids';
	case MatchingFilters = 'matching_filters';
	case EntireCatalog = 'entire_catalog';
}
