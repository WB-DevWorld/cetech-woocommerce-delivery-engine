<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum BulkOperationType: string {

	case CatalogUpdate = 'catalog_update';
	case CatalogCsvImport = 'catalog_csv_import';
	case CatalogCsvExport = 'catalog_csv_export';
	case ConfigExport = 'config_export';
	case ConfigImport = 'config_import';
	case RateCardUpdate = 'rate_card_update';
	case EntityUpdate = 'entity_update';
	case ValidationScan = 'validation_scan';
	case Cleanup = 'cleanup';
	case Rollback = 'rollback';
}
