<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetFilters;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvExportService;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigImportConflictMode;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Application\Bulk\Portability\ImportPackageGuard;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * WordPress-native Delivery Engine → Bulk Tools.
 */
final class BulkToolsPage {

	public const SLUG = 'cetech-delivery-engine-bulk-tools';

	public const ACTION_PREVIEW = 'cetech_de_bulk_preview';

	public const ACTION_APPLY = 'cetech_de_bulk_apply';

	public const ACTION_CANCEL = 'cetech_de_bulk_cancel';

	public const ACTION_ROLLBACK = 'cetech_de_bulk_rollback';

	public const ACTION_CSV_PREVIEW = 'cetech_de_bulk_csv_preview';

	public const ACTION_CONFIG_EXPORT = 'cetech_de_bulk_config_export';

	public const ACTION_CSV_EXPORT = 'cetech_de_bulk_csv_export';

	public const ACTION_CONFIG_IMPORT = 'cetech_de_bulk_config_import';

	public const ACTION_RATE_PREVIEW = 'cetech_de_bulk_rate_preview';

	public const ACTION_VALIDATION = 'cetech_de_bulk_validation';

	public function __construct(
		private readonly BulkJobEngine $engine,
		private readonly BulkJobRepositoryInterface $jobs,
		private readonly AdminActionHandler $action_handler,
		private readonly ConfigurationExporter $exporter,
		private readonly CatalogCsvMapper $csv,
		private readonly ?CatalogCsvExportService $csv_export = null,
		private readonly BulkCatalogAdminChoices $catalog_choices = new BulkCatalogAdminChoices()
	) {
	}

	public function add_screen_options(): void {
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Rows per page', 'cetech-woocommerce-delivery-engine' ),
				'default' => BulkAdminListPreferences::DEFAULT_PER_PAGE,
				'option'  => BulkAdminListPreferences::OPTION,
			]
		);
	}

	/**
	 * @param mixed $status
	 * @param mixed $option
	 * @param mixed $value
	 * @return mixed
	 */
	public static function filter_screen_option( $status, $option, $value ) {
		if ( BulkAdminListPreferences::OPTION !== (string) $option ) {
			return $status;
		}

		return BulkAdminListPreferences::sanitize_per_page( $value );
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_PREVIEW, self::ACTION_PREVIEW, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->create_preview_from_post();
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_APPLY, self::ACTION_APPLY, 'manage_product_delivery_rules', self::SLUG ) ) {
			$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( (string) $_POST['job_id'] ) ) : 0;
			try {
				$job = $this->engine->apply( $job_id, get_current_user_id() );
				$this->action_handler->notices()->flash_success(
					sprintf(
						/* translators: %s job code */
						__( 'Bulk job %s is applying in the background. You can leave this page.', 'cetech-woocommerce-delivery-engine' ),
						$job->job_code
					)
				);
			} catch ( \Throwable $exception ) {
				$this->action_handler->notices()->flash_error( $exception->getMessage() );
			}
			$this->action_handler->redirect( self::SLUG, [ 'job' => (string) $job_id, 'tab' => 'jobs' ] );
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_CANCEL, self::ACTION_CANCEL, 'manage_product_delivery_rules', self::SLUG ) ) {
			$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( (string) $_POST['job_id'] ) ) : 0;
			$this->engine->cancel( $job_id );
			$this->action_handler->notices()->flash_success( __( 'Remaining work was cancelled. Completed items were not reversed.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'job' => (string) $job_id, 'tab' => 'jobs' ] );
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_ROLLBACK, self::ACTION_ROLLBACK, 'manage_product_delivery_rules', self::SLUG ) ) {
			$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( (string) $_POST['job_id'] ) ) : 0;
			try {
				$child = $this->engine->rollback( $job_id, get_current_user_id() );
				$this->action_handler->notices()->flash_success(
					sprintf(
						/* translators: %s job code */
						__( 'Rollback job %s is running. Items edited after the original job will be skipped.', 'cetech-woocommerce-delivery-engine' ),
						$child->job_code
					)
				);
				$this->action_handler->redirect( self::SLUG, [ 'job' => (string) $child->id, 'tab' => 'jobs' ] );
			} catch ( \Throwable $exception ) {
				$this->action_handler->notices()->flash_error( $exception->getMessage() );
				$this->action_handler->redirect( self::SLUG, [ 'tab' => 'jobs' ] );
			}
		}
		if ( $this->action_handler->verify_post( self::ACTION_CSV_PREVIEW, self::ACTION_CSV_PREVIEW, 'import_delivery_data', self::SLUG ) ) {
			$this->create_csv_preview_from_post();
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_CONFIG_EXPORT, self::ACTION_CONFIG_EXPORT, 'manage_delivery_settings', self::SLUG ) ) {
			$this->download_configuration_package();
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_CSV_EXPORT, self::ACTION_CSV_EXPORT, 'import_delivery_data', self::SLUG ) ) {
			$this->create_csv_export_from_post();
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_CONFIG_IMPORT, self::ACTION_CONFIG_IMPORT, 'import_delivery_data', self::SLUG ) ) {
			$this->create_config_import_from_post();
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_RATE_PREVIEW, self::ACTION_RATE_PREVIEW, 'manage_delivery_rate_cards', self::SLUG ) ) {
			$this->create_rate_preview_from_post();
			return;
		}
		if ( $this->action_handler->verify_post( self::ACTION_VALIDATION, self::ACTION_VALIDATION, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->create_validation_from_post();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'catalog';
		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Bulk Tools', 'cetech-woocommerce-delivery-engine' ),
			__( 'Preview large catalog changes, then apply them in the background. One command can target many products; the server still processes them in small batches.', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Bulk Tools sections', 'cetech-woocommerce-delivery-engine' ) . '">';
		foreach ( $this->tabs() as $slug => $label ) {
			$class = $tab === $slug ? ' nav-tab-active' : '';
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				esc_attr( $class ),
				esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ),
				esc_html( $label )
			);
		}
		echo '</nav>';

		match ( $tab ) {
			'import' => $this->render_import_tab(),
			'validation' => $this->render_validation_tab(),
			'jobs' => $this->render_jobs_tab(),
			'rates' => $this->render_rates_tab(),
			default => $this->render_catalog_tab(),
		};

		AdminPageLayout::close_page();
	}

	/**
	 * @return array<string, string>
	 */
	private function tabs(): array {
		return [
			'catalog'    => __( 'Catalog', 'cetech-woocommerce-delivery-engine' ),
			'import'     => __( 'Import / Export', 'cetech-woocommerce-delivery-engine' ),
			'validation' => __( 'Validation & Cleanup', 'cetech-woocommerce-delivery-engine' ),
			'jobs'       => __( 'Jobs / History', 'cetech-woocommerce-delivery-engine' ),
			'rates'      => __( 'Charges', 'cetech-woocommerce-delivery-engine' ),
		];
	}

	private function render_catalog_tab(): void {
		echo '<form method="post" class="cetech-de-bulk-form" data-cetech-de-bulk-catalog>';
		wp_nonce_field( self::ACTION_PREVIEW, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_PREVIEW ) . '" />';

		AdminPageLayout::open_form_panel(
			__( '1. Choose products', 'cetech-woocommerce-delivery-engine' ),
			__( 'Search by name or SKU for a few products. Use filters for a group, or confirm the entire catalog.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->open_catalog_row( 'cetech-de-target-scope', __( 'Which products?', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-target-scope" name="target_scope" class="cetech-de-bulk-select-wide">';
		echo '<option value="' . esc_attr( BulkTargetScope::SelectedIds->value ) . '">' . esc_html__( 'Search and select products', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( BulkTargetScope::MatchingFilters->value ) . '">' . esc_html__( 'All products matching filters', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( BulkTargetScope::EntireCatalog->value ) . '">' . esc_html__( 'Entire catalog (requires confirmation)', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row(
			'cetech-de-selected-products',
			__( 'Products', 'cetech-woocommerce-delivery-engine' ),
			[ 'scope' => BulkTargetScope::SelectedIds->value ]
		);
		$this->render_product_search_select( 'cetech-de-selected-products', 'selected_product_ids' );
		echo '<p class="description">' . esc_html__( 'Type a product name or SKU, then select it. You can choose more than one.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->close_catalog_row();

		$this->open_catalog_row(
			'cetech-de-entire-confirm',
			__( 'Confirm entire catalog', 'cetech-woocommerce-delivery-engine' ),
			[ 'scope' => BulkTargetScope::EntireCatalog->value ],
			true
		);
		echo '<label><input type="checkbox" name="entire_catalog_confirmed" value="1" /> ';
		echo esc_html__( 'I confirm this should target the entire catalog', 'cetech-woocommerce-delivery-engine' );
		echo '</label>';
		$this->close_catalog_row();
		AdminPageLayout::close_form_panel();

		echo '<div class="cetech-de-bulk-reveal" data-reveal-scope="' . esc_attr( BulkTargetScope::SelectedIds->value . ',' . BulkTargetScope::MatchingFilters->value ) . '">';
		AdminPageLayout::open_advanced( __( 'Paste a large SKU or ID list', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p><label for="cetech-de-skus">' . esc_html__( 'Paste SKUs', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea id="cetech-de-skus" name="skus" rows="3" class="large-text"></textarea></p>';
		echo '<p class="description">' . esc_html__( 'Use this when you already have a SKU list. The product search above is enough for a few products.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p><label for="cetech-de-product-ids">' . esc_html__( 'Paste product IDs', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea id="cetech-de-product-ids" name="product_ids" rows="3" class="large-text"></textarea></p>';
		AdminPageLayout::close_advanced();
		echo '</div>';

		echo '<div class="cetech-de-bulk-reveal" data-reveal-scope="' . esc_attr( BulkTargetScope::MatchingFilters->value ) . '" hidden>';
		$this->render_catalog_filters();
		echo '</div>';

		AdminPageLayout::open_form_panel(
			__( '2. Choose action', 'cetech-woocommerce-delivery-engine' ),
			__( 'Only the fields you change are written. Leave an action on No change to skip it.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->open_catalog_row( 'cetech-de-fulfilment-action', __( 'Fulfilment Availability', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-fulfilment-action" name="fulfilment_action" class="cetech-de-bulk-select-wide">';
		echo '<option value="' . esc_attr( CatalogFieldAction::NO_CHANGE ) . '">' . esc_html__( 'No change', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogFieldAction::SET_OVERRIDE ) . '">' . esc_html__( 'Set a product-specific value', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogFieldAction::CLEAR_OVERRIDE ) . '">' . esc_html__( 'Restore Site-wide inheritance for this field', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row(
			'cetech-de-fulfilment-value',
			__( 'Fulfilment value', 'cetech-woocommerce-delivery-engine' ),
			[ 'fulfilment' => CatalogFieldAction::SET_OVERRIDE ],
			true
		);
		echo '<select id="cetech-de-fulfilment-value" name="fulfilment_value" class="cetech-de-bulk-select-wide">';
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			echo '<option value="' . esc_attr( $profile->key ) . '">' . esc_html( $profile->label ) . '</option>';
		}
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-offers-action', __( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-offers-action" name="offers_action" class="cetech-de-bulk-select-wide">';
		echo '<option value="' . esc_attr( CatalogFieldAction::NO_CHANGE ) . '">' . esc_html__( 'No change', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogFieldAction::COLLECTION_ADD ) . '">' . esc_html__( 'Add', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogFieldAction::COLLECTION_REMOVE ) . '">' . esc_html__( 'Remove', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogFieldAction::COLLECTION_REPLACE ) . '">' . esc_html__( 'Replace', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogFieldAction::COLLECTION_INHERIT ) . '">' . esc_html__( 'Restore inherited Delivery Options', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row(
			'cetech-de-offer-ids',
			__( 'Delivery Option', 'cetech-woocommerce-delivery-engine' ),
			[ 'offers' => CatalogFieldAction::COLLECTION_ADD . ',' . CatalogFieldAction::COLLECTION_REMOVE . ',' . CatalogFieldAction::COLLECTION_REPLACE ],
			true
		);
		$this->render_labeled_select(
			'cetech-de-offer-ids',
			'offer_ids',
			$this->catalog_choices->delivery_options(),
			__( 'Search for a Delivery Option', 'cetech-woocommerce-delivery-engine' ),
			true
		);
		echo '<p class="description">' . esc_html__( 'Shown as names such as Air Shipping. The stable ID is stored internally.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<details class="cetech-de-technical-details"><summary>' . esc_html__( 'Technical details', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
		echo '<p><label for="cetech-de-offer-ids-advanced">' . esc_html__( 'Delivery Option IDs or codes', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-offer-ids-advanced" name="offer_ids_advanced" type="text" class="regular-text" /></p>';
		echo '</details>';
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-reset-scope', __( 'Reset entire Product Exception', 'cetech-woocommerce-delivery-engine' ) );
		echo '<label><input id="cetech-de-reset-scope" type="checkbox" name="reset_entire_scope" value="1" /> ';
		echo esc_html__( 'Remove this product\'s Delivery Engine exception and restore Site-wide inheritance', 'cetech-woocommerce-delivery-engine' );
		echo '</label>';
		echo '<p class="description cetech-de-bulk-reveal" data-reveal-reset="1" hidden>';
		echo esc_html__( 'Every product-specific Delivery Engine setting on the targeted products will be cleared. They will use Site-wide Defaults again. Variation-specific settings follow the variation setup below.', 'cetech-woocommerce-delivery-engine' );
		echo '</p>';
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-variation-policy', __( 'Variation setup', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-variation-policy" name="variation_policy" class="cetech-de-bulk-select-wide">';
		echo '<option value="' . esc_attr( BulkVariationPolicy::PreserveOverrides->value ) . '">' . esc_html__( 'Keep existing variation settings (recommended)', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( BulkVariationPolicy::ParentOnly->value ) . '">' . esc_html__( 'Change parent products only', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( BulkVariationPolicy::ResetVariationsToParent->value ) . '">' . esc_html__( 'After changing the parent, restore each variation to inherit from the parent', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		$this->close_catalog_row();
		AdminPageLayout::close_form_panel();

		echo '<p class="cetech-de-form-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Preview impact (dry run)', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Preview never writes catalog configuration. Apply starts a background job. Closing the browser does not stop the job.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
	}

	private function render_import_tab(): void {
		if ( ! current_user_can( 'import_delivery_data' ) && ! current_user_can( 'manage_product_delivery_rules' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to import Delivery Engine data.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}
		echo '<h2>' . esc_html__( 'Catalog CSV', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'Assign Delivery Engine configuration by SKU. Blank cells mean no change. They do not reset a field to inherit and they never mean zero.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( $this->csv_export instanceof CatalogCsvExportService ) {
			echo '<form method="post">';
			wp_nonce_field( self::ACTION_CSV_EXPORT, 'cetech_de_nonce' );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_CSV_EXPORT ) . '" />';
			echo '<p><label><input type="checkbox" name="entire_catalog_confirmed" value="1" /> ' . esc_html__( 'Export the entire catalog (required for a complete CSV)', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			echo '<p><button type="submit" class="button">' . esc_html__( 'Download catalog CSV', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
			echo '</form>';
		}
		echo '<form method="post" class="cetech-de-bulk-form">';
		wp_nonce_field( self::ACTION_CSV_PREVIEW, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_CSV_PREVIEW ) . '" />';
		echo '<p><label for="cetech-de-csv">' . esc_html__( 'CSV (header row required)', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea id="cetech-de-csv" name="csv" rows="8" class="large-text code"></textarea></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Preview catalog import (dry run)', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'General configuration package', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'This is not a full site clone and not a product CSV. It copies reusable Delivery Engine setup for a sister store. Orders, shipments, customers, secrets, and runtime storefront flags are never included. Importing does not activate checkout.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( current_user_can( 'manage_delivery_settings' ) ) {
			echo '<form method="post">';
			wp_nonce_field( self::ACTION_CONFIG_EXPORT, 'cetech_de_nonce' );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_CONFIG_EXPORT ) . '" />';
			if ( current_user_can( 'manage_private_sources' ) ) {
				echo '<p><label><input type="checkbox" name="include_private_sources" value="1" /> ' . esc_html__( 'Include private suppliers and origins (off by default)', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			}
			echo '<p><button type="submit" class="button">' . esc_html__( 'Download complete configuration package', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
			echo '</form>';
			echo '<p class="description">' . esc_html__( 'This download iterates every matching entity in batches. It does not silently stop at 500 rows. Large stores should prefer WP-CLI: wp cetech-de config export --file=package.json', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<form method="post" class="cetech-de-bulk-form">';
		wp_nonce_field( self::ACTION_CONFIG_IMPORT, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_CONFIG_IMPORT ) . '" />';
		echo '<p><label for="cetech-de-package">' . esc_html__( 'Paste a configuration package JSON', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea id="cetech-de-package" name="package_json" rows="8" class="large-text code"></textarea></p>';
		echo '<p><label for="cetech-de-conflict">' . esc_html__( 'If a stable code already exists', 'cetech-woocommerce-delivery-engine' ) . '</label><br /><select id="cetech-de-conflict" name="conflict_mode">';
		echo '<option value="skip_conflicts">' . esc_html__( 'Skip conflicts (safer default)', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="add_missing">' . esc_html__( 'Add missing only', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="update_matching">' . esc_html__( 'Update matching codes', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="replace">' . esc_html__( 'Replace (advanced / dangerous)', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Preview configuration import', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	private function render_validation_tab(): void {
		echo '<p>' . esc_html__( 'Validation scans run as background jobs and do not change configuration until you confirm a cleanup job.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<form method="post" class="cetech-de-bulk-form">';
		wp_nonce_field( self::ACTION_VALIDATION, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_VALIDATION ) . '" />';
		AdminPageLayout::open_form_panel(
			__( 'Products to scan', 'cetech-woocommerce-delivery-engine' ),
			__( 'Search by name or SKU. Leave empty only if you paste a list below.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->open_catalog_row( 'cetech-de-validation-products', __( 'Products', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_product_search_select( 'cetech-de-validation-products', 'selected_product_ids' );
		$this->close_catalog_row();
		AdminPageLayout::close_form_panel();
		AdminPageLayout::open_advanced( __( 'Paste a product ID list', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p><label for="cetech-de-validation-ids">' . esc_html__( 'Product IDs', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea id="cetech-de-validation-ids" name="product_ids" rows="4" class="large-text"></textarea></p>';
		AdminPageLayout::close_advanced();
		echo '<p><button type="submit" class="button">' . esc_html__( 'Start validation scan (no writes)', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
		echo '<p>' . esc_html__( 'Needs Attention remains the operational list for products that currently fail delivery resolution.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
	}

	private function render_jobs_tab(): void {
		$per_page = BulkAdminListPreferences::per_page_for_current_user();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job_id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		if ( $job_id > 0 ) {
			$job = $this->engine->find( $job_id );
			if ( $job ) {
				echo '<h2>' . esc_html( $job->job_code ) . '</h2>';
				echo '<p role="status" data-cetech-de-job-id="' . esc_attr( (string) $job->id ) . '">' . esc_html( sprintf( '%s · %d / %d', $job->status->value, $job->processed_count, $job->total_count ) ) . '</p>';
				AdminPageLayout::render_summary_stats(
					[
						[ 'label' => __( 'Total', 'cetech-woocommerce-delivery-engine' ), 'value' => $job->total_count ],
						[ 'label' => __( 'Changed', 'cetech-woocommerce-delivery-engine' ), 'value' => $job->changed_count ],
						[ 'label' => __( 'Unchanged / skipped', 'cetech-woocommerce-delivery-engine' ), 'value' => $job->skipped_count ],
						[ 'label' => __( 'Failed', 'cetech-woocommerce-delivery-engine' ), 'value' => $job->failed_count ],
						[ 'label' => __( 'Warnings', 'cetech-woocommerce-delivery-engine' ), 'value' => $job->warning_count ],
					]
				);
				if ( $job->status->allows_apply() ) {
					$this->job_button( self::ACTION_APPLY, $job->id, __( 'Apply this preview', 'cetech-woocommerce-delivery-engine' ), true );
				}
				if ( $job->status->allows_cancel() ) {
					$this->job_button( self::ACTION_CANCEL, $job->id, __( 'Cancel remaining work', 'cetech-woocommerce-delivery-engine' ), false );
				}
				if ( $job->status->allows_rollback() ) {
					$this->job_button( self::ACTION_ROLLBACK, $job->id, __( 'Roll back eligible items', 'cetech-woocommerce-delivery-engine' ), false );
				}
				$this->render_job_items_table( (int) $job->id, $per_page );
				echo '<details><summary>' . esc_html__( 'Technical details', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
				echo '<pre>' . esc_html( wp_json_encode( $job->summary, JSON_PRETTY_PRINT ) ?: '' ) . '</pre></details>';
			}
		}

		echo '<h2>' . esc_html__( 'Recent jobs', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		$total_jobs = $this->jobs->count_jobs();
		$page       = BulkAdminListPreferences::current_page( 'paged' );
		$jobs       = $this->jobs->list_jobs_page( $page, $per_page );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: job count, 2: rows per page */
				__( 'Showing a page of %2$d jobs. Total jobs: %1$d. Change the page size in Screen Options.', 'cetech-woocommerce-delivery-engine' ),
				$total_jobs,
				$per_page
			)
		) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Job', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $jobs as $job ) {
			$url = add_query_arg( [ 'page' => self::SLUG, 'tab' => 'jobs', 'job' => (string) $job->id ], admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( $job->job_code ) . '</a></td>';
			echo '<td>' . esc_html( $job->operation_type->value ) . '</td>';
			echo '<td>' . esc_html( $job->status->value ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%d / %d', $job->processed_count, $job->total_count ) ) . '</td></tr>';
		}
		if ( [] === $jobs ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No bulk jobs yet.', 'cetech-woocommerce-delivery-engine' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_list_pagination(
			'paged',
			$page,
			$total_jobs,
			$per_page,
			[ 'page' => self::SLUG, 'tab' => 'jobs' ],
			__( 'Job history pagination', 'cetech-woocommerce-delivery-engine' )
		);
	}

	private function render_job_items_table( int $job_id, int $per_page ): void {
		$total_items = $this->jobs->count_items( $job_id );
		$page        = BulkAdminListPreferences::current_page( 'item_paged' );
		$items       = $this->jobs->list_items_page( $job_id, $page, $per_page );
		echo '<h3>' . esc_html__( 'Job items', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: item count, 2: rows per page */
				__( 'This table loads at most %2$d rows from the database. Total items: %1$d. Totals in the summary above come from job counters, not this page.', 'cetech-woocommerce-delivery-engine' ),
				$total_items,
				$per_page
			)
		) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Target', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			$label = '' !== $item->external_key ? $item->external_key : (string) $item->target_id;
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $item->target_type ) . '</td>';
			echo '<td>' . esc_html( $item->status->value ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item->error_code ?? $item->error_summary ?? '' ) ) . '</td></tr>';
		}
		if ( [] === $items ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No job items on this page.', 'cetech-woocommerce-delivery-engine' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_list_pagination(
			'item_paged',
			$page,
			$total_items,
			$per_page,
			[ 'page' => self::SLUG, 'tab' => 'jobs', 'job' => (string) $job_id ],
			__( 'Job item pagination', 'cetech-woocommerce-delivery-engine' )
		);
	}

	private function render_rates_tab(): void {
		if ( ! current_user_can( 'manage_delivery_rate_cards' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to change Delivery Charges in bulk.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Charge amount changes require a preview. A missing or invalid number never becomes 0. Explicit configured zero remains valid.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<form method="post" class="cetech-de-bulk-form">';
		wp_nonce_field( self::ACTION_RATE_PREVIEW, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_RATE_PREVIEW ) . '" />';
		echo '<p><label for="cetech-de-rate-ids">' . esc_html__( 'Delivery Charge IDs', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<textarea id="cetech-de-rate-ids" name="rate_card_ids" rows="4" class="large-text"></textarea></p>';
		echo '<p><label for="cetech-de-rate-op">' . esc_html__( 'Amount change', 'cetech-woocommerce-delivery-engine' ) . '</label><br /><select id="cetech-de-rate-op" name="amount_op">';
		echo '<option value="percent">' . esc_html__( 'Increase/decrease by percent', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="fixed">' . esc_html__( 'Increase/decrease by fixed amount', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select></p>';
		echo '<p><label for="cetech-de-rate-value">' . esc_html__( 'Value (for example 7.5 or -2.00)', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-rate-value" name="amount_value" type="text" class="regular-text" /></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Preview charge changes', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	private function create_preview_from_post(): void {
		$scope = isset( $_POST['target_scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['target_scope'] ) ) : BulkTargetScope::SelectedIds->value;
		$ids   = BulkCatalogAdminChoices::merge_product_ids( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$skus  = $this->parse_sku_list( (string) ( $_POST['skus'] ?? '' ) );
		$scope = BulkCatalogAdminChoices::resolve_target_scope( $scope, $ids, $skus );
		$confirmed = ! empty( $_POST['entire_catalog_confirmed'] );
		$actions   = [];
		$fulfilment_action = sanitize_key( (string) ( $_POST['fulfilment_action'] ?? CatalogFieldAction::NO_CHANGE ) );
		if ( CatalogFieldAction::NO_CHANGE !== $fulfilment_action ) {
			$actions[] = [
				'field_key' => ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
				'action'    => $fulfilment_action,
				'value'     => sanitize_key( (string) ( $_POST['fulfilment_value'] ?? '' ) ),
			];
		}
		$offers_action = sanitize_key( (string) ( $_POST['offers_action'] ?? CatalogFieldAction::NO_CHANGE ) );
		if ( CatalogFieldAction::NO_CHANGE !== $offers_action ) {
			$actions[] = [
				'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
				'action'    => $offers_action,
				'members'   => BulkCatalogAdminChoices::merge_offer_members( wp_unslash( $_POST ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			];
		}
		try {
			$job = $this->engine->create_preview(
				BulkOperationType::CatalogUpdate,
				get_current_user_id(),
				[
					'scope'                    => $scope,
					'selected_ids'             => $ids,
					'skus'                     => $skus,
					'filters'                  => $this->parse_filters_from_post(),
					'entire_catalog_confirmed' => $confirmed,
					'variation_policy'         => sanitize_key( (string) ( $_POST['variation_policy'] ?? BulkVariationPolicy::PreserveOverrides->value ) ),
				],
				[
					'field_actions'      => $actions,
					'reset_entire_scope' => ! empty( $_POST['reset_entire_scope'] ),
				]
			);
			if ( 'background_queue_unavailable' === $job->error_code ) {
				$this->action_handler->notices()->flash_error(
					__( 'Background processing is not available. The catalog was not changed.', 'cetech-woocommerce-delivery-engine' )
				);
			} else {
				$this->action_handler->notices()->flash_success(
					sprintf(
						/* translators: %s job code */
						__( 'Preview job %s started. You can leave this page.', 'cetech-woocommerce-delivery-engine' ),
						$job->job_code
					)
				);
			}
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'jobs', 'job' => (string) $job->id ] );
		} catch ( \Throwable $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG );
		}
	}

	private function job_button( string $action, ?int $job_id, string $label, bool $primary ): void {
		echo '<form method="post" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( $action, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="job_id" value="' . esc_attr( (string) $job_id ) . '" />';
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * @return list<int>
	 */
	private function parse_id_list( string $raw ): array {
		$ids = [];
		foreach ( preg_split( '/[\s,]+/', $raw ) ?: [] as $part ) {
			if ( ctype_digit( $part ) ) {
				$ids[] = (int) $part;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return list<string>
	 */
	private function parse_sku_list( string $raw ): array {
		$skus = [];
		foreach ( preg_split( '/[\s,]+/', $raw ) ?: [] as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$skus[] = $part;
			}
		}

		return $skus;
	}

	/**
	 * @return list<int|string>
	 */
	private function parse_member_list( string $raw ): array {
		$members = [];
		foreach ( preg_split( '/[\s,]+/', $raw ) ?: [] as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$members[] = ctype_digit( $part ) ? (int) $part : sanitize_key( $part );
		}

		return $members;
	}

	private function create_csv_preview_from_post(): void {
		$csv = isset( $_POST['csv'] ) ? (string) wp_unslash( $_POST['csv'] ) : '';
		try {
			ImportPackageGuard::assert_json_size( $csv, 5242880 );
			$job = $this->engine->create_preview(
				BulkOperationType::CatalogCsvImport,
				get_current_user_id(),
				[
					'scope' => BulkTargetScope::SelectedIds->value,
				],
				[
					'csv' => $csv,
				]
			);
			$this->redirect_job( $job->id, $job->job_code, $job->error_code );
		} catch ( \Throwable $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'import' ] );
		}
	}

	private function create_config_import_from_post(): void {
		$json = isset( $_POST['package_json'] ) ? (string) wp_unslash( $_POST['package_json'] ) : '';
		try {
			ImportPackageGuard::assert_json_size( $json );
			$package = ConfigurationPackage::from_json( $json );
			$mode    = sanitize_key( (string) ( $_POST['conflict_mode'] ?? ConfigImportConflictMode::SkipConflicts->value ) );
			$job     = $this->engine->create_preview(
				BulkOperationType::ConfigImport,
				get_current_user_id(),
				[ 'scope' => BulkTargetScope::SelectedIds->value ],
				[
					'package'                 => $package->to_array(),
					'conflict_mode'           => $mode,
					'include_private_sources' => current_user_can( 'manage_private_sources' ),
				]
			);
			$this->redirect_job( $job->id, $job->job_code, $job->error_code );
		} catch ( \Throwable $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'import' ] );
		}
	}

	private function create_rate_preview_from_post(): void {
		$ids = $this->parse_id_list( (string) ( $_POST['rate_card_ids'] ?? '' ) );
		try {
			$job = $this->engine->create_preview(
				BulkOperationType::RateCardUpdate,
				get_current_user_id(),
				[
					'scope'        => BulkTargetScope::SelectedIds->value,
					'selected_ids' => $ids,
				],
				[
					'amount_op'    => sanitize_key( (string) ( $_POST['amount_op'] ?? 'percent' ) ),
					'amount_value' => sanitize_text_field( (string) ( $_POST['amount_value'] ?? '' ) ),
				]
			);
			$this->redirect_job( $job->id, $job->job_code, $job->error_code );
		} catch ( \Throwable $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'rates' ] );
		}
	}

	private function create_validation_from_post(): void {
		$ids = BulkCatalogAdminChoices::merge_product_ids( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		try {
			$job = $this->engine->create_preview(
				BulkOperationType::ValidationScan,
				get_current_user_id(),
				[
					'scope'        => BulkTargetScope::SelectedIds->value,
					'selected_ids' => $ids,
				],
				[]
			);
			$this->redirect_job( $job->id, $job->job_code, $job->error_code );
		} catch ( \Throwable $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'validation' ] );
		}
	}

	private function redirect_job( ?int $job_id, string $code, ?string $error_code ): void {
		if ( 'background_queue_unavailable' === $error_code ) {
			$this->action_handler->notices()->flash_error(
				__( 'Background processing is not available. Nothing was changed.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_success(
				sprintf(
					/* translators: %s job code */
					__( 'Preview job %s started. You can leave this page.', 'cetech-woocommerce-delivery-engine' ),
					$code
				)
			);
		}
		$this->action_handler->redirect( self::SLUG, [ 'tab' => 'jobs', 'job' => (string) $job_id ] );
	}

	private function render_catalog_filters(): void {
		AdminPageLayout::open_form_panel(
			__( 'Basic product filters', 'cetech-woocommerce-delivery-engine' ),
			__( 'Matching filters with nothing selected match no products.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->open_catalog_row( 'cetech-de-search', __( 'Name or SKU', 'cetech-woocommerce-delivery-engine' ) );
		echo '<input id="cetech-de-search" name="search" type="text" class="regular-text" />';
		$this->close_catalog_row( __( 'Finds products whose name or SKU contains this text.', 'cetech-woocommerce-delivery-engine' ) );

		$this->open_catalog_row( 'cetech-de-category-id', __( 'Category', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_taxonomy_select( 'cetech-de-category-id', 'category_id', 'product_cat', __( 'Any category', 'cetech-woocommerce-delivery-engine' ) );
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-tag-id', __( 'Tag', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_taxonomy_select( 'cetech-de-tag-id', 'tag_id', 'product_tag', __( 'Any tag', 'cetech-woocommerce-delivery-engine' ) );
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-shipping-class-id', __( 'Shipping class', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_taxonomy_select( 'cetech-de-shipping-class-id', 'shipping_class_id', 'product_shipping_class', __( 'Any shipping class', 'cetech-woocommerce-delivery-engine' ) );
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-product-type', __( 'Product type', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-product-type" name="product_type" class="cetech-de-bulk-select-wide">';
		echo '<option value="">' . esc_html__( 'Any', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach (
			[
				'simple'   => __( 'Simple product', 'cetech-woocommerce-delivery-engine' ),
				'variable' => __( 'Variable product', 'cetech-woocommerce-delivery-engine' ),
				'grouped'  => __( 'Grouped product', 'cetech-woocommerce-delivery-engine' ),
				'external' => __( 'External/affiliate product', 'cetech-woocommerce-delivery-engine' ),
			] as $type => $label
		) {
			echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-stock-status', __( 'Stock status', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-stock-status" name="stock_status" class="cetech-de-bulk-select-wide">';
		echo '<option value="">' . esc_html__( 'Any', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach (
			[
				'instock'     => __( 'In stock', 'cetech-woocommerce-delivery-engine' ),
				'outofstock'  => __( 'Out of stock', 'cetech-woocommerce-delivery-engine' ),
				'onbackorder' => __( 'On backorder', 'cetech-woocommerce-delivery-engine' ),
			] as $value => $label
		) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		$this->close_catalog_row();
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel( __( 'Delivery filters', 'cetech-woocommerce-delivery-engine' ) );
		$this->open_catalog_row( 'cetech-de-configured-fulfilment', __( 'Product-specific fulfilment setting', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_fulfilment_filter_select( 'cetech-de-configured-fulfilment', 'configured_fulfilment' );
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-effective-fulfilment', __( 'Current fulfilment', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_fulfilment_filter_select( 'cetech-de-effective-fulfilment', 'effective_fulfilment' );
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-exception-state', __( 'Configuration source', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-exception-state" name="exception_state" class="cetech-de-bulk-select-wide">';
		echo '<option value="">' . esc_html__( 'Any', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogTargetFilters::EXCEPTION_SITE_WIDE ) . '">' . esc_html__( 'Uses Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogTargetFilters::EXCEPTION_PRODUCT ) . '">' . esc_html__( 'Has Product Exception', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-variation-state', __( 'Variation setup', 'cetech-woocommerce-delivery-engine' ) );
		echo '<select id="cetech-de-variation-state" name="variation_state" class="cetech-de-bulk-select-wide">';
		echo '<option value="">' . esc_html__( 'Any', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogTargetFilters::VARIATION_INHERIT ) . '">' . esc_html__( 'Inherits product', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="' . esc_attr( CatalogTargetFilters::VARIATION_OVERRIDE ) . '">' . esc_html__( 'Has variation override', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select>';
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-delivery-option-id', __( 'Delivery Option', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_labeled_select(
			'cetech-de-delivery-option-id',
			'delivery_option_id',
			$this->catalog_choices->delivery_options(),
			__( 'Any Delivery Option', 'cetech-woocommerce-delivery-engine' )
		);
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-logistics-id', __( 'Logistics Profile', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_labeled_select(
			'cetech-de-logistics-id',
			'logistics_profile_id',
			$this->catalog_choices->logistics_profiles(),
			__( 'Any logistics profile', 'cetech-woocommerce-delivery-engine' )
		);
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-pickup-id', __( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_labeled_select(
			'cetech-de-pickup-id',
			'pickup_location_id',
			$this->catalog_choices->pickup_locations(),
			__( 'Any pickup location', 'cetech-woocommerce-delivery-engine' )
		);
		$this->close_catalog_row();

		$this->open_catalog_row( 'cetech-de-missing-rate', __( 'Validation state', 'cetech-woocommerce-delivery-engine' ) );
		echo '<label><input id="cetech-de-missing-rate" type="checkbox" name="missing_usable_rate" value="1" /> ';
		echo esc_html__( 'Missing a usable rate or Delivery Option', 'cetech-woocommerce-delivery-engine' );
		echo '</label><br />';
		echo '<label><input type="checkbox" name="invalid_effective" value="1" /> ';
		echo esc_html__( 'Current delivery setup is invalid or incomplete', 'cetech-woocommerce-delivery-engine' );
		echo '</label>';
		$this->close_catalog_row();
		AdminPageLayout::close_form_panel();

		if ( $this->catalog_choices->can_view_private_sources() ) {
			AdminPageLayout::open_advanced( __( 'Private / advanced filters', 'cetech-woocommerce-delivery-engine' ) );
			echo '<p><label for="cetech-de-supplier-id">' . esc_html__( 'Supplier', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
			$this->render_labeled_select(
				'cetech-de-supplier-id',
				'supplier_id',
				$this->catalog_choices->suppliers(),
				__( 'Any supplier', 'cetech-woocommerce-delivery-engine' )
			);
			echo '</p>';
			echo '<p><label for="cetech-de-origin-id">' . esc_html__( 'Origin', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
			$this->render_labeled_select(
				'cetech-de-origin-id',
				'origin_id',
				$this->catalog_choices->origins(),
				__( 'Any origin', 'cetech-woocommerce-delivery-engine' )
			);
			echo '</p>';
			AdminPageLayout::close_advanced();
		}

		AdminPageLayout::open_technical_details( __( 'Advanced / Technical details', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p class="description">' . esc_html__( 'Numeric IDs and codes for troubleshooting. Normal work should use the labelled selectors above.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p><label for="cetech-de-category-id-advanced">' . esc_html__( 'Category ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-category-id-advanced" name="category_id_advanced" type="number" min="0" class="small-text" /></p>';
		echo '<p><label for="cetech-de-tag-id-advanced">' . esc_html__( 'Tag ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-tag-id-advanced" name="tag_id_advanced" type="number" min="0" class="small-text" /></p>';
		echo '<p><label for="cetech-de-shipping-class-id-advanced">' . esc_html__( 'Shipping class ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-shipping-class-id-advanced" name="shipping_class_id_advanced" type="number" min="0" class="small-text" /></p>';
		echo '<p><label for="cetech-de-delivery-option-id-advanced">' . esc_html__( 'Delivery Option ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-delivery-option-id-advanced" name="delivery_option_id_advanced" type="number" min="0" class="small-text" /></p>';
		echo '<p><label for="cetech-de-logistics-id-advanced">' . esc_html__( 'Logistics Profile ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-logistics-id-advanced" name="logistics_profile_id_advanced" type="number" min="0" class="small-text" /></p>';
		echo '<p><label for="cetech-de-pickup-id-advanced">' . esc_html__( 'Pickup Location ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<input id="cetech-de-pickup-id-advanced" name="pickup_location_id_advanced" type="number" min="0" class="small-text" /></p>';
		if ( $this->catalog_choices->can_view_private_sources() ) {
			echo '<p><label for="cetech-de-supplier-id-advanced">' . esc_html__( 'Supplier ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
			echo '<input id="cetech-de-supplier-id-advanced" name="supplier_id_advanced" type="number" min="0" class="small-text" /></p>';
			echo '<p><label for="cetech-de-origin-id-advanced">' . esc_html__( 'Origin ID', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
			echo '<input id="cetech-de-origin-id-advanced" name="origin_id_advanced" type="number" min="0" class="small-text" /></p>';
		}
		AdminPageLayout::close_technical_details();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_filters_from_post(): array {
		$raw = [
			CatalogTargetFilters::PRODUCT_TYPE          => sanitize_key( (string) ( $_POST['product_type'] ?? '' ) ),
			CatalogTargetFilters::STOCK_STATUS          => sanitize_key( (string) ( $_POST['stock_status'] ?? '' ) ),
			CatalogTargetFilters::SEARCH                => sanitize_text_field( (string) ( $_POST['search'] ?? '' ) ),
			CatalogTargetFilters::CATEGORY_ID           => BulkCatalogAdminChoices::first_positive_id( $_POST['category_id'] ?? 0, $_POST['category_id_advanced'] ?? 0 ),
			CatalogTargetFilters::TAG_ID                => BulkCatalogAdminChoices::first_positive_id( $_POST['tag_id'] ?? 0, $_POST['tag_id_advanced'] ?? 0 ),
			CatalogTargetFilters::SHIPPING_CLASS_ID     => BulkCatalogAdminChoices::first_positive_id( $_POST['shipping_class_id'] ?? 0, $_POST['shipping_class_id_advanced'] ?? 0 ),
			CatalogTargetFilters::CONFIGURED_FULFILMENT => sanitize_key( (string) ( $_POST['configured_fulfilment'] ?? '' ) ),
			CatalogTargetFilters::EFFECTIVE_FULFILMENT  => sanitize_key( (string) ( $_POST['effective_fulfilment'] ?? '' ) ),
			CatalogTargetFilters::EXCEPTION_STATE       => sanitize_key( (string) ( $_POST['exception_state'] ?? '' ) ),
			CatalogTargetFilters::VARIATION_STATE       => sanitize_key( (string) ( $_POST['variation_state'] ?? '' ) ),
			CatalogTargetFilters::DELIVERY_OPTION_ID    => BulkCatalogAdminChoices::first_positive_id( $_POST['delivery_option_id'] ?? 0, $_POST['delivery_option_id_advanced'] ?? 0 ),
			CatalogTargetFilters::LOGISTICS_PROFILE_ID  => BulkCatalogAdminChoices::first_positive_id( $_POST['logistics_profile_id'] ?? 0, $_POST['logistics_profile_id_advanced'] ?? 0 ),
			CatalogTargetFilters::PICKUP_LOCATION_ID    => BulkCatalogAdminChoices::first_positive_id( $_POST['pickup_location_id'] ?? 0, $_POST['pickup_location_id_advanced'] ?? 0 ),
			CatalogTargetFilters::MISSING_USABLE_RATE   => ! empty( $_POST['missing_usable_rate'] ),
			CatalogTargetFilters::INVALID_EFFECTIVE     => ! empty( $_POST['invalid_effective'] ),
		];
		if ( $this->catalog_choices->can_view_private_sources() ) {
			$raw[ CatalogTargetFilters::SUPPLIER_ID ] = BulkCatalogAdminChoices::first_positive_id( $_POST['supplier_id'] ?? 0, $_POST['supplier_id_advanced'] ?? 0 );
			$raw[ CatalogTargetFilters::ORIGIN_ID ]   = BulkCatalogAdminChoices::first_positive_id( $_POST['origin_id'] ?? 0, $_POST['origin_id_advanced'] ?? 0 );
		}

		return CatalogTargetFilters::sanitize( $raw );
	}

	private function download_configuration_package(): void {
		$private = ! empty( $_POST['include_private_sources'] ) && current_user_can( 'manage_private_sources' );
		$package = $this->exporter->export( [], $private, $private );
		$json    = $package->to_json();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cetech-de-config-package.json"' );
		header( 'Content-Length: ' . (string) strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * @param array<string, string> $query
	 */
	private function render_list_pagination( string $arg, int $page, int $total, int $per_page, array $query, string $aria_label ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 || ! function_exists( 'paginate_links' ) ) {
			return;
		}

		$base = add_query_arg( array_merge( $query, [ $arg => '%#%' ] ), admin_url( 'admin.php' ) );
		$links = paginate_links(
			[
				'base'      => esc_url_raw( $base ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'cetech-woocommerce-delivery-engine' ),
				'next_text' => __( 'Next &raquo;', 'cetech-woocommerce-delivery-engine' ),
				'type'      => 'plain',
			]
		);
		if ( ! is_string( $links ) || '' === $links ) {
			return;
		}

		echo '<nav class="tablenav bottom" aria-label="' . esc_attr( $aria_label ) . '">';
		echo '<div class="tablenav-pages">' . wp_kses(
			$links,
			[
				'a'    => [
					'class' => true,
					'href'  => true,
				],
				'span' => [
					'class'        => true,
					'aria-current' => true,
				],
			]
		) . '</div></nav>';
	}

	/**
	 * @param array{scope?: string, fulfilment?: string, offers?: string} $reveal
	 */
	private function open_catalog_row( string $for, string $label, array $reveal = [], bool $hidden = false ): void {
		$class = ( [] !== $reveal || $hidden ) ? ' class="cetech-de-bulk-reveal"' : '';
		$attrs = '';
		if ( isset( $reveal['scope'] ) ) {
			$attrs .= ' data-reveal-scope="' . esc_attr( $reveal['scope'] ) . '"';
		}
		if ( isset( $reveal['fulfilment'] ) ) {
			$attrs .= ' data-reveal-fulfilment="' . esc_attr( $reveal['fulfilment'] ) . '"';
		}
		if ( isset( $reveal['offers'] ) ) {
			$attrs .= ' data-reveal-offers="' . esc_attr( $reveal['offers'] ) . '"';
		}

		echo '<tr' . $class . $attrs . ( $hidden ? ' hidden' : '' ) . '>';
		echo '<th scope="row"><label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label></th><td>';
	}

	private function close_catalog_row( ?string $description = null ): void {
		if ( null !== $description && '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	private function render_product_search_select( string $id, string $name ): void {
		printf(
			'<select id="%s" name="%s[]" class="cetech-de-product-search cetech-de-bulk-select-wide" multiple="multiple" data-placeholder="%s"></select>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr__( 'Search for a product by name or SKU', 'cetech-woocommerce-delivery-engine' )
		);
	}

	/**
	 * @param array<int|string, string> $options
	 */
	private function render_labeled_select( string $id, string $name, array $options, string $empty_label, bool $multiple = false ): void {
		$name_attr = $multiple ? $name . '[]' : $name;
		$multiple_attr = $multiple ? ' multiple="multiple"' : '';
		printf(
			'<select id="%s" name="%s" class="cetech-de-enhanced-select cetech-de-bulk-select-wide"%s data-placeholder="%s">',
			esc_attr( $id ),
			esc_attr( $name_attr ),
			$multiple_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_attr( $empty_label )
		);
		if ( ! $multiple ) {
			echo '<option value="">' . esc_html( $empty_label ) . '</option>';
		}
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';
	}

	private function render_taxonomy_select( string $id, string $name, string $taxonomy, string $empty_label ): void {
		$this->render_labeled_select( $id, $name, $this->taxonomy_options( $taxonomy ), $empty_label );
	}

	/**
	 * @return array<int, string>
	 */
	private function taxonomy_options( string $taxonomy ): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return [];
		}

		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1000,
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$options = [];
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) {
				continue;
			}
			$options[ (int) $term->term_id ] = (string) $term->name;
		}

		return $options;
	}

	private function render_fulfilment_filter_select( string $id, string $name ): void {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="cetech-de-bulk-select-wide">';
		echo '<option value="">' . esc_html__( 'Any', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			echo '<option value="' . esc_attr( $profile->key ) . '">' . esc_html( $profile->label ) . '</option>';
		}
		echo '</select>';
	}

	private function create_csv_export_from_post(): void {
		if ( ! $this->csv_export instanceof CatalogCsvExportService ) {
			$this->action_handler->notices()->flash_error( __( 'Catalog CSV export is not available.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'import' ] );
			return;
		}
		$confirmed = ! empty( $_POST['entire_catalog_confirmed'] );
		if ( ! $confirmed ) {
			$this->action_handler->notices()->flash_error( __( 'Confirm entire catalog before downloading a complete CSV.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'tab' => 'import' ] );
			return;
		}
		$definition = CatalogTargetDefinition::from_array(
			[
				'scope'                    => BulkTargetScope::EntireCatalog->value,
				'entire_catalog_confirmed' => true,
			]
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cetech-de-catalog.csv"' );
		$handle = fopen( 'php://output', 'w' );
		if ( false !== $handle ) {
			$this->csv_export->write_stream( $handle, $definition );
			fclose( $handle );
		}
		exit;
	}
}
