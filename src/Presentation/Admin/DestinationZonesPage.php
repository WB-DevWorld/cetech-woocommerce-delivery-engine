<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\StoreAwareExamples;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;

final class DestinationZonesPage {

	public const SLUG = 'cetech-delivery-engine-destination-zones';

	private const ACTION_SAVE = 'cetech_de_save_destination_zone';

	private const ACTION_DEACTIVATE = 'cetech_de_deactivate_destination_zone';

	private const ACTION_DELETE = 'cetech_de_delete_destination_zone';

	private const ACTION_TEST = 'cetech_de_test_destination_zone';

	private const RULE_FORM_ROWS = 1;

	public function __construct(
		private DestinationZoneRepositoryInterface $zone_repository,
		private DestinationRuleRepositoryInterface $rule_repository,
		private RateCardRepositoryInterface $rate_card_repository,
		private DestinationZoneValidator $zone_validator,
		private DestinationRuleValidator $rule_validator,
		private DestinationZoneTestMatcher $test_matcher,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_delivery_zones', self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE, self::ACTION_DEACTIVATE, 'manage_delivery_zones', self::SLUG ) ) {
			$this->handle_deactivate();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE, self::ACTION_DELETE, 'manage_delivery_zones', self::SLUG ) ) {
			$this->handle_delete();
		}

		if ( $this->action_handler->verify_post( self::ACTION_TEST, self::ACTION_TEST, 'manage_delivery_zones', self::SLUG ) ) {
			$this->handle_test_match();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_zones' );

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
			__( 'Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
			__( 'Manage the places you deliver to and the delivery options available there.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Add Delivery Area', 'cetech-woocommerce-delivery-engine' ),
				'url'   => add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
				'class' => 'primary',
			]
		);
		AdminPageLayout::render_example(
			StoreAwareExamples::area_list_example()
		);

		$zones              = $this->zone_repository->list( [ 'limit' => 500 ] );
		$rate_cards_by_zone = $this->active_rate_cards_by_zone();
		$zones_without_rates = 0;

		foreach ( $zones as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );

			if ( $zone_id > 0 && RecordStatus::Active->value === (string) ( $zone['status'] ?? '' ) && ! isset( $rate_cards_by_zone[ $zone_id ] ) ) {
				++$zones_without_rates;
			}
		}

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Total delivery areas', 'cetech-woocommerce-delivery-engine' ),
					'value' => count( $zones ),
					'empty' => [] === $zones,
				],
				[
					'label' => __( 'Areas without delivery charges', 'cetech-woocommerce-delivery-engine' ),
					'value' => $zones_without_rates,
					'empty' => 0 === $zones_without_rates,
				],
			]
		);

		if ( $zones_without_rates > 0 ) {
			AdminPageLayout::render_warning(
				__( 'Some delivery areas have no delivery charges', 'cetech-woocommerce-delivery-engine' ),
				__( 'Customers may not see delivery prices for these areas until you add delivery charges that match each area.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Manage delivery charges', 'cetech-woocommerce-delivery-engine' ),
				AdminPageRenderer::list_url( RateCardsPage::SLUG )
			);
		}

		if ( [] === $zones ) {
			AdminPageLayout::render_empty_state(
				__( 'No Delivery Areas have been created yet.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery areas control where a delivery option can be offered. Add a local or international area, then connect delivery options and charges.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add Delivery Area', 'cetech-woocommerce-delivery-engine' ),
				add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
			);
		} else {
			AdminPageLayout::open_section(
				__( 'All delivery areas', 'cetech-woocommerce-delivery-engine' ),
				__( 'Check that each active delivery area has pricing before going live.', 'cetech-woocommerce-delivery-engine' )
			);

			$rows = [];

			foreach ( $zones as $zone ) {
				$zone_id = (int) ( $zone['id'] ?? 0 );
				$rules   = $this->rule_repository->listByZoneId( $zone_id );
				$summary = $this->summarize_rules( $rules );
				$rate_count = $rate_cards_by_zone[ $zone_id ] ?? 0;

				$rows[] = [
					'<strong>' . esc_html( (string) ( $zone['public_label'] ?? $zone['internal_name'] ?? '' ) ) . '</strong>',
					esc_html( $this->location_label( $zone, $summary ) ),
					esc_html(
						sprintf(
							/* translators: %d number of delivery options */
							_n( '%d option', '%d options', $rate_count, 'cetech-woocommerce-delivery-engine' ),
							$rate_count
						)
					),
					AdminUiHelper::rate_card_coverage_badge( $rate_count ),
					AdminUiHelper::record_status_badge( (string) ( $zone['status'] ?? '' ) ),
					$this->render_actions( $zone_id ),
				];
			}

			AdminPageRenderer::render_table(
				[
					__( 'Area', 'cetech-woocommerce-delivery-engine' ),
					__( 'Location', 'cetech-woocommerce-delivery-engine' ),
					__( 'Available Options', 'cetech-woocommerce-delivery-engine' ),
					__( 'Charge Setup', 'cetech-woocommerce-delivery-engine' ),
					__( 'Status', 'cetech-woocommerce-delivery-engine' ),
					__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
				],
				$rows,
				true
			);

			AdminPageLayout::close_section();
		}

		AdminPageLayout::open_advanced( __( 'Test an address', 'cetech-woocommerce-delivery-engine' ) );
		$this->render_test_tool();
		AdminPageLayout::close_advanced();
		AdminPageLayout::close_page();
	}

	private function render_test_tool(): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG . '_test' );

		echo '<h3>' . esc_html__( 'Test an address', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Enter a sample address to see which delivery area would match. Read-only — does not change data or prices.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_TEST );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_TEST ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		AdminFormHelper::text_field(
			'test_country_code',
			__( 'Country code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['test_country_code'] ?? '' )
		);
		AdminFormHelper::text_field(
			'test_region',
			__( 'Region', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['test_region'] ?? '' )
		);
		AdminFormHelper::text_field(
			'test_city',
			__( 'City', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['test_city'] ?? '' )
		);
		AdminFormHelper::text_field(
			'test_postcode',
			__( 'Postcode', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['test_postcode'] ?? '' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Run test', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( is_array( $draft ) && isset( $draft['test_result'] ) ) {
			echo '<p><strong>' . esc_html__( 'Result:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $draft['test_result'] );
			echo '</p>';
		}
	}

	private function render_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG );

		if ( null !== $draft ) {
			$record = $this->form_record_from_draft( $draft );
			$rules  = isset( $draft['destination_rules'] ) && is_array( $draft['destination_rules'] )
				? $draft['destination_rules']
				: [];
		} else {
			$record = $this->load_record_for_form( $is_edit );
			$rules  = $is_edit && ! empty( $record['id'] )
				? $this->rule_repository->listByZoneId( (int) $record['id'] )
				: [];
		}

		$title = $is_edit
			? __( 'Edit Delivery Area', 'cetech-woocommerce-delivery-engine' )
			: __( 'Add Delivery Area', 'cetech-woocommerce-delivery-engine' );
		$submit = $is_edit
			? __( 'Save Delivery Area', 'cetech-woocommerce-delivery-engine' )
			: __( 'Create Delivery Area', 'cetech-woocommerce-delivery-engine' );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery coverage', 'cetech-woocommerce-delivery-engine' ),
			$title,
			__( 'Define an area where delivery is available. Add matching rules so customer addresses map to this delivery area.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => $submit,
				'type'  => 'submit',
				'class' => 'primary',
				'form'  => AdminPageLayout::ENTITY_FORM_ID,
			],
			[
				'label' => __( 'Back to Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
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
			__( 'Delivery area details', 'cetech-woocommerce-delivery-engine' ),
			__( 'Give the delivery area a clear name your team will recognize.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'name',
			__( 'Delivery area name', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['name'] ?? '' ),
			true,
			StoreAwareExamples::area_name_example()
		);
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			false,
			__( 'Generated from the area name if left blank.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'public_label',
			__( 'Customer-facing label', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['public_label'] ?? '' ),
			false,
			__( 'Optional label shown to customers when relevant.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			(string) ( $record['status'] ?? RecordStatus::Active->value ),
			__( 'Inactive delivery areas are kept for reference but are not used for new orders.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_section(
			__( 'Locations & delivery options', 'cetech-woocommerce-delivery-engine' ),
			__( 'Tell the system which addresses belong in this delivery area.', 'cetech-woocommerce-delivery-engine' )
		);
		$this->render_rules_section( $rules );
		AdminPageLayout::close_section();

		AdminPageLayout::open_advanced( __( 'Advanced details', 'cetech-woocommerce-delivery-engine' ) );
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::number_field(
			'priority',
			__( 'Priority', 'cetech-woocommerce-delivery-engine' ),
			isset( $record['priority'] ) ? (int) $record['priority'] : 100,
			0,
			__( 'Lower numbers are checked first when more than one delivery area could match.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::checkbox_field(
			'is_fallback',
			__( 'Fallback delivery area', 'cetech-woocommerce-delivery-engine' ),
			! empty( $record['is_fallback'] ),
			__( 'Use this Delivery Area when no country or location rule matches. This is not a country named Everywhere.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::checkbox_field(
			'is_remote_area',
			__( 'Remote area', 'cetech-woocommerce-delivery-engine' ),
			! empty( $record['is_remote_area'] ),
			__( 'Mark this delivery area as a remote or hard-to-reach area for your team.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</tbody></table>';
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		echo '<a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE,
				'manage_delivery_zones'
			);
		}

		AdminPageLayout::close_page();
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 */
	private function render_rules_section( array $rules ): void {
		echo '<p>' . esc_html__( 'Where should this delivery area apply?', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Choose a country by name. This is a Delivery Engine Delivery Area, not a WooCommerce shipping zone. Continents and “Everywhere” are not countries.', 'cetech-woocommerce-delivery-engine' ) . '</p>';

		$configured = [];
		foreach ( $rules as $rule ) {
			if ( '' !== trim( (string) ( $rule['rule_type'] ?? '' ) ) && '' !== trim( (string) ( $rule['rule_value'] ?? '' ) ) ) {
				$configured[] = $rule;
			}
		}
		if ( [] === $configured ) {
			$configured[] = [];
		}

		echo '<div class="cetech-de-condition-builder" data-cetech-de-condition-builder>';
		echo '<table class="widefat striped cetech-de-condition-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Location', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Value', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $configured as $index => $rule ) {
			$this->render_condition_row( $index, $rule, false );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" data-cetech-de-add-condition>' . esc_html__( '+ Add another location condition', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '<table class="hidden"><tbody><tr data-cetech-de-condition-template>';
		$this->render_condition_row( 99, [], true );
		echo '</tr></tbody></table>';
		echo '</div>';

		echo '<details class="cetech-de-advanced-matching"><summary>' . esc_html__( 'Advanced matching', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Exact or starts-with matching and numeric priority stay available here. Most stores can leave these unchanged.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Match mode and priority are saved with each location condition. Change them only when a more specific match is required.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</details>';
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private function render_condition_row( int $index, array $rule, bool $template ): void {
		$name_index = $template ? '{{index}}' : (string) $index;
		$rule_type  = (string) ( $rule['rule_type'] ?? '' );
		$rule_value = (string) ( $rule['rule_value'] ?? $rule['country_code'] ?? '' );
		if ( ! $template && [] === $rule ) {
			$rule_type = DestinationRuleType::Country->value;
		}
		$is_country    = DestinationRuleType::Country->value === $rule_type;
		$value_name    = 'destination_rules[' . $name_index . '][rule_value]';
		$country_name  = 'destination_rules[' . $name_index . '][country_code]';
		$countries     = WooCommerceCountryCatalog::options();
		$disabled_attr = $template ? ' disabled="disabled"' : '';

		echo $template ? '' : '<tr class="cetech-de-condition-row">';
		echo '<td><select name="destination_rules[' . esc_attr( $name_index ) . '][rule_type]" data-cetech-de-rule-type' . $disabled_attr . '>';
		echo '<option value="">' . esc_html__( '— Select —', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->rule_type_options() as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $rule_type, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td>';
		echo '<td class="cetech-de-condition-value" data-cetech-de-condition-value data-cetech-de-value-name="' . esc_attr( $value_name ) . '">';
		if ( [] !== $countries ) {
			$stored         = WooCommerceCountryCatalog::canonical_iso2( $rule_value );
			$country_hidden = $is_country ? '' : ' hidden';
			echo '<select class="cetech-de-country-select" data-cetech-de-country-select name="' . esc_attr( $country_name ) . '"' . $country_hidden . $disabled_attr . ' aria-label="' . esc_attr__( 'Country', 'cetech-woocommerce-delivery-engine' ) . '">';
			echo '<option value="">' . esc_html__( 'Select a country', 'cetech-woocommerce-delivery-engine' ) . '</option>';
			foreach ( $countries as $code => $label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $code ),
					selected( $stored, $code, false ),
					esc_html( $label )
				);
			}
			if ( $is_country && '' !== $stored && ! isset( $countries[ $stored ] ) ) {
				printf(
					'<option value="%1$s" selected="selected">%1$s</option>',
					esc_attr( $stored )
				);
			}
			echo '</select>';
		}
		$text_hidden = [] !== $countries && $is_country;
		printf(
			'<input type="text" class="regular-text cetech-de-rule-text" data-cetech-de-rule-text name="%4$s" value="%1$s"%2$s%3$s%5$s />',
			esc_attr( $is_country && [] !== $countries ? '' : $rule_value ),
			$text_hidden ? ' hidden' : '',
			$is_country && [] === $countries
				? ' placeholder="' . esc_attr__( '2-letter country code, for example GB', 'cetech-woocommerce-delivery-engine' ) . '"'
				: '',
			esc_attr( $value_name ),
			$disabled_attr
		);
		echo '</td>';
		echo '<input type="hidden" name="destination_rules[' . esc_attr( $name_index ) . '][match_mode]" value="' . esc_attr( (string) ( $rule['match_mode'] ?? DestinationRuleMatchMode::Exact->value ) ) . '" class="cetech-de-condition-match"' . $disabled_attr . ' />';
		echo '<input type="hidden" name="destination_rules[' . esc_attr( $name_index ) . '][priority]" value="' . esc_attr( (string) ( $rule['priority'] ?? 100 ) ) . '" class="cetech-de-condition-priority"' . $disabled_attr . ' />';
		if ( ! $template ) {
			echo '</tr>';
		}
	}

	private function handle_save(): void {
		$input = $this->read_form_input();
		$input = AdminFormHelper::prepare_reference_code(
			$input,
			(string) ( $input['name'] ?? '' ),
			function ( string $candidate ) use ( $input ): bool {
				$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
				$existing = $this->zone_repository->findByCode( $candidate );

				return null !== $existing && (int) ( $existing['id'] ?? 0 ) !== $id;
			},
			function ( int $id ): string {
				$row = $this->zone_repository->findById( $id );

				return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
			},
			'delivery-area'
		);
		$input['destination_rules'] = $this->normalize_posted_destination_rules(
			isset( $input['destination_rules'] ) && is_array( $input['destination_rules'] )
				? $input['destination_rules']
				: []
		);
		$zone_errors = $this->zone_validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );
		$rule_result = $this->rule_validator->validate_and_normalize(
			array_values( $input['destination_rules'] )
		);

		$errors = array_merge( $zone_errors, $rule_result['errors'] );

		if ( [] !== $errors ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( implode( ' ', $errors ) );
			$this->redirect_to_form( $input );
		}

		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$code = AdminFormHelper::sanitize_code( (string) $input['code'] );
		$existing_by_code = $this->zone_repository->findByCode( $code );

		if ( null !== $existing_by_code && (int) ( $existing_by_code['id'] ?? 0 ) !== $id ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( __( 'A destination zone with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->redirect_to_form( $input );
		}

		$previous = $id > 0 ? $this->zone_repository->findById( $id ) : null;
		$previous_rules = $id > 0 ? $this->rule_repository->listByZoneId( $id ) : [];

		$payload = [
			'id'               => $id,
			'internal_code'    => $code,
			'internal_name'    => trim( (string) $input['name'] ),
			'public_label'     => trim( (string) ( $input['public_label'] ?? '' ) ),
			'is_fallback'      => ! empty( $input['is_fallback'] ),
			'remote_area_flag' => ! empty( $input['is_remote_area'] ),
			'priority'         => (int) ( $input['priority'] ?? 100 ),
			'status'           => (string) $input['status'],
		];

		$saved_id = $this->zone_repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save destination zone.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->rule_repository->replaceForZone( $saved_id, $rule_result['rules'] ) ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, array_merge( $input, [ 'id' => $saved_id ] ) );
			$this->action_handler->notices()->flash_error( __( 'Destination zone saved, but destination rules could not be updated.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'action' => 'edit', 'id' => $saved_id ] );
		}

		$zone_audit = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'destination_zone',
			$saved_id,
			$previous,
			$this->zone_repository->findById( $saved_id )
		);

		$rules_audit = $this->audit_logger->log(
			'replaced',
			'destination_rules',
			$saved_id,
			[ 'rules' => $previous_rules ],
			[ 'rules' => $this->rule_repository->listByZoneId( $saved_id ) ]
		);

		if ( $zone_audit && $rules_audit ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Destination zone updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Destination zone created.', 'cetech-woocommerce-delivery-engine' )
			);
		} elseif ( $zone_audit ) {
			$this->action_handler->notices()->flash_warning( __( 'Destination zone saved, but audit logging failed for rules.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Destination zone saved, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid destination zone.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->zone_repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Destination zone not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->zone_repository->softDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate destination zone.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Destination zone is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'destination_zone',
			$id,
			$previous,
			$this->zone_repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Destination zone deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Destination zone deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid destination zone.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->zone_repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Destination zone not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_destination_zone( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$this->rule_repository->deleteByZoneId( $id );

		if ( ! $this->zone_repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete destination zone.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'destination_zone', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Destination zone permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Destination zone deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_delivery_zones' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->zone_repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Destination zone not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE,
			self::ACTION_DEACTIVATE,
			'manage_delivery_zones',
			__( 'Delivery Area', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['internal_name'] ?? $record['public_label'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_destination_zone( $id )
		);
	}

	private function handle_test_match(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = [
			'test_country_code' => isset( $_POST['test_country_code'] ) ? wp_unslash( (string) $_POST['test_country_code'] ) : '',
			'test_region'       => isset( $_POST['test_region'] ) ? wp_unslash( (string) $_POST['test_region'] ) : '',
			'test_city'         => isset( $_POST['test_city'] ) ? wp_unslash( (string) $_POST['test_city'] ) : '',
			'test_postcode'     => isset( $_POST['test_postcode'] ) ? wp_unslash( (string) $_POST['test_postcode'] ) : '',
		];

		$matched = $this->test_matcher->match(
			$input['test_country_code'],
			$input['test_region'],
			$input['test_city'],
			$input['test_postcode']
		);

		if ( null === $matched ) {
			$input['test_result'] = __( 'No matching delivery area.', 'cetech-woocommerce-delivery-engine' );
		} else {
			$input['test_result'] = sprintf(
				/* translators: 1: area name */
				__( 'Matched delivery area: %s', 'cetech-woocommerce-delivery-engine' ),
				(string) ( $matched['public_label'] ?? $matched['internal_name'] ?? '' )
			);
		}

		$this->action_handler->notices()->stash_form_draft( self::SLUG . '_test', $input );
		$this->action_handler->redirect( self::SLUG );
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

		$row = $this->zone_repository->findById( $id );

		if ( null === $row ) {
			return [];
		}

		return [
			'id'             => (int) ( $row['id'] ?? 0 ),
			'code'           => (string) ( $row['internal_code'] ?? '' ),
			'name'           => (string) ( $row['internal_name'] ?? '' ),
			'public_label'   => (string) ( $row['public_label'] ?? '' ),
			'is_fallback'    => ! empty( $row['is_fallback'] ),
			'is_remote_area' => ! empty( $row['remote_area_flag'] ),
			'priority'       => (int) ( $row['priority'] ?? 100 ),
			'status'         => (string) ( $row['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function form_record_from_draft( array $draft ): array {
		return [
			'id'             => isset( $draft['id'] ) ? (int) $draft['id'] : 0,
			'code'           => (string) ( $draft['code'] ?? '' ),
			'name'           => (string) ( $draft['name'] ?? '' ),
			'public_label'   => (string) ( $draft['public_label'] ?? '' ),
			'is_fallback'    => ! empty( $draft['is_fallback'] ),
			'is_remote_area' => ! empty( $draft['is_remote_area'] ),
			'priority'       => isset( $draft['priority'] ) ? (int) $draft['priority'] : 100,
			'status'         => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_form_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return [
			'id'                => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'code'              => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '',
			'name'              => isset( $_POST['name'] ) ? wp_unslash( (string) $_POST['name'] ) : '',
			'public_label'      => isset( $_POST['public_label'] ) ? wp_unslash( (string) $_POST['public_label'] ) : '',
			'is_fallback'       => isset( $_POST['is_fallback'] ) ? 1 : 0,
			'is_remote_area'    => isset( $_POST['is_remote_area'] ) ? 1 : 0,
			'priority'          => $_POST['priority'] ?? 100,
			'status'            => isset( $_POST['status'] ) ? wp_unslash( (string) $_POST['status'] ) : '',
			'destination_rules' => isset( $_POST['destination_rules'] ) && is_array( $_POST['destination_rules'] )
				? wp_unslash( $_POST['destination_rules'] )
				: [],
		];
	}

	/**
	 * @param array<mixed> $rows
	 *
	 * @return list<array<string, mixed>>
	 */
	private function normalize_posted_destination_rules( array $rows ): array {
		$normalized = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rule_type    = sanitize_key( (string) ( $row['rule_type'] ?? '' ) );
			$text_value   = trim( (string) ( $row['rule_value'] ?? '' ) );
			$country_code = trim( (string) ( $row['country_code'] ?? '' ) );

			if ( DestinationRuleType::Country->value === $rule_type ) {
				$raw                 = '' !== $country_code ? $country_code : $text_value;
				$row['rule_value']   = WooCommerceCountryCatalog::canonical_iso2( $raw );
			} else {
				$row['rule_value'] = $text_value;
			}

			unset( $row['country_code'] );
			$normalized[] = $row;
		}

		return $normalized;
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 *
	 * @return array{country: string, region: string, city: string}
	 */
	private function summarize_rules( array $rules ): array {
		$summary = [
			'country'  => '',
			'region'   => '',
			'city'     => '',
			'postcode' => '',
		];

		foreach ( $rules as $rule ) {
			$type  = (string) ( $rule['rule_type'] ?? '' );
			$value = trim( (string) ( $rule['rule_value'] ?? '' ) );

			if ( '' === $value || '—' === $value ) {
				continue;
			}

			if ( DestinationRuleType::Country->value === $type ) {
				$summary['country'] = $value;
			} elseif ( DestinationRuleType::Region->value === $type ) {
				$summary['region'] = $value;
			} elseif ( DestinationRuleType::City->value === $type ) {
				$summary['city'] = $value;
			} elseif ( DestinationRuleType::Postcode->value === $type ) {
				$summary['postcode'] = $value;
			}
		}

		return $summary;
	}

	/**
	 * @param array<string, mixed> $zone
	 * @param array<string, string> $summary
	 */
	private function location_label( array $zone, array $summary ): string {
		$label = trim( (string) ( $zone['public_label'] ?? $zone['internal_name'] ?? '' ) );
		$parts = [];
		if ( '' !== $summary['country'] ) {
			$parts[] = $this->country_label( $summary['country'] );
		}
		if ( '' !== $summary['region'] ) {
			$parts[] = $summary['region'];
		}
		if ( '' !== $summary['city'] ) {
			$parts[] = $summary['city'];
		}
		if ( [] === $parts ) {
			if ( str_contains( strtolower( $label ), 'international' ) || ! empty( $zone['is_fallback'] ) ) {
				return __( 'International', 'cetech-woocommerce-delivery-engine' );
			}

			return '' !== $label ? $label : '—';
		}
		if ( 1 === count( $parts ) && str_contains( strtolower( $label ), 'international' ) ) {
			return __( 'International', 'cetech-woocommerce-delivery-engine' );
		}

		return implode( ', ', $parts );
	}

	private function country_label( string $code ): string {
		return WooCommerceCountryCatalog::label( $code );
	}

	private function render_actions( int $id ): string {
		$edit_url = esc_url( AdminPageRenderer::edit_url( self::SLUG, $id ) );
		$edit     = '<a href="' . $edit_url . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>';

		$deactivate = '<form method="post" style="display:inline;margin-left:8px;">';
		$deactivate .= wp_nonce_field( self::ACTION_DEACTIVATE, 'cetech_de_nonce', true, false );
		$deactivate .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_DEACTIVATE ) . '" />';
		$deactivate .= '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$deactivate .= '<button type="submit" class="button-link" onclick="return confirm(\'' . esc_js( __( 'Deactivate this delivery area?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
		$deactivate .= esc_html__( 'Deactivate', 'cetech-woocommerce-delivery-engine' );
		$deactivate .= '</button></form>';

		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE,
			'manage_delivery_zones'
		);

		return $edit . $deactivate . $delete;
	}

	/**
	 * @return array<int, int>
	 */
	private function active_rate_cards_by_zone(): array {
		$counts = [];

		foreach ( $this->rate_card_repository->list( [ 'limit' => 500 ] ) as $rate_card ) {
			if ( RecordStatus::Active->value !== (string) ( $rate_card['status'] ?? '' ) ) {
				continue;
			}

			$zone_id = (int) ( $rate_card['destination_zone_id'] ?? 0 );

			if ( $zone_id > 0 ) {
				$counts[ $zone_id ] = ( $counts[ $zone_id ] ?? 0 ) + 1;
			}
		}

		return $counts;
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

	/**
	 * @return array<string, string>
	 */
	private function rule_type_options(): array {
		return [
			DestinationRuleType::Country->value => __( 'Country', 'cetech-woocommerce-delivery-engine' ),
			DestinationRuleType::Region->value => __( 'State / Region', 'cetech-woocommerce-delivery-engine' ),
			DestinationRuleType::City->value => __( 'City', 'cetech-woocommerce-delivery-engine' ),
			DestinationRuleType::Postcode->value => __( 'Postcode', 'cetech-woocommerce-delivery-engine' ),
		];
	}

	private function rule_type_label( string $type ): string {
		return $this->rule_type_options()[ $type ] ?? $type;
	}

	/**
	 * @return array<string, string>
	 */
	private function match_mode_options(): array {
		return [
			DestinationRuleMatchMode::Exact->value => __( 'Exact', 'cetech-woocommerce-delivery-engine' ),
			DestinationRuleMatchMode::Prefix->value => __( 'Starts with', 'cetech-woocommerce-delivery-engine' ),
		];
	}
}
