<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\StoreAwareExamples;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\OriginValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\SupplierValidator;

/**
 * Private operational supplier and origin admin CRUD.
 *
 * wp-admin only. These records must never be exposed on the storefront,
 * in customer emails, REST/Store API, or checkout flows.
 */
final class SuppliersOriginsPage {

	public const SLUG = 'cetech-delivery-engine-suppliers-origins';

	private const ACTION_SAVE_SUPPLIER = 'cetech_de_save_supplier';

	private const ACTION_DEACTIVATE_SUPPLIER = 'cetech_de_deactivate_supplier';

	private const ACTION_DELETE_SUPPLIER = 'cetech_de_delete_supplier';

	private const ACTION_SAVE_ORIGIN = 'cetech_de_save_origin';

	private const ACTION_DEACTIVATE_ORIGIN = 'cetech_de_deactivate_origin';

	private const ACTION_DELETE_ORIGIN = 'cetech_de_delete_origin';

	private const DRAFT_SUPPLIER = 'supplier';

	private const DRAFT_ORIGIN = 'origin';

	public function __construct(
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository,
		private SupplierValidator $supplier_validator,
		private OriginValidator $origin_validator,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE_SUPPLIER, self::ACTION_SAVE_SUPPLIER, 'manage_private_sources', self::SLUG ) ) {
			$this->handle_save_supplier();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE_SUPPLIER, self::ACTION_DEACTIVATE_SUPPLIER, 'manage_private_sources', self::SLUG ) ) {
			$this->handle_deactivate_supplier();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE_SUPPLIER, self::ACTION_DELETE_SUPPLIER, 'manage_private_sources', self::SLUG ) ) {
			$this->handle_delete_supplier();
		}

		if ( $this->action_handler->verify_post( self::ACTION_SAVE_ORIGIN, self::ACTION_SAVE_ORIGIN, 'manage_private_sources', self::SLUG ) ) {
			$this->handle_save_origin();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE_ORIGIN, self::ACTION_DEACTIVATE_ORIGIN, 'manage_private_sources', self::SLUG ) ) {
			$this->handle_deactivate_origin();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE_ORIGIN, self::ACTION_DELETE_ORIGIN, 'manage_private_sources', self::SLUG ) ) {
			$this->handle_delete_origin();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_private_sources' );

		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entity = isset( $_GET['entity'] ) ? sanitize_key( wp_unslash( (string) $_GET['entity'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : 'list';

		if ( 'delete' === $action ) {
			if ( 'supplier' === $entity ) {
				$this->render_supplier_delete_confirmation();
				return;
			}

			if ( 'origin' === $entity ) {
				$this->render_origin_delete_confirmation();
				return;
			}

			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		if ( 'supplier' === $entity && ( 'add' === $action || 'edit' === $action ) ) {
			$this->render_supplier_form( 'edit' === $action );
			return;
		}

		if ( 'origin' === $entity && ( 'add' === $action || 'edit' === $action ) ) {
			$this->render_origin_form( 'edit' === $action );
			return;
		}

		$this->render_lists();
	}

	private function render_lists(): void {
		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Dispatch sources', 'cetech-woocommerce-delivery-engine' ),
			__( 'Suppliers & Origins', 'cetech-woocommerce-delivery-engine' ),
			__( 'Suppliers are the businesses or partners you source goods from. Origins are the physical places orders are dispatched from — such as your main store, a warehouse, a supplier location, or a pickup point.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::render_example(
			StoreAwareExamples::supplier_origin_example()
		);

		$this->render_supplier_list();
		$this->render_origin_list();

		AdminPageLayout::close_page();
	}

	private function render_supplier_list(): void {
		AdminPageLayout::open_section(
			__( 'Suppliers', 'cetech-woocommerce-delivery-engine' ),
			__( 'Private business contacts used internally. Never shown to customers.', 'cetech-woocommerce-delivery-engine' )
		);
		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( $this->form_url( 'supplier', 'add' ) ),
			esc_html__( 'Add Supplier', 'cetech-woocommerce-delivery-engine' )
		);

		$suppliers = $this->supplier_repository->list( [ 'limit' => 500 ] );

		if ( [] === $suppliers ) {
			AdminPageLayout::render_empty_state(
				__( 'No suppliers yet', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add suppliers when you need to track where goods come from before dispatch.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add supplier', 'cetech-woocommerce-delivery-engine' ),
				$this->form_url( 'supplier', 'add' )
			);
			AdminPageLayout::close_section();
			return;
		}

		$rows = [];

		foreach ( $suppliers as $supplier ) {
			$id = (int) ( $supplier['id'] ?? 0 );
			$rows[] = [
				esc_html( (string) ( $supplier['internal_name'] ?? '' ) ),
				esc_html( (string) ( $supplier['internal_code'] ?? '' ) ),
				esc_html( (string) ( $supplier['contact_email'] ?? '' ) ?: '—' ),
				esc_html( (string) ( $supplier['contact_phone'] ?? '' ) ?: '—' ),
				AdminUiHelper::record_status_badge( (string) ( $supplier['status'] ?? '' ) ),
				$this->render_supplier_actions( $id ),
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Supplier name', 'cetech-woocommerce-delivery-engine' ),
				__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
				__( 'Email', 'cetech-woocommerce-delivery-engine' ),
				__( 'Phone', 'cetech-woocommerce-delivery-engine' ),
				__( 'Status', 'cetech-woocommerce-delivery-engine' ),
				__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_section();
	}

	private function render_origin_list(): void {
		AdminPageLayout::open_section(
			__( 'Origins', 'cetech-woocommerce-delivery-engine' ),
			__( 'Places orders ship from. Each origin belongs to one supplier.', 'cetech-woocommerce-delivery-engine' )
		);
		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( $this->form_url( 'origin', 'add' ) ),
			esc_html__( 'Add Origin', 'cetech-woocommerce-delivery-engine' )
		);

		$origins   = $this->origin_repository->list( [ 'limit' => 500 ] );
		$suppliers = $this->supplier_name_map();

		if ( [] === $origins ) {
			AdminPageLayout::render_empty_state(
				__( 'No origins yet', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add an origin for each warehouse, store, or dispatch location your team uses.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add origin', 'cetech-woocommerce-delivery-engine' ),
				$this->form_url( 'origin', 'add' )
			);
			AdminPageLayout::close_section();
			return;
		}

		$rows = [];

		foreach ( $origins as $origin ) {
			$id          = (int) ( $origin['id'] ?? 0 );
			$supplier_id = (int) ( $origin['supplier_id'] ?? 0 );
			$address     = $this->origin_validator->decode_internal_address(
				isset( $origin['internal_address'] ) ? (string) $origin['internal_address'] : null
			);
			$location    = array_filter(
				[
					$address['city'] ?: null,
					$address['region'] ?: null,
					(string) ( $origin['country_code'] ?? '' ) ?: null,
				]
			);

			$rows[] = [
				esc_html( (string) ( $origin['internal_name'] ?? '' ) ),
				esc_html( $suppliers[ $supplier_id ] ?? '—' ),
				esc_html( [] !== $location ? implode( ', ', $location ) : '—' ),
				AdminUiHelper::record_status_badge( (string) ( $origin['status'] ?? '' ) ),
				$this->render_origin_actions( $id ),
			];
		}

		AdminPageRenderer::render_table(
			[
				__( 'Origin name', 'cetech-woocommerce-delivery-engine' ),
				__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
				__( 'Location', 'cetech-woocommerce-delivery-engine' ),
				__( 'Status', 'cetech-woocommerce-delivery-engine' ),
				__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
			],
			$rows,
			true
		);

		AdminPageLayout::close_section();
	}

	private function render_supplier_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( $this->draft_key( self::DRAFT_SUPPLIER ) );
		$record = null !== $draft ? $this->supplier_from_draft( $draft ) : $this->load_supplier_for_form( $is_edit );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Dispatch sources', 'cetech-woocommerce-delivery-engine' ),
			$is_edit
				? __( 'Edit Supplier', 'cetech-woocommerce-delivery-engine' )
				: __( 'Add Supplier', 'cetech-woocommerce-delivery-engine' ),
			__( 'A supplier is a business or partner you source goods from. This information is for your team only.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to suppliers & origins', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE_SUPPLIER );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE_SUPPLIER ) . '" />';

		if ( $is_edit && ! empty( $record['id'] ) ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record['id'] ) . '" />';
		}

		AdminPageLayout::open_form_panel(
			__( 'Supplier details', 'cetech-woocommerce-delivery-engine' ),
			__( 'Use names and codes your operations team will recognize.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'internal_name',
			__( 'Supplier name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['internal_name'] ?? '' ),
			true,
			__( 'Example: ABC Wholesale', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			true,
			__( 'Example: abc-wholesale', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'contact_email',
			__( 'Contact email', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['contact_email'] ?? '' ),
			false,
			__( 'Optional email for your team.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'contact_phone',
			__( 'Contact phone', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['contact_phone'] ?? '' ),
			false,
			__( 'Optional phone number for your team.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'internal_notes',
			__( 'Internal notes', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['internal_notes'] ?? '' ),
			4,
			__( 'Private notes for administrators only.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			(string) ( $record['status'] ?? RecordStatus::Active->value ),
			__( 'Inactive suppliers are kept for reference but not used for new setup.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		echo '<div class="cetech-de-form-actions">';
		submit_button( $is_edit ? __( 'Save Supplier', 'cetech-woocommerce-delivery-engine' ) : __( 'Create Supplier', 'cetech-woocommerce-delivery-engine' ) );
		echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE_SUPPLIER,
				'manage_private_sources',
				[ 'entity' => 'supplier' ]
			);
		}

		AdminPageLayout::close_page();
	}

	private function render_origin_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( $this->draft_key( self::DRAFT_ORIGIN ) );
		$record = null !== $draft ? $this->origin_from_draft( $draft ) : $this->load_origin_for_form( $is_edit );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Dispatch sources', 'cetech-woocommerce-delivery-engine' ),
			$is_edit
				? __( 'Edit Origin', 'cetech-woocommerce-delivery-engine' )
				: __( 'Add Origin', 'cetech-woocommerce-delivery-engine' ),
			__( 'An origin is where goods leave from before delivery — such as a main store, warehouse, or supplier location.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to suppliers & origins', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);
		AdminPageLayout::render_example(
			__( 'Main store, warehouse, supplier location, pickup point', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE_ORIGIN );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE_ORIGIN ) . '" />';

		if ( $is_edit && ! empty( $record['id'] ) ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record['id'] ) . '" />';
		}

		AdminPageLayout::open_form_panel(
			__( 'Origin details', 'cetech-woocommerce-delivery-engine' ),
			__( 'Link this dispatch location to a supplier and give it a clear name.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'internal_name',
			__( 'Origin name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['internal_name'] ?? '' ),
			true,
			__( 'Example: Main Warehouse', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			true,
			__( 'Example: main-warehouse-accra', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'supplier_id',
			__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
			$this->supplier_options(),
			(string) ( $record['supplier_id'] ?? '' ),
			__( 'Required. Every origin belongs to one supplier.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			(string) ( $record['status'] ?? RecordStatus::Active->value )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Location', 'cetech-woocommerce-delivery-engine' ),
			__( 'Where this origin is physically located.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'country_code',
			__( 'Country code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['country_code'] ?? '' ),
			false,
			__( '2-letter code. Example: GH', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field( 'region', __( 'Region', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['region'] ?? '' ) );
		AdminFormHelper::text_field( 'city', __( 'City', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['city'] ?? '' ) );
		AdminFormHelper::textarea_field(
			'address_summary',
			__( 'Address summary', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['address_summary'] ?? '' ),
			3,
			__( 'A short address your team can scan quickly.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_advanced( __( 'Dispatch timing and notes', 'cetech-woocommerce-delivery-engine' ) );
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::number_field(
			'dispatch_lead_days_min',
			__( 'Dispatch lead min days', 'cetech-woocommerce-delivery-engine' ),
			$record['dispatch_lead_days_min'] ?? null,
			0,
			__( 'Minimum days before goods leave this origin.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field( 'dispatch_lead_days_max', __( 'Dispatch lead max days', 'cetech-woocommerce-delivery-engine' ), $record['dispatch_lead_days_max'] ?? null, 0 );
		AdminFormHelper::textarea_field(
			'internal_notes',
			__( 'Internal notes', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['internal_notes'] ?? '' ),
			4,
			__( 'Private notes for administrators only.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</tbody></table>';
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		submit_button( $is_edit ? __( 'Save Origin', 'cetech-woocommerce-delivery-engine' ) : __( 'Create Origin', 'cetech-woocommerce-delivery-engine' ) );
		echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE_ORIGIN,
				'manage_private_sources',
				[ 'entity' => 'origin' ]
			);
		}

		AdminPageLayout::close_page();
	}

	private function handle_save_supplier(): void {
		$input  = $this->read_supplier_input();
		$errors = $this->supplier_validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );

		if ( [] !== $errors ) {
			$this->stash_supplier_draft( $input );
			$this->action_handler->notices()->flash_error( implode( ' ', $errors ) );
			$this->redirect_supplier_form( $input );
		}

		$code = AdminFormHelper::sanitize_code( (string) $input['code'] );
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$existing = $this->supplier_repository->findByCode( $code );

		if ( null !== $existing && (int) ( $existing['id'] ?? 0 ) !== $id ) {
			$this->stash_supplier_draft( $input );
			$this->action_handler->notices()->flash_error( __( 'A supplier with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->redirect_supplier_form( $input );
		}

		$previous = $id > 0 ? $this->supplier_repository->findById( $id ) : null;
		$payload  = [
			'id'             => $id,
			'internal_code'  => $code,
			'internal_name'  => trim( (string) $input['internal_name'] ),
			'contact_email'  => trim( (string) ( $input['contact_email'] ?? '' ) ),
			'contact_phone'  => trim( (string) ( $input['contact_phone'] ?? '' ) ),
			'internal_notes' => trim( (string) ( $input['internal_notes'] ?? '' ) ),
			'status'         => (string) $input['status'],
		];

		$saved_id = $this->supplier_repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save supplier.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'supplier',
			$saved_id,
			$previous,
			$this->supplier_repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Supplier updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Supplier created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Supplier updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Supplier created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate_supplier(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid supplier.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->supplier_repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Supplier not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->supplier_repository->deactivate( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate supplier.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Supplier is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'supplier',
			$id,
			$previous,
			$this->supplier_repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Supplier deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Supplier deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_save_origin(): void {
		$input  = $this->read_origin_input();
		$errors = $this->origin_validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );

		if ( [] !== $errors ) {
			$this->stash_origin_draft( $input );
			$this->action_handler->notices()->flash_error( implode( ' ', $errors ) );
			$this->redirect_origin_form( $input );
		}

		$code = AdminFormHelper::sanitize_code( (string) $input['code'] );
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$existing = $this->origin_repository->findByCode( $code );

		if ( null !== $existing && (int) ( $existing['id'] ?? 0 ) !== $id ) {
			$this->stash_origin_draft( $input );
			$this->action_handler->notices()->flash_error( __( 'An origin with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->redirect_origin_form( $input );
		}

		$previous = $id > 0 ? $this->origin_repository->findById( $id ) : null;
		$payload  = [
			'id'                     => $id,
			'supplier_id'            => (int) $input['supplier_id'],
			'internal_code'          => $code,
			'internal_name'          => trim( (string) $input['internal_name'] ),
			'internal_address'       => $this->origin_validator->encode_internal_address( $input ),
			'country_code'           => strtoupper( trim( (string) ( $input['country_code'] ?? '' ) ) ),
			'dispatch_lead_days_min' => $this->nullable_int( $input['dispatch_lead_days_min'] ?? null ),
			'dispatch_lead_days_max' => $this->nullable_int( $input['dispatch_lead_days_max'] ?? null ),
			'internal_notes'         => trim( (string) ( $input['internal_notes'] ?? '' ) ),
			'status'                 => (string) $input['status'],
		];

		$saved_id = $this->origin_repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save origin.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'origin',
			$saved_id,
			$previous,
			$this->origin_repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Origin updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Origin created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Origin updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Origin created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate_origin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid origin.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->origin_repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Origin not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->origin_repository->deactivate( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate origin.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Origin is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'origin',
			$id,
			$previous,
			$this->origin_repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Origin deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Origin deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete_supplier(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid supplier.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->supplier_repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Supplier not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_supplier( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->supplier_repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete supplier.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'supplier', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Supplier permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Supplier deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete_origin(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid origin.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->origin_repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Origin not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_origin( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->origin_repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete origin.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'origin', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Origin permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Origin deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_supplier_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_private_sources' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->supplier_repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Supplier not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE_SUPPLIER,
			self::ACTION_DEACTIVATE_SUPPLIER,
			'manage_private_sources',
			__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['internal_name'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_supplier( $id ),
			[ 'entity' => 'supplier' ]
		);
	}

	private function render_origin_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_private_sources' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->origin_repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Origin not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE_ORIGIN,
			self::ACTION_DEACTIVATE_ORIGIN,
			'manage_private_sources',
			__( 'Origin', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['internal_name'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_origin( $id ),
			[ 'entity' => 'origin' ]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_supplier_for_form( bool $is_edit ): array {
		if ( ! $is_edit ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			return [];
		}

		$row = $this->supplier_repository->findById( $id );

		if ( null === $row ) {
			return [];
		}

		return [
			'id'             => (int) ( $row['id'] ?? 0 ),
			'code'           => (string) ( $row['internal_code'] ?? '' ),
			'internal_name'  => (string) ( $row['internal_name'] ?? '' ),
			'contact_email'  => (string) ( $row['contact_email'] ?? '' ),
			'contact_phone'  => (string) ( $row['contact_phone'] ?? '' ),
			'internal_notes' => (string) ( $row['internal_notes'] ?? '' ),
			'status'         => (string) ( $row['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_origin_for_form( bool $is_edit ): array {
		if ( ! $is_edit ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			return [];
		}

		$row = $this->origin_repository->findById( $id );

		if ( null === $row ) {
			return [];
		}

		$address = $this->origin_validator->decode_internal_address(
			isset( $row['internal_address'] ) ? (string) $row['internal_address'] : null
		);

		return [
			'id'                     => (int) ( $row['id'] ?? 0 ),
			'code'                   => (string) ( $row['internal_code'] ?? '' ),
			'internal_name'          => (string) ( $row['internal_name'] ?? '' ),
			'supplier_id'            => (int) ( $row['supplier_id'] ?? 0 ),
			'country_code'           => (string) ( $row['country_code'] ?? '' ),
			'region'                 => $address['region'],
			'city'                   => $address['city'],
			'address_summary'        => $address['address_summary'],
			'dispatch_lead_days_min' => isset( $row['dispatch_lead_days_min'] ) ? (int) $row['dispatch_lead_days_min'] : null,
			'dispatch_lead_days_max' => isset( $row['dispatch_lead_days_max'] ) ? (int) $row['dispatch_lead_days_max'] : null,
			'internal_notes'         => (string) ( $row['internal_notes'] ?? '' ),
			'status'                 => (string) ( $row['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function supplier_from_draft( array $draft ): array {
		return [
			'id'             => isset( $draft['id'] ) ? (int) $draft['id'] : 0,
			'code'           => (string) ( $draft['code'] ?? '' ),
			'internal_name'  => (string) ( $draft['internal_name'] ?? '' ),
			'contact_email'  => (string) ( $draft['contact_email'] ?? '' ),
			'contact_phone'  => (string) ( $draft['contact_phone'] ?? '' ),
			'internal_notes' => (string) ( $draft['internal_notes'] ?? '' ),
			'status'         => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function origin_from_draft( array $draft ): array {
		return [
			'id'                     => isset( $draft['id'] ) ? (int) $draft['id'] : 0,
			'code'                   => (string) ( $draft['code'] ?? '' ),
			'internal_name'          => (string) ( $draft['internal_name'] ?? '' ),
			'supplier_id'            => isset( $draft['supplier_id'] ) ? (int) $draft['supplier_id'] : 0,
			'country_code'           => (string) ( $draft['country_code'] ?? '' ),
			'region'                 => (string) ( $draft['region'] ?? '' ),
			'city'                   => (string) ( $draft['city'] ?? '' ),
			'address_summary'        => (string) ( $draft['address_summary'] ?? '' ),
			'dispatch_lead_days_min' => isset( $draft['dispatch_lead_days_min'] ) && '' !== $draft['dispatch_lead_days_min'] ? (int) $draft['dispatch_lead_days_min'] : null,
			'dispatch_lead_days_max' => isset( $draft['dispatch_lead_days_max'] ) && '' !== $draft['dispatch_lead_days_max'] ? (int) $draft['dispatch_lead_days_max'] : null,
			'internal_notes'         => (string) ( $draft['internal_notes'] ?? '' ),
			'status'                 => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_supplier_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return [
			'id'             => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'code'           => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '',
			'internal_name'  => isset( $_POST['internal_name'] ) ? wp_unslash( (string) $_POST['internal_name'] ) : '',
			'contact_email'  => isset( $_POST['contact_email'] ) ? wp_unslash( (string) $_POST['contact_email'] ) : '',
			'contact_phone'  => isset( $_POST['contact_phone'] ) ? wp_unslash( (string) $_POST['contact_phone'] ) : '',
			'internal_notes' => isset( $_POST['internal_notes'] ) ? wp_unslash( (string) $_POST['internal_notes'] ) : '',
			'status'         => isset( $_POST['status'] ) ? wp_unslash( (string) $_POST['status'] ) : '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_origin_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return [
			'id'                     => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'code'                   => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '',
			'internal_name'          => isset( $_POST['internal_name'] ) ? wp_unslash( (string) $_POST['internal_name'] ) : '',
			'supplier_id'            => isset( $_POST['supplier_id'] ) ? (int) $_POST['supplier_id'] : 0,
			'country_code'           => isset( $_POST['country_code'] ) ? wp_unslash( (string) $_POST['country_code'] ) : '',
			'region'                 => isset( $_POST['region'] ) ? wp_unslash( (string) $_POST['region'] ) : '',
			'city'                   => isset( $_POST['city'] ) ? wp_unslash( (string) $_POST['city'] ) : '',
			'address_summary'        => isset( $_POST['address_summary'] ) ? wp_unslash( (string) $_POST['address_summary'] ) : '',
			'dispatch_lead_days_min' => $_POST['dispatch_lead_days_min'] ?? '',
			'dispatch_lead_days_max' => $_POST['dispatch_lead_days_max'] ?? '',
			'internal_notes'         => isset( $_POST['internal_notes'] ) ? wp_unslash( (string) $_POST['internal_notes'] ) : '',
			'status'                 => isset( $_POST['status'] ) ? wp_unslash( (string) $_POST['status'] ) : '',
		];
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function redirect_supplier_form( array $input ): never {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$this->action_handler->redirect(
			self::SLUG,
			$id > 0
				? [ 'entity' => 'supplier', 'action' => 'edit', 'id' => $id ]
				: [ 'entity' => 'supplier', 'action' => 'add' ]
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function redirect_origin_form( array $input ): never {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$this->action_handler->redirect(
			self::SLUG,
			$id > 0
				? [ 'entity' => 'origin', 'action' => 'edit', 'id' => $id ]
				: [ 'entity' => 'origin', 'action' => 'add' ]
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function stash_supplier_draft( array $input ): void {
		$this->action_handler->notices()->stash_form_draft( $this->draft_key( self::DRAFT_SUPPLIER ), $input );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function stash_origin_draft( array $input ): void {
		$this->action_handler->notices()->stash_form_draft( $this->draft_key( self::DRAFT_ORIGIN ), $input );
	}

	private function draft_key( string $entity ): string {
		return self::SLUG . '_' . $entity;
	}

	private function form_url( string $entity, string $action, int $id = 0 ): string {
		$args = [
			'page'   => self::SLUG,
			'entity' => $entity,
			'action' => $action,
		];

		if ( $id > 0 ) {
			$args['id'] = $id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function render_supplier_actions( int $id ): string {
		$edit = '<a href="' . esc_url( $this->form_url( 'supplier', 'edit', $id ) ) . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		$deactivate = $this->deactivate_form( self::ACTION_DEACTIVATE_SUPPLIER, $id, __( 'Deactivate this supplier?', 'cetech-woocommerce-delivery-engine' ), __( 'Deactivate', 'cetech-woocommerce-delivery-engine' ) );
		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE_SUPPLIER,
			'manage_private_sources',
			[ 'entity' => 'supplier' ]
		);

		return $edit . $deactivate . $delete;
	}

	private function render_origin_actions( int $id ): string {
		$edit = '<a href="' . esc_url( $this->form_url( 'origin', 'edit', $id ) ) . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		$deactivate = $this->deactivate_form( self::ACTION_DEACTIVATE_ORIGIN, $id, __( 'Deactivate this origin?', 'cetech-woocommerce-delivery-engine' ), __( 'Deactivate', 'cetech-woocommerce-delivery-engine' ) );
		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE_ORIGIN,
			'manage_private_sources',
			[ 'entity' => 'origin' ]
		);

		return $edit . $deactivate . $delete;
	}

	private function deactivate_form( string $action, int $id, string $confirm, string $label ): string {
		$form = '<form method="post" style="display:inline;margin-left:8px;">';
		$form .= wp_nonce_field( $action, 'cetech_de_nonce', true, false );
		$form .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
		$form .= '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$form .= '<button type="submit" class="button-link delete" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">';
		$form .= esc_html( $label );
		$form .= '</button></form>';

		return $form;
	}

	/**
	 * @return array<int, string>
	 */
	private function supplier_name_map(): array {
		$map = [];

		foreach ( $this->supplier_repository->list( [ 'limit' => 500 ] ) as $supplier ) {
			$id = (int) ( $supplier['id'] ?? 0 );

			if ( $id > 0 ) {
				$map[ $id ] = (string) ( $supplier['internal_name'] ?? '' );
			}
		}

		return $map;
	}

	/**
	 * @return array<string, string>
	 */
	private function supplier_options(): array {
		$options = [ '' => __( '— Select supplier —', 'cetech-woocommerce-delivery-engine' ) ];

		foreach ( $this->supplier_repository->list( [ 'limit' => 500 ] ) as $supplier ) {
			$id = (int) ( $supplier['id'] ?? 0 );

			if ( $id > 0 ) {
				$options[ (string) $id ] = sprintf(
					'%s (%s)',
					(string) ( $supplier['internal_name'] ?? '' ),
					(string) ( $supplier['internal_code'] ?? '' )
				);
			}
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_status_options(): array {
		$options = [];

		foreach ( RecordStatus::cases() as $status ) {
			$options[ $status->value ] = AdminUiHelper::record_status_label( $status->value );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function status_options(): array {
		$options = [];

		foreach ( RecordStatus::cases() as $status ) {
			$options[ $status->value ] = $status->value;
		}

		return $options;
	}

	private function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return max( 0, (int) $value );
	}
}
