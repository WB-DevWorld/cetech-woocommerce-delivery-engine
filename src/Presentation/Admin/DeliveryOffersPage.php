<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\CarrierVisibility;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;

final class DeliveryOffersPage {

	public const SLUG = 'cetech-delivery-engine-delivery-offers';

	private const ACTION_SAVE = 'cetech_de_save_delivery_offer';

	private const ACTION_DEACTIVATE = 'cetech_de_deactivate_delivery_offer';

	private const ACTION_DELETE = 'cetech_de_delete_delivery_offer';

	public function __construct(
		private DeliveryOfferRepositoryInterface $repository,
		private DeliveryOfferValidator $validator,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_delivery_offers', self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE, self::ACTION_DEACTIVATE, 'manage_delivery_offers', self::SLUG ) ) {
			$this->handle_deactivate();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE, self::ACTION_DELETE, 'manage_delivery_offers', self::SLUG ) ) {
			$this->handle_delete();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_offers' );

		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : 'list';

		if ( 'delete' === $action ) {
			$this->render_delete_confirmation();
			return;
		}

		if ( 'add' === $action || 'edit' === $action ) {
			$this->render_form( 'edit' === $action );
			return;
		}

		$this->render_list();
	}

	private function render_list(): void {
		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ),
			__( 'Create and manage the delivery choices customers can select.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Add Delivery Option', 'cetech-woocommerce-delivery-engine' ),
				'url'   => add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
				'class' => 'primary',
			]
		);
		AdminPageLayout::render_info_notice(
			__( 'These options appear at checkout or on product pages when a customer chooses how their order is delivered.', 'cetech-woocommerce-delivery-engine' ),
			'success'
		);

		$records = $this->repository->list( [ 'limit' => 500 ] );
		$active  = 0;

		foreach ( $records as $record ) {
			if ( RecordStatus::Active->value === (string) ( $record['status'] ?? '' ) ) {
				++$active;
			}
		}

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Total delivery options', 'cetech-woocommerce-delivery-engine' ),
					'value' => count( $records ),
					'empty' => [] === $records,
				],
				[
					'label' => __( 'Active delivery options', 'cetech-woocommerce-delivery-engine' ),
					'value' => $active,
					'empty' => 0 === $active,
				],
			]
		);

		if ( [] === $records ) {
			AdminPageLayout::render_empty_state(
				__( 'No Delivery Options have been created yet.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery Options are the choices customers can select, such as Standard Delivery, Air Shipping, or Sea Shipping.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Create Delivery Option', 'cetech-woocommerce-delivery-engine' ),
				add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
			);
		} else {
			AdminPageLayout::open_section(
				__( 'All delivery options', 'cetech-woocommerce-delivery-engine' ),
				__( 'The public label is what customers see at checkout.', 'cetech-woocommerce-delivery-engine' )
			);

			$rows = [];

			foreach ( $records as $record ) {
				$id = (int) ( $record['id'] ?? 0 );
				$rows[] = [
					'<strong>' . esc_html( (string) ( $record['public_label'] ?? '' ) ) . '</strong>',
					esc_html( $this->route_label( (string) ( $record['route'] ?? '' ) ) ),
					esc_html( $this->estimated_delivery_label( $record ) ),
					AdminUiHelper::record_status_badge( (string) ( $record['status'] ?? '' ) ),
					$this->render_actions( $id ),
				];
			}

			AdminPageRenderer::render_table(
				[
					__( 'Delivery Option', 'cetech-woocommerce-delivery-engine' ),
					__( 'Route', 'cetech-woocommerce-delivery-engine' ),
					__( 'Estimated Delivery', 'cetech-woocommerce-delivery-engine' ),
					__( 'Status', 'cetech-woocommerce-delivery-engine' ),
					__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
				],
				$rows,
				true
			);

			AdminPageLayout::close_section();
		}

		AdminPageLayout::close_page();
	}

	private function render_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG );

		if ( null !== $draft ) {
			$record = $this->form_record_from_draft( $draft );
		} else {
			$record = $this->load_record_for_form( $is_edit );
		}

		$title  = $is_edit
			? __( 'Edit Delivery Option', 'cetech-woocommerce-delivery-engine' )
			: __( 'Add Delivery Option', 'cetech-woocommerce-delivery-engine' );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			$title,
			__( 'Describe a delivery service customers can choose. Use a clear name they will recognize at checkout.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to Delivery Options', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);
		AdminPageLayout::render_example(
			__( 'Same-Day, Next-Day, Standard Delivery, Pickup', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		if ( $is_edit && null !== $record && ! empty( $record['id'] ) ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record['id'] ) . '" />';
		}

		AdminPageLayout::open_form_panel(
			__( 'What customers see', 'cetech-woocommerce-delivery-engine' ),
			__( 'These details appear when customers choose how they want their order delivered.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'public_label',
			__( 'Customer-facing name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['public_label'] ?? '' ),
			true,
			__( 'Example: Same-Day Delivery', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'description',
			__( 'Short description', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['description'] ?? '' ),
			3,
			__( 'Optional helper text shown to customers.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'route',
			__( 'Delivery type', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_route_options(),
			(string) ( $record['route'] ?? '' ),
			__( 'Whether this is home delivery or customer pickup.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'service_level',
			__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['service_level'] ?? '' ),
			false,
			__( 'Shown to customers when this delivery option is selected. Example: 3–6 business days.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			(string) ( $record['status'] ?? RecordStatus::Active->value ),
			__( 'Inactive delivery options are hidden from new checkout selections.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_advanced( __( 'Advanced details', 'cetech-woocommerce-delivery-engine' ) );
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			false,
			__( 'Generated from the name if left blank. Staff do not need to invent a code.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'display_priority',
			__( 'Sort order', 'cetech-woocommerce-delivery-engine' ),
			$record['display_priority'] ?? 100,
			0,
			__( 'Lower numbers appear first when multiple delivery options are shown.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field( 'processing_min_days', __( 'Processing min days', 'cetech-woocommerce-delivery-engine' ), $record['processing_min_days'] ?? null, 0, __( 'Minimum days to prepare the order before dispatch.', 'cetech-woocommerce-delivery-engine' ) );
		AdminFormHelper::number_field( 'processing_max_days', __( 'Processing max days', 'cetech-woocommerce-delivery-engine' ), $record['processing_max_days'] ?? null, 0 );
		AdminFormHelper::number_field( 'transit_min_days', __( 'Transit min days', 'cetech-woocommerce-delivery-engine' ), $record['transit_min_days'] ?? null, 0, __( 'Minimum days in transit after dispatch.', 'cetech-woocommerce-delivery-engine' ) );
		AdminFormHelper::number_field( 'transit_max_days', __( 'Transit max days', 'cetech-woocommerce-delivery-engine' ), $record['transit_max_days'] ?? null, 0 );
		AdminFormHelper::number_field( 'final_mile_min_days', __( 'Final mile min days', 'cetech-woocommerce-delivery-engine' ), $record['final_mile_min_days'] ?? null, 0 );
		AdminFormHelper::number_field( 'final_mile_max_days', __( 'Final mile max days', 'cetech-woocommerce-delivery-engine' ), $record['final_mile_max_days'] ?? null, 0 );
		AdminFormHelper::select_field(
			'carrier_visibility',
			__( 'Carrier visibility', 'cetech-woocommerce-delivery-engine' ),
			$this->carrier_visibility_options(),
			(string) ( $record['carrier_visibility'] ?? CarrierVisibility::AssignedByStore->value ),
			__( 'How carrier information is shown internally.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'carrier_display_name',
			__( 'Carrier display name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['carrier_display_name'] ?? '' ),
			false,
			__( 'Required when carrier visibility is set to Named.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</tbody></table>';
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		submit_button( $is_edit ? __( 'Save Delivery Option', 'cetech-woocommerce-delivery-engine' ) : __( 'Create Delivery Option', 'cetech-woocommerce-delivery-engine' ) );
		echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE,
				'manage_delivery_offers'
			);
		}

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		$input  = $this->read_form_input();
		$errors = $this->validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );

		if ( [] !== $errors ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( implode( ' ', $errors ) );
			$this->action_handler->redirect(
				self::SLUG,
				[
					'action' => isset( $input['id'] ) && (int) $input['id'] > 0 ? 'edit' : 'add',
					'id'     => isset( $input['id'] ) ? (int) $input['id'] : 0,
				]
			);
		}

		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$code = AdminFormHelper::sanitize_code( (string) $input['code'] );
		if ( '' === $code ) {
			$code = AdminFormHelper::generate_code_from_name(
				(string) ( $input['public_label'] ?? '' ),
				function ( string $candidate ) use ( $id ): bool {
					$existing = $this->repository->findByCode( $candidate );

					return null !== $existing && (int) ( $existing['id'] ?? 0 ) !== $id;
				},
				'delivery-option'
			);
			$input['code'] = $code;
		}
		$existing_by_code = $this->repository->findByCode( $code );

		if ( null !== $existing_by_code && (int) ( $existing_by_code['id'] ?? 0 ) !== $id ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( __( 'A delivery option with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, $id > 0 ? [ 'action' => 'edit', 'id' => $id ] : [ 'action' => 'add' ] );
		}

		$public_label = trim( (string) $input['public_label'] );
		$carrier_visibility = (string) $input['carrier_visibility'];
		$carrier_name = CarrierVisibility::Named->value === $carrier_visibility
			? trim( (string) ( $input['carrier_display_name'] ?? '' ) )
			: null;

		$previous = $id > 0 ? $this->repository->findById( $id ) : null;

		$payload = [
			'id'                     => $id,
			'internal_code'          => $code,
			'internal_name'          => $public_label,
			'public_label'           => $public_label,
			'public_description'     => trim( (string) ( $input['description'] ?? '' ) ),
			'route'                  => (string) $input['route'],
			'service_level'          => trim( (string) ( $input['service_level'] ?? '' ) ),
			'carrier_visibility'     => $carrier_visibility,
			'carrier_name'           => $carrier_name,
			'default_processing_min' => DeliveryOfferValidator::nullable_int( $input['processing_min_days'] ?? null ),
			'default_processing_max' => DeliveryOfferValidator::nullable_int( $input['processing_max_days'] ?? null ),
			'default_transit_min'    => DeliveryOfferValidator::nullable_int( $input['transit_min_days'] ?? null ),
			'default_transit_max'    => DeliveryOfferValidator::nullable_int( $input['transit_max_days'] ?? null ),
			'default_final_mile_min' => DeliveryOfferValidator::nullable_int( $input['final_mile_min_days'] ?? null ),
			'default_final_mile_max' => DeliveryOfferValidator::nullable_int( $input['final_mile_max_days'] ?? null ),
			'display_priority'       => (int) ( $input['display_priority'] ?? 100 ),
			'status'                 => (string) $input['status'],
		];

		$saved_id = $this->repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save delivery offer.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'delivery_offer',
			$saved_id,
			$previous,
			$this->repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Delivery offer updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Delivery offer created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Delivery offer updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Delivery offer created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid delivery offer.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Delivery offer not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->repository->softDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate delivery offer.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Delivery offer is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'delivery_offer',
			$id,
			$previous,
			$this->repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Delivery offer deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Delivery offer deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid delivery offer.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Delivery offer not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_delivery_offer( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete delivery offer.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'delivery_offer', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Delivery offer permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Delivery offer deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_delivery_offers' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Delivery offer not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE,
			self::ACTION_DEACTIVATE,
			'manage_delivery_offers',
			__( 'Delivery Option', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['public_label'] ?? $record['internal_name'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_delivery_offer( $id )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_record_for_form( bool $is_edit ): array {
		if ( ! $is_edit ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			return [];
		}

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			return [];
		}

		return [
			'id'                   => (int) ( $row['id'] ?? 0 ),
			'code'                 => (string) ( $row['internal_code'] ?? '' ),
			'public_label'         => (string) ( $row['public_label'] ?? '' ),
			'description'          => (string) ( $row['public_description'] ?? '' ),
			'route'                => (string) ( $row['route'] ?? '' ),
			'service_level'        => (string) ( $row['service_level'] ?? '' ),
			'carrier_visibility'   => (string) ( $row['carrier_visibility'] ?? '' ),
			'carrier_display_name' => (string) ( $row['carrier_name'] ?? '' ),
			'processing_min_days'  => isset( $row['default_processing_min'] ) ? (int) $row['default_processing_min'] : null,
			'processing_max_days'  => isset( $row['default_processing_max'] ) ? (int) $row['default_processing_max'] : null,
			'transit_min_days'     => isset( $row['default_transit_min'] ) ? (int) $row['default_transit_min'] : null,
			'transit_max_days'     => isset( $row['default_transit_max'] ) ? (int) $row['default_transit_max'] : null,
			'final_mile_min_days'  => isset( $row['default_final_mile_min'] ) ? (int) $row['default_final_mile_min'] : null,
			'final_mile_max_days'  => isset( $row['default_final_mile_max'] ) ? (int) $row['default_final_mile_max'] : null,
			'display_priority'     => (int) ( $row['display_priority'] ?? 100 ),
			'status'               => (string) ( $row['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_form_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return [
			'id'                   => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'code'                 => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '',
			'public_label'         => isset( $_POST['public_label'] ) ? wp_unslash( (string) $_POST['public_label'] ) : '',
			'description'          => isset( $_POST['description'] ) ? wp_unslash( (string) $_POST['description'] ) : '',
			'route'                => isset( $_POST['route'] ) ? wp_unslash( (string) $_POST['route'] ) : '',
			'service_level'        => isset( $_POST['service_level'] ) ? wp_unslash( (string) $_POST['service_level'] ) : '',
			'carrier_visibility'   => isset( $_POST['carrier_visibility'] ) ? wp_unslash( (string) $_POST['carrier_visibility'] ) : '',
			'carrier_display_name' => isset( $_POST['carrier_display_name'] ) ? wp_unslash( (string) $_POST['carrier_display_name'] ) : '',
			'processing_min_days'  => $_POST['processing_min_days'] ?? '',
			'processing_max_days'  => $_POST['processing_max_days'] ?? '',
			'transit_min_days'     => $_POST['transit_min_days'] ?? '',
			'transit_max_days'     => $_POST['transit_max_days'] ?? '',
			'final_mile_min_days'  => $_POST['final_mile_min_days'] ?? '',
			'final_mile_max_days'  => $_POST['final_mile_max_days'] ?? '',
			'display_priority'     => $_POST['display_priority'] ?? 100,
			'status'               => isset( $_POST['status'] ) ? wp_unslash( (string) $_POST['status'] ) : '',
		];
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function form_record_from_draft( array $draft ): array {
		return [
			'id'                   => isset( $draft['id'] ) ? (int) $draft['id'] : 0,
			'code'                 => (string) ( $draft['code'] ?? '' ),
			'public_label'         => (string) ( $draft['public_label'] ?? '' ),
			'description'          => (string) ( $draft['description'] ?? '' ),
			'route'                => (string) ( $draft['route'] ?? '' ),
			'service_level'        => (string) ( $draft['service_level'] ?? '' ),
			'carrier_visibility'   => (string) ( $draft['carrier_visibility'] ?? CarrierVisibility::AssignedByStore->value ),
			'carrier_display_name' => (string) ( $draft['carrier_display_name'] ?? '' ),
			'processing_min_days'  => $draft['processing_min_days'] ?? null,
			'processing_max_days'  => $draft['processing_max_days'] ?? null,
			'transit_min_days'     => $draft['transit_min_days'] ?? null,
			'transit_max_days'     => $draft['transit_max_days'] ?? null,
			'final_mile_min_days'  => $draft['final_mile_min_days'] ?? null,
			'final_mile_max_days'  => $draft['final_mile_max_days'] ?? null,
			'display_priority'     => isset( $draft['display_priority'] ) ? (int) $draft['display_priority'] : 100,
			'status'               => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
		];
	}

	private function render_actions( int $id ): string {
		$edit_url = esc_url( AdminPageRenderer::edit_url( self::SLUG, $id ) );
		$edit     = '<a href="' . $edit_url . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>';

		$deactivate = '<form method="post" style="display:inline;margin-left:8px;">';
		$deactivate .= wp_nonce_field( self::ACTION_DEACTIVATE, 'cetech_de_nonce', true, false );
		$deactivate .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_DEACTIVATE ) . '" />';
		$deactivate .= '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$deactivate .= '<button type="submit" class="button-link" onclick="return confirm(\'' . esc_js( __( 'Deactivate this delivery option?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
		$deactivate .= esc_html__( 'Deactivate', 'cetech-woocommerce-delivery-engine' );
		$deactivate .= '</button></form>';

		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE,
			'manage_delivery_offers'
		);

		return $edit . $deactivate . $delete;
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_route_options(): array {
		$options = [];

		foreach ( DeliveryRoute::cases() as $route ) {
			$options[ $route->value ] = $this->route_label( $route->value );
		}

		return $options;
	}

	private function route_label( string $route ): string {
		return match ( $route ) {
			DeliveryRoute::LocalDelivery->value => __( 'Local delivery', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::StorePickup->value => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Air->value => __( 'Air Shipping', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Sea->value => __( 'Sea Shipping', 'cetech-woocommerce-delivery-engine' ),
			default => $route,
		};
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function estimated_delivery_label( array $record ): string {
		return \CetechDeliveryEngine\Application\Configuration\Admin\OfferEstimatedDeliveryDisplay::label( $record );
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
	private function route_options(): array {
		$options = [];

		foreach ( DeliveryRoute::cases() as $route ) {
			$options[ $route->value ] = $route->value;
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function carrier_visibility_options(): array {
		$options = [];

		foreach ( CarrierVisibility::cases() as $visibility ) {
			$options[ $visibility->value ] = $visibility->value;
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
}
