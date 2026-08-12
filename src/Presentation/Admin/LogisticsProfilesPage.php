<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\LogisticsProfileValidator;

final class LogisticsProfilesPage {

	public const SLUG = 'cetech-delivery-engine-logistics-profiles';

	private const ACTION_SAVE = 'cetech_de_save_logistics_profile';

	private const ACTION_DEACTIVATE = 'cetech_de_deactivate_logistics_profile';

	private const ACTION_DELETE = 'cetech_de_delete_logistics_profile';

	public function __construct(
		private LogisticsProfileRepositoryInterface $repository,
		private LogisticsProfileValidator $validator,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_logistics_profiles', self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE, self::ACTION_DEACTIVATE, 'manage_logistics_profiles', self::SLUG ) ) {
			$this->handle_deactivate();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE, self::ACTION_DELETE, 'manage_logistics_profiles', self::SLUG ) ) {
			$this->handle_delete();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_logistics_profiles' );

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
			__( 'Delivery configuration', 'cetech-woocommerce-delivery-engine' ),
			__( 'Logistics Profiles', 'cetech-woocommerce-delivery-engine' ),
			__( 'Group delivery handling rules for products or orders that need similar delivery treatment.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Add Logistics Profile', 'cetech-woocommerce-delivery-engine' ),
				'url'   => add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
				'class' => 'primary',
			],
			[
				'label' => __( 'Back to Dashboard', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( AdminMenu::SYSTEM_STATUS_SLUG ),
			]
		);

		AdminPageLayout::render_example(
			__( 'Fragile Items → require careful handling and may only be available for Standard Delivery.', 'cetech-woocommerce-delivery-engine' )
		);

		$records         = $this->repository->list( [ 'limit' => 500 ] );
		$active          = 0;
		$inactive        = 0;
		$needing_review  = 0;

		foreach ( $records as $record ) {
			if ( RecordStatus::Active->value === (string) ( $record['status'] ?? '' ) ) {
				++$active;
			} else {
				++$inactive;
			}

			if ( $this->profile_needs_review( $record ) ) {
				++$needing_review;
			}
		}

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Total profiles', 'cetech-woocommerce-delivery-engine' ),
					'value' => count( $records ),
					'empty' => [] === $records,
				],
				[
					'label' => __( 'Active profiles', 'cetech-woocommerce-delivery-engine' ),
					'value' => $active,
					'empty' => 0 === $active,
				],
				[
					'label' => __( 'Inactive profiles', 'cetech-woocommerce-delivery-engine' ),
					'value' => $inactive,
					'empty' => 0 === $inactive,
				],
				[
					'label' => __( 'Profiles needing review', 'cetech-woocommerce-delivery-engine' ),
					'value' => $needing_review,
					'empty' => 0 === $needing_review,
				],
			]
		);

		if ( $needing_review > 0 ) {
			AdminPageLayout::render_warning(
				__( 'Some profiles may not be ready to use', 'cetech-woocommerce-delivery-engine' ),
				__( 'Inactive profiles are ignored at checkout. Active profiles without handling details may also need a description before staff know when to use them.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( [] === $records ) {
			AdminPageLayout::render_empty_state(
				__( 'No logistics profiles yet', 'cetech-woocommerce-delivery-engine' ),
				__( 'Create a logistics profile when some products need special delivery handling, such as heavy, fragile, pickup-only, or supplier-dispatched items.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add Logistics Profile', 'cetech-woocommerce-delivery-engine' ),
				add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
			);
		} else {
			AdminPageLayout::open_section(
				__( 'All logistics profiles', 'cetech-woocommerce-delivery-engine' ),
				__( 'Link profiles to product rules and rate cards when products need different handling or pricing.', 'cetech-woocommerce-delivery-engine' )
			);

			$rows = [];

			foreach ( $records as $record ) {
				$id = (int) ( $record['id'] ?? 0 );
				$rows[] = [
					$this->render_profile_name_cell( $record ),
					$this->render_code_cell( $record ),
					esc_html( $this->handling_summary( $record ) ),
					esc_html( $this->format_route_eligibility_friendly( (string) ( $record['route_eligibility'] ?? '' ) ) ),
					AdminUiHelper::record_status_badge( (string) ( $record['status'] ?? '' ) ),
					$this->render_actions( $id ),
				];
			}

			AdminPageRenderer::render_table(
				[
					__( 'Profile name', 'cetech-woocommerce-delivery-engine' ),
					__( 'Code / reference', 'cetech-woocommerce-delivery-engine' ),
					__( 'Handling purpose', 'cetech-woocommerce-delivery-engine' ),
					__( 'Eligible delivery routes', 'cetech-woocommerce-delivery-engine' ),
					__( 'Status', 'cetech-woocommerce-delivery-engine' ),
					__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
				],
				$rows,
				true
			);

			AdminPageLayout::close_section();
		}

		$this->render_help_section();
		AdminPageLayout::close_page();
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function render_profile_name_cell( array $record ): string {
		$name = esc_html( (string) ( $record['internal_name'] ?? '' ) );
		$id   = (int) ( $record['id'] ?? 0 );

		if ( $id <= 0 ) {
			return $name;
		}

		return $name . '<br><span class="cetech-de-setting-code">#' . esc_html( (string) $id ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function render_code_cell( array $record ): string {
		$code = esc_html( (string) ( $record['internal_code'] ?? '' ) );

		if ( '' === $code ) {
			return '—';
		}

		return $code;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function handling_summary( array $record ): string {
		$handling = trim( (string) ( $record['handling_class'] ?? '' ) );

		if ( '' !== $handling ) {
			return $handling;
		}

		$description = trim( (string) ( $record['description'] ?? '' ) );

		if ( '' === $description ) {
			return '—';
		}

		if ( strlen( $description ) > 80 ) {
			return substr( $description, 0, 77 ) . '...';
		}

		return $description;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function profile_needs_review( array $record ): bool {
		if ( RecordStatus::Inactive->value === (string) ( $record['status'] ?? '' ) ) {
			return true;
		}

		$handling    = trim( (string) ( $record['handling_class'] ?? '' ) );
		$description = trim( (string) ( $record['description'] ?? '' ) );

		return '' === $handling && '' === $description;
	}

	private function render_help_section(): void {
		AdminPageLayout::open_section(
			__( 'What is a logistics profile?', 'cetech-woocommerce-delivery-engine' ),
			__( 'A logistics profile groups the delivery handling rules used to fulfil an item, such as how it is dispatched or which delivery services can be used.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<div class="cetech-de-help-card">';
		echo '<p>' . esc_html__(
			'For example, heavy items, fragile items, pickup-only items, or items that need special handling.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '<ul class="cetech-de-help-steps">';
		echo '<li>' . esc_html__( 'Standard goods', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Heavy items', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Fragile items', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Pickup only', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Supplier-dispatched items', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Same-day eligible items', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__(
			'After creating a profile, connect it to product rules and rate cards so the right products use the right handling and pricing.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		printf(
			'<p class="cetech-de-help-action"><a class="button button-secondary" href="%1$s">%2$s</a> ',
			esc_url( AdminPageRenderer::list_url( ProductDeliveryRulesPage::SLUG ) ),
			esc_html__( 'Manage product rules', 'cetech-woocommerce-delivery-engine' )
		);
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a></p>',
			esc_url( AdminPageRenderer::list_url( RateCardsPage::SLUG ) ),
			esc_html__( 'Manage rate cards', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</div>';
		AdminPageLayout::close_section();
	}

	private function render_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG );

		if ( null !== $draft ) {
			$record = $this->form_record_from_draft( $draft );
		} else {
			$record = $this->load_record_for_form( $is_edit );
		}

		$title = $is_edit
			? __( 'Edit Logistics Profile', 'cetech-woocommerce-delivery-engine' )
			: __( 'Add Logistics Profile', 'cetech-woocommerce-delivery-engine' );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery configuration', 'cetech-woocommerce-delivery-engine' ),
			$title,
			__( 'Describe how products with similar delivery needs should be handled, then link the profile to product rules and rate cards.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to profiles', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);

		AdminPageLayout::render_example(
			__( 'Heavy Items → may need special delivery pricing or pickup-only handling.', 'cetech-woocommerce-delivery-engine' )
		);

		$status = (string) ( $record['status'] ?? RecordStatus::Active->value );

		if ( RecordStatus::Inactive->value === $status ) {
			AdminPageLayout::render_warning(
				__( 'This profile is inactive', 'cetech-woocommerce-delivery-engine' ),
				__( 'It will not be used until you set the status back to Active and save.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$routes = (array) ( $record['route_eligibility'] ?? [] );

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		if ( $is_edit && ! empty( $record['id'] ) ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record['id'] ) . '" />';
		}

		AdminPageLayout::open_form_panel(
			__( 'Profile details', 'cetech-woocommerce-delivery-engine' ),
			__( 'How staff will recognize this profile in lists and when linking it to product rules.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'name',
			__( 'Profile name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['name'] ?? '' ),
			true,
			__( 'Example: Fragile Items', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			true,
			__( 'Example: fragile-items. Lowercase letters, numbers, underscores, and hyphens only.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			$status,
			__( 'Inactive profiles are ignored until activated again.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Delivery handling', 'cetech-woocommerce-delivery-engine' ),
			__( 'Explain what makes products in this group different and how staff should treat them.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'handling_type',
			__( 'Handling type', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['handling_type'] ?? '' ),
			false,
			__( 'Example: heavy, fragile, pickup-only, or supplier-dispatched.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'description',
			__( 'Description and instructions', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['description'] ?? '' ),
			4,
			__( 'Describe when staff should use this profile and any special handling notes.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_advanced( __( 'Advanced settings', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p class="description">' . esc_html__(
			'Technical routing and sizing options. Most stores can leave these blank unless CETECH support asks you to configure them.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::text_field(
			'parcel_size_class',
			__( 'Parcel size class', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['parcel_size_class'] ?? '' ),
			false,
			__( 'Optional internal size grouping, such as small, medium, or oversized.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">parcel_size_class</p></td></tr>';
		AdminFormHelper::checkbox_group_field(
			'route_eligibility',
			__( 'Eligible delivery routes', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_route_options(),
			$routes,
			__( 'Limit which delivery routes can use this profile. Leave all unchecked if any route may apply.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">route_eligibility</p></td></tr>';
		AdminFormHelper::text_field(
			'consolidation_policy',
			__( 'Consolidation policy', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['consolidation_policy'] ?? '' ),
			false,
			__( 'Optional internal rule for combining shipments. Leave blank unless instructed by CETECH support.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">consolidation_rule</p></td></tr>';
		echo '</tbody></table>';
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		submit_button( $is_edit ? __( 'Save Profile', 'cetech-woocommerce-delivery-engine' ) : __( 'Create Profile', 'cetech-woocommerce-delivery-engine' ) );
		echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE,
				'manage_logistics_profiles'
			);
		}

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		$input = $this->read_form_input();
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

		$code = AdminFormHelper::sanitize_code( (string) $input['code'] );
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$existing_by_code = $this->repository->findByCode( $code );

		if ( null !== $existing_by_code && (int) ( $existing_by_code['id'] ?? 0 ) !== $id ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( __( 'A logistics profile with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, $id > 0 ? [ 'action' => 'edit', 'id' => $id ] : [ 'action' => 'add' ] );
		}

		$previous = $id > 0 ? $this->repository->findById( $id ) : null;
		$routes   = isset( $input['route_eligibility'] ) && is_array( $input['route_eligibility'] )
			? array_map( 'strval', $input['route_eligibility'] )
			: [];

		$payload = [
			'id'                 => $id,
			'internal_code'      => $code,
			'internal_name'      => trim( (string) $input['name'] ),
			'description'        => trim( (string) ( $input['description'] ?? '' ) ),
			'parcel_size_class'  => trim( (string) ( $input['parcel_size_class'] ?? '' ) ),
			'handling_class'     => trim( (string) ( $input['handling_type'] ?? '' ) ),
			'route_eligibility'  => $this->validator->encode_route_eligibility( $routes ),
			'consolidation_rule' => trim( (string) ( $input['consolidation_policy'] ?? '' ) ),
			'status'             => (string) $input['status'],
		];

		$saved_id = $this->repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save logistics profile.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'logistics_profile',
			$saved_id,
			$previous,
			$this->repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Logistics profile updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Logistics profile created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Logistics profile updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Logistics profile created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid logistics profile.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Logistics profile not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->repository->softDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate logistics profile.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Logistics profile is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'logistics_profile',
			$id,
			$previous,
			$this->repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Logistics profile deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Logistics profile deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid logistics profile.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Logistics profile not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_logistics_profile( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete logistics profile.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'logistics_profile', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Logistics profile permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Logistics profile deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_logistics_profiles' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Logistics profile not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE,
			self::ACTION_DEACTIVATE,
			'manage_logistics_profiles',
			__( 'Logistics Profile', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['internal_name'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_logistics_profile( $id )
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load_record_for_form( bool $is_edit ): ?array {
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
			'name'                 => (string) ( $row['internal_name'] ?? '' ),
			'description'          => (string) ( $row['description'] ?? '' ),
			'parcel_size_class'    => (string) ( $row['parcel_size_class'] ?? '' ),
			'handling_type'        => (string) ( $row['handling_class'] ?? '' ),
			'route_eligibility'    => $this->validator->decode_route_eligibility( (string) ( $row['route_eligibility'] ?? '' ) ),
			'consolidation_policy' => (string) ( $row['consolidation_rule'] ?? '' ),
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
			'name'                 => isset( $_POST['name'] ) ? wp_unslash( (string) $_POST['name'] ) : '',
			'description'          => isset( $_POST['description'] ) ? wp_unslash( (string) $_POST['description'] ) : '',
			'parcel_size_class'    => isset( $_POST['parcel_size_class'] ) ? wp_unslash( (string) $_POST['parcel_size_class'] ) : '',
			'handling_type'        => isset( $_POST['handling_type'] ) ? wp_unslash( (string) $_POST['handling_type'] ) : '',
			'route_eligibility'    => isset( $_POST['route_eligibility'] ) ? array_map( 'wp_unslash', (array) $_POST['route_eligibility'] ) : [],
			'consolidation_policy' => isset( $_POST['consolidation_policy'] ) ? wp_unslash( (string) $_POST['consolidation_policy'] ) : '',
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
			'name'                 => (string) ( $draft['name'] ?? '' ),
			'description'          => (string) ( $draft['description'] ?? '' ),
			'parcel_size_class'    => (string) ( $draft['parcel_size_class'] ?? '' ),
			'handling_type'        => (string) ( $draft['handling_type'] ?? '' ),
			'route_eligibility'    => isset( $draft['route_eligibility'] ) && is_array( $draft['route_eligibility'] )
				? array_map( 'strval', $draft['route_eligibility'] )
				: [],
			'consolidation_policy' => (string) ( $draft['consolidation_policy'] ?? '' ),
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
		$deactivate .= '<button type="submit" class="button-link delete" onclick="return confirm(\'' . esc_js( __( 'Deactivate this logistics profile?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
		$deactivate .= esc_html__( 'Deactivate', 'cetech-woocommerce-delivery-engine' );
		$deactivate .= '</button></form>';

		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE,
			'manage_logistics_profiles'
		);

		return $edit . $deactivate . $delete;
	}

	private function format_route_eligibility_friendly( string $stored ): string {
		$routes = $this->validator->decode_route_eligibility( $stored );

		if ( [] === $routes ) {
			return __( 'Any route', 'cetech-woocommerce-delivery-engine' );
		}

		$labels = array_map( [ $this, 'route_label' ], $routes );

		return implode( ', ', $labels );
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
			DeliveryRoute::Air->value => __( 'Air freight', 'cetech-woocommerce-delivery-engine' ),
			DeliveryRoute::Sea->value => __( 'Sea freight', 'cetech-woocommerce-delivery-engine' ),
			default => $route,
		};
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
}
