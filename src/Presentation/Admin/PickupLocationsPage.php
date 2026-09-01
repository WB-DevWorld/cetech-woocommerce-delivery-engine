<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\PickupLocationValidator;
use CetechDeliveryEngine\Integrations\WPML\WpmlPublicCopyCatalog;

final class PickupLocationsPage {

	public const SLUG = 'cetech-delivery-engine-pickup-locations';

	private const ACTION_SAVE = 'cetech_de_save_pickup_location';

	private const ACTION_DEACTIVATE = 'cetech_de_deactivate_pickup_location';

	private const ACTION_DELETE = 'cetech_de_delete_pickup_location';

	public function __construct(
		private PickupLocationRepositoryInterface $repository,
		private PickupLocationValidator $validator,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker,
		private ?WpmlPublicCopyCatalog $wpml_copy = null
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, Capabilities::PICKUP, self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE, self::ACTION_DEACTIVATE, Capabilities::PICKUP, self::SLUG ) ) {
			$this->handle_deactivate();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE, self::ACTION_DELETE, Capabilities::PICKUP, self::SLUG ) ) {
			$this->handle_delete();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( Capabilities::PICKUP );

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
			__( 'Pickup Locations', 'cetech-woocommerce-delivery-engine' ),
			__( 'Manage store or warehouse pickup points.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Add Pickup Location', 'cetech-woocommerce-delivery-engine' ),
				'url'   => add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
				'class' => 'primary',
			]
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
					'label' => __( 'Total locations', 'cetech-woocommerce-delivery-engine' ),
					'value' => count( $records ),
					'empty' => [] === $records,
				],
				[
					'label' => __( 'Active locations', 'cetech-woocommerce-delivery-engine' ),
					'value' => $active,
					'empty' => 0 === $active,
				],
			]
		);

		if ( [] === $records ) {
			AdminPageLayout::render_empty_state(
				__( 'No pickup locations have been added yet.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Pickup locations are used when customers choose pickup instead of delivery. Add the places where customers can collect their orders.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add Pickup Location', 'cetech-woocommerce-delivery-engine' ),
				add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
			);
		} else {
			AdminPageLayout::open_section(
				__( 'All pickup locations', 'cetech-woocommerce-delivery-engine' ),
				__( 'Address and contact details help staff and customers find each location quickly.', 'cetech-woocommerce-delivery-engine' )
			);

			$rows = [];

			foreach ( $records as $record ) {
				$id    = (int) ( $record['id'] ?? 0 );
				$ready = trim( (string) ( $record['readiness_estimate'] ?? '' ) );
				$rows[] = [
					'<strong>' . esc_html( (string) ( $record['location_name'] ?? '' ) ) . '</strong>',
					esc_html( $this->validator->address_summary( isset( $record['public_address'] ) ? (string) $record['public_address'] : null ) ),
					esc_html( '' !== $ready ? $ready : '—' ),
					AdminUiHelper::record_status_badge( (string) ( $record['status'] ?? '' ) ),
					$this->render_actions( $id ),
				];
			}

			AdminPageRenderer::render_table(
				[
					__( 'Location', 'cetech-woocommerce-delivery-engine' ),
					__( 'Public address', 'cetech-woocommerce-delivery-engine' ),
					__( 'Pickup readiness', 'cetech-woocommerce-delivery-engine' ),
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

	private function render_contact_cell( string $phone, string $email ): string {
		$parts = [];

		if ( '' !== trim( $phone ) ) {
			$parts[] = '<span class="cetech-de-contact-line">' . esc_html( $phone ) . '</span>';
		}

		if ( '' !== trim( $email ) ) {
			$parts[] = '<span class="cetech-de-contact-line">' . esc_html( $email ) . '</span>';
		}

		return [] !== $parts ? implode( '', $parts ) : '—';
	}

	private function render_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG );

		if ( null !== $draft ) {
			$record = $this->form_record_from_draft( $draft );
		} else {
			$record = $this->load_record_for_form( $is_edit );
		}

		$title  = $is_edit
			? __( 'Edit Pickup Location', 'cetech-woocommerce-delivery-engine' )
			: __( 'Add Pickup Location', 'cetech-woocommerce-delivery-engine' );
		$submit = $is_edit
			? __( 'Save Location', 'cetech-woocommerce-delivery-engine' )
			: __( 'Create Location', 'cetech-woocommerce-delivery-engine' );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Customer pickup', 'cetech-woocommerce-delivery-engine' ),
			$title,
			__( 'Add the address, country, and pickup readiness customers need to collect their order.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => $submit,
				'type'  => 'submit',
				'class' => 'primary',
				'form'  => AdminPageLayout::ENTITY_FORM_ID,
			],
			[
				'label' => __( 'Back to pickup locations', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);
		AdminPageLayout::open_entity_form(
			self::ACTION_SAVE,
			self::ACTION_SAVE,
			$submit,
			$is_edit && ! empty( $record['id'] ) ? (int) $record['id'] : null
		);

		AdminPageLayout::open_form_panel(
			__( 'Location name', 'cetech-woocommerce-delivery-engine' ),
			__( 'How customers and staff will recognize this pickup point.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'location_name',
			__( 'Location name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['location_name'] ?? '' ),
			true,
			__( 'Example: CETECH Accra Store', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			false,
			__( 'Generated from the name if left blank. Staff do not need to invent a code. Renaming does not change an established code.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			(string) ( $record['status'] ?? RecordStatus::Active->value ),
			__( 'Inactive locations are hidden from new pickup selections.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Address', 'cetech-woocommerce-delivery-engine' ),
			__( 'The physical address where customers collect their orders.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field( 'address_line_1', __( 'Address line 1', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['address_line_1'] ?? '' ) );
		AdminFormHelper::text_field( 'address_line_2', __( 'Address line 2', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['address_line_2'] ?? '' ) );
		AdminFormHelper::text_field( 'city', __( 'City', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['city'] ?? '' ) );
		AdminFormHelper::text_field( 'region', __( 'Region', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['region'] ?? '' ) );
		AdminFormHelper::country_select_field(
			'country_code',
			__( 'Country', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['country_code'] ?? '' ),
			__( 'Staff select Ghana; the stored value is GH.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field( 'postcode', __( 'Postcode', 'cetech-woocommerce-delivery-engine' ), (string) ( $record['postcode'] ?? '' ) );
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Contact, readiness, and pickup instructions', 'cetech-woocommerce-delivery-engine' ),
			__( 'Help customers know when and how to collect their order.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'readiness_estimate',
			__( 'Pickup readiness', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['readiness_estimate'] ?? '' ),
			false,
			__( 'Shown to customers as “Ready for pickup”. Example: 1–2 business days.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'contact_phone',
			__( 'Phone', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['contact_phone'] ?? '' ),
			false,
			__( 'Shown to customers when they need to call about pickup.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'contact_email',
			__( 'Email', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['contact_email'] ?? '' ),
			false,
			__( 'Optional contact email for pickup questions.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'public_opening_hours',
			__( 'Opening hours', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['public_opening_hours'] ?? '' ),
			3,
			__( 'Example: Mon–Fri 9:00 AM – 5:00 PM', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'public_pickup_instructions',
			__( 'Pickup instructions', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['public_pickup_instructions'] ?? '' ),
			4,
			__( 'Tell customers where to go and what to bring when collecting. Example: Collect from the CETECH Store.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		echo '<div class="cetech-de-form-actions">';
		echo '<a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE,
				Capabilities::PICKUP
			);
		}

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		$input = $this->read_form_input();
		$input = AdminFormHelper::prepare_reference_code(
			$input,
			(string) ( $input['location_name'] ?? '' ),
			function ( string $candidate ) use ( $input ): bool {
				$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$existing = $this->repository->findByCode( $candidate );

				return null !== $existing && (int) ( $existing['id'] ?? 0 ) !== $id;
			},
			function ( int $id ): string {
				$row = $this->repository->findById( $id );

				return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
			},
			'pickup-location'
		);
		$errors = $this->validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );

		if ( [] !== $errors ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( implode( ' ', $errors ) );
			$this->redirect_to_form( $input );
		}

		$code = AdminFormHelper::sanitize_code( (string) $input['code'] );
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$existing_by_code = $this->repository->findByCode( $code );

		if ( null !== $existing_by_code && (int) ( $existing_by_code['id'] ?? 0 ) !== $id ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( __( 'A pickup location with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->redirect_to_form( $input );
		}

		$previous = $id > 0 ? $this->repository->findById( $id ) : null;

		$payload = [
			'id'                         => $id,
			'internal_code'              => $code,
			'location_name'              => trim( (string) $input['location_name'] ),
			'public_address'             => $this->validator->encode_public_address( $input ),
			'public_opening_hours'       => trim( (string) ( $input['public_opening_hours'] ?? '' ) ),
			'public_pickup_instructions' => trim( (string) ( $input['public_pickup_instructions'] ?? '' ) ),
			'contact_phone'              => trim( (string) ( $input['contact_phone'] ?? '' ) ),
			'contact_email'              => trim( (string) ( $input['contact_email'] ?? '' ) ),
			'readiness_estimate'         => trim( (string) ( $input['readiness_estimate'] ?? '' ) ),
			'status'                     => (string) $input['status'],
		];

		$saved_id = $this->repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save pickup location.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$saved = $this->repository->findById( $saved_id );
		if ( is_array( $saved ) ) {
			$this->wpml_copy?->register_pickup_location( $saved );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'pickup_location',
			$saved_id,
			$previous,
			$this->repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Pickup location updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Pickup location created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Pickup location updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Pickup location created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid pickup location.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Pickup location not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->repository->softDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate pickup location.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Pickup location is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'pickup_location',
			$id,
			$previous,
			$this->repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Pickup location deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Pickup location deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid pickup location.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Pickup location not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_pickup_location( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete pickup location.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'pickup_location', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Pickup location permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Pickup location deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_delete_confirmation(): void {
		AdminPageAccess::require_capability( Capabilities::PICKUP );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Pickup location not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE,
			self::ACTION_DEACTIVATE,
			Capabilities::PICKUP,
			__( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['location_name'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_pickup_location( $id )
		);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function redirect_to_form( array $input ): never {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$this->action_handler->redirect(
			self::SLUG,
			$id > 0 ? [ 'action' => 'edit', 'id' => $id ] : [ 'action' => 'add' ]
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

		$address = $this->validator->decode_public_address( isset( $row['public_address'] ) ? (string) $row['public_address'] : null );

		return array_merge(
			[
				'id'                         => (int) ( $row['id'] ?? 0 ),
				'code'                       => (string) ( $row['internal_code'] ?? '' ),
				'location_name'              => (string) ( $row['location_name'] ?? '' ),
				'contact_phone'              => (string) ( $row['contact_phone'] ?? '' ),
				'contact_email'              => (string) ( $row['contact_email'] ?? '' ),
				'public_opening_hours'       => (string) ( $row['public_opening_hours'] ?? '' ),
				'public_pickup_instructions' => (string) ( $row['public_pickup_instructions'] ?? '' ),
				'readiness_estimate'         => (string) ( $row['readiness_estimate'] ?? '' ),
				'status'                     => (string) ( $row['status'] ?? RecordStatus::Active->value ),
			],
			$address
		);
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function form_record_from_draft( array $draft ): array {
		return [
			'id'                         => isset( $draft['id'] ) ? (int) $draft['id'] : 0,
			'code'                       => (string) ( $draft['code'] ?? '' ),
			'location_name'              => (string) ( $draft['location_name'] ?? '' ),
			'address_line_1'             => (string) ( $draft['address_line_1'] ?? '' ),
			'address_line_2'             => (string) ( $draft['address_line_2'] ?? '' ),
			'city'                       => (string) ( $draft['city'] ?? '' ),
			'region'                     => (string) ( $draft['region'] ?? '' ),
			'country_code'               => (string) ( $draft['country_code'] ?? '' ),
			'postcode'                   => (string) ( $draft['postcode'] ?? '' ),
			'contact_phone'              => (string) ( $draft['contact_phone'] ?? '' ),
			'contact_email'              => (string) ( $draft['contact_email'] ?? '' ),
			'public_opening_hours'       => (string) ( $draft['public_opening_hours'] ?? '' ),
			'public_pickup_instructions' => (string) ( $draft['public_pickup_instructions'] ?? '' ),
			'readiness_estimate'         => (string) ( $draft['readiness_estimate'] ?? '' ),
			'status'                     => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_form_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return [
			'id'                         => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'code'                       => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '',
			'location_name'              => isset( $_POST['location_name'] ) ? wp_unslash( (string) $_POST['location_name'] ) : '',
			'address_line_1'             => isset( $_POST['address_line_1'] ) ? wp_unslash( (string) $_POST['address_line_1'] ) : '',
			'address_line_2'             => isset( $_POST['address_line_2'] ) ? wp_unslash( (string) $_POST['address_line_2'] ) : '',
			'city'                       => isset( $_POST['city'] ) ? wp_unslash( (string) $_POST['city'] ) : '',
			'region'                     => isset( $_POST['region'] ) ? wp_unslash( (string) $_POST['region'] ) : '',
			'country_code'               => WooCommerceCountryCatalog::canonical_iso2(
				isset( $_POST['country_code'] ) ? wp_unslash( (string) $_POST['country_code'] ) : ''
			),
			'postcode'                   => isset( $_POST['postcode'] ) ? wp_unslash( (string) $_POST['postcode'] ) : '',
			'contact_phone'              => isset( $_POST['contact_phone'] ) ? wp_unslash( (string) $_POST['contact_phone'] ) : '',
			'contact_email'              => isset( $_POST['contact_email'] ) ? wp_unslash( (string) $_POST['contact_email'] ) : '',
			'public_opening_hours'       => isset( $_POST['public_opening_hours'] ) ? wp_unslash( (string) $_POST['public_opening_hours'] ) : '',
			'public_pickup_instructions' => isset( $_POST['public_pickup_instructions'] ) ? wp_unslash( (string) $_POST['public_pickup_instructions'] ) : '',
			'readiness_estimate'         => isset( $_POST['readiness_estimate'] ) ? wp_unslash( (string) $_POST['readiness_estimate'] ) : '',
			'status'                     => isset( $_POST['status'] ) ? wp_unslash( (string) $_POST['status'] ) : '',
		];
	}

	private function render_actions( int $id ): string {
		$edit_url = esc_url( AdminPageRenderer::edit_url( self::SLUG, $id ) );
		$edit     = '<a href="' . $edit_url . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>';

		$deactivate = '<form method="post" style="display:inline;margin-left:8px;">';
		$deactivate .= wp_nonce_field( self::ACTION_DEACTIVATE, 'cetech_de_nonce', true, false );
		$deactivate .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_DEACTIVATE ) . '" />';
		$deactivate .= '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$deactivate .= '<button type="submit" class="button-link" onclick="return confirm(\'' . esc_js( __( 'Deactivate this pickup location?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
		$deactivate .= esc_html__( 'Deactivate', 'cetech-woocommerce-delivery-engine' );
		$deactivate .= '</button></form>';

		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE,
			Capabilities::PICKUP
		);

		return $edit . $deactivate . $delete;
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
}
