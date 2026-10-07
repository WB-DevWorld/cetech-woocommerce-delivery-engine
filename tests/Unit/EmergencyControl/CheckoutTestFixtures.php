<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionControlInterface;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOrderQuoteValidatorInterface;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;

final class CheckoutSourceFixture implements ProductDeliveryConfigurationSourceInterface {
	public bool $failed = false;
	public bool $managed = true;
	public string $label = 'legacy';
	public int $calls = 0;
	public bool $pickup = false;
	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
		++$this->calls;
		if ( $this->failed ) {
			return new ProductDeliveryRuntimeResolution( ProductRuleResolutionResult::failure( $target_type, $target_id, 'PRIVATE_SQL_ADDRESS_OPERATOR_DETAIL' ), $this->label );
		}
		$rule = new ResolvedProductDeliveryRule( 7, $target_type, $target_id, null, 1, 'in_store', $this->pickup ? 'store_pickup' : 'delivery', $this->pickup ? [] : [ 21 ], null, null, null, 100, $this->pickup ? 55 : null );
		return new ProductDeliveryRuntimeResolution( new ProductRuleResolutionResult( true, null, $target_type, $target_id, null, [], '', $this->managed ? [ $rule ] : [], $this->managed ? [ 'in_store' => $rule ] : [], [], [], [], $this->managed ? null : 'No match.' ), $this->label );
	}
}

final class CheckoutControlFixture implements EmergencyAdmissionControlInterface {
	public EmergencyControlState $state;
	public int $reads = 0;
	public int $confirms = 0;
	public bool $released = false;
	public bool $locked = false;
	public ?string $failure = null;
	public ?\Closure $during_confirm = null;
	public function __construct() { $this->state = EmergencyControlState::absent( 1 ); }
	public function read( int $site_id, ?RequestContext $request = null ): EmergencyControlReadResult {
		++$this->reads;
		return null === $this->failure ? EmergencyControlReadResult::ready( $this->state ) : EmergencyControlReadResult::unavailable( $this->failure, $request ?? RequestContext::create() );
	}
	public function confirm_enabled( int $site_id, int $expected_revision, ?callable $unchanged_facts = null, ?RequestContext $request = null ): EmergencyControlReadResult {
		++$this->confirms;
		$this->locked = true;
		if ( null !== $this->during_confirm ) { ( $this->during_confirm )(); }
		if ( null !== $this->failure ) { return EmergencyControlReadResult::unavailable( $this->failure, $request ?? RequestContext::create() ); }
		if ( ! $this->state->enabled() ) { return EmergencyControlReadResult::unavailable( 'checkout_suspended', $request ?? RequestContext::create() ); }
		if ( $expected_revision !== $this->state->revision || null !== $unchanged_facts && ! $unchanged_facts() ) { return EmergencyControlReadResult::unavailable( 'stale_revision', $request ?? RequestContext::create() ); }
		$this->released = true;
		$this->locked = false;
		return EmergencyControlReadResult::ready( $this->state );
	}
	public function pause( int $revision = 2 ): void { $this->state = EmergencyControlState::from_physical( 1, 50, EmergencyControlState::record_json( 1, 'checkout_suspended', $revision, 'operator_pause', 7, 1780000000 ) ); }
	public function resume( int $revision = 3 ): void { $this->state = EmergencyControlState::from_physical( 1, 50, EmergencyControlState::record_json( 1, 'enabled', $revision, 'resume_verified', 7, 1780000001 ) ); }
}

final class CheckoutQuoteFixture implements EmergencyOrderQuoteValidatorInterface {
	public bool $valid = true;
	public int $calls = 0;
	public string $hash = 'initial-server-facts';
	public ?\Closure $during_validation = null;
	public ?\Closure $on_fingerprint = null;
	public function validate_order( \WC_Order $order, string $route ): bool { ++$this->calls; if ( null !== $this->during_validation ) { ( $this->during_validation )(); } return $this->valid; }
	public function fingerprint( \WC_Order $order ): ?string { if ( null !== $this->on_fingerprint ) { ( $this->on_fingerprint )(); } return $this->hash; }
}

final class CheckoutOrderFixture extends \WC_Order {
	public array $facts = [];
	public function get_currency( string $context = 'view' ): string { return $this->facts['currency'] ?? 'GHS'; }
	public function get_customer_id( string $context = 'view' ): int { return $this->facts['customer'] ?? 9; }
	public function get_total( string $context = 'view' ): string { return $this->facts['total'] ?? '110.00'; }
	public function get_total_tax( string $context = 'view' ): string { return $this->facts['tax'] ?? '0'; }
	public function get_shipping_total( string $context = 'view' ): string { return $this->facts['shipping'] ?? '10'; }
	public function get_shipping_tax( string $context = 'view' ): string { return $this->facts['shipping_tax'] ?? '0'; }
	public function get_shipping_country( string $context = 'view' ): string { return 'GH'; }
	public function get_shipping_state( string $context = 'view' ): string { return 'AA'; }
	public function get_shipping_city( string $context = 'view' ): string { return $this->facts['city'] ?? 'Accra'; }
	public function get_shipping_postcode( string $context = 'view' ): string { return ''; }
	public function get_shipping_address_1( string $context = 'view' ): string { return 'PRIVATE_ADDRESS'; }
	public function get_shipping_address_2( string $context = 'view' ): string { return ''; }
	public function get_taxable_location(): array { return [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '' ]; }
	public function get_items_tax_classes(): array { return [ '' ]; }
}

final class CheckoutShippingFixture extends \WC_Order_Item_Shipping {
	public string $tax = '0';
	public array $taxes = [ 'total' => [] ];
	public function get_total_tax( string $context = 'view' ): string { return $this->tax; }
	public function get_taxes( string $context = 'view' ): array { return $this->taxes; }
	public function get_tax_status( string $context = 'view' ): string { return 'taxable'; }
}

final class CheckoutPersistedItemFixture extends \WC_Order_Item_Product {
	public ?int $view_quantity = null;
	public function __construct( array $data, private int $owner_id ) { parent::__construct( $data ); }
	public function get_order_id( string $context = 'view' ): int { return $this->owner_id; }
	public function get_quantity(): int { return $this->view_quantity ?? parent::get_quantity(); }
	public function change_owner( int $id ): void { $this->owner_id = $id; }
	public function mutate_raw( string $key, mixed $value ): void {
		$property = new \ReflectionProperty( \WC_Order_Item_Product::class, 'data' );
		$data = $property->getValue( $this ); $data[ $key ] = $value; $property->setValue( $this, $data );
	}
}

/** Real no-selection Builder/Persister path; unrelated quote dependencies are not reached. */
function checkout_native_mapping_order(): \WC_Order {
	if ( ! class_exists( 'WooCommerce', false ) ) { eval( 'class WooCommerce {}' ); }
	if ( ! class_exists( 'WC_Shipping_Method', false ) ) { eval( 'class WC_Shipping_Method { public string $id=""; public int $instance_id=0; public string $title=""; public string $method_title=""; public string $method_description=""; public string $tax_status=""; public array $supports=[]; public array $instance_form_fields=[]; public function init_form_fields():void{} public function init_settings():void{} public function get_option(string $key,$default_value=""){return $default_value;} public function process_admin_options():void{} }' ); }
	$old_options = $GLOBALS['cetech_de_test_options'] ?? []; $old_wc = $GLOBALS['cetech_de_test_wc'] ?? null;
	try {
		$line = [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1 ];
		$GLOBALS['cetech_de_test_wc'] = (object) [ 'cart' => new class( $line ) { public function __construct( private array $line ) {} public function get_cart(): array { return [ 'ordinary-key' => $this->line ]; } } ];
		$flags = new \CetechDeliveryEngine\Bootstrap\FeatureFlags();
		foreach ( [ 'enable_order_delivery_snapshot_persistence', 'enable_woocommerce_shipping_rate_calculation', 'enable_product_delivery_selector', 'enable_cart_delivery_selection_capture', 'enable_checkout_delivery_selection_validation' ] as $flag ) { $flags->set( $flag, true ); }
		$requirements = new \CetechDeliveryEngine\Core\Requirements();
		$gate = new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotGate( $flags, $requirements, new \CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate( $flags, $requirements ) );
		$builder = ( new \ReflectionClass( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotBuilder::class ) )->newInstanceWithoutConstructor();
		$persister = new \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister( $gate, $builder, new \CetechDeliveryEngine\Support\Logger() );
		$item = new \WC_Order_Item_Product( [ 'id' => 71, 'product_id' => 10, 'quantity' => 1 ] ); $order = new \WC_Order( [ 'id' => 19, 'items' => [ $item ] ] );
		if ( ! $gate->is_runtime_active() || null !== $builder->build_line_snapshot( 'ordinary-key', $line, $order ) ) { throw new \LogicException( 'Native no-selection fixture is not active.' ); }
		$persister->handle_create_order_line_item( $item, 'ordinary-key', $line, $order ); $persister->handle_order_created( $order );
		return $order;
	} finally { $GLOBALS['cetech_de_test_options'] = $old_options; $GLOBALS['cetech_de_test_wc'] = $old_wc; }
}
