<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Calculator\AdminRateCardTester;
use CetechDeliveryEngine\Application\Configuration\Admin\AdminMoneyFormatter;
use CetechDeliveryEngine\Application\Configuration\Admin\StoreAwareExamples;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\RateCardValidator;

/**
 * Admin-only rate card configuration. Not exposed to storefront or checkout.
 */
final class RateCardsPage {

	public const SLUG = 'cetech-delivery-engine-rate-cards';

	private const ACTION_SAVE = 'cetech_de_save_rate_card';

	private const ACTION_DEACTIVATE = 'cetech_de_deactivate_rate_card';

	private const ACTION_DELETE = 'cetech_de_delete_rate_card';

	private const ACTION_TEST = 'cetech_de_test_rate_card';

	private const ACTION_QUOTE_TEST = 'cetech_de_test_rate_quote';

	public function __construct(
		private RateCardRepositoryInterface $repository,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private DestinationZoneRepositoryInterface $destination_zone_repository,
		private LogisticsProfileRepositoryInterface $logistics_profile_repository,
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository,
		private RateCardValidator $validator,
		private AdminRateCardTester $tester,
		private RateQuoteEngine $quote_engine,
		private AdminActionHandler $action_handler,
		private ConfigurationAuditLogger $audit_logger,
		private AdminRecordDependencyChecker $dependency_checker
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_SAVE, self::ACTION_SAVE, 'manage_delivery_rate_cards', self::SLUG ) ) {
			$this->handle_save();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DEACTIVATE, self::ACTION_DEACTIVATE, 'manage_delivery_rate_cards', self::SLUG ) ) {
			$this->handle_deactivate();
		}

		if ( $this->action_handler->verify_post( self::ACTION_DELETE, self::ACTION_DELETE, 'manage_delivery_rate_cards', self::SLUG ) ) {
			$this->handle_delete();
		}

		if ( $this->action_handler->verify_post( self::ACTION_TEST, self::ACTION_TEST, 'manage_delivery_rate_cards', self::SLUG ) ) {
			$this->handle_test();
		}

		if ( $this->action_handler->verify_post( self::ACTION_QUOTE_TEST, self::ACTION_QUOTE_TEST, 'manage_delivery_rate_cards', self::SLUG ) ) {
			$this->handle_quote_test();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_rate_cards' );

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
			__( 'Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
			__( 'Set how much customers pay for delivery.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Add Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
				'url'   => add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
				'class' => 'primary',
			]
		);
		AdminPageLayout::render_example(
			StoreAwareExamples::charge_list_example()
		);

		$records = $this->repository->list( [ 'limit' => 500 ] );
		$active  = 0;
		$inactive = 0;

		foreach ( $records as $record ) {
			if ( RecordStatus::Active->value === (string) ( $record['status'] ?? '' ) ) {
				++$active;
			} else {
				++$inactive;
			}
		}

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Total delivery charges', 'cetech-woocommerce-delivery-engine' ),
					'value' => count( $records ),
					'empty' => [] === $records,
				],
				[
					'label' => __( 'Active', 'cetech-woocommerce-delivery-engine' ),
					'value' => $active,
					'empty' => 0 === $active,
				],
				[
					'label' => __( 'Inactive', 'cetech-woocommerce-delivery-engine' ),
					'value' => $inactive,
					'empty' => 0 === $inactive,
				],
			]
		);

		if ( [] === $records ) {
			AdminPageLayout::render_empty_state(
				__( 'No Delivery Charges have been created yet.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Delivery charges are linked to delivery areas and delivery options. Customers see the applicable charge at checkout based on their address and selected delivery option.', 'cetech-woocommerce-delivery-engine' ),
				__( 'Add Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
				add_query_arg( [ 'page' => self::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
			);
		} else {
			AdminPageLayout::open_section(
				__( 'All delivery charges', 'cetech-woocommerce-delivery-engine' ),
				__( 'Each row shows where a delivery service applies and how much it costs.', 'cetech-woocommerce-delivery-engine' )
			);

			$lookups = $this->build_lookups();
			$rows    = [];

			foreach ( $records as $record ) {
				$id = (int) ( $record['id'] ?? 0 );
				$zone_label  = $this->lookup_zone_label( $lookups['zones'], (int) ( $record['destination_zone_id'] ?? 0 ) );
				$offer_label = $this->lookup_offer_label( $lookups['offers'], (int) ( $record['delivery_offer_id'] ?? 0 ) );
				$charge_name = $this->human_charge_name( $zone_label, $offer_label );
				if ( '' === $charge_name ) {
					$charge_name = (string) ( $record['internal_code'] ?? '' );
				}
				$rows[] = [
					'<strong>' . esc_html( $charge_name ) . '</strong>',
					esc_html( $this->charge_how_label( (string) ( $record['charge_type'] ?? '' ) ) ),
					esc_html( $zone_label . ' / ' . $offer_label ),
					esc_html( AdminUiHelper::format_money( (string) ( $record['base_amount'] ?? '' ), (string) ( $record['base_currency'] ?? '' ) ) ),
					AdminUiHelper::record_status_badge( (string) ( $record['status'] ?? '' ) ),
					$this->render_actions( $id ),
				];
			}

			AdminPageRenderer::render_table(
				[
					__( 'Charge Name', 'cetech-woocommerce-delivery-engine' ),
					__( 'How it is calculated', 'cetech-woocommerce-delivery-engine' ),
					__( 'Area / Option', 'cetech-woocommerce-delivery-engine' ),
					__( 'Amount', 'cetech-woocommerce-delivery-engine' ),
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

	private function render_test_tool(): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG . '_test' );

		echo '<h3>' . esc_html__( 'Preview a rate card match', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Check which stored rate card would apply for a zone, offer, and quantity. This is read-only and does not change checkout or customer prices.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_TEST );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_TEST ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		AdminFormHelper::select_field(
			'test_delivery_offer_id',
			__( 'Delivery offer', 'cetech-woocommerce-delivery-engine' ),
			$this->required_select_options( $this->delivery_offer_options() ),
			(string) ( $draft['test_delivery_offer_id'] ?? '' )
		);
		AdminFormHelper::select_field(
			'test_destination_zone_id',
			__( 'Destination zone', 'cetech-woocommerce-delivery-engine' ),
			$this->required_select_options( $this->destination_zone_options() ),
			(string) ( $draft['test_destination_zone_id'] ?? '' )
		);
		AdminFormHelper::select_field(
			'test_logistics_profile_id',
			__( 'Logistics profile', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->logistics_profile_options() ),
			(string) ( $draft['test_logistics_profile_id'] ?? '' ),
			__( 'Optional. Leave blank for wildcard.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'test_supplier_id',
			__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->supplier_options() ),
			(string) ( $draft['test_supplier_id'] ?? '' ),
			__( 'Optional. Leave blank for wildcard.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'test_origin_id',
			__( 'Origin', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->origin_options() ),
			(string) ( $draft['test_origin_id'] ?? '' ),
			__( 'Optional. Leave blank for wildcard.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'test_quantity',
			__( 'Quantity', 'cetech-woocommerce-delivery-engine' ),
			isset( $draft['test_quantity'] ) ? (int) $draft['test_quantity'] : 1,
			1
		);
		AdminFormHelper::text_field(
			'test_currency_code',
			__( 'Currency code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['test_currency_code'] ?? '' ),
			true,
			__( '3-letter ISO code.', 'cetech-woocommerce-delivery-engine' )
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

	private function render_quote_test_tool(): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG . '_quote_test' );

		echo '<h3>' . esc_html__( 'Check a delivery price', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Use this support tool to preview the delivery fee the engine would calculate. This check does not change the cart, checkout, or orders.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_QUOTE_TEST );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_QUOTE_TEST ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		AdminFormHelper::select_field(
			'quote_delivery_offer_id',
			__( 'Delivery offer', 'cetech-woocommerce-delivery-engine' ),
			$this->required_select_options( $this->delivery_offer_options() ),
			(string) ( $draft['quote_delivery_offer_id'] ?? '' )
		);
		AdminFormHelper::select_field(
			'quote_destination_zone_id',
			__( 'Destination zone', 'cetech-woocommerce-delivery-engine' ),
			$this->required_select_options( $this->destination_zone_options() ),
			(string) ( $draft['quote_destination_zone_id'] ?? '' )
		);
		AdminFormHelper::select_field(
			'quote_logistics_profile_id',
			__( 'Logistics profile', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->logistics_profile_options() ),
			(string) ( $draft['quote_logistics_profile_id'] ?? '' ),
			__( 'Optional. Leave blank for wildcard.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'quote_supplier_id',
			__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->supplier_options() ),
			(string) ( $draft['quote_supplier_id'] ?? '' ),
			__( 'Optional. Leave blank for wildcard.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'quote_origin_id',
			__( 'Origin', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->origin_options() ),
			(string) ( $draft['quote_origin_id'] ?? '' ),
			__( 'Optional. Leave blank for wildcard.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'quote_quantity',
			__( 'Quantity', 'cetech-woocommerce-delivery-engine' ),
			isset( $draft['quote_quantity'] ) ? (int) $draft['quote_quantity'] : 1,
			1
		);
		AdminFormHelper::text_field(
			'quote_currency_code',
			__( 'Currency code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $draft['quote_currency_code'] ?? '' ),
			true,
			__( '3-letter ISO code.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'quote_product_id',
			__( 'Product ID (optional)', 'cetech-woocommerce-delivery-engine' ),
			isset( $draft['quote_product_id'] ) ? (int) $draft['quote_product_id'] : 0,
			0
		);
		echo '</tbody></table>';
		submit_button( __( 'Check delivery price', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( is_array( $draft ) && isset( $draft['quote_result'] ) ) {
			echo '<p><strong>' . esc_html__( 'Quote result:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
			echo esc_html( (string) $draft['quote_result'] );
			echo '</p>';
		}
	}

	private function render_form( bool $is_edit ): void {
		$draft = $this->action_handler->notices()->consume_form_draft( self::SLUG );

		if ( null !== $draft ) {
			$record = $this->form_record_from_draft( $draft );
		} else {
			$record = $this->load_record_for_form( $is_edit );
		}

		$title = $is_edit
			? __( 'Edit Delivery Charge', 'cetech-woocommerce-delivery-engine' )
			: __( 'Add Delivery Charge', 'cetech-woocommerce-delivery-engine' );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			$title,
			$is_edit
				? __( 'Update how much customers pay for this delivery area and option.', 'cetech-woocommerce-delivery-engine' )
				: __( 'Set how much customers pay by choosing an area, a delivery option, and a charge method.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Back to Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
				'url'   => AdminPageRenderer::list_url( self::SLUG ),
				'class' => 'secondary',
			]
		);
		AdminPageLayout::render_example(
			StoreAwareExamples::charge_editor_name_example()
		);

		echo '<form method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		if ( $is_edit && ! empty( $record['id'] ) ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record['id'] ) . '" />';
		}

		AdminPageLayout::open_form_panel(
			__( 'How should this delivery charge work?', 'cetech-woocommerce-delivery-engine' ),
			__( 'Choose a simple method first. Advanced pricing rules can be refined later if needed.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<fieldset class="cetech-de-choice-fieldset"><legend class="screen-reader-text">' . esc_html__( 'How should this delivery charge work?', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		$selected_charge = (string) ( $record['charge_type'] ?? RateCardChargeType::FixedPerShipment->value );
		foreach ( $this->charge_type_options() as $value => $label ) {
			echo '<p><label><input type="radio" name="charge_type" value="' . esc_attr( $value ) . '" ' . checked( $selected_charge, $value, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset>';
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Delivery area and option', 'cetech-woocommerce-delivery-engine' ),
			__( 'Choose where delivery applies and which service this price is for.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'destination_zone_id',
			__( 'Delivery area', 'cetech-woocommerce-delivery-engine' ),
			$this->required_select_options( $this->destination_zone_options() ),
			(string) ( $record['destination_zone_id'] ?? '' ),
			StoreAwareExamples::area_help(),
			true
		);
		AdminFormHelper::select_field(
			'delivery_offer_id',
			__( 'Delivery option', 'cetech-woocommerce-delivery-engine' ),
			$this->required_select_options( $this->delivery_offer_options() ),
			(string) ( $record['delivery_offer_id'] ?? '' ),
			__( 'The delivery service this price belongs to, such as Same-Day or Standard Delivery.', 'cetech-woocommerce-delivery-engine' ),
			true
		);
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_form_panel(
			__( 'Delivery fee', 'cetech-woocommerce-delivery-engine' ),
			__( 'The amount customers pay when this area and option match at checkout.', 'cetech-woocommerce-delivery-engine' )
		);
		$store_currency = $this->store_currency();
		$currency       = strtoupper( trim( (string) ( $record['currency_code'] ?? '' ) ) );
		if ( '' === $currency ) {
			$currency = $store_currency;
		}
		echo '<tr><th scope="row">' . esc_html__( 'Delivery fee', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
		echo '<span class="cetech-de-currency-prefix">' . esc_html( $store_currency ) . '</span> ';
		echo '<input type="text" class="regular-text" id="base_amount" name="base_amount" value="' . esc_attr( AdminMoneyFormatter::input_amount( (string) ( $record['base_amount'] ?? '' ) ) ) . '" required />';
		echo '<input type="hidden" name="currency_code" value="' . esc_attr( $store_currency ) . '" />';
		echo '<p class="description">' . esc_html__( 'Currency: ', 'cetech-woocommerce-delivery-engine' ) . esc_html( $store_currency ) . ' — ' . esc_html__( 'From WooCommerce', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</td></tr>';
		AdminPageLayout::close_form_panel();

		AdminPageLayout::open_advanced( __( 'Advanced Details', 'cetech-woocommerce-delivery-engine' ) );
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		AdminFormHelper::text_field(
			'code',
			__( 'Reference code', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['code'] ?? '' ),
			false,
			__( 'Optional. Leave blank to generate one automatically. Use lowercase letters, numbers, or dashes.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'logistics_profile_id',
			__( 'Logistics profile', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->logistics_profile_options() ),
			(string) ( $record['logistics_profile_id'] ?? '' ),
			__( 'Optional. Leave blank to apply this price regardless of logistics profile.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'supplier_id',
			__( 'Supplier', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->supplier_options() ),
			(string) ( $record['supplier_id'] ?? '' ),
			__( 'Optional. Limit this price to orders from a specific supplier.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'origin_id',
			__( 'Origin', 'cetech-woocommerce-delivery-engine' ),
			$this->optional_select_options( $this->origin_options() ),
			(string) ( $record['origin_id'] ?? '' ),
			__( 'Optional. Must belong to the selected supplier when both are set.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::number_field(
			'priority',
			__( 'Priority', 'cetech-woocommerce-delivery-engine' ),
			isset( $record['priority'] ) ? (int) $record['priority'] : 100,
			0,
			__( 'Priority decides which rate card takes precedence if more than one card could apply. A lower number is considered first. Most rate cards can leave this unchanged (default 100).', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'effective_from',
			__( 'Effective from', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['effective_from'] ?? '' ),
			false,
			__( 'Optional start date and time in UTC. Example: 2026-01-01 00:00:00', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::text_field(
			'effective_to',
			__( 'Effective to', 'cetech-woocommerce-delivery-engine' ),
			(string) ( $record['effective_to'] ?? '' ),
			false,
			__( 'Optional end date and time in UTC.', 'cetech-woocommerce-delivery-engine' )
		);
		AdminFormHelper::select_field(
			'status',
			__( 'Status', 'cetech-woocommerce-delivery-engine' ),
			$this->friendly_status_options(),
			(string) ( $record['status'] ?? RecordStatus::Active->value ),
			__( 'Inactive delivery charges are kept for reference but are not used at checkout.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '</tbody></table>';
		AdminPageLayout::close_advanced();

		echo '<div class="cetech-de-form-actions">';
		submit_button( $is_edit ? __( 'Save Delivery Charge', 'cetech-woocommerce-delivery-engine' ) : __( 'Create Delivery Charge', 'cetech-woocommerce-delivery-engine' ) );
		echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( self::SLUG ) ) . '">' . esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</div></form>';

		if ( $is_edit && isset( $record['id'] ) && (int) $record['id'] > 0 ) {
			AdminPermanentDeleteFlow::render_edit_danger_zone(
				self::SLUG,
				(int) $record['id'],
				self::ACTION_DELETE,
				'manage_delivery_rate_cards'
			);
		}

		AdminPageLayout::close_page();
	}

	private function handle_save(): void {
		$input = $this->read_form_input();
		if ( '' === AdminFormHelper::sanitize_code( (string) ( $input['code'] ?? '' ) ) ) {
			$input['code'] = $this->generate_charge_code( $input );
		}
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
			$this->action_handler->notices()->flash_error( __( 'A rate card with this code already exists.', 'cetech-woocommerce-delivery-engine' ) );
			$this->redirect_to_form( $input );
		}

		$previous = $id > 0 ? $this->repository->findById( $id ) : null;

		$payload = [
			'id'                   => $id,
			'internal_code'        => $code,
			'delivery_offer_id'    => (int) $input['delivery_offer_id'],
			'destination_zone_id'  => (int) $input['destination_zone_id'],
			'logistics_profile_id' => $this->nullable_int( $input['logistics_profile_id'] ?? null ),
			'supplier_id'          => $this->nullable_int( $input['supplier_id'] ?? null ),
			'origin_id'            => $this->nullable_int( $input['origin_id'] ?? null ),
			'charge_type'          => (string) $input['charge_type'],
			'base_amount'          => trim( (string) $input['base_amount'] ),
			'base_currency'        => strtoupper( trim( (string) ( $input['currency_code'] ?? '' ) ) ) ?: $this->store_currency(),
			'priority'             => (int) $input['priority'],
			'effective_from'       => trim( (string) ( $input['effective_from'] ?? '' ) ),
			'effective_to'         => trim( (string) ( $input['effective_to'] ?? '' ) ),
			'status'               => (string) $input['status'],
		];

		$saved_id = $this->repository->save( $payload );

		if ( $saved_id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to save rate card.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			$id > 0 ? 'updated' : 'created',
			'rate_card',
			$saved_id,
			$previous,
			$this->repository->findById( $saved_id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success(
				$id > 0
					? __( 'Rate card updated.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Rate card created.', 'cetech-woocommerce-delivery-engine' )
			);
		} else {
			$this->action_handler->notices()->flash_warning(
				$id > 0
					? __( 'Rate card updated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Rate card created, but audit logging failed.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_deactivate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid rate card.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Rate card not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$was_already_inactive = RecordStatus::Inactive->value === (string) ( $previous['status'] ?? '' );

		if ( ! $this->repository->softDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to deactivate rate card.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( $was_already_inactive ) {
			$this->action_handler->notices()->flash_success( __( 'Rate card is already inactive.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log(
			'deactivated',
			'rate_card',
			$id,
			$previous,
			$this->repository->findById( $id )
		);

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Rate card deactivated.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Rate card deactivated, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id <= 0 ) {
			$this->action_handler->notices()->flash_error( __( 'Invalid rate card.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$previous = $this->repository->findById( $id );

		if ( null === $previous ) {
			$this->action_handler->notices()->flash_error( __( 'Rate card not found.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$dependencies = $this->dependency_checker->check_rate_card( $id );

		if ( ! $dependencies->can_delete ) {
			$this->action_handler->notices()->flash_error( implode( ' ', $dependencies->blocking_reasons ) );
			$this->action_handler->redirect( self::SLUG );
		}

		if ( ! $this->repository->hardDelete( $id ) ) {
			$this->action_handler->notices()->flash_error( __( 'Unable to delete rate card.', 'cetech-woocommerce-delivery-engine' ) );
			$this->action_handler->redirect( self::SLUG );
		}

		$audit_logged = $this->audit_logger->log( 'deleted', 'rate_card', $id, $previous, null );

		if ( $audit_logged ) {
			$this->action_handler->notices()->flash_success( __( 'Rate card permanently deleted.', 'cetech-woocommerce-delivery-engine' ) );
		} else {
			$this->action_handler->notices()->flash_warning( __( 'Rate card deleted, but audit logging failed.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$this->action_handler->redirect( self::SLUG );
	}

	private function render_delete_confirmation(): void {
		AdminPageAccess::require_capability( 'manage_delivery_rate_cards' );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid delete request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$record = $this->repository->findById( $id );

		if ( null === $record ) {
			wp_die( esc_html__( 'Rate card not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		AdminPermanentDeleteFlow::render_confirmation_screen(
			self::SLUG,
			self::ACTION_DELETE,
			self::ACTION_DEACTIVATE,
			'manage_delivery_rate_cards',
			__( 'Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
			$id,
			(string) ( $record['internal_code'] ?? '' ),
			(string) ( $record['internal_code'] ?? '' ),
			$this->dependency_checker->check_rate_card( $id )
		);
	}

	private function handle_test(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = [
			'test_delivery_offer_id'    => isset( $_POST['test_delivery_offer_id'] ) ? (int) $_POST['test_delivery_offer_id'] : 0,
			'test_destination_zone_id'  => isset( $_POST['test_destination_zone_id'] ) ? (int) $_POST['test_destination_zone_id'] : 0,
			'test_logistics_profile_id' => isset( $_POST['test_logistics_profile_id'] ) ? (int) $_POST['test_logistics_profile_id'] : 0,
			'test_supplier_id'        => isset( $_POST['test_supplier_id'] ) ? (int) $_POST['test_supplier_id'] : 0,
			'test_origin_id'          => isset( $_POST['test_origin_id'] ) ? (int) $_POST['test_origin_id'] : 0,
			'test_quantity'           => isset( $_POST['test_quantity'] ) ? (int) $_POST['test_quantity'] : 0,
			'test_currency_code'      => isset( $_POST['test_currency_code'] ) ? wp_unslash( (string) $_POST['test_currency_code'] ) : '',
		];

		$errors = $this->validator->validate_test_input( $input );

		if ( [] !== $errors ) {
			$input['test_result'] = implode( ' ', $errors );
			$this->action_handler->notices()->stash_form_draft( self::SLUG . '_test', $input );
			$this->action_handler->redirect( self::SLUG );
		}

		$result = $this->tester->test(
			$input['test_delivery_offer_id'],
			$input['test_destination_zone_id'],
			$input['test_logistics_profile_id'] > 0 ? $input['test_logistics_profile_id'] : null,
			$input['test_supplier_id'] > 0 ? $input['test_supplier_id'] : null,
			$input['test_origin_id'] > 0 ? $input['test_origin_id'] : null,
			$input['test_quantity'],
			strtoupper( trim( $input['test_currency_code'] ) )
		);

		if ( ! $result['matched'] ) {
			$input['test_result'] = (string) $result['explanation'];
		} else {
			$input['test_result'] = sprintf(
				/* translators: 1: rate card code, 2: charge type, 3: amount, 4: currency, 5: explanation */
				__( 'Matched: %1$s | Charge: %2$s | Amount: %3$s %4$s | %5$s', 'cetech-woocommerce-delivery-engine' ),
				(string) ( $result['rate_card_code'] ?? '' ),
				(string) ( $result['charge_type'] ?? '' ),
				(string) ( $result['amount'] ?? '' ),
				(string) ( $result['currency'] ?? '' ),
				(string) ( $result['explanation'] ?? '' )
			);
		}

		$this->action_handler->notices()->stash_form_draft( self::SLUG . '_test', $input );
		$this->action_handler->redirect( self::SLUG );
	}

	private function handle_quote_test(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = [
			'quote_delivery_offer_id'    => isset( $_POST['quote_delivery_offer_id'] ) ? (int) $_POST['quote_delivery_offer_id'] : 0,
			'quote_destination_zone_id'  => isset( $_POST['quote_destination_zone_id'] ) ? (int) $_POST['quote_destination_zone_id'] : 0,
			'quote_logistics_profile_id' => isset( $_POST['quote_logistics_profile_id'] ) ? (int) $_POST['quote_logistics_profile_id'] : 0,
			'quote_supplier_id'          => isset( $_POST['quote_supplier_id'] ) ? (int) $_POST['quote_supplier_id'] : 0,
			'quote_origin_id'            => isset( $_POST['quote_origin_id'] ) ? (int) $_POST['quote_origin_id'] : 0,
			'quote_quantity'             => isset( $_POST['quote_quantity'] ) ? (int) $_POST['quote_quantity'] : 0,
			'quote_currency_code'        => isset( $_POST['quote_currency_code'] ) ? wp_unslash( (string) $_POST['quote_currency_code'] ) : '',
			'quote_product_id'           => isset( $_POST['quote_product_id'] ) ? (int) $_POST['quote_product_id'] : 0,
		];

		$errors = $this->validator->validate_quote_test_input( $input );

		if ( [] !== $errors ) {
			$input['quote_result'] = implode( ' ', $errors );
			$this->action_handler->notices()->stash_form_draft( self::SLUG . '_quote_test', $input );
			$this->action_handler->redirect( self::SLUG );
		}

		try {
			$request = RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'    => $input['quote_delivery_offer_id'],
					'destination_zone_id'  => $input['quote_destination_zone_id'],
					'quantity'             => $input['quote_quantity'],
					'currency_code'        => strtoupper( trim( $input['quote_currency_code'] ) ),
					'logistics_profile_id' => $input['quote_logistics_profile_id'] > 0 ? $input['quote_logistics_profile_id'] : null,
					'supplier_id'          => $input['quote_supplier_id'] > 0 ? $input['quote_supplier_id'] : null,
					'origin_id'            => $input['quote_origin_id'] > 0 ? $input['quote_origin_id'] : null,
					'product_id'           => $input['quote_product_id'] > 0 ? $input['quote_product_id'] : null,
				]
			);
		} catch ( \InvalidArgumentException $exception ) {
			$input['quote_result'] = $exception->getMessage();
			$this->action_handler->notices()->stash_form_draft( self::SLUG . '_quote_test', $input );
			$this->action_handler->redirect( self::SLUG );
		}

		$result = $this->quote_engine->quote( $request );

		if ( ! $result->success ) {
			$input['quote_result'] = (string) $result->message;

			if ( null !== $result->error_code ) {
				$input['quote_result'] .= ' [' . $result->error_code . ']';
			}
		} else {
			$input['quote_result'] = sprintf(
				/* translators: 1: rate card code, 2: rate card ID, 3: charge type, 4: amount, 5: currency, 6: explanation */
				__( 'Success | Matched: %1$s (ID %2$d) | Charge: %3$s | Amount: %4$s %5$s | %6$s', 'cetech-woocommerce-delivery-engine' ),
				(string) ( $result->matched_rate_card_code ?? '' ),
				(int) ( $result->matched_rate_card_id ?? 0 ),
				(string) ( $result->charge_type ?? '' ),
				(string) ( $result->amount?->amount() ?? '' ),
				(string) ( $result->amount?->currency()->value() ?? '' ),
				(string) $result->message
			);
		}

		$this->action_handler->notices()->stash_form_draft( self::SLUG . '_quote_test', $input );
		$this->action_handler->redirect( self::SLUG );
	}

	private function render_actions( int $id ): string {
		$edit_url = AdminPageRenderer::edit_url( self::SLUG, $id );

		ob_start();
		echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'cetech-woocommerce-delivery-engine' ) . '</a> | ';
		echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Deactivate this delivery charge?', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
		AdminFormHelper::nonce_field( self::ACTION_DEACTIVATE );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_DEACTIVATE ) . '" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
		submit_button( __( 'Deactivate', 'cetech-woocommerce-delivery-engine' ), 'link-delete', 'submit', false );
		echo '</form>';
		echo AdminPermanentDeleteFlow::list_delete_link(
			self::SLUG,
			$id,
			self::ACTION_DELETE,
			'manage_delivery_rate_cards'
		);

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load_record_for_form( bool $is_edit ): ?array {
		if ( ! $is_edit ) {
			return [
				'priority'      => 100,
				'charge_type'   => RateCardChargeType::FixedPerShipment->value,
				'status'        => RecordStatus::Active->value,
			];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid edit request.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$row = $this->repository->findById( $id );

		if ( null === $row ) {
			wp_die( esc_html__( 'Rate card not found.', 'cetech-woocommerce-delivery-engine' ) );
		}

		return $this->map_row_to_form( $row );
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
			'delivery_offer_id'    => (string) ( $draft['delivery_offer_id'] ?? '' ),
			'destination_zone_id'  => (string) ( $draft['destination_zone_id'] ?? '' ),
			'logistics_profile_id' => (string) ( $draft['logistics_profile_id'] ?? '' ),
			'supplier_id'          => (string) ( $draft['supplier_id'] ?? '' ),
			'origin_id'            => (string) ( $draft['origin_id'] ?? '' ),
			'charge_type'          => (string) ( $draft['charge_type'] ?? '' ),
			'base_amount'          => (string) ( $draft['base_amount'] ?? '' ),
			'currency_code'        => (string) ( $draft['currency_code'] ?? '' ),
			'priority'             => isset( $draft['priority'] ) ? (int) $draft['priority'] : 100,
			'effective_from'       => (string) ( $draft['effective_from'] ?? '' ),
			'effective_to'         => (string) ( $draft['effective_to'] ?? '' ),
			'status'               => (string) ( $draft['status'] ?? RecordStatus::Active->value ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function map_row_to_form( array $row ): array {
		return [
			'id'                   => (int) ( $row['id'] ?? 0 ),
			'code'                 => (string) ( $row['internal_code'] ?? '' ),
			'delivery_offer_id'    => (string) ( $row['delivery_offer_id'] ?? '' ),
			'destination_zone_id'  => (string) ( $row['destination_zone_id'] ?? '' ),
			'logistics_profile_id' => (string) ( $row['logistics_profile_id'] ?? '' ),
			'supplier_id'          => (string) ( $row['supplier_id'] ?? '' ),
			'origin_id'            => (string) ( $row['origin_id'] ?? '' ),
			'charge_type'          => (string) ( $row['charge_type'] ?? '' ),
			'base_amount'          => (string) ( $row['base_amount'] ?? '' ),
			'currency_code'        => (string) ( $row['base_currency'] ?? '' ),
			'priority'             => (int) ( $row['priority'] ?? 100 ),
			'effective_from'       => (string) ( $row['effective_from'] ?? '' ),
			'effective_to'         => (string) ( $row['effective_to'] ?? '' ),
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
			'delivery_offer_id'    => isset( $_POST['delivery_offer_id'] ) ? (int) $_POST['delivery_offer_id'] : 0,
			'destination_zone_id'  => isset( $_POST['destination_zone_id'] ) ? (int) $_POST['destination_zone_id'] : 0,
			'logistics_profile_id' => isset( $_POST['logistics_profile_id'] ) ? (int) $_POST['logistics_profile_id'] : 0,
			'supplier_id'          => isset( $_POST['supplier_id'] ) ? (int) $_POST['supplier_id'] : 0,
			'origin_id'            => isset( $_POST['origin_id'] ) ? (int) $_POST['origin_id'] : 0,
			'charge_type'          => isset( $_POST['charge_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['charge_type'] ) ) : '',
			'base_amount'          => isset( $_POST['base_amount'] ) ? wp_unslash( (string) $_POST['base_amount'] ) : '',
			'currency_code'        => isset( $_POST['currency_code'] ) && '' !== trim( (string) wp_unslash( $_POST['currency_code'] ) )
				? wp_unslash( (string) $_POST['currency_code'] )
				: $this->store_currency(),
			'priority'             => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 100,
			'effective_from'       => isset( $_POST['effective_from'] ) ? wp_unslash( (string) $_POST['effective_from'] ) : '',
			'effective_to'         => isset( $_POST['effective_to'] ) ? wp_unslash( (string) $_POST['effective_to'] ) : '',
			'status'               => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '',
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
	 * @return array<string, string>
	 */
	private function delivery_offer_options(): array {
		$options = [];

		foreach ( $this->delivery_offer_repository->list( [ 'limit' => 500 ] ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$label = trim( (string) ( $row['public_label'] ?? $row['internal_name'] ?? '' ) );
			$options[ (string) $id ] = '' !== $label ? $label : (string) ( $row['internal_code'] ?? $id );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function destination_zone_options(): array {
		$options = [];

		foreach ( $this->destination_zone_repository->list( [ 'limit' => 500 ] ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$label = trim( (string) ( $row['public_label'] ?? $row['internal_name'] ?? '' ) );
			$options[ (string) $id ] = '' !== $label ? $label : (string) ( $row['internal_code'] ?? $id );
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
	 * @return array<string, string>
	 */
	private function charge_type_options(): array {
		return [
			RateCardChargeType::FixedPerShipment->value => __( 'Flat amount per delivery', 'cetech-woocommerce-delivery-engine' ),
			RateCardChargeType::FixedPerItem->value => __( 'Amount per item', 'cetech-woocommerce-delivery-engine' ),
		];
	}

	private function charge_how_label( string $type ): string {
		$options = $this->charge_type_options();

		return $options[ $type ] ?? __( 'Advanced pricing rules', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function generate_charge_code( array $input ): string {
		$parts = [
			'dc',
			(string) ( (int) ( $input['destination_zone_id'] ?? 0 ) ),
			(string) ( (int) ( $input['delivery_offer_id'] ?? 0 ) ),
			(string) ( $input['charge_type'] ?? RateCardChargeType::FixedPerShipment->value ),
		];
		$code = AdminFormHelper::sanitize_code( implode( '-', $parts ) );
		if ( '' === $code ) {
			$code = 'delivery-charge-' . (string) time();
		}

		$existing = $this->repository->findByCode( $code );
		if ( null !== $existing && (int) ( $existing['id'] ?? 0 ) !== (int) ( $input['id'] ?? 0 ) ) {
			$code .= '-' . (string) time();
		}

		return $code;
	}

	/**
	 * @return array<string, string>
	 */
	private function friendly_status_options(): array {
		$options = [];

		foreach ( RecordStatus::cases() as $case ) {
			$options[ $case->value ] = AdminUiHelper::record_status_label( $case->value );
		}

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function status_options(): array {
		$options = [];

		foreach ( RecordStatus::cases() as $case ) {
			$options[ $case->value ] = $case->value;
		}

		return $options;
	}

	/**
	 * @param array<string, string> $options
	 *
	 * @return array<string, string>
	 */
	private function required_select_options( array $options ): array {
		return [ '' => __( '— Select —', 'cetech-woocommerce-delivery-engine' ) ] + $options;
	}

	/**
	 * @param array<string, string> $options
	 *
	 * @return array<string, string>
	 */
	private function optional_select_options( array $options ): array {
		return [ '' => __( '— None —', 'cetech-woocommerce-delivery-engine' ) ] + $options;
	}

	/**
	 * @return array{
	 *     offers: array<int, array<string, mixed>>,
	 *     zones: array<int, array<string, mixed>>,
	 *     profiles: array<int, array<string, mixed>>,
	 *     suppliers: array<int, array<string, mixed>>,
	 *     origins: array<int, array<string, mixed>>
	 * }
	 */
	private function build_lookups(): array {
		return [
			'offers'    => $this->index_by_id( $this->delivery_offer_repository->list( [ 'limit' => 500 ] ) ),
			'zones'     => $this->index_by_id( $this->destination_zone_repository->list( [ 'limit' => 500 ] ) ),
			'profiles'  => $this->index_by_id( $this->logistics_profile_repository->list( [ 'limit' => 500 ] ) ),
			'suppliers' => $this->index_by_id( $this->supplier_repository->list( [ 'limit' => 500 ] ) ),
			'origins'   => $this->index_by_id( $this->origin_repository->list( [ 'limit' => 500 ] ) ),
		];
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
	 * @param array<int, array<string, mixed>> $lookup
	 */
	private function lookup_offer_label( array $lookup, int $id ): string {
		if ( $id <= 0 || ! isset( $lookup[ $id ] ) ) {
			return '—';
		}

		$label = trim( (string) ( $lookup[ $id ]['public_label'] ?? '' ) );

		return '' !== $label ? $label : (string) ( $lookup[ $id ]['internal_name'] ?? '—' );
	}

	/**
	 * @param array<int, array<string, mixed>> $lookup
	 */
	private function lookup_zone_label( array $lookup, int $id ): string {
		if ( $id <= 0 || ! isset( $lookup[ $id ] ) ) {
			return '—';
		}

		$label = trim( (string) ( $lookup[ $id ]['public_label'] ?? $lookup[ $id ]['internal_name'] ?? '' ) );

		return '' !== $label ? $label : '—';
	}

	private function human_charge_name( string $area, string $option ): string {
		if ( '—' === $area && '—' === $option ) {
			return '';
		}
		if ( '—' === $area || '' === $area ) {
			return $option;
		}
		if ( '—' === $option || '' === $option ) {
			return $area;
		}

		return $area . ' — ' . $option;
	}

	private function store_currency(): string {
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtoupper( (string) get_woocommerce_currency() );
			if ( '' !== $currency ) {
				return $currency;
			}
		}

		return 'USD';
	}

	private function lookup_optional( array $lookup, mixed $id ): string {
		if ( null === $id || '' === $id ) {
			return '—';
		}

		$int_id = (int) $id;

		if ( $int_id <= 0 || ! isset( $lookup[ $int_id ] ) ) {
			return '—';
		}

		return sprintf(
			'%s (%s)',
			(string) ( $lookup[ $int_id ]['internal_code'] ?? '' ),
			(string) ( $lookup[ $int_id ]['internal_name'] ?? '' )
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
