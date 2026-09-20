<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\StoreAwareExamples;
use CetechDeliveryEngine\Application\Coverage\CoverageConfigurationValidator;
use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\OverlappingDeliveryAreaCoverage;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeographyAdminLabels;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
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
		private AdminRecordDependencyChecker $dependency_checker,
		private ?CoverageGroupRepositoryInterface $coverage_groups = null,
		private ?CanonicalLocationRepositoryInterface $locations = null,
		private ?CanonicalLocationResolver $resolver = null,
		private ?CoverageConfigurationValidator $coverage_validator = null
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

		$per_page           = 50;
		$page               = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( (string) $_GET['paged'] ) ) ) : 1;
		$total_zones        = $this->zone_repository->count_all();
		$after              = 0;
		if ( $page > 1 ) {
			$skip = ( $page - 1 ) * $per_page;
			$seen = 0;
			do {
				$walk = $this->zone_repository->page_after( $after, min( 100, $skip - $seen ) );
				if ( [] === $walk ) {
					break;
				}
				foreach ( $walk as $row ) {
					$after = max( $after, (int) ( $row['id'] ?? 0 ) );
					++$seen;
					if ( $seen >= $skip ) {
						break 2;
					}
				}
			} while ( [] !== $walk );
		}
		$zones              = $this->zone_repository->page_after( $after, $per_page );
		$rate_cards_by_zone = [];
		foreach ( $zones as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );
			if ( $zone_id <= 0 ) {
				continue;
			}
			$count = $this->rate_card_repository->countActiveByDestinationZoneId( $zone_id );
			if ( $count > 0 ) {
				$rate_cards_by_zone[ $zone_id ] = $count;
			}
		}
		$zones_without_rates = 0;
		$scan_after          = 0;
		do {
			$scan = $this->zone_repository->page_after( $scan_after, 100, [ 'status' => RecordStatus::Active->value ] );
			foreach ( $scan as $zone ) {
				$scan_id    = (int) ( $zone['id'] ?? 0 );
				$scan_after = max( $scan_after, $scan_id );
				if ( $scan_id > 0 && $this->rate_card_repository->countActiveByDestinationZoneId( $scan_id ) <= 0 ) {
					++$zones_without_rates;
				}
			}
		} while ( [] !== $scan );

		$coverage   = new OverlappingDeliveryAreaCoverage(
			$this->zone_repository,
			$this->rule_repository,
			$this->rate_card_repository,
			null,
			$this->coverage_groups
		);
		$uncovered  = $coverage->uncovered_zone_ids();
		$unproven   = $coverage->unproven_zone_ids();
		$overlap_warnings = $coverage->warnings();

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Total delivery areas', 'cetech-woocommerce-delivery-engine' ),
					'value' => $total_zones,
					'empty' => 0 === $total_zones,
				],
				[
					'label' => __( 'Areas without delivery charges', 'cetech-woocommerce-delivery-engine' ),
					'value' => $zones_without_rates,
					'empty' => 0 === $zones_without_rates,
				],
			]
		);

		if ( $coverage->has_nested_overlaps() ) {
			AdminPageLayout::render_info_notice(
				__( 'Some delivery areas overlap. A more-specific area such as a city is used first. If that area has no charge for the selected Delivery Option, pricing can use the broader matching area. A different Delivery Option is never substituted.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		foreach ( $overlap_warnings as $warning ) {
			AdminPageLayout::render_warning(
				(string) $warning['title'],
				(string) $warning['message'],
				__( 'Manage delivery charges', 'cetech-woocommerce-delivery-engine' ),
				AdminPageRenderer::list_url( RateCardsPage::SLUG )
			);
		}

		if ( [] !== $unproven || $coverage->analysis_incomplete() ) {
			AdminPageLayout::render_warning(
				__( 'Some delivery-area overlap could not be fully proven', 'cetech-woocommerce-delivery-engine' ),
				__( 'Canonical coverage fallback was not exhaustively proven for every area. Test specific addresses instead of treating those areas as uncovered.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Manage delivery charges', 'cetech-woocommerce-delivery-engine' ),
				AdminPageRenderer::list_url( RateCardsPage::SLUG )
			);
		}

		if ( [] !== $uncovered ) {
			AdminPageLayout::render_warning(
				__( 'Some delivery areas have no delivery charges', 'cetech-woocommerce-delivery-engine' ),
				__( 'Customers may not see delivery prices for these areas until you add delivery charges that match each area. Broader overlapping areas are already considered before this warning is shown.', 'cetech-woocommerce-delivery-engine' ),
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
				$canonical = '';
				if ( $this->coverage_groups instanceof CoverageGroupRepositoryInterface && $this->locations instanceof CanonicalLocationRepositoryInterface ) {
					$canonical = ( new \CetechDeliveryEngine\Application\Coverage\CoverageGroupSummarizer( $this->locations ) )->summarize(
						$this->coverage_groups->list_by_zone( $zone_id )
					);
				}
				$rate_count = $rate_cards_by_zone[ $zone_id ] ?? 0;

				$rows[] = [
					'<strong>' . esc_html( (string) ( $zone['public_label'] ?? $zone['internal_name'] ?? '' ) ) . '</strong>',
					esc_html( '' !== $canonical ? $canonical : $this->location_label( $zone, $summary ) ),
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

			$total_pages = (int) max( 1, (int) ceil( $total_zones / $per_page ) );
			if ( $total_pages > 1 && function_exists( 'paginate_links' ) ) {
				echo '<nav class="tablenav bottom" aria-label="' . esc_attr__( 'Delivery Area list pagination', 'cetech-woocommerce-delivery-engine' ) . '">';
				echo wp_kses_post(
					(string) paginate_links(
						[
							'base'      => esc_url( add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=' . self::SLUG ) ) ),
							'format'    => '',
							'current'   => $page,
							'total'     => $total_pages,
							'type'      => 'plain',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						]
					)
				);
				echo '</nav>';
			}

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
			'Enter a sample address to see which delivery areas would match. Read-only — does not change data or prices.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_TEST );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_TEST ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		AdminFormHelper::country_select_field(
			'test_country_code',
			__( 'Country', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['test_country_code'] ?? '' ),
			__( 'Choose the country the customer would select at checkout. The plugin uses the standard country code internally.', 'cetech-woocommerce-delivery-engine' )
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
			echo '<p><strong>' . esc_html__( 'Primary match:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $draft['test_result'] );
			echo '</p>';

			if ( ! empty( $draft['test_also'] ) ) {
				echo '<p><strong>' . esc_html__( 'Also matches:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
				echo esc_html( (string) $draft['test_also'] );
				echo '</p>';
				echo '<p class="description">' . esc_html__(
					'Pricing can use a charge from a broader matching Delivery Area when the selected Delivery Option has no charge in the more-specific area. A different Delivery Option is never substituted.',
					'cetech-woocommerce-delivery-engine'
				) . '</p>';
			}

			if ( ! empty( $draft['test_global_fallback'] ) ) {
				echo '<p class="description">' . esc_html__(
					'This is the global fallback (Everywhere else). It was used because no other Delivery Area matched this address.',
					'cetech-woocommerce-delivery-engine'
				) . '</p>';
			} elseif ( ! empty( $draft['test_constrained_fallback'] ) ) {
				echo '<p class="description">' . esc_html__(
					'This match is a constrained fallback. Location rules still apply, so this area cannot be used for a country or region it does not cover.',
					'cetech-woocommerce-delivery-engine'
				) . '</p>';
			}
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
		$this->render_coverage_builder( $is_edit && ! empty( $record['id'] ) ? (int) $record['id'] : 0 );
		$this->render_rules_section( $rules, $is_edit && ! empty( $record['id'] ) ? (int) $record['id'] : 0 );
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
			__( 'Use as fallback for unmatched addresses', 'cetech-woocommerce-delivery-engine' ),
			! empty( $record['is_fallback'] ),
			__( 'If this area has no location rules, it is a global fallback: a true Everywhere else area used only when no other Delivery Area matches. If this area also has location rules, those rules still apply — for example a Greater Accra fallback with Ghana + Greater Accra never matches the United States. Native WooCommerce shipping is never used as a fallback.', 'cetech-woocommerce-delivery-engine' )
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

	private function render_coverage_builder( int $zone_id ): void {
		$groups = [];
		if ( $zone_id > 0 && $this->coverage_groups instanceof CoverageGroupRepositoryInterface ) {
			$groups = $this->coverage_groups->list_by_zone( $zone_id );
		}

		$countries = WooCommerceCountryCatalog::options();
		echo '<div class="cetech-de-coverage-builder" data-cetech-de-coverage-builder data-countries="' . esc_attr( (string) wp_json_encode( $countries ) ) . '">';
		echo '<p>' . esc_html__( 'Coverage groups describe the destinations that share this Delivery Area. Different levels inside a group combine with AND. Multiple places at the same level combine with OR. Additional groups combine with OR.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( [] === $groups ) {
			echo '<p class="description">' . esc_html__( 'Add a coverage group to use canonical geography. Legacy location conditions stay available only until coverage is active.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}

		$index = 0;
		foreach ( $groups as $group ) {
			$this->render_coverage_group_editor( $index, $group );
			++$index;
		}
		echo '<div data-cetech-de-coverage-groups></div>';
		echo '<p><button type="button" class="button" data-cetech-de-add-coverage-group>' . esc_html__( '+ Add another coverage group', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '<p><label><input type="checkbox" name="confirm_drop_canonical" value="1" /> ' . esc_html__( 'If I remove every coverage group, stop using canonical coverage for this Delivery Area. Do not silently fall back to hidden legacy conditions.', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
		echo '</div>';
	}

	/**
	 * @param \CetechDeliveryEngine\Domain\Coverage\CoverageGroup $group
	 */
	private function render_coverage_group_editor( int $index, $group ): void {
		$root = ( $this->locations && $group->root_location_id > 0 )
			? $this->locations->find_by_id( $group->root_location_id )
			: null;
		$includes = [];
		$excludes = [];
		if ( $this->locations ) {
			foreach ( $group->members as $member ) {
				$loc = $this->locations->find_by_id( $member->location_id );
				if ( ! $loc ) {
					continue;
				}
				$chip = [ 'id' => $loc->id, 'key' => $loc->location_key, 'name' => $loc->canonical_name ];
				if ( 'include' === $member->membership->value ) {
					$includes[] = $chip;
				} else {
					$excludes[] = $chip;
				}
			}
		}

		$country_code = $root?->country_code ?? '';
		$admin_label  = GeographyAdminLabels::administrative_area_label( $country_code );
		$prefix       = 'coverage_groups[' . $index . ']';
		$mode         = $group->mode->value;

		echo '<fieldset class="cetech-de-coverage-group" data-cetech-de-coverage-group>';
		echo '<legend>' . esc_html( sprintf( __( 'Coverage group %d', 'cetech-woocommerce-delivery-engine' ), $index + 1 ) ) . '</legend>';
		if ( $group->id > 0 ) {
			echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[id]" value="' . esc_attr( (string) $group->id ) . '" />';
		}
		if ( $group->review_required ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Review required: this group was converted from legacy rules or could not be mapped with full confidence. Saving other fields will not clear this warning.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			$unmapped_city = 'unmapped_city' === (string) ( $group->legacy_migration['reason'] ?? '' )
				|| ( isset( $group->legacy_migration['unmapped_cities'] ) && is_array( $group->legacy_migration['unmapped_cities'] ) && [] !== $group->legacy_migration['unmapped_cities'] );
			if ( $unmapped_city ) {
				$legacy_city = (string) ( $group->legacy_migration['unmapped_cities'][0] ?? ( $group->legacy_migration['cities'][0] ?? 'unresolved city' ) );
				$region      = $root?->canonical_name ?? (string) ( $group->legacy_migration['regions'][0] ?? 'selected area' );
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: country, 2: region, 3: city */
						__( 'Previous legacy scope: %1$s > %2$s > %3$s. Mapping this to the entire selected area is broader coverage.', 'cetech-woocommerce-delivery-engine' ),
						(string) ( $group->legacy_migration['countries'][0] ?? ( $root?->country_code ?: __( 'unknown country', 'cetech-woocommerce-delivery-engine' ) ) ),
						$region,
						$legacy_city
					)
				) . '</p>';
				echo '<p><label><input type="checkbox" name="' . esc_attr( $prefix ) . '[confirm_scope_replacement]" value="1" /> ' . esc_html__( 'Confirm replacement with entire selected area', 'cetech-woocommerce-delivery-engine' ) . '</label></p>';
			}
			echo '<p><label><input type="checkbox" name="' . esc_attr( $prefix ) . '[resolve_review]" value="1" /> ' . esc_html__( 'I reviewed this migrated coverage', 'cetech-woocommerce-delivery-engine' ) . '</label></p></div>';
			echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[review_required]" value="1" />';
		}

		echo '<p><label for="cetech-de-coverage-country-' . esc_attr( (string) $index ) . '">' . esc_html__( 'Country', 'cetech-woocommerce-delivery-engine' ) . '</label><br />';
		echo '<select id="cetech-de-coverage-country-' . esc_attr( (string) $index ) . '" name="' . esc_attr( $prefix ) . '[country]" data-cetech-de-coverage-country>';
		echo '<option value="">' . esc_html__( 'Select…', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( WooCommerceCountryCatalog::options() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $country_code, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<div data-cetech-de-admin-browser>';
		echo '<p><label for="cetech-de-coverage-root-' . esc_attr( (string) $index ) . '">' . esc_html( $admin_label ) . '</label><br />';
		echo '<select id="cetech-de-coverage-root-' . esc_attr( (string) $index ) . '" data-cetech-de-coverage-root>';
		if ( $root ) {
			$root_label = $root->isCountry()
				? sprintf( __( 'Entire %s', 'cetech-woocommerce-delivery-engine' ), $root->canonical_name )
				: $root->canonical_name;
			echo '<option value="' . esc_attr( $root->location_key ) . '" selected>' . esc_html( $root_label ) . '</option>';
		} else {
			echo '<option value="">' . esc_html__( 'Select…', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		}
		echo '</select></p>';
		echo '</div>';

		echo '<p><label>' . esc_html__( 'Coverage', 'cetech-woocommerce-delivery-engine' ) . ' ';
		echo '<select name="' . esc_attr( $prefix ) . '[mode]" data-cetech-de-coverage-mode>';
		foreach ( [
			CoverageMode::EntireArea->value => __( 'Entire selected area', 'cetech-woocommerce-delivery-engine' ),
			CoverageMode::SelectedDescendants->value => __( 'Selected locations', 'cetech-woocommerce-delivery-engine' ),
			CoverageMode::EntireExcept->value => __( 'Entire selected area except…', 'cetech-woocommerce-delivery-engine' ),
		] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[root_location_id]" value="' . esc_attr( (string) $group->root_location_id ) . '" data-cetech-de-root-id />';
		echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[root_key]" value="' . esc_attr( $root?->location_key ?? '' ) . '" data-cetech-de-root-key />';

		$include_hidden = CoverageMode::SelectedDescendants === $group->mode ? '' : ' hidden';
		echo '<div data-cetech-de-include-panel' . $include_hidden . '>';
		echo '<p><label>' . esc_html__( 'Include locations', 'cetech-woocommerce-delivery-engine' ) . '<br />';
		echo '<input type="search" class="regular-text" data-cetech-de-locality-search data-cetech-de-search-target="include" placeholder="' . esc_attr__( 'Search…', 'cetech-woocommerce-delivery-engine' ) . '" aria-label="' . esc_attr__( 'Search localities to include', 'cetech-woocommerce-delivery-engine' ) . '" autocomplete="off" /></label></p>';
		echo '<ul class="cetech-de-coverage-results" data-cetech-de-search-results="include" role="listbox" hidden></ul>';
		echo '<ul class="cetech-de-coverage-chips" data-cetech-de-chips="include">';
		foreach ( $includes as $chip ) {
			echo '<li>' . esc_html( $chip['name'] ) . ' <button type="button" class="button-link" data-remove-member="' . esc_attr( (string) $chip['id'] ) . '">×</button>';
			echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[members][]" value="' . esc_attr( (string) $chip['id'] ) . '" /></li>';
		}
		echo '</ul>';
		echo '<p class="description" data-cetech-de-include-count>' . esc_html( sprintf( _n( '%d location included', '%d locations included', count( $includes ), 'cetech-woocommerce-delivery-engine' ), count( $includes ) ) ) . '</p>';
		echo '<p><button type="button" class="button" data-cetech-de-select-all>' . esc_html__( 'Select all', 'cetech-woocommerce-delivery-engine' ) . '</button> ';
		echo '<button type="button" class="button" data-cetech-de-clear-members>' . esc_html__( 'Clear', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</div>';

		$exclude_hidden = CoverageMode::EntireExcept === $group->mode ? '' : ' hidden';
		echo '<div data-cetech-de-exclude-panel' . $exclude_hidden . '>';
		echo '<p><label>' . esc_html__( 'Excluded locations', 'cetech-woocommerce-delivery-engine' ) . '<br />';
		echo '<input type="search" class="regular-text" data-cetech-de-locality-search data-cetech-de-search-target="exclude" placeholder="' . esc_attr__( 'Search locations to exclude…', 'cetech-woocommerce-delivery-engine' ) . '" aria-label="' . esc_attr__( 'Search localities to exclude', 'cetech-woocommerce-delivery-engine' ) . '" autocomplete="off" /></label></p>';
		echo '<ul class="cetech-de-coverage-results" data-cetech-de-search-results="exclude" role="listbox" hidden></ul>';
		echo '<ul class="cetech-de-coverage-chips" data-cetech-de-chips="exclude">';
		foreach ( $excludes as $chip ) {
			echo '<li>' . esc_html( $chip['name'] ) . ' <button type="button" class="button-link" data-remove-member="' . esc_attr( (string) $chip['id'] ) . '">×</button>';
			echo '<input type="hidden" name="' . esc_attr( $prefix ) . '[exclusions][]" value="' . esc_attr( (string) $chip['id'] ) . '" /></li>';
		}
		echo '</ul>';
		echo '<p class="description" data-cetech-de-exclude-count>' . esc_html( sprintf( _n( '%d location excluded', '%d locations excluded', count( $excludes ), 'cetech-woocommerce-delivery-engine' ), count( $excludes ) ) ) . '</p>';
		echo '</div>';

		echo '<div data-cetech-de-postcode-panel>';
		echo '<p><strong>' . esc_html__( 'Postcode constraints', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__( 'Optional. Multiple values are OR. Leave empty for no postcode restriction.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<table class="widefat striped" data-cetech-de-postcode-table><tbody>';
		if ( [] === $group->postcodes ) {
			echo $this->postcode_row_html( $index, 0, '', DestinationRuleMatchMode::Exact->value );
		} else {
			foreach ( $group->postcodes as $p_index => $postcode ) {
				echo $this->postcode_row_html( $index, $p_index, $postcode->postcode_value, $postcode->match_mode->value );
			}
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" data-cetech-de-add-postcode>' . esc_html__( 'Add postcode', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</div>';
		echo '<p><button type="button" class="button-link-delete" data-cetech-de-remove-coverage-group>' . esc_html__( 'Remove coverage group', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</fieldset>';
	}

	private function postcode_row_html( int $group_index, int $row_index, string $value, string $mode ): string {
		$html  = '<tr data-cetech-de-postcode-row>';
		$html .= '<td><input type="text" class="regular-text" name="coverage_groups[' . esc_attr( (string) $group_index ) . '][postcodes][' . esc_attr( (string) $row_index ) . '][postcode_value]" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Postcode', 'cetech-woocommerce-delivery-engine' ) . '" /></td>';
		$html .= '<td><select name="coverage_groups[' . esc_attr( (string) $group_index ) . '][postcodes][' . esc_attr( (string) $row_index ) . '][match_mode]">';
		$html .= '<option value="' . esc_attr( DestinationRuleMatchMode::Exact->value ) . '"' . selected( $mode, DestinationRuleMatchMode::Exact->value, false ) . '>' . esc_html__( 'Exact', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		$html .= '<option value="' . esc_attr( DestinationRuleMatchMode::Prefix->value ) . '"' . selected( $mode, DestinationRuleMatchMode::Prefix->value, false ) . '>' . esc_html__( 'Prefix', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		$html .= '</select></td>';
		$html .= '<td><button type="button" class="button-link" data-cetech-de-remove-postcode>×</button></td>';
		$html .= '</tr>';

		return $html;
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 */
	private function render_rules_section( array $rules, int $zone_id = 0 ): void {
		$coverage_active = $this->zone_has_active_coverage( $zone_id );
		if ( $coverage_active ) {
			echo '<details class="cetech-de-legacy-coverage-evidence"><summary>' . esc_html__( 'Legacy location conditions (compatibility evidence only)', 'cetech-woocommerce-delivery-engine' ) . '</summary>';
			echo '<p class="description">' . esc_html__( 'Canonical coverage groups above are the live Delivery Area configuration. These legacy conditions are kept as a migration record and are not edited independently while coverage is active.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '<ul>';
			foreach ( $rules as $rule ) {
				$type  = (string) ( $rule['rule_type'] ?? '' );
				$value = (string) ( $rule['rule_value'] ?? '' );
				if ( '' === $type || '' === $value ) {
					continue;
				}
				echo '<li>' . esc_html( $type . ': ' . $value ) . '</li>';
			}
			echo '</ul></details>';

			return;
		}

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

		$coverage_posted = $this->posted_coverage_groups();
		$existing_id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$coverage_valid  = [ 'ok' => true, 'errors' => [], 'groups' => [] ];
		if ( is_array( $coverage_posted ) && $this->coverage_validator instanceof CoverageConfigurationValidator ) {
			$existing_groups = [];
			if ( $existing_id > 0 && $this->coverage_groups instanceof CoverageGroupRepositoryInterface ) {
				$existing_groups = $this->coverage_groups->list_by_zone( $existing_id );
			}
			$coverage_valid = $this->coverage_validator->validate( $coverage_posted, '', $existing_id, $existing_groups );
			if ( ! $coverage_valid['ok'] ) {
				$errors = array_merge( $errors, $coverage_valid['errors'] );
			}
		}

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

		$canonical_submitted = is_array( $coverage_posted ) && [] !== ( $coverage_valid['groups'] ?? [] );
		if ( $canonical_submitted ) {
			// Schema-6 coverage is exclusive authority. Keep any prior destination_rules as evidence.
		} elseif ( ! $this->rule_repository->replaceForZone( $saved_id, $rule_result['rules'] ) ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, array_merge( $input, [ 'id' => $saved_id ] ) );
			$this->action_handler->notices()->flash_error( __( 'Destination zone saved, but destination rules could not be updated.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG, [ 'action' => 'edit', 'id' => $saved_id ] );
		}

		$coverage_persisted = true;
		if ( is_array( $coverage_posted ) || $this->posted_confirm_drop_canonical() ) {
			$validated_groups = is_array( $coverage_valid['groups'] ?? null ) ? $coverage_valid['groups'] : [];
			if ( [] === $validated_groups && $this->zone_has_active_coverage( $saved_id ) && ! $this->posted_confirm_drop_canonical() ) {
				$this->action_handler->notices()->flash_error( __( 'Removing the last canonical coverage group requires an explicit confirmation. Hidden legacy conditions will not be used automatically.', 'cetech-woocommerce-delivery-engine' ) );
				$coverage_persisted = false;
			} else {
				$coverage_persisted = $this->persist_posted_coverage( $saved_id, $validated_groups );
			}
		}

		if ( ! $coverage_persisted ) {
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

		$deleted = false;
		if ( $this->coverage_groups instanceof CoverageGroupRepositoryInterface ) {
			$cleanup = new \CetechDeliveryEngine\Application\Coverage\DeliveryAreaCoverageCleanup(
				$this->coverage_groups,
				$this->rule_repository,
				$this->zone_repository
			);
			$deleted = $cleanup->hard_delete_zone( $id );
		} else {
			$this->rule_repository->deleteByZoneId( $id );
			$deleted = $this->zone_repository->hardDelete( $id );
		}

		if ( ! $deleted ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete destination zone. The previous record was kept.', 'cetech-woocommerce-delivery-engine' ) );
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
		$raw_country = isset( $_POST['test_country_code'] ) ? wp_unslash( (string) $_POST['test_country_code'] ) : '';
		$input       = [
			'test_country_code' => WooCommerceCountryCatalog::canonical_iso2( $raw_country ),
			'test_region'       => isset( $_POST['test_region'] ) ? wp_unslash( (string) $_POST['test_region'] ) : '',
			'test_city'         => isset( $_POST['test_city'] ) ? wp_unslash( (string) $_POST['test_city'] ) : '',
			'test_postcode'     => isset( $_POST['test_postcode'] ) ? wp_unslash( (string) $_POST['test_postcode'] ) : '',
		];

		$matched = $this->test_matcher->match_all(
			$input['test_country_code'],
			$input['test_region'],
			$input['test_city'],
			$input['test_postcode']
		);

		if ( [] === $matched ) {
			$input['test_result'] = __( 'No matching delivery area.', 'cetech-woocommerce-delivery-engine' );
		} else {
			$primary      = $matched[0];
			$primary_id   = (int) ( $primary['id'] ?? 0 );
			$primary_rules = $primary_id > 0 ? $this->rule_repository->listByZoneId( $primary_id ) : [];
			$input['test_result'] = (string) ( $primary['public_label'] ?? $primary['internal_name'] ?? '' );

			if ( DestinationZoneMatcher::is_unrestricted_fallback( $primary, $primary_rules, $this->zone_has_active_coverage( $primary_id ) ) ) {
				$input['test_global_fallback'] = 1;
			} elseif ( ! empty( $primary['is_fallback'] ) ) {
				$input['test_constrained_fallback'] = 1;
			}

			$also = [];

			foreach ( array_slice( $matched, 1 ) as $extra ) {
				$label = trim( (string) ( $extra['public_label'] ?? $extra['internal_name'] ?? '' ) );

				if ( '' !== $label ) {
					$also[] = $label;
				}
			}

			if ( [] !== $also ) {
				$input['test_also'] = implode( ', ', $also );
			}
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

	private function persist_posted_coverage( int $zone_id, ?array $validated_groups = null ): bool {
		if ( ! $this->coverage_groups instanceof CoverageGroupRepositoryInterface ) {
			return true;
		}

		if ( null === $validated_groups ) {
			$posted = $this->posted_coverage_groups();
			if ( ! is_array( $posted ) ) {
				return true;
			}
			if ( $this->coverage_validator instanceof CoverageConfigurationValidator ) {
				$existing_groups = $this->coverage_groups->list_by_zone( $zone_id );
				$result          = $this->coverage_validator->validate( $posted, '', $zone_id, $existing_groups );
				if ( ! $result['ok'] ) {
					$this->action_handler->notices()->flash_error( implode( ' ', $result['errors'] ) );

					return false;
				}
				$validated_groups = $result['groups'];
			} else {
				return true;
			}
		}

		$existing = $this->coverage_groups->list_by_zone( $zone_id );
		if ( [] === $validated_groups && $this->zone_has_usable_groups( $existing ) && ! $this->posted_confirm_drop_canonical() ) {
			$this->action_handler->notices()->flash_error( __( 'Removing the last canonical coverage group requires an explicit confirmation. Hidden legacy conditions will not be used automatically.', 'cetech-woocommerce-delivery-engine' ) );

			return false;
		}

		$by_id    = [];
		foreach ( $existing as $group ) {
			$by_id[ $group->id ] = $group;
		}

		$payloads = [];
		foreach ( $validated_groups as $index => $row ) {
			$existing_id = (int) ( $row['id'] ?? 0 );
			$previous    = $existing_id > 0 ? ( $by_id[ $existing_id ] ?? null ) : null;
			$resolve     = ! empty( $row['resolve_review'] );
			if ( $previous instanceof \CetechDeliveryEngine\Domain\Coverage\CoverageGroup && ! $resolve ) {
				$row['review_required']  = $previous->review_required;
				$row['legacy_migration'] = $previous->legacy_migration;
			} elseif ( $previous instanceof \CetechDeliveryEngine\Domain\Coverage\CoverageGroup && $resolve ) {
				$row['review_required']  = false;
				$row['legacy_migration'] = $previous->legacy_migration;
			}
			$row['sort_order'] = ( $index + 1 ) * 10;
			$payloads[]        = $row;
		}

		try {
			$saved = $this->coverage_groups->replace_for_zone( $zone_id, $payloads );
			if ( [] === $saved && [] !== $payloads ) {
				$this->action_handler->notices()->flash_error( __( 'Coverage could not be saved. The previous working configuration was kept.', 'cetech-woocommerce-delivery-engine' ) );

				return false;
			}
		} catch ( \InvalidArgumentException $e ) {
			$this->action_handler->notices()->flash_error( __( 'Coverage could not be saved because a coverage group does not belong to this Delivery Area.', 'cetech-woocommerce-delivery-engine' ) );

			return false;
		}

		return true;
	}

	/**
	 * @return list<array<string, mixed>>|null
	 */
	private function posted_confirm_drop_canonical(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return ! empty( $_POST['confirm_drop_canonical'] );
	}

	/**
	 * @param list<\CetechDeliveryEngine\Domain\Coverage\CoverageGroup> $groups
	 */
	private function zone_has_usable_groups( array $groups ): bool {
		foreach ( $groups as $group ) {
			if ( $group->isUsable() ) {
				return true;
			}
		}

		return false;
	}

	private function posted_coverage_groups(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['coverage_groups'] ) || ! is_array( $_POST['coverage_groups'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return wp_unslash( $_POST['coverage_groups'] );
	}

	private function zone_has_active_coverage( int $zone_id ): bool {
		if ( $zone_id <= 0 || ! $this->coverage_groups instanceof CoverageGroupRepositoryInterface ) {
			return false;
		}
		foreach ( $this->coverage_groups->list_by_zone( $zone_id ) as $group ) {
			if ( $group->isUsable() ) {
				return true;
			}
		}

		return false;
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
			if ( ! empty( $zone['is_fallback'] ) ) {
				return __( 'Everywhere else (global fallback)', 'cetech-woocommerce-delivery-engine' );
			}
			if ( str_contains( strtolower( $label ), 'international' ) ) {
				return __( 'International', 'cetech-woocommerce-delivery-engine' );
			}

			return '' !== $label ? $label : '—';
		}
		if ( 1 === count( $parts ) && str_contains( strtolower( $label ), 'international' ) ) {
			return __( 'International', 'cetech-woocommerce-delivery-engine' );
		}

		$location = implode( ', ', $parts );

		if ( ! empty( $zone['is_fallback'] ) ) {
			return sprintf(
				/* translators: %s: country, region, or city summary */
				__( '%s (fallback only inside this area)', 'cetech-woocommerce-delivery-engine' ),
				$location
			);
		}

		return $location;
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
