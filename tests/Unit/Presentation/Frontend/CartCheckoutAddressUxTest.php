<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextEditorService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Frontend\CartCustomerContextEditorRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CartExternalFormBuffer;
use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CartCheckoutAddressUxTest extends TestCase {

	private CartCustomerContextEditorRenderer $renderer;

	private CheckoutAddressPolicy $policy;

	protected function setUp(): void {
		$capture         = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$this->renderer  = new CartCustomerContextEditorRenderer( new FeatureFlags(), new Requirements(), $capture );
		$this->policy    = ( new ReflectionClass( CheckoutAddressPolicy::class ) )->newInstanceWithoutConstructor();
		CartExternalFormBuffer::reset();
	}

	protected function tearDown(): void {
		CartExternalFormBuffer::reset();
	}

	public function test_incomplete_delivery_line_shows_address_needed_and_add_delivery_address(): void {
		$html = $this->render_line( 'line-incomplete', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );

		self::assertStringContainsString( CustomerStorefrontCopy::address_needed(), $html );
		self::assertStringContainsString( CustomerStorefrontCopy::add_delivery_address(), $html );
		self::assertStringNotContainsString( '>' . CustomerStorefrontCopy::edit_delivery_details() . '<', $html );
		self::assertStringContainsString( 'data-cetech-de-address-complete="0"', $html );
		self::assertStringContainsString( 'data-cetech-de-address-required="1"', $html );
		self::assertStringContainsString( 'id="' . CartDeliveryUiAnchor::for_cart_item_key( 'line-incomplete' ) . '"', $html );
	}

	public function test_complete_delivery_line_shows_edit_delivery_details_without_address_needed(): void {
		$html = $this->render_line(
			'line-complete',
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) )
		);

		self::assertStringNotContainsString( CustomerStorefrontCopy::address_needed(), $html );
		self::assertStringContainsString( CustomerStorefrontCopy::edit_delivery_details(), $html );
		self::assertStringContainsString( 'data-cetech-de-address-complete="1"', $html );
		self::assertStringContainsString( 'data-cetech-de-address-required="0"', $html );
		self::assertStringContainsString( '12 Boundary Rd', $html );
		self::assertStringContainsString( 'name="cetech_de_company"', $html );
		self::assertStringContainsString( 'CETECH', $html );
		self::assertStringContainsString( '<details class="cetech-de-cart-context__disclosure cetech-de-cart-context__recipient" open', $html );
	}

	public function test_pickup_line_uses_pickup_edit_copy_and_is_not_address_required(): void {
		$html = $this->render_line(
			'line-pickup',
			CustomerCartContext::pickup( 4 ),
			$this->intent( 303, null, 'in_store', FulfilmentChoice::StorePickup->value ),
			[ $this->option( 'in_store:store_pickup:pickup', FulfilmentChoice::StorePickup->value, null, 4 ) ]
		);

		self::assertStringContainsString( CustomerStorefrontCopy::edit_pickup_details(), $html );
		self::assertStringNotContainsString( CustomerStorefrontCopy::address_needed(), $html );
		self::assertStringNotContainsString( CustomerStorefrontCopy::add_delivery_address(), $html );
		self::assertStringContainsString( 'data-cetech-de-address-required="0"', $html );
		self::assertStringContainsString( 'data-cetech-de-fulfilment="store_pickup"', $html );
	}

	public function test_ui_anchor_is_deterministic_and_omits_address_content(): void {
		$key  = 'aaa111bbbb2222cccc3333';
		$html = $this->render_line(
			$key,
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Independence Avenue' ) )
		);
		$anchor = CartDeliveryUiAnchor::for_cart_item_key( $key );

		self::assertStringContainsString( 'id="' . $anchor . '"', $html );
		self::assertDoesNotMatchRegularExpression( '/id="[^"]*Independence/', $html );
		self::assertDoesNotMatchRegularExpression( '/id="[^"]*Ama/', $html );
		self::assertDoesNotMatchRegularExpression( '/id="[^"]*0244000000/', $html );
		self::assertStringNotContainsString( 'matching_identity', $html );
		self::assertStringNotContainsString( 'delivery_location_identity', $html );
		self::assertStringNotContainsString( 'data-group-id', $html );
	}

	public function test_checkout_incomplete_primary_action_is_independent_of_can_apply(): void {
		$contents = [
			'gh'    => $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ) ),
			'accra' => $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) ),
		];
		$summary = $this->policy->summarize_cart( $contents );
		$html    = $this->policy->render_incomplete_actions( $summary );

		self::assertGreaterThan( 0, $summary['incomplete_delivery'] );
		self::assertFalse( $summary['can_apply_checkout_address'] );
		self::assertStringContainsString( CustomerStorefrontCopy::add_delivery_address(), $html );
		self::assertStringNotContainsString( CustomerStorefrontCopy::use_my_checkout_address(), $html );
		self::assertStringContainsString( '#' . $summary['first_incomplete_anchor'], $html );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'gh' ), $summary['first_incomplete_anchor'] );
	}

	public function test_checkout_can_apply_true_keeps_secondary_use_checkout_address(): void {
		$contents = [
			'accra' => $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) ),
		];
		$summary = $this->policy->summarize_cart( $contents );
		$html    = $this->policy->render_incomplete_actions( $summary );

		self::assertTrue( $summary['can_apply_checkout_address'] );
		self::assertStringContainsString( 'cetech-de-checkout-incomplete-address__primary', $html );
		self::assertStringContainsString( CustomerStorefrontCopy::add_delivery_address(), $html );
		self::assertStringContainsString( CustomerStorefrontCopy::use_my_checkout_address(), $html );
		self::assertStringContainsString( 'cetech-de-use-checkout-address__button--secondary', $html );
		self::assertStringContainsString( '#' . CartDeliveryUiAnchor::for_cart_item_key( 'accra' ), $html );
	}

	public function test_single_option_is_compact_and_retains_hidden_display_key(): void {
		$html = $this->render_line( 'one-opt', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );

		self::assertStringContainsString( 'cetech-de-cart-context__method-summary', $html );
		self::assertStringContainsString( 'type="hidden"', $html );
		self::assertStringContainsString( 'name="cetech_de_delivery_option_key"', $html );
		self::assertStringContainsString( 'value="in_warehouse:delivery:10"', $html );
		self::assertStringNotContainsString( '<select', $html );
		self::assertStringContainsString( 'data-cetech-de-required-address="1"', $html );
		self::assertStringContainsString( 'name="cetech_de_address_1"', $html );
	}

	public function test_multiple_options_render_selector_with_selected_value(): void {
		$options = [
			$this->option( 'in_warehouse:delivery:10', FulfilmentChoice::Delivery->value, 10 ),
			$this->option( 'in_warehouse:delivery:11', FulfilmentChoice::Delivery->value, 11 ),
		];
		$html    = $this->render_line(
			'multi-opt',
			PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ),
			$this->intent( 101, 10 ),
			$options
		);

		self::assertStringContainsString( '<select', $html );
		self::assertStringContainsString( 'value="in_warehouse:delivery:10"', $html );
		self::assertStringContainsString( 'selected="selected"', $html );
		self::assertStringContainsString( 'data-cetech-de-choice="delivery"', $html );
	}

	public function test_empty_recipient_disclosure_is_closed_and_quantity_hidden_when_one(): void {
		$html = $this->render_line( 'qty-one', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ), $this->intent( 101, 10 ), null, 1 );

		self::assertStringContainsString( CustomerStorefrontCopy::recipient_details_optional(), $html );
		self::assertStringNotContainsString( 'cetech-de-cart-context__recipient" open', $html );
		self::assertStringNotContainsString( 'data-cetech-de-qty-split', $html );
		self::assertStringContainsString( 'type="button"', $html );
		self::assertStringContainsString( CustomerStorefrontCopy::cancel(), $html );
		self::assertStringContainsString( CustomerStorefrontCopy::save_delivery_details(), $html );
		self::assertStringContainsString( CustomerStorefrontCopy::use_for_all_delivery_items(), $html );
		self::assertStringContainsString( 'name="' . CartCustomerContextEditorService::POST_USE_FOR_ALL . '"', $html );
	}

	public function test_quantity_disclosure_exists_when_quantity_greater_than_one(): void {
		$html = $this->render_line( 'qty-two', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ), $this->intent( 101, 10 ), null, 2 );

		self::assertStringContainsString( 'data-cetech-de-qty-split="1"', $html );
		self::assertStringContainsString( CustomerStorefrontCopy::apply_to_quantity(), $html );
		self::assertStringContainsString( 'Move some quantity', $html );
		self::assertStringContainsString( 'name="' . CartCustomerContextEditorService::POST_APPLY_MODE . '"', $html );
		self::assertStringContainsString( 'name="' . CartCustomerContextEditorService::POST_SPLIT_QTY . '"', $html );
		self::assertStringContainsString( CustomerStorefrontCopy::apply_to_all_n( 2 ), $html );
	}

	public function test_copy_helpers_exist(): void {
		self::assertSame( 'Address needed', CustomerStorefrontCopy::address_needed() );
		self::assertSame( 'Add delivery address', CustomerStorefrontCopy::add_delivery_address() );
		self::assertSame( 'Edit delivery details', CustomerStorefrontCopy::edit_delivery_details() );
		self::assertSame( 'Edit pickup details', CustomerStorefrontCopy::edit_pickup_details() );
		self::assertSame( 'Change destination', CustomerStorefrontCopy::change_destination() );
		self::assertSame( 'Recipient details (optional)', CustomerStorefrontCopy::recipient_details_optional() );
		self::assertSame( 'Apply to quantity', CustomerStorefrontCopy::apply_to_quantity() );
		self::assertSame( 'Save delivery details', CustomerStorefrontCopy::save_delivery_details() );
		self::assertSame( 'Cancel', CustomerStorefrontCopy::cancel() );
	}

	public function test_classic_line_output_has_no_form_and_owns_external_form(): void {
		$html    = $this->render_line( 'line-a', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$form_id = CartDeliveryUiAnchor::form_id_for_cart_item_key( 'line-a' );

		self::assertStringNotContainsString( '<form', $html );
		self::assertStringContainsString( 'data-cetech-de-form-id="' . $form_id . '"', $html );
		self::assertStringContainsString( 'form="' . $form_id . '"', $html );
		self::assertStringContainsString( 'class="button cetech-de-cart-context__save"', $html );
		self::assertMatchesRegularExpression( '/cetech-de-cart-context__save"[^>]*form="' . preg_quote( $form_id, '/' ) . '"/', $html );
		self::assertMatchesRegularExpression( '/cetech-de-cart-context__use-for-all[^>]*form="' . preg_quote( $form_id, '/' ) . '"/', $html );
		self::assertStringContainsString( 'data-cetech-de-has-matching-location="1"', $html );

		$shells = CartExternalFormBuffer::drain();
		self::assertStringContainsString( 'id="' . $form_id . '"', $shells );
		self::assertStringContainsString( 'name="' . CartCustomerContextEditorService::POST_CART_ITEM_KEY . '" value="line-a"', $shells );
		self::assertStringContainsString( 'name="' . CartCustomerContextEditorService::POST_ACTION . '"', $shells );
	}

	public function test_two_cart_lines_do_not_cross_submit(): void {
		$html_a = $this->render_line( 'alpha', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$html_b = $this->render_line( 'beta', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$form_a = CartDeliveryUiAnchor::form_id_for_cart_item_key( 'alpha' );
		$form_b = CartDeliveryUiAnchor::form_id_for_cart_item_key( 'beta' );

		self::assertNotSame( $form_a, $form_b );
		self::assertStringContainsString( 'form="' . $form_a . '"', $html_a );
		self::assertStringNotContainsString( 'form="' . $form_b . '"', $html_a );
		self::assertStringContainsString( 'form="' . $form_b . '"', $html_b );
		self::assertStringNotContainsString( 'form="' . $form_a . '"', $html_b );

		$shells = CartExternalFormBuffer::drain();
		self::assertStringContainsString( 'id="' . $form_a . '"', $shells );
		self::assertStringContainsString( 'id="' . $form_b . '"', $shells );
		preg_match( '/<form id="' . preg_quote( $form_a, '/' ) . '".*?<\/form>/s', $shells, $form_a_html );
		preg_match( '/<form id="' . preg_quote( $form_b, '/' ) . '".*?<\/form>/s', $shells, $form_b_html );
		self::assertNotSame( [], $form_a_html );
		self::assertNotSame( [], $form_b_html );
		self::assertStringContainsString( 'value="alpha"', $form_a_html[0] );
		self::assertStringNotContainsString( 'value="beta"', $form_a_html[0] );
		self::assertStringContainsString( 'value="beta"', $form_b_html[0] );
		self::assertStringNotContainsString( 'value="alpha"', $form_b_html[0] );
	}

	public function test_external_forms_are_flushed_after_woo_cart_form_hook(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Frontend/CartCustomerContextEditorRenderer.php' );
		self::assertStringContainsString( "add_action( 'woocommerce_after_cart'", $src );
		self::assertStringContainsString( 'render_deferred_forms', $src );
		self::assertStringNotContainsString( "add_action( 'woocommerce_after_cart_table'", $src );

		$this->render_line( 'flush-line', PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		self::assertNotSame( [], CartExternalFormBuffer::queued_ids() );
		ob_start();
		$this->renderer->render_deferred_forms();
		$out = (string) ob_get_clean();
		self::assertStringContainsString( 'data-cetech-de-cart-external-forms="1"', $out );
		self::assertStringContainsString( '<form id="' . CartDeliveryUiAnchor::form_id_for_cart_item_key( 'flush-line' ) . '"', $out );
		self::assertSame( [], CartExternalFormBuffer::queued_ids() );
	}

	public function test_checkout_incomplete_actions_use_div_flow_markup(): void {
		$html = $this->policy->render_incomplete_actions(
			[
				'incomplete_delivery'        => 1,
				'can_apply_checkout_address' => true,
				'first_incomplete_anchor'    => CartDeliveryUiAnchor::for_cart_item_key( 'accra' ),
			]
		);

		self::assertStringContainsString( '<div class="cetech-de-checkout-incomplete-address__actions">', $html );
		self::assertStringNotContainsString( '<p class="cetech-de-checkout-incomplete-address__actions">', $html );
		self::assertStringContainsString( CustomerStorefrontCopy::add_delivery_address(), $html );
		self::assertStringContainsString( CustomerStorefrontCopy::use_my_checkout_address(), $html );
	}

	public function test_missing_destination_opens_destination_disclosure(): void {
		$html = $this->render_line( 'no-dest', PerItemContextFixtures::emptyMatchingContext( 10 ) );

		self::assertStringContainsString( 'data-cetech-de-has-matching-location="0"', $html );
		self::assertStringContainsString( 'cetech-de-cart-context__disclosure" open', $html );
		self::assertStringContainsString( 'data-cetech-de-destination-control="country"', $html );
	}

	public function test_first_incomplete_anchor_skips_reselection_line(): void {
		$reselect = $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ) );
		$reselect[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] = true;
		$editable = $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$summary  = $this->policy->summarize_cart(
			[
				'reselect' => $reselect,
				'accra'    => $editable,
			]
		);

		self::assertSame( 2, $summary['incomplete_delivery'] );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'accra' ), $summary['first_incomplete_anchor'] );
		self::assertStringContainsString( '#' . $summary['first_incomplete_anchor'], $this->policy->primary_add_address_url( $summary ) );
	}

	public function test_all_reselection_lines_leave_first_incomplete_anchor_empty(): void {
		$one = $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() ) );
		$two = $this->named_line( PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingGhanaCountry() ) );
		$one[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] = true;
		$two[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] = true;
		$summary = $this->policy->summarize_cart(
			[
				'one' => $one,
				'two' => $two,
			]
		);

		self::assertSame( 2, $summary['incomplete_delivery'] );
		self::assertSame( '', $summary['first_incomplete_anchor'] );
		self::assertSame( 'https://example.test/cart/', $this->policy->primary_add_address_url( $summary ) );
		self::assertStringNotContainsString( '#', $this->policy->primary_add_address_url( $summary ) );
	}

	/**
	 * @param list<ProductDeliveryOption>|null $options
	 * @param array<string, mixed>|null        $intent
	 */
	private function render_line( string $key, CustomerCartContext $context, ?array $intent = null, ?array $options = null, int $qty = 1 ): string {
		$intent  = $intent ?? $this->intent( 101, 10 );
		$options = $options ?? [ $this->option( (string) $intent['display_key'], (string) $intent['fulfilment_choice'], $intent['delivery_offer_id'] ) ];
		$item    = PerItemContextFixtures::cartItem( $intent, $context, $qty );

		return $this->renderer->render_editor( $key, $item, $context, $intent, $options, [] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function named_line( CustomerCartContext $context ): array {
		return PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $context );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent( int $product_id, ?int $offer_id, string $availability = 'in_warehouse', string $choice = 'delivery' ): array {
		$key = $availability . ':' . $choice . ':' . ( $offer_id ?? 'pickup' );

		return [
			'contract_version'        => '1',
			'product_id'              => $product_id,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => $product_id,
			'display_key'             => $key,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id,
			'rule_id'                 => 1,
			'issued_at'               => '2026-09-02T00:00:00+00:00',
		];
	}

	private function option( string $key, string $choice, ?int $offer_id, ?int $pickup_id = null ): ProductDeliveryOption {
		return new ProductDeliveryOption(
			$key,
			explode( ':', $key )[0],
			'Availability',
			$choice,
			'Choice',
			$offer_id,
			$choice === FulfilmentChoice::StorePickup->value ? 'Store pickup' : 'QA Local Standard',
			null,
			'2-4 days',
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			$pickup_id ? 'QA Accra Pickup' : null,
			null,
			null,
			$pickup_id
		);
	}
}
