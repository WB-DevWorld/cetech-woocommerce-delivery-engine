<?php
declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote {
	/** Isolated preparation seam; the environment, cached restore and raw native fence are production code. */
	final class NativeCartQuotePreparation {
		public function __construct( \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory $factory ) {}
		public function matches_original( QuoteIssueCommand $original, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader $header, QuoteCartDraft $draft ): bool { return $original->owner()->equals( $draft->owner() ) && $header->material_digest() === $original->context()->digest(); }
		public function evidence( QuoteIssueCommand $original, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence {
			++\Calls::$captures; \Calls::$timeline[] = 'capture'; $wc = $GLOBALS['woocommerce']; $shipping = $wc->shipping(); $rate = $shipping->packages[0]['rates'][\Calls::RATE];
			\Calls::$projected_at_capture = ( new \ReflectionProperty( $rate, 'meta_data' ) )->getValue( $rate ) === [ '_cetech_de_quote_component_handle' => 'inert-handle' ];
			\Calls::$same_selected_rate = $wc->cart->selected()[0] === $rate;
			\Calls::$money_unchanged = $rate->packet() === \Calls::MONEY;
			$source = new QuoteNativeWooSource();
			foreach ( [ 'wc' => $wc, 'objects' => [ $wc->cart, $wc->customer, $wc->session, $shipping, $rate ], 'site' => 1, 'user' => 9, 'option_names' => [] ] as $name => $value ) { ( new \ReflectionProperty( $source, $name ) )->setValue( $source, $value ); }
			$binding = ( new \ReflectionMethod( $source, 'raw_binding' ) )->invoke( $source ); ( new \ReflectionProperty( $source, 'binding' ) )->setValue( $source, $binding );
			if ( 'optional' !== \Calls::$mode ) { $GLOBALS['projection']->project_loaded_rates(); }
			\Calls::$raw_fence_stable = $source->unchanged(); \Calls::$source = $source;
			$guard = new class( $source ) implements QuoteCurrentEvidenceGuard {
				public function __construct( private QuoteNativeWooSource $source ) {}
				public function tables( \CetechDeliveryEngine\Domain\Operation\OperationSession $session ): array { return []; }
				public function verify( \CetechDeliveryEngine\Domain\Operation\OperationSession $session, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner $owner, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext $context ): bool { return $this->source->unchanged(); }
			};
			return new QuoteCartCurrentEvidence( $original->context(), $guard );
		}
	}
}

namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteRateProjection,NativeCartQuoteEnvironment,QuoteIssueCommand,QuotePreparationAccess};
	use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
	use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
	define( 'AUTH_KEY', str_repeat( 'a', 32 ) ); define( 'AUTH_SALT', str_repeat( 'b', 32 ) ); define( 'LOGGED_IN_COOKIE', 'native_logged_in' );
	final class Calls {
		public const RATE = 'delivery_engine_selected_offer:1:group'; public const MONEY = [ 'id' => self::RATE, 'cost' => '7', 'taxes' => [ 1 => '0.7' ] ];
		public static string $mode; public static array $timeline = []; public static int $projections = 0, $captures = 0, $totals = 0, $writes = 0, $queries = 0;
		public static bool $projected_at_capture = false, $same_selected_rate = false, $money_unchanged = false, $raw_fence_stable = false; public static ?object $source = null;
	}
	class WP_Hook { public array $callbacks = []; }
	class WP_Object_Cache { private array $cache = [ 'options' => [ 'alloptions' => [ 'woocommerce_currency' => 'GHS', 'woocommerce_price_num_decimals' => '2', '_transient_shipping-transient-version' => '1791380000' ] ] ]; private string $blog_prefix = ''; protected array $global_groups = []; private bool $multisite = false; }
	class WC_Customer { protected array $data = [ 'shipping' => [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE ADDRESS', 'address_2' => '' ] ]; protected array $changes = []; }
	class WC_Shipping_Rate { protected array $data = Calls::MONEY; protected array $meta_data = []; public function packet(): array { return $this->data; } }
	class WC_Cart {
		public array $cart_contents; protected array $totals = [ 'shipping_total' => '7', 'shipping_tax' => '0.7' ]; protected array $shipping_methods = []; protected bool $has_calculated_shipping = false;
		public function get_shipping_packages(): array { Calls::$timeline[] = 'restore'; return [ [ 'contents' => $this->cart_contents, 'destination' => [ 'country' => 'GH' ] ] ]; }
		public function selected(): array { return $this->shipping_methods; } public function calculate_totals(): never { ++Calls::$totals; throw new \LogicException( 'Unexpected repricing.' ); }
	}
	class WC_Session_Handler {
		protected string $_customer_id = '9'; protected bool $_has_cookie = true; protected string $_cookie = 'native_session';
		public function __construct( protected array $_data ) {} public function bytes(): string { return serialize( $this->_data ); }
		public function save_data(): never { ++Calls::$writes; throw new \LogicException( 'Unexpected session persistence.' ); } public function set(): never { ++Calls::$writes; throw new \LogicException( 'Unexpected session mutation.' ); }
	}
	class WC_Shipping { protected static ?self $_instance = null; public array $packages = []; public static function install( self $shipping ): void { self::$_instance = $shipping; } private function get_package_hash( array $package ): string { unset( $package['rates'] ); return 'wc_ship_' . md5( serialize( $package ) ); } }
	function get_option( string $name, mixed $default = null ): mixed { return $default; } function wp_using_ext_object_cache(): bool { return false; } function wp_installing(): bool { return false; }
	$selection = [ 'contract_version' => '1', 'product_id' => 10, 'variation_id' => null, 'target_type' => 'product', 'target_id' => 10, 'display_key' => 'in_warehouse:delivery:20', 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20, 'rule_id' => 1 ];
	$item = [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 2, 'cetech_de_delivery_selection' => $selection, 'cetech_de_delivery_selection_hash' => hash( 'sha256', 'selection' ), 'cetech_de_customer_context' => [ 'contract_version' => 1, 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20, 'delivery_address' => [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE ADDRESS', 'address_2' => '' ] ] ];
	$cart = new WC_Cart(); $cart->cart_contents = [ 'line_one' => $item ]; $shipping = new WC_Shipping(); WC_Shipping::install( $shipping );
	$package = [ 'contents' => $cart->cart_contents, 'destination' => [ 'country' => 'GH' ] ]; $hash = 'wc_ship_' . md5( serialize( $package ) ); Calls::$mode = $argv[1] ?? 'matching';
	$session = new WC_Session_Handler( [ 'chosen_shipping_methods' => serialize( [ Calls::RATE ] ), 'cart_totals' => serialize( [ 'shipping_total' => '7', 'shipping_tax' => '0.7' ] ), 'shipping_for_package_0' => serialize( [ 'package_hash' => 'changed_cache' === Calls::$mode ? 'wrong_hash' : $hash, 'rates' => [ Calls::RATE => new WC_Shipping_Rate() ] ] ) ] );
	$GLOBALS['woocommerce'] = new class( $cart, $session, $shipping ) { public WC_Customer $customer; public object $countries; public function __construct( public WC_Cart $cart, public WC_Session_Handler $session, private WC_Shipping $ship ) { $this->customer = new WC_Customer(); $this->countries = new \stdClass(); } public function shipping(): WC_Shipping { return $this->ship; } };
	$GLOBALS['current_user'] = (object) [ 'ID' => 9 ]; $GLOBALS['blog_id'] = 1; $GLOBALS['wp_filter'] = []; $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); $_COOKIE[LOGGED_IN_COOKIE] = 'name|2000000000|first-auth-session|signature';
	$factory = new class implements OperationConnectionFactory { public function open(): OperationSession { ++Calls::$queries; throw new \LogicException( 'Unexpected SQL session.' ); } };
	$access = new class implements QuotePreparationAccess { public function observe( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner $owner ): ?int { Calls::$timeline[] = 'control'; return 'paused' === Calls::$mode ? null : 1; } public function confirm( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner $owner, int $revision ): bool { Calls::$timeline[] = 'confirm'; return 1 === $revision; } };
	$environment = new NativeCartQuoteEnvironment( $factory, $access );
	$projection = new class implements CartQuoteRateProjection { public int $calls = 0; public function project_loaded_rates(): void { ++$this->calls; ++Calls::$projections; Calls::$timeline[] = 'project'; if ( 'projection_throws' === Calls::$mode ) { throw new \RuntimeException( 'Projection refused.' ); } $wc = $GLOBALS['woocommerce']; $rate = $wc->shipping()->packages[0]['rates'][Calls::RATE]; ( new \ReflectionProperty( $rate, 'meta_data' ) )->setValue( $rate, [ '_cetech_de_quote_component_handle' => 'inert-handle' ] ); } }; $GLOBALS['projection'] = $projection;
	$same_allowed = false; $replacement_refused = false; $replacement = new class implements CartQuoteRateProjection { public int $calls = 0; public function project_loaded_rates(): void { ++$this->calls; } };
	if ( 'optional' !== Calls::$mode ) { $environment->set_rate_projection( $projection ); $environment->set_rate_projection( $projection ); $same_allowed = true; if ( 'replacement' === Calls::$mode ) { try { $environment->set_rate_projection( $replacement ); } catch ( \LogicException ) { $replacement_refused = true; } } }
	$draft = $environment->draft(); if ( null === $draft ) { throw new \RuntimeException( 'Loaded native fixture refused.' ); }
	$facts = QuoteFixtures::context()->private_facts(); $facts['destination']['key_epoch'] = $draft->owner()->key_epoch(); $context = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext::from_array( $facts ); $command = QuoteIssueCommand::create( $draft->owner(), $context, 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1, 'original-token' ); $id = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate();
	$header = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader::issue( $id, $draft->owner(), $context, QuoteFixtures::terms(), \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now(), $command->namespace_hashes( $id ), 'legacy_fixed_base_v1' );
	$before = $session->bytes(); $evidence = $environment->evidence( $command, $header, $draft ); $stable = Calls::$raw_fence_stable; $late = null;
	if ( null !== Calls::$source ) { $rate = $shipping->packages[0]['rates'][Calls::RATE]; ( new \ReflectionProperty( $rate, 'meta_data' ) )->setValue( $rate, [ '_cetech_de_quote_component_handle' => 'late-foreign-handle' ] ); $late = Calls::$source->unchanged(); }
	echo json_encode( [ 'available' => null !== $evidence, 'timeline' => Calls::$timeline, 'projected_at_capture' => Calls::$projected_at_capture, 'same_selected_rate' => Calls::$same_selected_rate, 'money_unchanged' => Calls::$money_unchanged, 'session_unchanged' => $before === $session->bytes(), 'raw_fence_stable' => $stable, 'raw_fence_after_late_change' => $late, 'totals_calls' => Calls::$totals, 'writes' => Calls::$writes, 'queries' => Calls::$queries, 'captures' => Calls::$captures, 'projection_calls' => Calls::$projections, 'same_instance_allowed' => $same_allowed, 'replacement_refused' => $replacement_refused, 'replacement_calls' => $replacement->calls ], JSON_THROW_ON_ERROR );
}
