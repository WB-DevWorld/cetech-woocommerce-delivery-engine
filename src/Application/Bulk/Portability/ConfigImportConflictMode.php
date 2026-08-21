<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

enum ConfigImportConflictMode: string {

	case AddMissing = 'add_missing';
	case UpdateMatching = 'update_matching';
	case SkipConflicts = 'skip_conflicts';
	case Replace = 'replace';
}
