<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Presentation\Admin\Validation\ProductDeliveryRuleValidator;

/**
 * Admin-only product delivery rule configuration.
 *
 * Not exposed to storefront, cart, checkout, or WooCommerce product edit screens.
 */
final class ProductDeliveryRulesPage {

	public const SLUG = 'cetech-delivery-engine-product-rules';

	private const ACTION_SAVE = 'cetech_de_save_product_delivery_rule';

	private const ACTION_DEACTIVATE = 'cetech_de_deactivate_product_delivery_rule';

	private const ACTION_DELETE = 'cetech_de_delete_product_delivery_rule';

	private const ACTION_RESOLVE_TEST = 'cetech_de_test_product_rule_resolution';

	private const ACTION_VALIDATE_SELECTION = 'cetech_de_test_selection_validation';

	public function __construct(
		private ProductDeliveryRuleRepositoryInterface $repository,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private LogisticsProfileRepositoryInterface $logistics_profile_repository,
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository,
		private ProductDeliveryRuleValidator $validator,
		private ProductDeliveryRuleResolver $rule_resolver,
		private ProductDeliverySelectionValidator $selection_validator,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE, self::ACTION_DEACTIVATE, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->handle_deactivate();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE, self::ACTION_DELETE, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->handle_delete();
		}

		if ( $this->action_handler->verify_post( self::ACTION_RESOLVE_TEST, self::ACTION_RESOLVE_TEST, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->handle_resolution_test();
		}

		if ( $this->action_handler->verify_post( self::ACTION_VALIDATE_SELECTION, self::ACTION_VALIDATE_SELECTION, 'manage_product_delivery_rules', self::SLUG ) ) {
			$this->handle_selection_validation_test();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );

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
			__( 'Product delivery', 'cetech-woocommerce-delivery-engine' ),
			__( 'Legacy Delivery Rules', 'cetech-woocommerce-delivery-engine' ),
			__( 'These older product rules still control what shoppers see until the New Delivery Settings System is turned on. Use Delivery Settings for the new inherited values.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Add Product Rule', 'cetech-woocommerce-delivery-engine' ),
				'url'   => add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
				'class' => 'primary',
			],
			[
				'label' => __( 'Manage Logistics Profiles', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( LogisticsProfilesPage::SLUG ),
			]
		);

		AdminPageLayout::render_example(
			__( 'Heavy Tools → use the Heavy Items logistics profile and restrict same-day delivery.', 'cetech-woocommerce-delivery-engine' )
		);

		$lookups        = $this->build_lookups();
		$records        = $this->repository->list( [ 'limit' => 500 ] );
		$active         = 0;
		$inactive       = 0;
		$needing_review = 0;

		foreach ( $records as $record ) {
			if ( RecordStatus::Active->value === (string) ( $record['status'] ?? '' ) ) {
				++$active;
			} else {
				++$inactive;
			}

			if ( $this->rule_needs_review( $record ) ) {
				++$needing_review;
			}
		}

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Total rules', 'cetech-woocommerce-delivery-engine' ),
					'value' => count( $records ),
					'empty' => [] === $records,
				],
				[
					'label' => __( 'Active rules', 'cetech-woocommerce-delivery-engine' ),
					'value' => $active,
					'empty' => 0 === $active,
				],
				[
					'label' => __( 'Inactive rules', 'cetech-woocommerce-delivery-engine' ),
					'value' => $inactive,
					'empty' => 0 === $inactive,
				],
				[
					'label' => __( 'Rules needing review', 'cetech-woocommerce-delivery-engine' ),
					'value' => $needing_review,
					'empty' => 0 === $needing_review,
				],
			]
		);

		if ( $needing_review > 0 ) {
			AdminPageLayout::render_warning(
				__( 'Some rules may not be ready to use', 'cetech-woocommerce-delivery-engine' ),
				__( 'Inactive rules are ignored at checkout. Active rules without a product target or logistics profile may also need attention before they affect delivery.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Manage logistics profiles', 'cetech-woocommerce-delivery-engine' ),
				AdminPageRenderer::list_url( LogisticsProfilesPage::SLUG )
			);
		}

		if ( [] === $records ) {
			AdminPageLayout::render_empty_state(
				__( 'No legacy delivery rules yet', 'cetech-woocommerce-delivery-engine' ),
				__( 'Create a legacy delivery rule when certain products need special delivery treatment, such as heavy, fragile, pickup-only, or supplier-dispatched items. Shoppers still use these rules until the New Delivery Settings System is turned on.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add Product Rule', 'cetech-woocommerce-delivery-engine' ),
				add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
			);
		} else {
			AdminPageLayout::open_section(
				__( 'All legacy delivery rules', 'cetech-woocommerce-delivery-engine' ),
				__( 'These rules apply to matching products, categories, or variations. They remain in use for shoppers until the New Delivery Settings System is turned on.', 'cetech-woocommerce-delivery-engine' )
			);

			$rows = [];

			foreach ( $records as $record ) {
				$id = (int) ( $record['id'] ?? 0 );
				$rows[] = [
					$this->render_rule_name_cell( $record ),
					esc_html( $this->matching_summary( $record ) ),
					esc_html( $this->delivery_behavior_summary( $record, $lookups ) ),
					AdminUiHelper::record_status_badge( (string) ( $record['status'] ?? '' ) ),
					$this->render_actions( $id ),
				];
			}

			AdminPageRenderer::render_table(
				[
					__( 'Rule name', 'cetech-woocommerce-delivery-engine' ),
					__( 'Product / matching condition', 'cetech-woocommerce-delivery-engine' ),
					__( 'Logistics profile / delivery behavior', 'cetech-woocommerce-delivery-engine' ),
					__( 'Status', 'cetech-woocommerce-delivery-engine' ),
					__( 'Actions', 'cetech-woocommerce-delivery-engine' ),
				],
				$rows,
				true
			);

			AdminPageLayout::close_section();
		}

		$this->render_help_section();

		AdminPageLayout::open_advanced( AdminLanguage::technical_diagnostic_tools() );
		echo '<p class="description">' . esc_html( AdminLanguage::technical_diagnostic_tools_intro() ) . '</p>';
		echo '<p class="description">' . esc_html__(
			'These checks do not change saved settings, the cart, checkout, or orders.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		$this->render_resolution_test_tool( $lookups );
		$this->render_selection_validation_test_tool();
		AdminPageLayout::close_advanced();

		AdminPageLayout::close_page();
	}

	private function render_resolution_test_tool( array $lookups ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG . '_resolve' );

		echo '<h3>' . esc_html( AdminLanguage::check_applicable_legacy_rule() ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Enter a product, variation, or category to see which Legacy Delivery Rule would currently apply. This check does not change any saved settings.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_RESOLVE_TEST );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_RESOLVE_TEST ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		AdminFormHelper::select_field(
			'test_target_type',
			__( 'Item type', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_target_type_options(),
			(string) ( $draft['test_target_type'] ?? ProductTargetType::Product->value )
		);
		AdminFormHelper::number_field(
			'test_target_id',
			__( 'Item ID', 'cetech-woocommerce-delivery-engine' ),
			isset( $draft['test_target_id'] ) ? (int) $draft['test_target_id'] : null,
			1,
			__( 'Enter the WooCommerce ID of the product, product variation, or product category you want to check.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</tbody></table>';
		submit_button( AdminLanguage::check_applicable_rule_button(), 'secondary', 'submit', false );
		echo '</form>';

		if ( is_array( $draft ) && isset( $draft['resolution_result'] ) && is_array( $draft['resolution_result'] ) ) {
			$this->render_resolution_result( ProductRuleResolutionResult::fromArray( $draft['resolution_result'] ), $lookups );
		} elseif ( is_array( $draft ) && ! empty( $draft['resolution_error'] ) ) {
			echo '<h3>' . esc_html__( 'Check result', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
			echo '<p><strong>' . esc_html__( 'Error:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $draft['resolution_error'] );
			echo '</p>';
		}
	}

	/**
	 * @param array{
	 *     offers: array<int, array<string, mixed>>,
	 *     profiles: array<int, array<string, mixed>>,
	 *     suppliers: array<int, array<string, mixed>>,
	 *     origins: array<int, array<string, mixed>>
	 * } $lookups
	 */
	private function render_resolution_result( ProductRuleResolutionResult $result, array $lookups ): void {
		echo '<h3>' . esc_html__( 'Check result', 'cetech-woocommerce-delivery-engine' ) . '</h3>';

		if ( ! $result->success ) {
			echo '<p><strong>' . esc_html__( 'Error:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $result->error );
			echo '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Checked item:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
		echo esc_html( $this->target_type_label( $result->input_target_type ) . ' #' . (string) $result->input_target_id );
		if ( null !== $result->input_target_label && '' !== $result->input_target_label ) {
			echo ' — ' . esc_html( $result->input_target_label );
		}
		echo '</p>';

		if ( '' !== $result->hierarchy_explanation ) {
			echo '<p><strong>' . esc_html__( 'How matching works:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( $result->hierarchy_explanation );
			echo '</p>';
		}

		if ( [] !== $result->candidate_hierarchy ) {
			echo '<p><strong>' . esc_html__( 'Search order:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
			echo '<ol>';
			foreach ( $result->candidate_hierarchy as $entry ) {
				$label = isset( $entry['label'] ) && is_string( $entry['label'] ) && '' !== $entry['label']
					? $entry['label']
					: '—';
				$type_label = $this->target_type_label( (string) ( $entry['target_type'] ?? '' ) );
				echo '<li>' . esc_html( $type_label . ' #' . (string) ( $entry['target_id'] ?? 0 ) . ' (' . $label . ')' ) . '</li>';
			}
			echo '</ol>';
		}

		if ( null !== $result->no_match_message && '' !== $result->no_match_message ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'No match:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( $result->no_match_message );
			echo '</p></div>';
		}

		if ( [] !== $result->warnings ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Warnings:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p><ul>';
			foreach ( $result->warnings as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( [] !== $result->matched_rules ) {
			echo '<p><strong>' . esc_html__( 'Matched rules:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) count( $result->matched_rules ) );
			echo '</p>';
		}

		if ( [] !== $result->chosen_rules ) {
			echo '<p><strong>' . esc_html__( 'Applicable rules by fulfilment location:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
			echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Fulfilment availability', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Rule', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Why chosen', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Target', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Choice', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Delivery offers', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Logistics profile', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Supplier', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Origin', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Priority', 'cetech-woocommerce-delivery-engine' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $result->chosen_rules as $availability => $rule ) {
				if ( ! $rule instanceof ResolvedProductDeliveryRule ) {
					continue;
				}

				$explanation = $result->chosen_explanations[ $availability ] ?? '';

				echo '<tr>';
				echo '<td>' . esc_html( $this->availability_label( $availability ) ) . '</td>';
				echo '<td>' . esc_html( '#' . (string) $rule->rule_id ) . '</td>';
				echo '<td>' . esc_html( $explanation ) . '</td>';
				echo '<td>' . esc_html( $this->target_type_label( $rule->target_type ) . ' #' . (string) $rule->target_id ) . '</td>';
				echo '<td>' . esc_html( $this->choice_label( $rule->fulfilment_choice ) ) . '</td>';
				echo '<td>' . esc_html( $this->format_offer_id_list( $lookups['offers'], $rule->delivery_offer_ids ) ) . '</td>';
				echo '<td>' . esc_html( $this->lookup_optional( $lookups['profiles'], $rule->logistics_profile_id ) ) . '</td>';
				echo '<td>' . esc_html( $this->lookup_optional( $lookups['suppliers'], $rule->supplier_id ) ) . '</td>';
				echo '<td>' . esc_html( $this->lookup_optional( $lookups['origins'], $rule->origin_id ) ) . '</td>';
				echo '<td>' . esc_html( (string) $rule->priority ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		} elseif ( null === $result->no_match_message || '' === $result->no_match_message ) {
			echo '<p><em>' . esc_html__( 'No rules were chosen for any fulfilment location.', 'cetech-woocommerce-delivery-engine' ) . '</em></p>';
		}

		AdminPageLayout::open_developer_information();
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: internal contract version */
				__( 'Internal contract version: %s (support check only; not used on the storefront).', 'cetech-woocommerce-delivery-engine' ),
				$result->contract_version
			)
		) . '</p>';
		AdminPageLayout::close_developer_information();

		if ( [] !== $result->skipped_rules ) {
			echo '<p><strong>' . esc_html__( 'Skipped rules:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p><ul>';
			foreach ( $result->skipped_rules as $skipped ) {
				$code = isset( $skipped['code'] ) && is_string( $skipped['code'] ) && '' !== $skipped['code']
					? ' [' . $skipped['code'] . ']'
					: '';
				echo '<li>' . esc_html(
					sprintf(
						/* translators: 1: rule ID, 2: skip reason, 3: optional skip code */
						__( 'Rule #%1$d: %2$s%3$s', 'cetech-woocommerce-delivery-engine' ),
						(int) ( $skipped['rule_id'] ?? 0 ),
						(string) ( $skipped['reason'] ?? '' ),
						$code
					)
				) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function handle_resolution_test(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = [
			'test_target_type' => isset( $_POST['test_target_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['test_target_type'] ) ) : '',
			'test_target_id'   => isset( $_POST['test_target_id'] ) ? (int) $_POST['test_target_id'] : 0,
		];

		$errors = $this->validator->validate_resolution_test_input( $input );

		if ( [] !== $errors ) {
			$input['resolution_error'] = implode( ' ', array_values( $errors ) );
			$this->action_handler->notices()->stash_form_draft( self::SLUG . '_resolve', $input );
			$this->action_handler->notices()->flash_error( implode( ' ', array_values( $errors ) ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$result = $this->rule_resolver->resolve(
			(string) $input['test_target_type'],
			(int) $input['test_target_id']
		);

		$input['resolution_result'] = $result->toArray();
		$this->action_handler->notices()->stash_form_draft( self::SLUG . '_resolve', $input );
		$this->action_handler->redirect( self::SLUG );
	}

	private function render_selection_validation_test_tool(): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG . '_validate' );
		$selector_label = FeatureFlagLabels::label( 'enable_product_delivery_selector' );

		echo '<h3>' . esc_html( AdminLanguage::check_delivery_choice() ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Use this support tool to check whether a specific delivery choice is still valid for a product or variation. This check does not change the cart, checkout, orders, or product settings.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: operational setting label */
				__( 'This diagnostic requires “%s” to be turned on.', 'cetech-woocommerce-delivery-engine' ),
				$selector_label
			)
		) . '</p>';

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_VALIDATE_SELECTION );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_VALIDATE_SELECTION ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		AdminFormHelper::number_field(
			'test_product_id',
			__( 'Product ID', 'cetech-woocommerce-delivery-engine' ),
			isset( $draft['test_product_id'] ) ? (int) $draft['test_product_id'] : null,
			1,
			__( 'WooCommerce simple or parent product ID.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'test_variation_id',
			__( 'Product variation ID (optional)', 'cetech-woocommerce-delivery-engine' ),
			isset( $draft['test_variation_id'] ) ? (int) $draft['test_variation_id'] : null,
			0,
			__( 'Required for variable products. Leave 0 for simple products.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'test_display_key',
			AdminLanguage::delivery_choice_identifier(),
			(string) ( $draft['test_display_key'] ?? '' ),
			true,
			__( 'This technical identifier represents one specific delivery choice. Support staff may ask you to paste one here when troubleshooting.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</tbody></table>';
		AdminPageLayout::open_developer_information();
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: internal setting key */
				__( 'Internal setting key: %s', 'cetech-woocommerce-delivery-engine' ),
				'enable_product_delivery_selector'
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__(
			'Identifier format: availability:choice:suffix. Example: in_store:delivery:12 or in_store:store_pickup:pickup.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		AdminPageLayout::close_developer_information();
		submit_button( __( 'Check delivery choice', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( is_array( $draft ) && isset( $draft['validation_result'] ) && is_array( $draft['validation_result'] ) ) {
			$this->render_selection_validation_result( ProductDeliverySelectionValidationResult::fromArray( $draft['validation_result'] ) );
		} elseif ( is_array( $draft ) && ! empty( $draft['validation_error'] ) ) {
			echo '<h3>' . esc_html__( 'Check result', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
			echo '<p><strong>' . esc_html__( 'Error:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $draft['validation_error'] );
			echo '</p>';
		}
	}

	private function render_selection_validation_result( ProductDeliverySelectionValidationResult $result ): void {
		echo '<h3>' . esc_html__( 'Check result', 'cetech-woocommerce-delivery-engine' ) . '</h3>';

		echo '<p><strong>' . esc_html__( 'Valid:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
		echo esc_html( $result->valid ? __( 'Yes', 'cetech-woocommerce-delivery-engine' ) : __( 'No', 'cetech-woocommerce-delivery-engine' ) );
		echo '</p>';

		if ( ! $result->valid ) {
			echo '<p><strong>' . esc_html__( 'Message:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $result->error_message );
			echo '</p>';
		}

		if ( [] !== $result->warnings ) {
			echo '<p><strong>' . esc_html__( 'Warnings:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p><ul>';
			foreach ( $result->warnings as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul>';
		}

		if ( is_array( $result->matched_option ) ) {
			echo '<p><strong>' . esc_html__( 'Matching delivery choice:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
			echo '<ul>';
			foreach (
				[
					'fulfilment_availability_label'     => __( 'Fulfilment availability', 'cetech-woocommerce-delivery-engine' ),
					'fulfilment_choice_label'           => __( 'Fulfilment choice', 'cetech-woocommerce-delivery-engine' ),
					'delivery_offer_public_label'       => __( 'Customer-facing label', 'cetech-woocommerce-delivery-engine' ),
					'estimate_text'                     => __( 'Estimate', 'cetech-woocommerce-delivery-engine' ),
					'is_available'                      => __( 'Available', 'cetech-woocommerce-delivery-engine' ),
				] as $field => $label
			) {
				if ( ! isset( $result->matched_option[ $field ] ) ) {
					continue;
				}

				$value = $result->matched_option[ $field ];

				if ( 'is_available' === $field ) {
					$value = ! empty( $value )
						? __( 'Yes', 'cetech-woocommerce-delivery-engine' )
						: __( 'No', 'cetech-woocommerce-delivery-engine' );
				}

				echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
			}
			echo '</ul>';
		}

		AdminPageLayout::open_developer_information();
		if ( ! $result->valid ) {
			echo '<p><strong>' . esc_html__( 'Error code:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $result->error_code );
			echo '</p>';
		}
		if ( is_array( $result->matched_option ) && isset( $result->matched_option['display_key'] ) ) {
			echo '<p><strong>' . esc_html( AdminLanguage::delivery_choice_identifier() ) . ':</strong> ';
			echo esc_html( (string) $result->matched_option['display_key'] );
			echo '</p>';
		}
		if ( is_array( $result->intent ) ) {
			echo '<p><strong>' . esc_html__( 'Internal selection record:', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
			echo '<ul>';
			foreach (
				[
					'contract_version'        => __( 'Contract version', 'cetech-woocommerce-delivery-engine' ),
					'product_id'              => __( 'Product ID', 'cetech-woocommerce-delivery-engine' ),
					'variation_id'            => __( 'Product variation ID', 'cetech-woocommerce-delivery-engine' ),
					'target_type'             => __( 'Item type', 'cetech-woocommerce-delivery-engine' ),
					'target_id'               => __( 'Item ID', 'cetech-woocommerce-delivery-engine' ),
					'display_key'             => AdminLanguage::delivery_choice_identifier(),
					'rule_id'                 => __( 'Rule ID', 'cetech-woocommerce-delivery-engine' ),
					'delivery_offer_id'       => __( 'Delivery offer ID', 'cetech-woocommerce-delivery-engine' ),
					'issued_at'               => __( 'Issued at', 'cetech-woocommerce-delivery-engine' ),
				] as $field => $label
			) {
				if ( ! isset( $result->intent[ $field ] ) || ( '' === $result->intent[ $field ] && 'variation_id' !== $field ) ) {
					continue;
				}

				echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $result->intent[ $field ] ) . '</li>';
			}
			echo '</ul>';
		}
		AdminPageLayout::close_developer_information();
	}

	private function handle_selection_validation_test(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = [
			'test_product_id'    => isset( $_POST['test_product_id'] ) ? (int) $_POST['test_product_id'] : 0,
			'test_variation_id'  => isset( $_POST['test_variation_id'] ) ? (int) $_POST['test_variation_id'] : 0,
			'test_display_key'   => isset( $_POST['test_display_key'] ) ? wp_unslash( (string) $_POST['test_display_key'] ) : '',
		];

		$errors = $this->validator->validate_selection_test_input( $input );

		if ( [] !== $errors ) {
			$input['validation_error'] = implode( ' ', array_values( $errors ) );
			$this->action_handler->notices()->stash_form_draft( self::SLUG . '_validate', $input );
			$this->action_handler->notices()->flash_error( implode( ' ', array_values( $errors ) ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$variation_id = (int) $input['test_variation_id'] > 0 ? (int) $input['test_variation_id'] : null;

		$result = $this->selection_validator->validate(
			(int) $input['test_product_id'],
			$variation_id,
			(string) $input['test_display_key']
		);

		$input['validation_result'] = $result->toArray();
		$this->action_handler->notices()->stash_form_draft( self::SLUG . '_validate', $input );
		$this->action_handler->redirect( self::SLUG );
	}

	private function render_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG );

		if ( null !== $draft ) {
			$record = $this->form_record_from_draft( $draft );
		} else {
			$record = $this->load_record_for_form( $is_edit );
		}

		$title = $is_edit
			? __( 'Edit Product Rule', 'cetech-woocommerce-delivery-engine' )
			: __( 'Add Product Rule', 'cetech-woocommerce-delivery-engine' );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Product delivery', 'cetech-woocommerce-delivery-engine' ),
			$title,
			__( 'Define which products this rule applies to and how delivery, pickup, or logistics handling should work.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to product rules', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);

		AdminPageLayout::render_example(
			__( 'Fragile Items → require careful handling and may only qualify for standard delivery.', 'cetech-woocommerce-delivery-engine' )
		);

		$status = (string) ( $record['status'] ?? RecordStatus::Active->value );

		if ( RecordStatus::Inactive->value === $status ) {
			AdminPageLayout::render_warning(
				__( 'This rule is inactive', 'cetech-woocommerce-delivery-engine' ),
				__( 'It will not affect delivery until you set the status back to Active and save.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$target_id = isset( $record['target_id'] ) ? (int) $record['target_id'] : 0;

		if ( $target_id <= 0 ) {
			AdminPageLayout::render_warning(
				__( 'No matching product selected', 'cetech-woocommerce-delivery-engine' ),
				__( 'Choose a WooCommerce product, variation, or category ID so the rule knows what to match.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$logistics_profile_id = isset( $record['logistics_profile_id'] ) ? (int) $record['logistics_profile_id'] : 0;

		if ( $logistics_profile_id <= 0 && FulfilmentChoice::Delivery->value === (string) ( $record['fulfilment_choice'] ?? '' ) ) {
			AdminPageLayout::render_warning(
				__( 'No logistics profile linked', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery rules often work best when linked to a logistics profile for special handling or pricing.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Manage logistics profiles', 'cetech-woocommerce-delivery-engine' ),
				AdminPageRenderer::list_url( LogisticsProfilesPage::SLUG )
			);
		}

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		if ( $is_edit && ! empty( $record['id'] ) ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record['id'] ) . '" />';
		}

		AdminPageLayout::open_form_panel(
			__( 'Rule details', 'cetech-woocommerce-delivery-engine' ),
			__( 'Status and internal notes for staff reference.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			$status,
			__( 'Inactive rules are ignored until activated again.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::textarea_field(
			'internal_notes',
			__( 'Internal notes', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['internal_notes'] ?? '' ),
			4,
			__( 'Private admin-only notes. Not shown to customers.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Product matching', 'cetech-woocommerce-delivery-engine' ),
			__( 'Which product, variation, or category this rule applies to.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'target_type',
			__( 'Match type', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_target_type_options(),
			(string) ( $record['target_type'] ?? ProductTargetType::Product->value ),
			__( 'Whether the rule targets a single product, a variation, or a whole category.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'target_id',
			__( 'WooCommerce ID', 'cetech-woocommerce-delivery-engine' ),
			isset( $record['target_id'] ) ? (int) $record['target_id'] : null,
			1,
			__( 'Product ID, variation ID, or product category term ID from WooCommerce.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'target_label_snapshot',
			__( 'Display label', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['target_label_snapshot'] ?? '' ),
			false,
			__( 'Optional friendly name for lists. Auto-filled from WooCommerce when left blank on save.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">target_type, target_id, target_label_snapshot</p></td></tr>';
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Delivery handling', 'cetech-woocommerce-delivery-engine' ),
			__( 'How matched products should be offered for delivery or pickup.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'fulfilment_availability',
			__( 'Fulfilment availability', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_availability_options(),
			(string) ( $record['fulfilment_availability'] ?? FulfilmentAvailability::InStore->value ),
			__( 'Choose where this item is fulfilled from. This affects which delivery methods can be offered.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'fulfilment_choice',
			__( 'Fulfilment choice', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_choice_options(),
			(string) ( $record['fulfilment_choice'] ?? FulfilmentChoice::Delivery->value ),
			__( 'Whether customers can choose home delivery or store pickup for this rule.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::checkbox_group_field(
			'delivery_offer_ids',
			__( 'Allowed delivery offers', 'cetech-woocommerce-delivery-engine' ),
			$this->delivery_offer_options(),
			(array) ( $record['delivery_offer_ids'] ?? [] ),
			__( 'Required for delivery fulfilment. Leave empty when fulfilment choice is store pickup.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'logistics_profile_id',
			__( 'Logistics profile', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->logistics_profile_options() ),
			(string) ( $record['logistics_profile_id'] ?? '' ),
			__( 'A logistics profile groups the delivery handling rules used to fulfil an item, such as how it is dispatched or which delivery services can be used. Optional.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_advanced( __( 'Advanced settings', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p class="description">' . esc_html__(
			'Supplier dispatch, rule priority, and other technical options. Most stores can leave these blank unless CETECH support asks you to configure them.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::select_field(
			'supplier_id',
			__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->supplier_options() ),
			(string) ( $record['supplier_id'] ?? '' ),
			__( 'Optional. Use for supplier-dispatched products.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">supplier_id</p></td></tr>';
		AdminFormHelper::select_field(
			'origin_id',
			__( 'Origin', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->origin_options() ),
			(string) ( $record['origin_id'] ?? '' ),
			__( 'Optional. Must belong to the selected supplier when both are set.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">origin_id</p></td></tr>';
		AdminFormHelper::number_field(
			'priority',
			__( 'Priority', 'cetech-woocommerce-delivery-engine' ),
			isset( $record['priority'] ) ? (int) $record['priority'] : 100,
			0,
			__( 'Priority decides which delivery setup takes precedence if more than one setup could apply. A lower number is considered first. Most products can leave this unchanged (default 100).', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<tr><th scope="row"></th><td><p class="description cetech-de-setting-code">priority</p></td></tr>';
		echo '</tbody></table>';
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		submit_button( $is_edit ? __( 'Save Product Rule', 'cetech-woocommerce-delivery-engine' ) : __( 'Create Product Rule', 'cetech-woocommerce-delivery-engine' ) );
		echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE,
				'manage_product_delivery_rules'
			);
		}

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		$input  = $this->read_form_input();
		$errors = $this->validator->validate( $input, isset( $input['id'] ) ? (int) $input['id'] : null );

		if ( [] !== $errors ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( implode( ' ', array_values( $errors ) ) );
			$this->redirect_to_form( $input );
		}

		$id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$previous = $id > 0 ? $this->repository->findById( $id ) : null;

		$payload = [
			'id'                      => $id,
			'target_type'             => sanitize_key( (string) $input['target_type'] ),
			'target_id'               => (int) $input['target_id'],
			'target_label_snapshot'   => $this->validator->resolve_target_label_snapshot( $input ),
			'fulfilment_availability' => (string) $input['fulfilment_availability'],
			'fulfilment_choice'       => (string) $input['fulfilment_choice'],
			'delivery_offer_ids'      => $this->validator->normalize_offer_ids( $input['delivery_offer_ids'] ?? [] ),
			'logistics_profile_id'    => $this->nullable_int( $input['logistics_profile_id'] ?? null ),
			'supplier_id'             => $this->nullable_int( $input['supplier_id'] ?? null ),
			'origin_id'               => $this->nullable_int( $input['origin_id'] ?? null ),
			'priority'                => (int) $input['priority'],
			'status'                  => (string) $input['status'],
			'internal_notes'          => trim( (string) ( $input['internal_notes'] ?? '' ) ),
		];

		$saved_id = $this->repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->stash_form_draft( self::SLUG, $input );
			$this->action_handler->notices()->flash_error( __( 'Unable to save product rule.', 'cetech-woocommerce-delivery-engine' ) );
			$this->redirect_to_form( $input );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'product_delivery_rule',
			$saved_id,
			$previous,
			$this->repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Product rule updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Product rule created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Product rule updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Product rule created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid product rule.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Product rule not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->repository->deactivate( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate product rule.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Product rule is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'product_delivery_rule',
			$id,
			$previous,
			$this->repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Product rule deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Product rule deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid product rule.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Product rule not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_product_rule( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete product rule.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'product_delivery_rule', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Product rule permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Product rule deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_product_delivery_rules' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Product rule not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$name = (string) ( $record['target_label_snapshot'] ?? '' );

		if ( '' === $name ) {
			$name = sprintf(
				'%s #%d',
				(string) ( $record['target_type'] ?? '' ),
				(int) ( $record['target_id'] ?? 0 )
			);
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE,
			self::ACTION_DEACTIVATE,
			'manage_product_delivery_rules',
			__( 'Product Rule', 'cetech-woocommerce-delivery-engine' ),
			$id,
			$name,
			null,
			$this->dependency_checker->check_product_rule( $id )
		);
	}

	private function render_actions( int $id ): string {
		$edit_url = esc_url( AdminPageRenderer::edit_url( self::SLUG, $id ) );
		$edit     = '<a href="' . $edit_url . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a>';

		$deactivate = '<form method="post" style="display:inline;margin-left:8px;">';
		$deactivate .= wp_nonce_field( self::ACTION_DEACTIVATE, 'cetech_de_nonce', true, false );
		$deactivate .= '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_DEACTIVATE ) . '" />';
		$deactivate .= '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		$deactivate .= '<button type="submit" class="button-link delete" onclick="return confirm(\'' . esc_js( __( 'Deactivate this product rule?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
		$deactivate .= esc_html__( 'Deactivate', 'cetech-woocommerce-delivery-engine' );
		$deactivate .= '</button></form>';

		$delete = AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE,
			'manage_product_delivery_rules'
		);

		return $edit . $deactivate . $delete;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load_record_for_form( bool $is_edit ): ?array {
		if ( ! $is_edit ) {
			return [
				'target_type'             => ProductTargetType::Product->value,
				'fulfilment_availability' => FulfilmentAvailability::InStore->value,
				'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
				'delivery_offer_ids'      => [],
			];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid edit request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			wp_die( esc_html__( 'Product rule not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		return $this->map_row_to_form( $row );
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array<string, mixed>
	 */
	private function form_record_from_draft( array $draft ): array {
		$offer_ids = $this->validator->normalize_offer_ids( $draft['delivery_offer_ids'] ?? [] );

		return [
			'id'                      => isset( $draft['id'] ) ? (int) $draft['id'] : 0,
			'target_type'             => (string) ( $draft['target_type'] ?? '' ),
			'target_id'               => isset( $draft['target_id'] ) ? (int) $draft['target_id'] : 0,
			'target_label_snapshot'   => (string) ( $draft['target_label_snapshot'] ?? '' ),
			'fulfilment_availability' => (string) ( $draft['fulfilment_availability'] ?? '' ),
			'fulfilment_choice'       => (string) ( $draft['fulfilment_choice'] ?? '' ),
			'delivery_offer_ids'      => array_map( 'strval', $offer_ids ),
			'logistics_profile_id'    => (string) ( $draft['logistics_profile_id'] ?? '' ),
			'supplier_id'             => (string) ( $draft['supplier_id'] ?? '' ),
			'origin_id'               => (string) ( $draft['origin_id'] ?? '' ),
			'priority'                => isset( $draft['priority'] ) ? (int) $draft['priority'] : 100,
			'status'                  => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
			'internal_notes'          => (string) ( $draft['internal_notes'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function map_row_to_form( array $row ): array {
		$offer_ids = $this->decode_offer_ids( $row['delivery_offer_ids'] ?? null );

		return [
			'id'                      => (int) ( $row['id'] ?? 0 ),
			'target_type'             => (string) ( $row['target_type'] ?? '' ),
			'target_id'               => (int) ( $row['target_id'] ?? 0 ),
			'target_label_snapshot'   => (string) ( $row['target_label_snapshot'] ?? '' ),
			'fulfilment_availability' => (string) ( $row['fulfilment_availability'] ?? '' ),
			'fulfilment_choice'       => (string) ( $row['fulfilment_choice'] ?? '' ),
			'delivery_offer_ids'      => array_map( 'strval', $offer_ids ),
			'logistics_profile_id'    => (string) ( $row['logistics_profile_id'] ?? '' ),
			'supplier_id'             => (string) ( $row['supplier_id'] ?? '' ),
			'origin_id'               => (string) ( $row['origin_id'] ?? '' ),
			'priority'                => (int) ( $row['priority'] ?? 100 ),
			'status'                  => (string) ( $row['status'] ?? RecordStatus::Active->value ),
			'internal_notes'          => (string) ( $row['internal_notes'] ?? '' ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_form_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offer_ids = isset( $_POST['delivery_offer_ids'] ) && is_array( $_POST['delivery_offer_ids'] )
			? array_map( 'intval', wp_unslash( $_POST['delivery_offer_ids'] ) )
			: [];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return [
			'id'                      => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
			'target_type'             => isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['target_type'] ) ) : '',
			'target_id'               => isset( $_POST['target_id'] ) ? (int) $_POST['target_id'] : 0,
			'target_label_snapshot'   => isset( $_POST['target_label_snapshot'] ) ? wp_unslash( (string) $_POST['target_label_snapshot'] ) : '',
			'fulfilment_availability' => isset( $_POST['fulfilment_availability'] ) ? sanitize_key( wp_unslash( (string) $_POST['fulfilment_availability'] ) ) : '',
			'fulfilment_choice'       => isset( $_POST['fulfilment_choice'] ) ? sanitize_key( wp_unslash( (string) $_POST['fulfilment_choice'] ) ) : '',
			'delivery_offer_ids'      => $offer_ids,
			'logistics_profile_id'    => isset( $_POST['logistics_profile_id'] ) ? (int) $_POST['logistics_profile_id'] : 0,
			'supplier_id'             => isset( $_POST['supplier_id'] ) ? (int) $_POST['supplier_id'] : 0,
			'origin_id'               => isset( $_POST['origin_id'] ) ? (int) $_POST['origin_id'] : 0,
			'priority'                => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 100,
			'status'                  => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '',
			'internal_notes'          => isset( $_POST['internal_notes'] ) ? wp_unslash( (string) $_POST['internal_notes'] ) : '',
		];
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
	 * @return array{
	 *     offers: array<int, array<string, mixed>>,
	 *     profiles: array<int, array<string, mixed>>,
	 *     suppliers: array<int, array<string, mixed>>,
	 *     origins: array<int, array<string, mixed>>
	 * }
	 */
	private function build_lookups(): array {
		return [
			'offers'    => $this->index_by_id( $this->delivery_offer_repository->list( [ 'limit' => 500 ] ) ),
			'profiles'  => $this->index_by_id( $this->logistics_profile_repository->list( [ 'limit' => 500 ] ) ),
			'suppliers' => $this->index_by_id( $this->supplier_repository->list( [ 'limit' => 500 ] ) ),
			'origins'   => $this->index_by_id( $this->origin_repository->list( [ 'limit' => 500 ] ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $entities
	 */
	private function lookup_optional( array $entities, mixed $id ): string {
		if ( null === $id || '' === $id ) {
			return '—';
		}

		$int = (int) $id;

		if ( $int <= 0 || ! isset( $entities[ $int ] ) ) {
			return sprintf( '#%d', $int );
		}

		$row = $entities[ $int ];

		return sprintf(
			'%s (%s)',
			(string) ( $row['internal_code'] ?? '' ),
			(string) ( $row['internal_name'] ?? $row['public_label'] ?? '' )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $offers
	 */
	private function format_offer_ids( array $offers, mixed $stored ): string {
		$ids = $this->decode_offer_ids( $stored );

		if ( [] === $ids ) {
			return '—';
		}

		$labels = [];

		foreach ( $ids as $id ) {
			if ( isset( $offers[ $id ] ) ) {
				$labels[] = (string) ( $offers[ $id ]['internal_code'] ?? (string) $id );
			} else {
				$labels[] = '#' . (string) $id;
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * @param array<int, array<string, mixed>> $offers
	 * @param list<int>                        $ids
	 */
	private function format_offer_id_list( array $offers, array $ids ): string {
		if ( [] === $ids ) {
			return '—';
		}

		$labels = [];

		foreach ( $ids as $id ) {
			if ( isset( $offers[ $id ] ) ) {
				$labels[] = (string) ( $offers[ $id ]['internal_code'] ?? (string) $id );
			} else {
				$labels[] = '#' . (string) $id;
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * @return list<int>
	 */
	private function decode_offer_ids( mixed $stored ): array {
		if ( $this->repository instanceof WpdbProductDeliveryRuleRepository ) {
			return $this->repository->decode_offer_ids( $stored );
		}

		if ( null === $stored || '' === $stored ) {
			return [];
		}

		$decoded = json_decode( (string) $stored, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$ids = [];

		foreach ( $decoded as $value ) {
			$int = (int) $value;

			if ( $int > 0 ) {
				$ids[] = $int;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function index_by_id( array $rows ): array {
		$indexed = [];

		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id > 0 ) {
				$indexed[ $id ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function render_rule_name_cell( array $record ): string {
		$label = trim( (string) ( $record['target_label_snapshot'] ?? '' ) );
		$id    = (int) ( $record['id'] ?? 0 );

		if ( '' === $label ) {
			$label = sprintf(
				/* translators: %d: product rule ID */
				__( 'Product rule #%d', 'cetech-woocommerce-delivery-engine' ),
				$id
			);
		}

		$name = esc_html( $label );

		if ( $id <= 0 ) {
			return $name;
		}

		return $name . '<br><span class="cetech-de-setting-code">#' . esc_html( (string) $id ) . '</span>';
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function matching_summary( array $record ): string {
		$type  = $this->target_type_label( (string) ( $record['target_type'] ?? '' ) );
		$id    = (int) ( $record['target_id'] ?? 0 );
		$label = trim( (string) ( $record['target_label_snapshot'] ?? '' ) );

		if ( '' !== $label && $id > 0 ) {
			return sprintf( '%s (%s #%d)', $label, $type, $id );
		}

		if ( $id > 0 ) {
			return sprintf( '%s #%d', $type, $id );
		}

		return $type;
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array{
	 *     offers: array<int, array<string, mixed>>,
	 *     profiles: array<int, array<string, mixed>>,
	 *     suppliers: array<int, array<string, mixed>>,
	 *     origins: array<int, array<string, mixed>>
	 * } $lookups
	 */
	private function delivery_behavior_summary( array $record, array $lookups ): string {
		$parts = [];

		$profile = $this->lookup_optional( $lookups['profiles'], $record['logistics_profile_id'] ?? null );

		if ( '—' !== $profile ) {
			$parts[] = $profile;
		}

		$parts[] = $this->choice_label( (string) ( $record['fulfilment_choice'] ?? '' ) );
		$parts[] = $this->availability_label( (string) ( $record['fulfilment_availability'] ?? '' ) );

		$offers = $this->format_offer_ids( $lookups['offers'], $record['delivery_offer_ids'] ?? null );

		if ( '—' !== $offers ) {
			$parts[] = $offers;
		}

		return implode( ' · ', array_filter( $parts ) );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function rule_needs_review( array $record ): bool {
		if ( RecordStatus::Inactive->value === (string) ( $record['status'] ?? '' ) ) {
			return true;
		}

		return (int) ( $record['target_id'] ?? 0 ) <= 0;
	}

	private function render_help_section(): void {
		AdminPageLayout::open_section(
			__( 'What is a product rule?', 'cetech-woocommerce-delivery-engine' ),
			__( 'Product rules tell the Delivery Engine how to treat certain products during delivery.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<div class="cetech-de-help-card">';
		echo '<p>' . esc_html__(
			'Use them when a product needs special handling, such as heavy items, fragile items, pickup-only items, supplier-dispatched items, or products that should use a specific logistics profile.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '<ul class="cetech-de-help-steps">';
		echo '<li>' . esc_html__( 'Heavy tools → special delivery handling', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Fragile items → careful handling / standard delivery only', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Pickup-only products → do not show normal delivery', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Supplier-dispatched products → dispatch from supplier origin', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Same-day eligible products → allow faster delivery offers', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '</ul>';
		printf(
			'<p class="cetech-de-help-action"><a class="button button-secondary" href="%1$s">%2$s</a> ',
			esc_url( AdminPageRenderer::list_url( LogisticsProfilesPage::SLUG ) ),
			esc_html__( 'Manage logistics profiles', 'cetech-woocommerce-delivery-engine' )
		);
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a></p>',
			esc_url( AdminPageRenderer::list_url( AdminMenu::SYSTEM_STATUS_SLUG ) ),
			esc_html__( 'Back to Dashboard', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</div>';
		AdminPageLayout::close_section();
	}

	private function target_type_label( string $type ): string {
		return match ( $type ) {
			ProductTargetType::Product->value => __( 'Product', 'cetech-woocommerce-delivery-engine' ),
			ProductTargetType::Variation->value => __( 'Product variation', 'cetech-woocommerce-delivery-engine' ),
			ProductTargetType::Category->value => __( 'Product category', 'cetech-woocommerce-delivery-engine' ),
			default => $type,
		};
	}

	private function availability_label( string $availability ): string {
		return match ( $availability ) {
			FulfilmentAvailability::InStore->value => __( 'In store', 'cetech-woocommerce-delivery-engine' ),
			FulfilmentAvailability::InWarehouse->value => __( 'In warehouse', 'cetech-woocommerce-delivery-engine' ),
			FulfilmentAvailability::InternationalFulfilment->value => __( 'International fulfilment', 'cetech-woocommerce-delivery-engine' ),
			default => $availability,
		};
	}

	private function choice_label( string $choice ): string {
		return match ( $choice ) {
			FulfilmentChoice::Delivery->value => __( 'Delivery', 'cetech-woocommerce-delivery-engine' ),
			FulfilmentChoice::StorePickup->value => __( 'Store pickup', 'cetech-woocommerce-delivery-engine' ),
			default => $choice,
		};
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_target_type_options(): array {
		$options = [];

		foreach ( ProductTargetType::cases() as $case ) {
			$options[ $case->value ] = $this->target_type_label( $case->value );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_availability_options(): array {
		$options = [];

		foreach ( FulfilmentAvailability::cases() as $case ) {
			$options[ $case->value ] = $this->availability_label( $case->value );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_choice_options(): array {
		$options = [];

		foreach ( FulfilmentChoice::cases() as $case ) {
			$options[ $case->value ] = $this->choice_label( $case->value );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_status_options(): array {
		$options = [];

		foreach ( RecordStatus::cases() as $status ) {
			if ( RecordStatus::Archived->value === $status->value ) {
				continue;
			}

			$options[ $status->value ] = AdminUiHelper::record_status_label( $status->value );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function target_type_options(): array {
		$options = [];

		foreach ( ProductTargetType::cases() as $case ) {
			$options[ $case->value ] = $case->value;
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function availability_options(): array {
		$options = [];

		foreach ( FulfilmentAvailability::cases() as $case ) {
			$options[ $case->value ] = $case->value;
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function choice_options(): array {
		$options = [];

		foreach ( FulfilmentChoice::cases() as $case ) {
			$options[ $case->value ] = $case->value;
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function status_options(): array {
		return [
			RecordStatus::Active->value   => RecordStatus::Active->value,
			RecordStatus::Inactive->value => RecordStatus::Inactive->value,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function delivery_offer_options(): array {
		$options = [];

		foreach ( $this->delivery_offer_repository->list( [ 'limit' => 500 ] ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$options[ (string) $id ] = sprintf(
				'%s (%s)',
				(string) ( $row['internal_code'] ?? '' ),
				(string) ( $row['public_label'] ?? '' )
			);
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function logistics_profile_options(): array {
		$options = [];

		foreach ( $this->logistics_profile_repository->list( [ 'limit' => 500 ] ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$options[ (string) $id ] = sprintf(
				'%s (%s)',
				(string) ( $row['internal_code'] ?? '' ),
				(string) ( $row['internal_name'] ?? '' )
			);
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function supplier_options(): array {
		$options = [];

		foreach ( $this->supplier_repository->list( [ 'limit' => 500 ] ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$options[ (string) $id ] = sprintf(
				'%s (%s)',
				(string) ( $row['internal_code'] ?? '' ),
				(string) ( $row['internal_name'] ?? '' )
			);
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function origin_options(): array {
		$options = [];

		foreach ( $this->origin_repository->list( [ 'limit' => 500 ] ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$options[ (string) $id ] = sprintf(
				'%s (%s)',
				(string) ( $row['internal_code'] ?? '' ),
				(string) ( $row['internal_name'] ?? '' )
			);
		}

		return $options;
	}

	/**
	 * @param array<string, string> $options
	 *
	 * @return array<string, string>
	 */
	private function optional_select_options( array $options ): array {
		return array_merge(
			[ '' => __( '— None —', 'cetech-woocommerce-delivery-engine' ) ],
			$options
		);
	}

	private function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value || 0 === (int) $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}
}
