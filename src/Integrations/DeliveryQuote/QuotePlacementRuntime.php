<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCartPlacementEvidenceReader,QuoteDurableService,QuotePlacementEvidence,QuotePlacementProof,QuotePlacementSavedEvidenceGuard,QuotePlacementService,QuoteProviderRegistry,QuoteSavedOrderAuthorization};
use CetechDeliveryEngine\Application\EmergencyControl\{EmergencyCheckoutFacts,EmergencyCheckoutLocalBinding,EmergencyFinalPlacementGuard,EmergencyOrderQuoteValidatorInterface};
use CetechDeliveryEngine\Application\Order\{QuoteNativeOrderFacts,QuoteNativeOrderHistory,QuoteNativeOrderStager,QuoteNativeOrderStageResult};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlResponse;

/** Native staging plus the mandatory C07-to-acknowledged-placement boundary. */
final class QuotePlacementRuntime implements EmergencyFinalPlacementGuard, EmergencyOrderQuoteValidatorInterface {
	public const SESSION_CONTINUATION = 'cetech_de_quote_payment_continuation';
	private \Closure $active;
	private \Closure $managed_cart;
	private ?\Closure $saved_reader;
	private ?\Closure $decorate_guard;
	private ?\Closure $redirect;
	private ?\Closure $terminate;
	private ?\Closure $final_admission;
	private \WeakMap $store_posts;
	private \WeakMap $saved;
	private \WeakMap $continuations;
	private array $stages = [];
	private ?QuoteNativeOrderPayContinuation $pay_continuation = null;
	private bool $registered = false;

	/** Policy/ownership closures are invoked only outside owned database locks. */
	public function __construct(
		private OperationConnectionFactory $factory,
		private QuoteCartPlacementEvidenceReader $cart,
		private QuoteNativeOrderStager $stager,
		private EmergencyOrderQuoteValidatorInterface $legacy,
		callable $active,
		callable $managed_cart,
		?callable $saved_reader = null,
		?callable $guard_decorator = null,
		?callable $redirect = null,
		?callable $terminate = null,
		?callable $final_admission = null
	) {
		$this->active = \Closure::fromCallable( $active ); $this->managed_cart = \Closure::fromCallable( $managed_cart );
		$this->saved_reader = null === $saved_reader ? null : \Closure::fromCallable( $saved_reader );
		$this->decorate_guard = null === $guard_decorator ? null : \Closure::fromCallable( $guard_decorator );
		$this->redirect = null === $redirect ? null : \Closure::fromCallable( $redirect );
		$this->terminate = null === $terminate ? null : \Closure::fromCallable( $terminate );
		$this->final_admission = null === $final_admission ? null : \Closure::fromCallable( $final_admission );
		$this->store_posts = new \WeakMap(); $this->saved = new \WeakMap(); $this->continuations = new \WeakMap();
	}

	/** Saved quote protection stays installed even when new site adoption is off. */
	public function register(): void {
		if ( $this->registered ) { return; } $this->registered = true;
		add_action( 'woocommerce_checkout_process', [ $this, 'protect_classic_retry' ], -100 );
		add_action( 'woocommerce_resume_order', [ $this, 'resume_classic' ], -100, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'bind_native_line' ], -100, 4 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'stage_classic' ], PHP_INT_MAX - 1, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'remember_store_post' ], PHP_INT_MAX - 1, 2 );
		// Pinned Woo synchronizes the request and changes status to pending first.
		// Legacy processed writer (10) and final C07 admission (PHP_INT_MAX) follow.
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'stage_store_post' ], 9, 1 );
		add_action( 'woocommerce_after_order_object_save', [ $this, 'observe_native_logging_save' ], PHP_INT_MAX, 2 );
		add_filter( 'rest_dispatch_request', [ $this, 'recover_store_post' ], -100, 4 );
		add_action( 'woocommerce_pre_payment_complete', [ $this, 'guard_free_payment_continuation' ], PHP_INT_MAX, 1 );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'guard_store_payment_context' ], -PHP_INT_MAX, 2 );
	}

	/** Persister guard: a quote-owned group never falls back to the legacy writer. */
	public function owns_order( \WC_Order $order ): bool {
		try { return QuoteNativeOrderHistory::attempted( $order ) || $this->new_cart_owned(); }
		catch ( \Throwable ) { return true; }
	}
	public function fingerprint( \WC_Order $order ): ?string { return EmergencyCheckoutFacts::order_fingerprint( $order ); }
	/** Original native cart key disambiguates identical product/quantity lines. */
	public function bind_native_line( mixed $item, mixed $key, mixed $values, mixed $order ): void {
		if ( ! $this->new_cart_owned() ) { return; }
		if ( ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order || ! is_string( $key ) || '' === $key || strlen( $key ) > 200 || ! is_array( $values ) || \CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotMarker::exists( $item ) || QuoteNativeOrderHistory::owned( $order ) ) { self::refuse( 'classic' ); }
		$existing = $item->get_meta( QuoteNativeOrderFacts::META_LINE_KEY, true ); if ( '' !== $existing && $existing !== $key ) { self::refuse( 'classic' ); }
		$item->update_meta_data( QuoteNativeOrderFacts::META_LINE_KEY, $key );
	}

	public function stage_classic( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) { if ( $this->new_cart_owned() ) { self::refuse( 'classic' ); } return; }
		if ( ! $this->new_cart_owned() ) { return; }
		try { $this->stage( $order, 'classic' ); } catch ( \Throwable $error ) { throw new \RuntimeException( EmergencyControlResponse::message(), 0, $error ); }
	}
	/** Read the completed pinned native logger save outside all owned SQL. */
	public function observe_native_logging_save( mixed $order, mixed $store = null ): void {
		if ( ! $order instanceof \WC_Order ) { return; }
		try {
			$coordinate = $this->coordinate( $order ); $entry = $this->stages[$coordinate] ?? null;
			if ( null === $entry || 'classic' !== $entry['route'] || ! ( $entry['logging'] ?? null ) instanceof QuoteNativeCheckoutLoggingEvidence ) { return; }
			$receipt = $entry['logging']->observe_saved( $order ); if ( null === $receipt ) { return; }
			$before = $entry['logger_receipt'] ?? null;
			if ( 4 === $receipt->phase() && null === $before ) { $this->stages[$coordinate]['logger_receipt'] = $receipt; return; }
			if ( 5 === $receipt->phase() && $before instanceof QuoteNativeCheckoutLoggingEvidence && $receipt->successor_of( $before ) && null !== $this->pay_continuation ) {
				$this->pay_continuation->observe_logging( $receipt ); $this->stages[$coordinate]['logger_receipt'] = $receipt; return;
			}
			throw new \RuntimeException( 'Native logger observation was not unique.' );
		} catch ( \Throwable ) { if ( isset( $coordinate, $this->stages[$coordinate] ) ) { $this->stages[$coordinate]['logging_failed'] = true; } if ( null !== $this->pay_continuation ) { $this->pay_continuation->reject_logging(); } }
	}
	/** This native hook runs after cookie/session/nonce permission checks and sync. */
	public function remember_store_post( mixed $order, mixed $request ): void {
		if ( ! $order instanceof \WC_Order || ! $request instanceof \WP_REST_Request ) { return; }
		if ( 'POST' !== $request->get_method() || '/wc/store/v1/checkout' !== $request->get_route() ) { return; }
		$this->store_posts[$order] = $request;
	}
	public function stage_store_post( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || ! $this->new_cart_owned() ) { return; }
		$request = $this->store_posts[$order] ?? null;
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() || '/wc/store/v1/checkout' !== $request->get_route() ) { self::refuse( 'store_api' ); }
		try { $this->stage( $order, 'store_api' ); } catch ( \Throwable ) { self::refuse( 'store_api' ); }
	}
	/** Recover the server-only original before native synchronization can mutate it. */
	public function recover_store_post( mixed $dispatch, mixed $request, mixed $route, mixed $handler ): mixed {
		if ( null !== $dispatch || ! $request instanceof \WP_REST_Request || '/wc/store/v1/checkout' !== $request->get_route() || ! is_array( $handler ) ) { return $dispatch; }
		$callback = $handler['callback'] ?? null;
		if ( ! is_array( $callback ) || ! is_object( $callback[0] ?? null ) || 'Automattic\\WooCommerce\\StoreApi\\Routes\\V1\\Checkout' !== get_class( $callback[0] ) || 'get_response' !== ( $callback[1] ?? null ) ) { return $dispatch; }
		try {
			// Woo's cart nonce is checked inside its callback, after this WP seam.
			// Require that same native nonce explicitly before private lookup/recovery.
			if ( function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || ! ( WC()->session ?? null ) ) ) { wc_load_cart(); }
			$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
			if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) ) { return $dispatch; }
			$original = $native->get( self::SESSION_CONTINUATION ); if ( null === $original ) { return $dispatch; }
			if ( ! self::valid_continuation( $original ) ) { return $dispatch; }
			if ( 'POST' !== $request->get_method() ) {
				// Detached sealed/prepared history is never a mutable GET/PATCH draft.
				if ( $native->get( 'store_api_draft_order', 0 ) === $original['order_id'] ) { $native->set( 'store_api_draft_order', 0 ); $native->save_data(); if ( ! $this->acknowledge_native_session( $native ) ) { return $this->store_recovery_error(); } }
				return $dispatch;
			}
			$nonce = $request->get_header( 'Nonce' );
			if ( ! is_string( $nonce ) || strlen( $nonce ) > 128 || ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'wc_store_api' ) || '' !== (string) $request->get_header( 'Cart-Token' ) || ! $native->has_session() ) { return $this->store_recovery_error(); }
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $original['order_id'] ) : null;
			if ( ! $order instanceof \WC_Order || ! $this->authorize_checkout_pointer( $order, $native ) ) { return $this->store_recovery_error(); }
			// This dispatch seam precedes Woo's handler. Load the same native session
			// cart once, after authorization and before capturing any quote evidence.
			if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Utilities\CartController' ) ) { return $this->store_recovery_error(); }
			( new \Automattic\WooCommerce\StoreApi\Utilities\CartController() )->load_cart();
			$evidence = $this->cart->current( RequestContext::create() );
			if ( null === $evidence ) { return $this->store_recovery_error(); }
			if ( $evidence->header()->id()->value() !== $original['quote_id'] ) {
				if ( ! $this->original_is_disposed( $native, $original, $evidence ) ) { return $this->store_recovery_error(); }
				$native->set( self::SESSION_CONTINUATION, null ); $native->set( 'store_api_draft_order', 0 ); $native->save_data();
				return $this->acknowledge_native_session( $native ) ? $dispatch : $this->store_recovery_error();
			}
			if ( ( new QuotePlacementService( $this->durable( $evidence ), $evidence ) )->placement_id() !== $original['placement_id'] ) { return $this->store_recovery_error(); }
			if ( ! $this->store_payload_matches( $order, $request ) ) { return $this->store_recovery_error(); }
			$this->stage( $order, $original['route'] );
			if ( ! $this->finish_recovery( $order, $original['route'] ) || ! $this->authorize_checkout_pointer( $order, $native ) || ! method_exists( $order, 'get_checkout_payment_url' ) ) { return $this->store_recovery_error(); }
			$url = $order->get_checkout_payment_url(); if ( ! $this->safe_payment_url( $url ) ) { return $this->store_recovery_error(); }
			return new \WP_REST_Response( [ 'order_id' => $order->get_id(), 'status' => $order->get_status(), 'order_key' => $order->get_order_key(), 'customer_note' => $order->get_customer_note(), 'customer_id' => $order->get_customer_id(), 'billing_address' => $order->get_address( 'billing' ), 'shipping_address' => $order->get_address( 'shipping' ), 'payment_method' => $order->get_payment_method(), 'payment_result' => [ 'payment_status' => 'success', 'payment_details' => [], 'redirect_url' => $url ], 'extensions' => [] ], 200 );
		} catch ( \Throwable ) { return $this->store_recovery_error(); }
	}

	public function validate_order( \WC_Order $order, string $route ): bool {
		try {
			if ( ! in_array( $route, [ 'classic', 'blocks', 'store_api', 'order_pay' ], true ) ) { return false; }
			$entry = $this->stages[$this->coordinate( $order )] ?? null;
			if ( null !== $entry ) { return $this->stage_matches( $order, $entry ) && ( $entry['route'] === $route || 'order_pay' === $route && 'sealed' === $entry['binding']->state() && $this->authorize_saved_order( $order ) ) && QuoteNativeOrderHistory::verify( $order ); }
			if ( ! QuoteNativeOrderHistory::owned( $order ) ) {
				if ( QuoteNativeOrderHistory::attempted( $order ) ) { return false; }
				return 'order_pay' === $route || ! $this->new_cart_owned() ? $this->legacy->validate_order( $order, $route ) : false;
			}
			// A saved, empty-cart payment is resolved only after native exact-order auth.
			if ( 'order_pay' !== $route || null === $this->saved_reader || ! $this->authorize_saved_order( $order ) ) { return false; }
			$evidence = ( $this->saved_reader )( $order, RequestContext::create() );
			if ( ! $evidence instanceof QuotePlacementEvidence || null === $evidence->binding() || 'sealed' !== $evidence->binding()->state()
				|| null === $evidence->saved_guard() || ! QuoteNativeOrderHistory::verify( $order ) || ! $this->authorize_saved_order( $order ) ) { return false; }
			$this->saved[$order] = $evidence; return true;
		} catch ( \Throwable ) { return false; }
	}

	/** Invoked by C07 only after its tentative current control confirmation releases. */
	public function complete( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $local ): bool {
		try {
			if ( ! $local->unchanged() || $control_revision < 1 ) { return false; }
			if ( 'store_api' === $route && ! $this->store_payment_hook_supported() ) { return false; }
			$entry = $this->stages[$this->coordinate( $order )] ?? null;
			if ( null !== $entry ) {
				if ( ! $this->stage_matches( $order, $entry ) ) { return false; }
				if ( ( $entry['route'] !== $route && ( 'order_pay' !== $route || 'sealed' !== $entry['binding']->state() || ! $this->authorize_saved_order( $order ) ) ) || ! QuoteNativeOrderHistory::verify( $order ) ) { return false; }
				$guard = $entry['guard'];
				$new_promise = 2 === $entry['evidence']->header()->format_version();
				$logging = $entry['logger_receipt'] ?? null;
				if ( $logging instanceof QuoteNativeCheckoutLoggingEvidence ) { if ( 'classic' !== $route || 4 !== $logging->phase() || ! $logging->is_current() ) { return false; } if ( ! $new_promise ) { $guard = $logging->with_saved( $guard ); } }
				if ( 'store_api' === $route ) { $guard = $this->detach_store_pointer( $order, $entry['evidence'], $entry['binding'], $guard ); }
				elseif ( 'classic' === $route ) { $guard = $this->retain_classic_pointer( $order, $entry['evidence'], $entry['binding'], $guard ); }
				elseif ( 'order_pay' === $route ) { $guard = $this->saved_authorization_guard( $order, $guard ); }
				// Native session disposition is part of the exact source fence. Capture
				// its new physical state only after that independent write acknowledges.
				$evidence = $this->refresh_evidence( $entry['evidence'] );
				$service = new QuotePlacementService( $this->durable( $evidence ), $evidence );
				if ( ( $new_promise || 'sealed' !== $entry['binding']->state() ) && null !== $this->decorate_guard ) { $guard = ( $this->decorate_guard )( $guard, $evidence ); }
				if ( ! $guard instanceof QuotePlacementSavedEvidenceGuard || ! $local->unchanged() ) { return false; }
				if ( $new_promise ) {
					// Preserve this exact adoption/session decision for the payment owner.
					// The native #5 logger successor is verified separately there.
					$this->stages[$this->coordinate( $order )]['promise_payment_guard'] = $guard;
					if ( $logging instanceof QuoteNativeCheckoutLoggingEvidence ) { $guard = $logging->with_saved( $guard ); }
				}
				if ( 'sealed' === $entry['binding']->state() ) {
					if ( ! $this->durable( $evidence )->admit_sealed_placement( $evidence->owner(), $evidence->reference(), $evidence->header(), $entry['binding'], RequestContext::create(), $evidence->current_context(), $control_revision, $local, $guard ) ) { return false; }
				} else {
					$promise_terms = 2 === $evidence->header()->format_version() ? $evidence->terms() : null;
					$proof = QuotePlacementProof::capture( $entry['binding'], $control_revision, $local, $guard, $promise_terms, null === $promise_terms ? null : QuoteTime::now() );
					$result = $service->seal( $entry['binding'], $proof, RequestContext::create() );
					if ( 'accepted' !== $result->attempt->outcome->state || 'accepted' !== $result->attempt->completion?->state || null === $result->binding
						|| 'sealed' !== $result->binding->state() || 3 !== $result->binding->revision() || ! $proof->unchanged() || ! $local->unchanged() ) { return false; }
					$this->stages[$this->coordinate( $order )]['binding'] = $result->binding;
				}
				if ( $logging instanceof QuoteNativeCheckoutLoggingEvidence && ! $logging->is_current() ) { return false; }
			} elseif ( QuoteNativeOrderHistory::owned( $order ) ) {
				$evidence = $this->saved[$order] ?? null;
				if ( 'order_pay' !== $route || ! $evidence instanceof QuotePlacementEvidence || ! $this->authorize_saved_order( $order ) ) { return false; }
				$binding = $evidence->binding(); $guard = $evidence->saved_guard();
				if ( null !== $guard ) { $guard = $this->saved_authorization_guard( $order, $guard ); }
				if ( null !== $guard && 2 === $evidence->header()->format_version() && null !== $this->decorate_guard ) { $guard = ( $this->decorate_guard )( $guard, $evidence ); }
				if ( null === $binding || null === $guard || ! $this->durable( $evidence )->admit_sealed_placement( $evidence->owner(), $evidence->reference(), $evidence->header(), $binding, RequestContext::create(), $evidence->current_context(), $control_revision, $local, $guard )
					|| ! $this->authorize_saved_order( $order ) || ! $local->unchanged() ) { return false; }
			} else { return ! QuoteNativeOrderHistory::attempted( $order ) && ( ! $this->new_cart_owned() || 'order_pay' === $route ); }
			$promise_boundary = null;
			$promise_evidence = $evidence;
			$promise_binding = null !== $entry ? $this->stages[$this->coordinate( $order )]['binding'] : $binding;
			if ( 2 === $promise_evidence->header()->format_version() ) {
				$linkage = $this->durable( $promise_evidence )->acknowledged_promise_seal( $promise_evidence->owner(), $promise_evidence->reference(), $promise_evidence->header(), $promise_binding, $guard );
				if ( null === $linkage ) { return false; }
				$promise_boundary = new \CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePaymentBoundary( $promise_evidence->quote_record(), $promise_binding, $linkage, $promise_evidence->guard() );
			}
			if ( 'order_pay' !== $route || isset( $_POST['woocommerce_pay'] ) ) {
				$pay_binding = null !== $entry ? $this->stages[$this->coordinate( $order )]['binding'] : $binding;
				$pay_guard = null !== $entry ? ( 2 === $evidence->header()->format_version() ? ( $this->stages[$this->coordinate( $order )]['promise_payment_guard'] ?? null ) : $entry['guard'] ) : ( 2 === $evidence->header()->format_version() ? $guard : $evidence->saved_guard() );
				$method = 'order_pay' === $route ? ( $_POST['payment_method'] ?? '_missing_native_method' ) : $order->get_payment_method( 'edit' ); if ( '' === $method ) { $method = '_free_native_method'; }
				if ( ! is_string( $method ) || null === $pay_binding || null === $pay_guard ) { return false; }
				$authorization = QuoteSavedOrderAuthorization::capture( $order, [ $this, 'authorize_saved_order' ] );
				$this->pay_continuation = new QuoteNativeOrderPayContinuation( $this->factory, $order, $pay_binding, $pay_guard, $authorization, $method, 'order_pay' === $route, 'classic' === $route && null !== $entry ? ( $entry['logger_receipt'] ?? null ) : null, $promise_boundary );
				// Append only once the actual native order-pay request has acknowledged.
				add_filter( 'woocommerce_available_payment_gateways', [ $this, 'guard_payment_gateways' ], PHP_INT_MAX, 1 );
				remove_action( 'woocommerce_pre_payment_complete', [ $this, 'guard_free_payment_continuation' ], PHP_INT_MAX );
				add_action( 'woocommerce_pre_payment_complete', [ $this, 'guard_free_payment_continuation' ], PHP_INT_MAX, 1 );
			}
			$this->continuations[$order] = [ 'route' => $route, 'revision' => $control_revision, 'local' => $local ]; return true;
		} catch ( \Throwable ) { return false; }
	}
	public function admitted( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $binding ): bool {
		try {
			$entry = $this->continuations[$order] ?? null;
			if ( null !== $entry ) { return $entry['route'] === $route && $entry['revision'] === $control_revision && $entry['local'] === $binding && $binding->unchanged(); }
			return ! QuoteNativeOrderHistory::attempted( $order ) && ( 'order_pay' === $route || ! $this->new_cart_owned() );
		} catch ( \Throwable ) { return false; }
	}

	/** Native pay_action saves the selected method and validates fields after C07. */
	public function guard_payment_gateways( mixed $gateways ): mixed {
		if ( null === $this->pay_continuation ) { return $gateways; }
		if ( ! is_array( $gateways ) || ! $this->terminal_gateway_filter() ) { throw new \RuntimeException( EmergencyControlResponse::message() ); }
		$id = $this->pay_continuation->method(); $gateway = $gateways[$id] ?? null;
		if ( null === $gateway ) { return $gateways; }
		if ( ! $gateway instanceof \WC_Payment_Gateway || $gateway->id !== $id ) { throw new \RuntimeException( EmergencyControlResponse::message() ); }
		if ( ! $gateway instanceof QuoteOrderPayGateway ) { $gateways[$id] = new QuoteOrderPayGateway( $gateway, $this->pay_continuation, [ $this, 'terminal_gateway_filter' ] ); }
		return $gateways;
	}
	/** A same-priority later replacement must never remove the native boundary. */
	public function terminal_gateway_filter(): bool {
		$hook = $GLOBALS['wp_filter']['woocommerce_available_payment_gateways'] ?? null;
		if ( ! $hook instanceof \WP_Hook || ! is_array( $hook->callbacks ) ) { return false; }
		$callbacks = $hook->callbacks; if ( [] === $callbacks ) { return false; } ksort( $callbacks, SORT_NUMERIC ); $last = end( $callbacks ); $priority = key( $callbacks ); $callback = is_array( $last ) && [] !== $last ? end( $last ) : null;
		return PHP_INT_MAX === $priority && is_array( $callback ) && ( $callback['accepted_args'] ?? null ) === 1 && ( $callback['function'] ?? null ) === [ $this, 'guard_payment_gateways' ];
	}
	/** Before native free completion, retain only the already acknowledged facts. */
	public function guard_free_payment_continuation( mixed $order_id ): void {
		if ( null === $this->pay_continuation || $this->pay_continuation->delegated() || $order_id !== $this->pay_continuation->order_id() ) { return; }
		$hook = $GLOBALS['wp_filter']['woocommerce_pre_payment_complete'] ?? null;
		$callbacks = $hook instanceof \WP_Hook ? $hook->callbacks : []; ksort( $callbacks, SORT_NUMERIC ); $last = end( $callbacks ); $callback = is_array( $last ) && [] !== $last ? end( $last ) : null;
		if ( key( $callbacks ) !== PHP_INT_MAX || ! is_array( $callback ) || ( $callback['accepted_args'] ?? null ) !== 1 || ( $callback['function'] ?? null ) !== [ $this, 'guard_free_payment_continuation' ] ) { throw new \RuntimeException( EmergencyControlResponse::message() ); }
		if ( ! $this->pay_continuation->verify_free() ) { throw new \RuntimeException( EmergencyControlResponse::message() ); }
		$this->pay_continuation->delegate();
	}
	/** Before native Store API callbacks, retain the same acknowledged order. */
	public function guard_store_payment_context( mixed $context, mixed $result ): void {
		if ( null === $this->pay_continuation ) { return; }
		if ( ! $this->store_payment_hook_supported() || ! is_object( $context ) || 'Automattic\\WooCommerce\\StoreApi\\Payments\\PaymentContext' !== get_class( $context ) || ! $context->order instanceof \WC_Order || $context->order->get_id() !== $this->pay_continuation->order_id() || $context->payment_method !== $this->pay_continuation->method() || ! $this->pay_continuation->verify() ) { throw new \RuntimeException( EmergencyControlResponse::message() ); }
	}
	private function store_payment_hook_supported(): bool {
		$hook = $GLOBALS['wp_filter']['woocommerce_rest_checkout_process_payment_with_context'] ?? null;
		if ( ! $hook instanceof \WP_Hook || ! is_array( $hook->callbacks ) ) { return false; } $ours = 0; $native = 0;
		foreach ( $hook->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $callback ) {
			$fn = $callback['function'] ?? null; if ( ( $callback['accepted_args'] ?? null ) !== 2 || ! is_array( $fn ) ) { return false; }
			if ( $fn === [ $this, 'guard_store_payment_context' ] && -PHP_INT_MAX === $priority ) { ++$ours; continue; }
			if ( is_object( $fn[0] ?? null ) && 'Automattic\\WooCommerce\\StoreApi\\Legacy' === get_class( $fn[0] ) && 'process_legacy_payment' === ( $fn[1] ?? null ) && 999 === $priority ) { ++$native; continue; }
			return false;
		} } return 1 === $ours && 1 === $native;
	}

	/** Native order-pay authorization, before any private quote/binding lookup. */
	public function authorize_saved_order( \WC_Order $order ): bool {
		try {
			$id = $order->get_id(); if ( ! is_int( $id ) || $id < 1 || ! method_exists( $order, 'get_customer_id' ) || ! method_exists( $order, 'get_order_key' ) ) { return false; }
			$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			$customer = $order->get_customer_id( 'edit' );
			$key = $_GET['key'] ?? $_POST['key'] ?? null; $stored = $order->get_order_key( 'edit' );
			$exact_key = is_string( $key ) && strlen( $key ) <= 128 && is_string( $stored ) && '' !== $stored && hash_equals( $stored, $key );
			$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
			if ( $user > 0 ) {
				// Pinned Woo grants pay_for_order for one's customer order or a guest
				// order; its native pay handler also checks the exact retained key.
				if ( ! in_array( $customer, [ 0, $user ], true ) || ! function_exists( 'current_user_can' ) || ! current_user_can( 'pay_for_order', $id ) ) { return false; }
				return $exact_key || $native instanceof \WC_Session_Handler && \WC_Session_Handler::class === get_class( $native ) && $this->authorize_checkout_pointer( $order, $native );
			}
			if ( 0 !== $customer ) { return false; }
			return $native instanceof \WC_Session_Handler && \WC_Session_Handler::class === get_class( $native ) && $native->has_session()
				&& ( $this->authorize_checkout_pointer( $order, $native ) || $exact_key );
		} catch ( \Throwable ) { return false; }
	}

	/** Detach an old sealed Classic pointer before Woo's item-deletion resume path. */
	public function protect_classic_retry(): void {
		$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
		if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) ) { return; }
		$id = $native->get( 'order_awaiting_payment', 0 );
		$original = $native->get( self::SESSION_CONTINUATION );
		if ( 0 === $id && self::valid_continuation( $original ) ) { $id = $original['order_id']; }
		if ( ! is_int( $id ) || $id < 1 || ! function_exists( 'wc_get_order' ) ) { return; }
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) { return; }
		if ( ! self::valid_continuation( $original ) || $original['order_id'] !== $id ) { if ( QuoteNativeOrderHistory::owned( $order ) ) { self::refuse( 'classic' ); } return; }
		if ( ! $this->authorize_checkout_pointer( $order, $native ) ) { self::refuse( 'classic' ); }
		// The pointer belongs to this native authorized checkout session. Do not
		// expose a saved order or reuse it through a browser-supplied order ID.
		$evidence = $this->cart->current( RequestContext::create() );
		if ( null !== $evidence && $original['quote_id'] === $evidence->header()->id()->value() ) {
			if ( ! $this->classic_payload_matches( $order ) ) { self::refuse( 'classic' ); }
			if ( $native->get( 'order_awaiting_payment', 0 ) !== $id ) { $native->set( 'order_awaiting_payment', $id ); $native->save_data(); if ( ! $this->acknowledge_native_session( $native ) ) { self::refuse( 'classic' ); } }
			return;
		}
		if ( null === $evidence || ! $this->original_is_disposed( $native, $original, $evidence ) ) { self::refuse( 'classic' ); }
		$native->set( 'order_awaiting_payment', 0 ); $native->set( self::SESSION_CONTINUATION, null ); $native->save_data();
		if ( ! $this->acknowledge_native_session( $native ) ) { self::refuse( 'classic' ); }
	}
	/** Pinned Woo invokes this before remove_order_items; sealed facts stay untouched. */
	public function resume_classic( mixed $order_id ): void {
		if ( ! is_int( $order_id ) || $order_id < 1 || ! function_exists( 'wc_get_order' ) ) { return; }
		$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
		if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || $native->get( 'order_awaiting_payment', 0 ) !== $order_id ) { self::refuse( 'classic' ); }
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) { return; }
		$original = $native->get( self::SESSION_CONTINUATION );
		if ( ! self::valid_continuation( $original ) || $original['order_id'] !== $order_id ) { if ( QuoteNativeOrderHistory::owned( $order ) ) { self::refuse( 'classic' ); } return; }
		if ( ! $this->authorize_checkout_pointer( $order, $native ) || ! method_exists( $order, 'get_checkout_payment_url' ) ) { self::refuse( 'classic' ); }
		try { $this->stage( $order, $original['route'] ); } catch ( \Throwable ) { self::refuse( 'classic' ); }
		if ( ! $this->finish_recovery( $order, $original['route'] ) ) { self::refuse( 'classic' ); }
		$url = $order->get_checkout_payment_url(); $this->redirect_payment( $url );
	}

	private function stage( \WC_Order $order, string $route ): void {
		$coordinate = $this->coordinate( $order );
		if ( isset( $this->stages[$coordinate] ) ) { if ( $this->stages[$coordinate]['route'] !== $route || ! QuoteNativeOrderHistory::verify( $order ) ) { self::refuse( $route ); } return; }
		$logging = 'classic' === $route ? QuoteNativeCheckoutLoggingEvidence::begin_classic() : null;
		$evidence = $this->cart->current( RequestContext::create() ); if ( null === $evidence ) { throw new \RuntimeException( 'Quote placement accepted evidence unavailable.' ); }
		$service = new QuotePlacementService( $this->durable( $evidence ), $evidence );
		$this->preserve_original_pointer( $order, $evidence, $service->placement_id(), $route );
		$evidence = $this->refresh_evidence( $evidence ); $service = new QuotePlacementService( $this->durable( $evidence ), $evidence );
		$prepared = $service->prepare( $order->get_id(), $this->stager->mapping( $order, $evidence ), RequestContext::create() );
		if ( 'accepted' !== $prepared->attempt->outcome->state || null === $prepared->binding || ! in_array( $prepared->binding->revision(), [ 1, 2, 3 ], true ) ) { throw new \RuntimeException( 'Quote placement preparation did not acknowledge: ' . $prepared->attempt->outcome->state . '/' . ( $prepared->attempt->outcome->error?->code ?? 'none' ) ); }
		if ( 1 === $prepared->binding->revision() ) {
			$stage = $this->stager->stage( $order, $evidence, $prepared->binding, $route );
			$bindings = $this->stage_bindings( $order, $stage );
			$binding = $service->verified_binding( $prepared->binding, $stage->snapshot_digest(), $stage->context_digest(), QuoteTime::now() );
			$verified = $service->verify( $binding, $stage->guard(), RequestContext::create() );
			if ( 'accepted' !== $verified->attempt->outcome->state || null === $verified->binding || 'prepared' !== $verified->binding->state() || 2 !== $verified->binding->revision() ) { throw new \RuntimeException( 'Quote placement verification did not acknowledge: ' . $verified->attempt->outcome->state . '/' . ( $verified->attempt->outcome->error?->code ?? 'none' ) ); }
			$binding = $verified->binding;
		} else {
			$binding = $prepared->binding; $stage = $this->stager->saved_guard( $order, $evidence->quote_record(), $binding );
			$bindings = $this->stage_bindings( $order, $stage );
		}
		if ( ! $bindings['local']->unchanged() || ! $bindings['original']->unchanged() ) { throw new \RuntimeException( 'Native staged placement changed before verification acknowledged.' ); }
		$this->stages[$coordinate] = [ 'route' => $route, 'evidence' => $evidence, 'service' => $service, 'binding' => $binding, 'guard' => $stage->guard(), ...$bindings, 'method' => $order->get_payment_method( 'edit' ), 'title' => $order->get_payment_method_title( 'edit' ), 'logging' => $logging, 'logger_receipt' => null, 'logging_failed' => false ];
	}
	/** Freeze the created object and the independently verified persisted readback. */
	private function stage_bindings( \WC_Order $order, QuoteNativeOrderStageResult $stage ): array {
		$this->prewarm_native_order( $order );
		$local = EmergencyCheckoutLocalBinding::capture( $order ); if ( null === $local ) { throw new \RuntimeException( 'Native staged placement binding unavailable.' ); }
		$original = QuoteOrderPayLocalBinding::capture( $order );
		$saved = $stage->fresh_order();
		$this->prewarm_native_order( $saved );
		$native = QuoteOrderPayLocalBinding::capture( $saved );
		if ( ! $local->unchanged() || ! $original->unchanged() ) { throw new \RuntimeException( 'Native staged placement changed while capturing saved readback.' ); }
		return [ 'created' => $order, 'local' => $local, 'original' => $original, 'native' => $native ];
	}
	/** Only native metadata/group loading; called before any owned final SQL. */
	private function prewarm_native_order( \WC_Order $order ): void {
		$order->get_meta_data();
		foreach ( [ 'line_item', 'shipping', 'tax', 'fee', 'coupon' ] as $type ) {
			foreach ( $order->get_items( $type ) as $item ) { if ( is_object( $item ) && method_exists( $item, 'get_meta_data' ) ) { $item->get_meta_data(); } }
		}
	}
	private function stage_matches( \WC_Order $order, array $entry ): bool {
		if ( true === ( $entry['logging_failed'] ?? false ) || ! $entry['local']->unchanged() || ! $entry['original']->unchanged() || $order->get_payment_method( 'edit' ) !== $entry['method'] || $order->get_payment_method_title( 'edit' ) !== $entry['title'] ) { return false; }
		// Classic reloads the saved order before its final hook. Verify the exact
		// saved plan and prewarm all native item groups before comparing raw facts.
		$guard = $this->stager->saved_guard( $order, $entry['evidence']->quote_record(), $entry['binding'] );
		$logging = $entry['logger_receipt'] ?? null;
		if ( $logging instanceof QuoteNativeCheckoutLoggingEvidence && ( 'classic' !== $entry['route'] || 4 !== $logging->phase() || ! $logging->is_current() || ! $logging->matches_saved( $guard->fresh_order() ) ) ) { return false; }
		return $entry['local']->unchanged() && $entry['original']->unchanged() && $order->get_payment_method( 'edit' ) === $entry['method'] && $order->get_payment_method_title( 'edit' ) === $entry['title'] && ( $entry['created'] === $order || $entry['native']->same_native_facts( $order ) || $logging instanceof QuoteNativeCheckoutLoggingEvidence && $entry['native']->same_native_facts( $order, $logging ) ) && $guard->snapshot_digest() === $entry['binding']->row()['snapshot_digest'] && $guard->context_digest() === $entry['binding']->row()['context_digest'];
	}
	private function durable( QuotePlacementEvidence $evidence ): QuoteDurableService { return new QuoteDurableService( $this->factory, new QuoteProviderRegistry(), [ $evidence, 'authorize' ], evidence: $evidence->guard() ); }
	private function refresh_evidence( QuotePlacementEvidence $original ): QuotePlacementEvidence {
		$fresh = $this->cart->current( RequestContext::create() );
		if ( null === $fresh || ! $fresh->owner()->equals( $original->owner() ) || $fresh->header()->to_private_json() !== $original->header()->to_private_json() || $fresh->reference()->public_fields() !== $original->reference()->public_fields() || $fresh->draft_facts() !== $original->draft_facts() || $fresh->current_context()->digest() !== $original->current_context()->digest() ) { throw new \RuntimeException( 'Original quote placement evidence changed.' ); }
		return $fresh;
	}
	private function coordinate( \WC_Order $order ): string { $site = get_current_blog_id(); $id = $order->get_id(); if ( ! is_int( $site ) || $site < 1 || ! is_int( $id ) || $id < 1 ) { throw new \RuntimeException( 'Quote placement unavailable.' ); } return $site . ':' . $id; }
	private function new_cart_owned(): bool { try { return true === ( $this->active )() && true === ( $this->managed_cart )(); } catch ( \Throwable ) { return true; } }
	private function preserve_original_pointer( \WC_Order $order, QuotePlacementEvidence $evidence, string $placement, string $route ): void {
		$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
		if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.session' ) ) { self::refuse( $route ); }
		$original = $this->continuation( $order, $evidence, $placement, $route ); $prior = $native->get( self::SESSION_CONTINUATION );
		if ( null !== $prior && $prior !== $original ) {
			// Explicit new confirmed intent follows only acknowledged sealed history
			// or a proved terminal no-effect refusal. Uncertain identity is retained.
			if ( ! self::valid_continuation( $prior ) || $prior['quote_id'] === $original['quote_id'] || ! $this->original_is_disposed( $native, $prior, $evidence ) ) { self::refuse( $route ); }
			$native->set( 'order_awaiting_payment', 0 ); $native->set( 'store_api_draft_order', 0 ); $native->set( self::SESSION_CONTINUATION, null ); $native->save_data();
			if ( ! $this->acknowledge_native_session( $native ) ) { self::refuse( $route ); }
		}
		$key = 'classic' === $route ? 'order_awaiting_payment' : 'store_api_draft_order'; $pointer = $native->get( $key, 0 );
		if ( $pointer !== 0 && $pointer !== $order->get_id() ) { self::refuse( $route ); }
		$native->set( self::SESSION_CONTINUATION, $original ); if ( 'classic' === $route ) { $native->set( $key, $order->get_id() ); } $native->save_data();
		if ( ! $this->acknowledge_native_session( $native ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.session' ) ) { throw new \RuntimeException( 'Quote placement original native pointer did not acknowledge.' ); }
	}
	private function continuation( \WC_Order $order, QuotePlacementEvidence $evidence, string $placement, string $route ): array { return [ 'format' => 1, 'order_id' => $order->get_id(), 'placement_id' => $placement, 'quote_id' => $evidence->header()->id()->value(), 'route' => $route ]; }
	private function original_is_disposed( \WC_Session_Handler $native, array $original, QuotePlacementEvidence $current ): bool {
		$session = null; $begun = false;
		try {
			if ( ! self::valid_continuation( $original ) || $original['quote_id'] === $current->header()->id()->value() || $native->get( self::SESSION_CONTINUATION ) !== $original || ! $current->authorize( $current->owner(), 'delivery_quote.read' ) ) { return false; }
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $original['order_id'] ) : null;
			if ( ! $order instanceof \WC_Order || ! $this->authorize_checkout_pointer( $order, $native ) ) { return false; }
			$session = $this->factory->open(); ( new \CetechDeliveryEngine\Application\Operation\DatabaseOperationReadiness() )->assert_ready( $session );
			if ( $session->site_id() !== $current->owner()->site_id() || $session->in_transaction() || $session->is_retired() || ! $session->begin() ) { return false; } $begun = true;
			$repository = new \CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository( $session );
			$quote = $repository->find_quote( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string( $original['quote_id'] ) );
			if ( null === $quote || ! $quote->header()->owner()->equals( $current->owner() ) ) { return false; }
			$binding = $repository->find_binding( $quote ); $verifier = new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteReceiptVerifier(); $receipts = $verifier->lock( $session, $quote->header(), $binding );
			$fresh = $repository->find_quote( $quote->header()->id(), true ); $latest = null === $fresh ? null : $repository->find_binding( $fresh, true );
			if ( null === $fresh || $fresh->row() !== $quote->row() || $latest?->row() !== $binding?->row() || null !== $latest && ( $latest->row()['order_id'] !== $original['order_id'] || $latest->row()['placement_uuid'] !== $original['placement_id'] || ! ( 'sealed' === $latest->state() && 3 === $latest->revision() || 'prepared' === $latest->state() && in_array( $latest->revision(), [ 1, 2 ], true ) ) ) || ! $fresh->header()->owner()->equals( $current->owner() ) || ! $verifier->verify( $fresh, $receipts, $latest ) || ! $session->rollback() ) { return false; }
			$begun = false; if ( ! $session->retire() || $native->get( self::SESSION_CONTINUATION ) !== $original || ! $this->authorize_checkout_pointer( $order, $native ) || ! $current->authorize( $current->owner(), 'delivery_quote.read' ) ) { return false; }
			if ( null !== $latest && 'sealed' === $latest->state() ) { return QuoteNativeOrderHistory::verify( $order ); }
			if ( null === $latest || 1 === $latest->revision() ) {
				$guard = QuotePlacementNoEffectNativeGuard::capture( $this->factory, $this->stager, $order, $fresh, $latest );
				return null !== $guard && $this->durable( $current )->known_rejected_original_placement( $current, $fresh, $original['order_id'], $original['placement_id'], $latest, $guard ) && $guard->unchanged() && $native->get( self::SESSION_CONTINUATION ) === $original && $this->authorize_checkout_pointer( $order, $native ) && $current->authorize( $current->owner(), 'delivery_quote.read' );
			}
			if ( ! QuoteNativeOrderHistory::verify( $order ) ) { return false; }
			$reference_json = $order->get_meta( QuoteNativeOrderFacts::META_REFERENCE, true ); if ( ! is_string( $reference_json ) || strlen( $reference_json ) > 4096 ) { return false; }
			$reference = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference::from_array( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::decode( $reference_json, 4096 ) );
			$guard = $this->stager->saved_guard( $order, $fresh, $latest );
			return $this->durable( $current )->known_rejected_placement( $current->owner(), $reference, $fresh->header(), $latest, $guard ) && $native->get( self::SESSION_CONTINUATION ) === $original && $this->authorize_checkout_pointer( $order, $native ) && $current->authorize( $current->owner(), 'delivery_quote.read' );
		} catch ( \Throwable ) { return false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	private static function valid_continuation( mixed $value ): bool {
		if ( ! is_array( $value ) || array_keys( $value ) !== [ 'format', 'order_id', 'placement_id', 'quote_id', 'route' ] || 1 !== $value['format'] || ! is_int( $value['order_id'] ) || $value['order_id'] < 1 || ! in_array( $value['route'], [ 'classic', 'store_api' ], true ) ) { return false; }
		try { \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string( $value['placement_id'] ); \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string( $value['quote_id'] ); return true; } catch ( \Throwable ) { return false; }
	}
	private function authorize_checkout_pointer( \WC_Order $order, \WC_Session_Handler $native ): bool {
		try {
			$original = $native->get( self::SESSION_CONTINUATION ); if ( ! self::valid_continuation( $original ) || $original['order_id'] !== $order->get_id() || ! $native->has_session() ) { return false; }
			$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			return $order->get_customer_id( 'edit' ) === $user && ( 0 === $user || function_exists( 'current_user_can' ) && current_user_can( 'pay_for_order', $order->get_id() ) );
		} catch ( \Throwable ) { return false; }
	}
	private function finish_recovery( \WC_Order $order, string $route ): bool {
		if ( null === $this->final_admission ) { return false; }
		$entry = $this->stages[$this->coordinate( $order )] ?? null; if ( null !== $entry && 'sealed' === $entry['binding']->state() ) { $route = 'order_pay'; }
		$result = ( $this->final_admission )( $order, $route ); return $result instanceof \CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionResult ? $result->allowed : true === $result;
	}
	/** Recovery is read-only; a changed synchronized native payload needs new review. */
	private function store_payload_matches( \WC_Order $order, \WP_REST_Request $request ): bool {
		try {
			foreach ( [ 'billing', 'shipping' ] as $type ) {
				$incoming = $request->get_param( $type . '_address' ); if ( ! is_array( $incoming ) ) { return false; }
				foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $field ) { $value = $incoming[$field] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > 1024 || $value !== $order->{'get_' . $type . '_' . $field}( 'edit' ) ) { return false; } }
			}
			$method = $request->get_param( 'payment_method' ); return is_string( $method ) && strlen( $method ) <= 200 && $method === $order->get_payment_method( 'edit' );
		} catch ( \Throwable ) { return false; }
	}
	private function classic_payload_matches( \WC_Order $order ): bool {
		try {
			$checkout = function_exists( 'WC' ) ? WC()->checkout() : null; if ( ! $checkout instanceof \WC_Checkout ) { return false; }
			$data = $checkout->get_posted_data(); if ( ! is_array( $data ) ) { return false; }
			foreach ( [ 'billing', 'shipping' ] as $type ) {
				$source = 'shipping' === $type && empty( $data['ship_to_different_address'] ) ? 'billing' : $type;
				foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $field ) { $value = $data[$source . '_' . $field] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > 1024 || $value !== $order->{'get_' . $type . '_' . $field}( 'edit' ) ) { return false; } }
			}
			$method = $data['payment_method'] ?? null; return is_string( $method ) && strlen( $method ) <= 200 && $method === $order->get_payment_method( 'edit' );
		} catch ( \Throwable ) { return false; }
	}
	private function store_recovery_error(): \WP_Error { return new \WP_Error( 'cetech_de_quote_placement_unavailable', 'Checkout needs review before payment. Please return to your cart and try again.', [ 'status' => 409 ] ); }

	/** Clear the mutable draft pointer and acknowledge the exact native persisted row. */
	private function saved_authorization_guard( \WC_Order $order, QuotePlacementSavedEvidenceGuard $guard ): QuotePlacementSavedEvidenceGuard {
		$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
		if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || ! $this->authorize_saved_order( $order ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); }
		$native->save_data();
		if ( ! $this->acknowledge_native_session( $native ) || ! $this->authorize_saved_order( $order ) ) { throw new \RuntimeException( 'Saved quote authorization unavailable.' ); }
		return new QuoteNativeSessionPlacementGuard( $guard, $native, self::session_bytes( $native ), $native->get_customer_id(), self::session_expiry( $native ) );
	}
	private function retain_classic_pointer( \WC_Order $order, QuotePlacementEvidence $evidence, QuoteBinding $binding, QuotePlacementSavedEvidenceGuard $guard ): QuotePlacementSavedEvidenceGuard {
		$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
		if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || $native->get( 'order_awaiting_payment', 0 ) !== $order->get_id() || $native->get( self::SESSION_CONTINUATION ) !== $this->continuation( $order, $evidence, $binding->row()['placement_uuid'], 'classic' ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.session' ) || ! $this->acknowledge_native_session( $native ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); }
		return new QuoteNativeSessionPlacementGuard( $guard, $native, self::session_bytes( $native ), $native->get_customer_id(), self::session_expiry( $native ) );
	}
	private function detach_store_pointer( \WC_Order $order, QuotePlacementEvidence $evidence, QuoteBinding $binding, QuotePlacementSavedEvidenceGuard $guard ): QuotePlacementSavedEvidenceGuard {
		$native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null;
		if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.session' ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); }
		$continuation = $this->continuation( $order, $evidence, $binding->row()['placement_uuid'], 'store_api' );
		if ( $native->get( 'store_api_draft_order', 0 ) !== $order->get_id() && ( $native->get( 'store_api_draft_order', 0 ) !== 0 || $native->get( self::SESSION_CONTINUATION ) !== $continuation ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); }
		$native->set( self::SESSION_CONTINUATION, $continuation ); $native->set( 'store_api_draft_order', 0 ); $native->save_data();
		if ( ! $this->acknowledge_native_session( $native ) || ! $evidence->authorize( $evidence->owner(), 'delivery_quote.session' ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); }
		return new QuoteNativeSessionPlacementGuard( $guard, $native, self::session_bytes( $native ), $native->get_customer_id(), self::session_expiry( $native ) );
	}
	private function acknowledge_native_session( \WC_Session_Handler $native ): bool {
		$session = null; $begun = false;
		try {
			$bytes = self::session_bytes( $native ); $key = $native->get_customer_id(); $expiry = self::session_expiry( $native );
			$session = $this->factory->open();
			if ( $session->site_id() !== get_current_blog_id() || $session->in_transaction() || $session->is_retired() || ! $session->begin() ) { return false; } $begun = true;
			$table = $session->table_prefix() . 'woocommerce_sessions';
			if ( ! $session->validate_tables( [ $table ] ) || ! QuoteNativeSessionPlacementGuard::row_matches( $session, $key, $bytes, $expiry ) || ! $session->rollback() ) { return false; }
			$begun = false; return $session->retire() && self::session_bytes( $native ) === $bytes && self::session_expiry( $native ) === $expiry;
		} catch ( \Throwable ) { return false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	public static function session_bytes( \WC_Session_Handler $native ): string {
		$data = self::raw_session( $native, '_data' );
		if ( ! is_array( $data ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); }
		$nodes = 0; self::session_primitives( $data, 0, $nodes ); $bytes = serialize( $data );
		if ( strlen( $bytes ) > 1048576 ) { throw new \RuntimeException( 'Quote placement unavailable.' ); } return $bytes;
	}
	public static function session_expiry( \WC_Session_Handler $native ): int { $value = self::raw_session( $native, '_session_expiration' ); if ( ! is_int( $value ) || $value < 1 ) { throw new \RuntimeException( 'Quote placement unavailable.' ); } return $value; }
	private static function raw_session( \WC_Session_Handler $native, string $property ): mixed { $r = new \ReflectionObject( $native ); $p = $r->getProperty( $property ); if ( $p->isStatic() || ! $p->isInitialized( $native ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); } return method_exists( $p, 'getRawValue' ) ? $p->getRawValue( $native ) : $p->getValue( $native ); }
	private static function session_primitives( mixed $value, int $depth, int &$nodes ): void { if ( ++$nodes > 20000 || $depth > 20 || is_object( $value ) || is_resource( $value ) || is_float( $value ) && ! is_finite( $value ) ) { throw new \RuntimeException( 'Quote placement unavailable.' ); } if ( is_array( $value ) ) { foreach ( $value as $child ) { self::session_primitives( $child, $depth + 1, $nodes ); } } }
	private function redirect_payment( mixed $url ): never {
		if ( ! $this->safe_payment_url( $url ) ) { self::refuse( 'classic' ); }
		$ok = null !== $this->redirect ? true === ( $this->redirect )( $url, 303 ) : function_exists( 'wp_safe_redirect' ) && wp_safe_redirect( $url, 303 );
		if ( ! $ok ) { self::refuse( 'classic' ); } if ( null !== $this->terminate ) { ( $this->terminate )(); } exit;
	}
	private function safe_payment_url( mixed $url ): bool {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! function_exists( 'home_url' ) ) { return false; }
		$parts = parse_url( $url ); $home = parse_url( home_url() );
		return is_array( $parts ) && is_array( $home ) && ( $parts['host'] ?? null ) === ( $home['host'] ?? null ) && ( $parts['port'] ?? null ) === ( $home['port'] ?? null ) && ( $parts['scheme'] ?? null ) === ( $home['scheme'] ?? null ) && in_array( $parts['scheme'] ?? null, [ 'http', 'https' ], true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}
	private static function refuse( string $route ): never { if ( 'store_api' === $route ) { EmergencyControlResponse::reject_store_api(); } EmergencyControlResponse::reject_classic(); }
}

/** Exact acknowledged native pointer disposition, retained through final receipt. */
final readonly class QuoteNativeSessionPlacementGuard implements QuotePlacementSavedEvidenceGuard {
	public function __construct( private QuotePlacementSavedEvidenceGuard $saved, private \WC_Session_Handler $native, private string $bytes, private string $key, private int $expiry ) {}
	public function tables( OperationSession $session ): array { return [ ...$this->saved->tables( $session ), $session->table_prefix() . 'woocommerce_sessions' ]; }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool {
		try { return QuotePlacementRuntime::session_bytes( $this->native ) === $this->bytes && QuotePlacementRuntime::session_expiry( $this->native ) === $this->expiry && $this->saved->verify( $session, $binding ) && self::row_matches( $session, $this->key, $this->bytes, $this->expiry ); }
		catch ( \Throwable ) { return false; }
	}
	public static function row_matches( OperationSession $session, string $key, string $bytes, int $expiry ): bool {
		if ( '' === $key || strlen( $key ) > 128 || strlen( $bytes ) > 1048576 || $expiry < 1 ) { return false; }
		$table = $session->table_prefix() . 'woocommerce_sessions';
		$rows = $session->get_results( $session->prepare( "SELECT session_key,CASE WHEN OCTET_LENGTH(session_value)<=1048576 THEN session_value ELSE NULL END AS session_value,session_expiry FROM `{$table}` WHERE session_key=%s LIMIT 2 FOR UPDATE", $key ) );
		return is_array( $rows ) && 1 === count( $rows ) && ( $rows[0]['session_key'] ?? null ) === $key && ( $rows[0]['session_value'] ?? null ) === $bytes && (string) ( $rows[0]['session_expiry'] ?? '' ) === (string) $expiry;
	}
}
