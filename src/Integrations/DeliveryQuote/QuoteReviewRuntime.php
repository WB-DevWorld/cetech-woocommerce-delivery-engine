<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteReviewService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Presentation\Frontend\QuoteReviewRenderer;

/** Explicit prepared-review transports. Placement activation belongs to Q06. */
final class QuoteReviewRuntime {

	public const NAMESPACE = 'cetech-delivery-quote-review';
	public const AJAX_ACTION = 'cetech_delivery_quote_review';
	public const NONCE_ACTION = 'cetech_delivery_quote_review';
	public const SCRIPT_HANDLE = 'cetech-de-delivery-quote-review';
	private bool $registered = false;
	private bool $store_registered = false;
	private ?\Closure $adoption_gate;

	/** The trusted server composition supplies this gate; browser fields cannot mount it. */
	public function __construct(
		private readonly CartQuoteReviewService $service,
		private readonly bool $prepared_review_enabled = false,
		private readonly QuoteReviewRenderer $renderer = new QuoteReviewRenderer(),
		?callable $adoption_gate = null
	) { $this->adoption_gate = null === $adoption_gate ? null : \Closure::fromCallable( $adoption_gate ); }
	private function enabled(): bool { try { return $this->prepared_review_enabled && ( null === $this->adoption_gate || true === ( $this->adoption_gate )() ); } catch ( \Throwable ) { return false; } }

	public function register(): void {
		if ( ! $this->enabled() || $this->registered ) { return; }
		$this->registered = true;
		add_action( 'wc_ajax_' . self::AJAX_ACTION, [ $this, 'handle_classic_post' ] );
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_store_api' ], 5 );
		add_action( 'woocommerce_review_order_after_shipping', [ $this, 'render_classic' ] );
		add_action( 'woocommerce_cart_totals_after_shipping', [ $this, 'render_classic' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		$this->register_store_api();
	}

	public function register_store_api(): void {
		if ( ! $this->enabled() || $this->store_registered
			|| ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
			|| ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) { return; }
		foreach ( [ 'cart', 'checkout' ] as $endpoint ) {
			woocommerce_store_api_register_endpoint_data( [ 'endpoint' => $endpoint, 'namespace' => self::NAMESPACE,
				'data_callback' => [ $this, 'current_facts' ], 'schema_callback' => [ $this, 'schema' ], 'schema_type' => ARRAY_A ] );
		}
		woocommerce_store_api_register_update_callback( [ 'namespace' => self::NAMESPACE, 'callback' => [ $this, 'handle_store_api_update' ] ] );
		$this->store_registered = true;
	}

	/** Read callbacks never capture, issue or accept; native totals re-read current authority. */
	public function current_facts(): array {
		$request = RequestContext::create();
		if ( ! $this->enabled() ) { return self::unavailable( $request ); }
		try { return $this->service->current( $request )->shopper_facts(); }
		catch ( \Throwable ) { return self::unavailable( $request ); }
	}

	/** Called only through native POST/cart/extensions, whose route checks its native nonce. */
	public function handle_store_api_update( array $data ): void { $this->dispatch( $data ); }

	/** Exact caller fields; references, owners, prices and arbitrary context are never command inputs. */
	public function dispatch( array $data ): array {
		if ( ! $this->enabled() ) { self::refuse(); }
		$action = $data['action'] ?? null;
		$keys = 'refresh' === $action ? [ 'action', 'generation', 'review_token' ] : [ 'action', 'generation' ];
		if ( ! is_string( $action ) || ! in_array( $action, [ 'refresh', 'confirm', 'retry' ], true )
			|| count( $data ) !== count( $keys ) || array_diff( array_keys( $data ), $keys )
			|| ! is_int( $data['generation'] ?? null ) || $data['generation'] < 0 || $data['generation'] > 9007199254740991 ) { self::refuse(); }
		if ( 'refresh' === $action && ( ! is_string( $data['review_token'] )
			|| 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $data['review_token'] ) ) ) { self::refuse(); }
		$request = RequestContext::create();
		try {
			$result = match ( $action ) {
				'refresh' => $this->service->refresh( $data['review_token'], $data['generation'], $request ),
				'confirm' => $this->service->confirm( $data['generation'], $request ),
				'retry' => $this->service->retry( $data['generation'], $request ),
			};
			return $result->shopper_facts();
		} catch ( \Throwable ) { self::refuse( 503 ); }
	}

	/** Native wc-ajax POST uses its real current session and purpose-specific CSRF nonce. */
	public function handle_classic_post(): void {
		try { $facts = $this->classic_response( wp_unslash( $_POST ), (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ); }
		catch ( \Throwable $error ) {
			$status = in_array( $error->getCode(), [ 400, 403, 405, 503 ], true ) ? $error->getCode() : 503;
			wp_send_json_error( [ 'code' => 'quote_review_unavailable', 'message' => QuoteReviewRenderer::message( 'unavailable' ) ], $status );
			return;
		}
		wp_send_json_success( $facts );
	}

	/** Same native handler boundary, exposed separately for finite transport tests. */
	public function classic_response( array $data, string $method ): array {
		if ( 'POST' !== $method || ! $this->enabled() ) { self::refuse( 405 ); }
		if ( ! is_array( $data ) || ! is_string( $data['_wpnonce'] ?? null )
			|| ! wp_verify_nonce( $data['_wpnonce'], self::NONCE_ACTION ) ) { self::refuse( 403 ); }
		unset( $data['_wpnonce'] );
		if ( is_string( $data['generation'] ?? null ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]{0,15})\z/D', $data['generation'] ) ) {
			$data['generation'] = (int) $data['generation'];
		}
		return $this->dispatch( $data );
	}

	public function render_classic(): void {
		if ( ! $this->enabled() ) { return; }
		try {
			$result = $this->service->current( RequestContext::create() );
			echo $this->renderer->table_row( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- explicit renderer escapes each field.
		} catch ( \Throwable ) {
			echo $this->renderer->unavailable_table_row(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed escaped copy.
		}
	}

	public function enqueue_assets(): void {
		if ( ! $this->enabled() || ! ( function_exists( 'is_cart' ) && is_cart() || function_exists( 'is_checkout' ) && is_checkout() ) ) { return; }
		$base = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';
		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';
		wp_enqueue_script( self::SCRIPT_HANDLE, $base . 'assets/frontend/delivery-quote-review.js', [ 'wp-data' ], $version, true );
		wp_enqueue_style( self::SCRIPT_HANDLE, $base . 'assets/frontend/delivery-quote-review.css', [], $version );
		wp_localize_script( self::SCRIPT_HANDLE, 'cetechDeQuoteReview', [ 'namespace' => self::NAMESPACE,
			'ajaxUrl' => class_exists( '\WC_AJAX' ) ? \WC_AJAX::get_endpoint( self::AJAX_ACTION ) : '',
			'nonce' => wp_create_nonce( self::NONCE_ACTION ), 'i18n' => [
				'messages' => array_combine( \CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteResult::STATUSES, array_map( QuoteReviewRenderer::message( ... ), \CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteResult::STATUSES ) ),
				'heldUntil' => __( 'Delivery price is held until', 'cetech-woocommerce-delivery-engine' ), 'unchanged' => __( 'Delivery details and price rules must stay unchanged.', 'cetech-woocommerce-delivery-engine' ),
				'refresh' => __( 'Refresh delivery', 'cetech-woocommerce-delivery-engine' ), 'confirm' => __( 'Confirm delivery price', 'cetech-woocommerce-delivery-engine' ), 'retry' => __( 'Retry same request', 'cetech-woocommerce-delivery-engine' ),
				'originalPromise' => __( 'Original recorded delivery estimate', 'cetech-woocommerce-delivery-engine' ), 'reviewLabel' => __( 'Delivery price review', 'cetech-woocommerce-delivery-engine' ),
			] ] );
	}

	public function schema(): array {
		$readonly = static fn ( string|array $type ): array => [ 'type' => $type, 'readonly' => true ];
		$money = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [
			'amount' => $readonly( 'string' ), 'currency' => $readonly( 'string' ), 'precision' => $readonly( 'integer' ) ] ];
		$component = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'customer_label' => $readonly( 'string' ),
			'list_price' => $money, 'promotion' => [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'state' => $readonly( 'string' ), 'amount' => [ ...$money, 'type' => [ 'object', 'null' ] ] ] ],
			'final_price' => $money, 'tax' => $money, 'rounded_tax' => [ ...$money, 'type' => [ 'object', 'null' ] ], 'total' => $money, 'display_total' => [ ...$money, 'type' => [ 'object', 'null' ] ] ] ];
		$promise = self::promise_schema( $readonly );
		return [ 'contract_version' => $readonly( 'integer' ), 'status' => $readonly( 'string' ), 'generation' => $readonly( 'integer' ),
			// Woo derives request defaults from direct object properties even when
			// readonly. oneOf preserves this response shape without inventing input.
			'quote' => [ 'type' => [ 'object', 'null' ], 'readonly' => true, 'oneOf' => [ [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [
				'contract_version' => $readonly( 'integer' ), 'decision_kind' => $readonly( 'string' ), 'quote_id' => $readonly( 'string' ), 'status' => $readonly( 'string' ),
				'currently_applicable' => $readonly( 'boolean' ), 'expires_at' => $readonly( 'string' ), 'customer_label' => $readonly( 'string' ),
				'money' => [ 'type' => 'array', 'maxItems' => 200, 'items' => $component, 'readonly' => true ], 'reason_code' => $readonly( [ 'string', 'null' ] ),
				'recovery_action' => $readonly( [ 'string', 'null' ] ), 'correlation_id' => $readonly( 'string' ), 'promise' => $promise ] ], [ 'type' => 'null' ] ] ],
			'can_refresh' => $readonly( 'boolean' ), 'can_confirm' => $readonly( 'boolean' ), 'can_retry' => $readonly( 'boolean' ),
			'message_code' => $readonly( 'string' ), 'correlation_id' => $readonly( 'string' ) ];
	}
	private static function promise_schema( callable $readonly ): array {
		$base = [ 'format_version' => $readonly( 'integer' ), 'service_label' => $readonly( 'string' ), 'state' => $readonly( 'string' ), 'display_timezone' => $readonly( 'string' ), 'reason_codes' => [ 'type' => 'array', 'readonly' => true, 'items' => $readonly( 'string' ) ] ];
		$object = static fn( array $properties ): array => [ 'type' => 'object', 'readonly' => true, 'additionalProperties' => false, 'properties' => $properties ];
		$view = [ 'readonly' => true, 'oneOf' => [ $object( $base + [ 'from' => $readonly( 'string' ), 'until' => $readonly( 'string' ) ] ), $object( $base + [ 'relative_explanation' => $readonly( 'string' ), 'min' => $readonly( 'integer' ), 'max' => $readonly( 'integer' ), 'unit' => $readonly( 'string' ), 'known_zero' => $readonly( 'boolean' ) ] ), $object( $base ) ] ];
		return $object( [ 'contract_version' => $readonly( 'integer' ), 'original' => $readonly( 'boolean' ), 'groups' => [ 'type' => 'array', 'readonly' => true, 'maxItems' => 200, 'items' => $object( [ 'views' => [ 'type' => 'array', 'readonly' => true, 'maxItems' => 16, 'items' => $view ], 'customer_text' => $readonly( 'string' ) ] ) ] ] );
	}

	private static function unavailable( RequestContext $request ): array {
		return [ 'contract_version' => 1, 'status' => 'unavailable', 'generation' => 0, 'quote' => null,
			'can_refresh' => false, 'can_confirm' => false, 'can_retry' => false, 'message_code' => 'unavailable', 'correlation_id' => $request->correlation_id ];
	}

	private static function refuse( int $status = 400 ): never {
		$message = __( 'Delivery quoting is temporarily unavailable. Try again.', 'cetech-woocommerce-delivery-engine' );
		$class = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
		if ( class_exists( $class ) ) { throw new $class( 'cetech_de_quote_review_unavailable', $message, $status ); }
		throw new \RuntimeException( $message, $status );
	}
}
