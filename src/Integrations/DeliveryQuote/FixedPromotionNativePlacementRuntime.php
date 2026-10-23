<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteBindService, FixedPromotionQuotePlacementEvidence, FixedPromotionQuotePlacementEvidenceReader, FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealService, FixedPromotionQuoteSealedEvidence, FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyInputs, FixedPromotionQuoteVerifyService, FixedPromotionSavedOrderPlacementEvidenceReader, QuoteCartDraft, QuoteNativeTaxSource, QuotePlacementSavedEvidenceGuard, QuotePlacementService, QuoteSavedOrderAuthorization};
use CetechDeliveryEngine\Application\EmergencyControl\{EmergencyCheckoutLocalBinding, EmergencyFinalPlacementGuard, EmergencyOrderQuoteValidatorInterface};
use CetechDeliveryEngine\Application\Order\{QuoteNativeOrderHistory, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};

/** Explicitly injected owned promotion handoff. Sealing retains holds and never admits payment. */
final class FixedPromotionNativePlacementRuntime implements EmergencyFinalPlacementGuard, EmergencyOrderQuoteValidatorInterface, \JsonSerializable {

	private \Closure $current;
	private \Closure $authorize_order;
	private ?\Closure $saved_inputs;
	private ?\Closure $selection;
	private array $stages = [];
	private array $observations = [];

	public function __construct( private OperationConnectionFactory $factory, private QuoteNativeOrderStager $stager, private FixedPromotionQuoteBindService $binder, private FixedPromotionQuoteVerifyService $verifier, private FixedPromotionQuoteSealService $sealer, private FixedPromotionQuotePlacementEvidenceReader $reader, private FixedPromotionSavedOrderPlacementEvidenceReader $saved, callable $current, callable $authorize_order, ?callable $saved_inputs = null, ?callable $selected = null ) {
		$this->current = \Closure::fromCallable( $current ); $this->authorize_order = \Closure::fromCallable( $authorize_order ); $this->saved_inputs = null === $saved_inputs ? null : \Closure::fromCallable( $saved_inputs ); $this->selection = null === $selected ? null : \Closure::fromCallable( $selected );
	}
	public function selected(): bool { try { return null === $this->selection ? null !== $this->bundle( RequestContext::create() ) : true === ( $this->selection )(); } catch ( \Throwable ) { return true; } }
	public function owns( \WC_Order $order ): bool { try { return isset( $this->stages[$this->coordinate( $order )] ) || $this->saved->owns( $order ); } catch ( \Throwable ) { return true; } }
	public function fingerprint( \WC_Order $order ): ?string { try { return true === ( $this->authorize_order )( $order ) ? \CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts::order_fingerprint( $order ) : null; } catch ( \Throwable ) { return null; } }

	/** Native order creation is complete; all native reads and writes precede the corresponding owned SQL. */
	public function stage( \WC_Order $order, string $route, ?RequestContext $request = null ): void {
		$request ??= RequestContext::create(); if ( ! in_array( $route, [ 'classic', 'store_api', 'order_pay' ], true ) || true !== ( $this->authorize_order )( $order ) ) { self::fail(); }
		$key = $this->coordinate( $order ); if ( isset( $this->stages[$key] ) ) { if ( ! $this->matches( $order, $this->stages[$key] ) || $this->stages[$key]['route'] !== $route ) { self::fail(); } return; }
		$logging = 'classic' === $route ? QuoteNativeCheckoutLoggingEvidence::begin_classic() : null;
		$bundle = $this->bundle( $request ); $owned = $this->saved->owns( $order ); $history = $owned ? $this->saved->read( $order, $request ) : null; $original = $owned && null === $history ? $this->saved->original( $order ) : null;
		if ( $owned && null === $history && null === $original ) { self::fail(); }
		if ( null !== $history ) {
			$bind = $history['bind']; $binding = $history['binding'];
			if ( null === $bundle || $bundle['evidence']->header()->id()->value() !== $bind->header()->id()->value() ) {
				if ( 1 === $binding->revision() || null === $this->saved_inputs ) { self::fail(); }
				$inputs = ( $this->saved_inputs )( $order, $bind, $request ); if ( ! $inputs instanceof FixedPromotionQuoteAcceptInputs ) { self::fail(); }
				$bundle = [ 'evidence' => null, 'inputs' => $inputs, 'draft' => $this->stager->load_draft( $order, $bind->owner() ), 'tax_source' => $this->stager->load_tax_source( $order ) ];
			}
			if ( 1 === $binding->revision() ) { $ack = $this->binder->reconcile( $bind, $request ); if ( ! self::accepted( $ack, 1 ) ) { self::fail(); } $stage = $this->stager->stage_promotion( $order, $ack, $bundle['draft'], $bundle['tax_source'], $route, $this->authorize_order ); }
			else { $stage = $this->stager->saved_promotion_guard( $order, $history['quote'], $binding, $this->authorize_order ); }
			$verify = $history['verify']; $seal = $history['seal'];
		} else {
			if ( null === $bundle || 'order_pay' === $route ) { self::fail(); }
			$evidence = $bundle['evidence']; $placement = QuotePlacementService::placement_id_for( $evidence->owner(), $evidence->header() );
			if ( null !== $original && ( $original->acceptance()->to_recovery_array() !== $evidence->acceptance()->to_recovery_array() || $original->binding()->row()['placement_uuid'] !== $placement ) ) { self::fail(); }
			$this->preserve_pointer( $order, $evidence, $placement, $route );
			$fresh = $this->bundle( $request ); if ( null === $fresh || $fresh['evidence']->acceptance()->to_recovery_array() !== $evidence->acceptance()->to_recovery_array() || $fresh['draft']->draft_digest() !== $bundle['draft']->draft_digest() ) { self::fail(); } $bundle = $fresh; $evidence = $bundle['evidence'];
			$mapping = $this->stager->promotion_mapping( $order, $evidence, $this->authorize_order );
			$native = FixedPromotionNativeOrderEvidence::capture( $this->factory, $order, $evidence->quote_record(), $mapping, $bundle['tax_source'], $this->authorize_order ); if ( null === $native ) { self::fail(); }
			$bind = $original ?? FixedPromotionQuoteBindCommand::create( $evidence->acceptance(), $evidence->quote_record(), $order->get_id(), QuoteId::from_string( $placement ), $mapping, $native->evidence_digest() );
			if ( $bind->binding()->mapping() !== $mapping || ! hash_equals( $bind->order_evidence_digest(), $native->evidence_digest() ) ) { self::fail(); }
			$this->saved->preserve( $order, $bind );
			$ack = $this->binder->bind( $bind, FixedPromotionQuoteBindInputs::from_sources( $bundle['inputs'], $native ), $request ); if ( ! self::accepted( $ack, 1 ) ) { self::fail(); }
			$binding = $ack->binding; $stage = $this->stager->stage_promotion( $order, $ack, $bundle['draft'], $bundle['tax_source'], $route, $this->authorize_order ); $verify = null; $seal = null;
		}
		if ( 1 === $binding->revision() ) {
			$native = FixedPromotionNativeOrderEvidence::capture( $this->factory, $order, $bind->quote_record(), $bind->binding()->mapping(), $bundle['tax_source'], $this->authorize_order );
			if ( null === $native || ! hash_equals( $bind->order_evidence_digest(), $native->evidence_digest() ) ) { self::fail(); }
			$verify = FixedPromotionQuoteVerifyCommand::create( $bind, $binding, $stage->snapshot_digest(), $stage->context_digest() );
			$verified = $this->verifier->verify( $verify, FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( $bundle['inputs'], $native ), $stage->guard() ), $request ); if ( ! self::accepted( $verified, 2 ) ) { self::fail(); } $binding = $verified->binding;
		}
		$this->prewarm( $order ); $this->prewarm( $stage->fresh_order() );
		$local = EmergencyCheckoutLocalBinding::capture( $order ); if ( null === $local || null === $verify || ! $local->unchanged() ) { self::fail(); }
		$this->stages[$key] = [ 'created' => $order, 'authorization' => QuoteSavedOrderAuthorization::capture( $order, $this->authorize_order ), 'route' => $route, 'bind' => $bind, 'verify' => $verify, 'seal' => $seal, 'binding' => $binding, 'guard' => $stage->guard(), 'bundle' => $bundle, 'local' => $local, 'original' => QuoteOrderPayLocalBinding::capture( $order ), 'native' => QuoteOrderPayLocalBinding::capture( $stage->fresh_order() ), 'logging' => $logging, 'logger_receipt' => null, 'logging_failed' => false ];
	}
	public function validate_order( \WC_Order $order, string $route ): bool { try { if ( true !== ( $this->authorize_order )( $order ) ) { return false; } $entry = $this->stages[$this->coordinate( $order )] ?? null; return null !== $entry && $entry['route'] === $route && $this->matches( $order, $entry ); } catch ( \Throwable ) { return false; } }

	/** C07 calls this after its current-control lock is retired. A seal remains an observation, not admission. */
	public function complete( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $local ): bool {
		try {
			if ( true !== ( $this->authorize_order )( $order ) ) { return false; }
			$key = $this->coordinate( $order ); unset( $this->observations[$key] ); $entry = $this->stages[$key] ?? null;
			if ( null === $entry || $entry['route'] !== $route || $control_revision < 1 || ! $local->unchanged() || ! $this->matches( $order, $entry ) ) { return false; }
			$guard = $this->stager->saved_promotion_guard( $order, $entry['bind']->quote_record(), $entry['binding'], $this->authorize_order )->guard();
			$guard = $this->disposition( $order, $entry['bind'], $route, $guard );
			$logging = $entry['logger_receipt']; if ( $logging instanceof QuoteNativeCheckoutLoggingEvidence ) { if ( ! $logging->is_current() || 4 !== $logging->phase() || 'classic' !== $route ) { return false; } $guard = $logging->with_saved( $guard ); }
			$inputs = $this->fresh_inputs( $order, $entry['bind'], RequestContext::create() ); if ( null === $inputs ) { return false; }
			$native = FixedPromotionNativeOrderEvidence::capture( $this->factory, $order, $entry['bind']->quote_record(), $entry['bind']->binding()->mapping(), $entry['bundle']['tax_source'], $this->authorize_order );
			if ( null === $native || ! hash_equals( $entry['bind']->order_evidence_digest(), $native->evidence_digest() ) || ! $local->unchanged() ) { return false; }
			$current = FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( $inputs, $native ), $guard );
			$seal = $entry['seal'];
			if ( null === $seal ) { $seal = FixedPromotionQuoteSealCommand::create( $entry['verify'], $entry['binding'], $control_revision ); $sealed = $this->sealer->seal( $seal, $current, RequestContext::create() ); if ( ! self::accepted( $sealed, 3 ) ) { return false; } $this->stages[$key]['binding'] = $sealed->binding; $this->stages[$key]['seal'] = $seal; }
			$observed = $this->reader->current_sealed( $seal, $current, $control_revision, RequestContext::create() );
			if ( null !== $observed && $local->unchanged() ) { $this->observations[$key] = [ 'evidence' => $observed, 'order' => $order, 'authorization' => QuoteSavedOrderAuthorization::capture( $order, $this->authorize_order ), 'local' => $local, 'full' => QuoteOrderPayLocalBinding::capture( $order ) ]; }
		} catch ( \Throwable ) {}
		// The selected E15 policy retains the held budget. No gateway/free-payment continuation exists.
		return false;
	}
	public function admitted( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $binding ): bool { return false; }
	public function sealed( \WC_Order $order ): ?FixedPromotionQuoteSealedEvidence { try { if ( true !== ( $this->authorize_order )( $order ) ) { return null; } $observation = $this->observations[$this->coordinate( $order )] ?? null; if ( null === $observation || $observation['order'] !== $order || ! $observation['authorization']->unchanged() || ! $observation['local']->unchanged() || ! $observation['full']->unchanged() ) { return null; } return $observation['evidence']; } catch ( \Throwable ) { return null; } }
	public function current_sealed( \WC_Order $order, int $control_revision, RequestContext $request ): ?FixedPromotionQuoteSealedEvidence {
		try { $history = $this->saved->read( $order, $request ); if ( null === $history || null === $history['seal'] ) { return null; } $inputs = $this->fresh_inputs( $order, $history['bind'], $request ); if ( null === $inputs ) { return null; } $tax = $this->stager->load_tax_source( $order ); $native = FixedPromotionNativeOrderEvidence::capture( $this->factory, $order, $history['quote'], $history['binding']->mapping(), $tax, $this->authorize_order ); if ( null === $native || ! hash_equals( $history['bind']->order_evidence_digest(), $native->evidence_digest() ) ) { return null; } $guard = $this->stager->saved_promotion_guard( $order, $history['quote'], $history['binding'], $this->authorize_order )->guard(); return $this->reader->current_sealed( $history['seal'], FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( $inputs, $native ), $guard ), $control_revision, $request ); } catch ( \Throwable ) { return null; }
	}
	public function observe_native_logging_save( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) { return; }
		try { if ( true !== ( $this->authorize_order )( $order ) ) { return; } $key = $this->coordinate( $order ); $entry = $this->stages[$key] ?? null; if ( null === $entry || ! $entry['logging'] instanceof QuoteNativeCheckoutLoggingEvidence ) { return; } $receipt = $entry['logging']->observe_saved( $order ); if ( null === $receipt ) { return; } if ( 4 !== $receipt->phase() || null !== $entry['logger_receipt'] ) { self::fail(); } $this->stages[$key]['logger_receipt'] = $receipt; } catch ( \Throwable ) { if ( isset( $key, $this->stages[$key] ) ) { $this->stages[$key]['logging_failed'] = true; } }
	}
	private function bundle( RequestContext $request ): ?array { $bundle = ( $this->current )( $request ); if ( null === $bundle ) { return null; } if ( ! is_array( $bundle ) || array_keys( $bundle ) !== [ 'evidence', 'inputs', 'draft', 'tax_source' ] || ! $bundle['evidence'] instanceof FixedPromotionQuotePlacementEvidence || ! $bundle['inputs'] instanceof FixedPromotionQuoteAcceptInputs || ! $bundle['draft'] instanceof QuoteCartDraft || ! $bundle['tax_source'] instanceof QuoteNativeTaxSource || ! $bundle['draft']->owner()->equals( $bundle['evidence']->owner() ) || ! $bundle['tax_source']->matches_context( $bundle['evidence']->context() ) ) { self::fail(); } return $bundle; }
	private function fresh_inputs( \WC_Order $order, FixedPromotionQuoteBindCommand $bind, RequestContext $request ): ?FixedPromotionQuoteAcceptInputs { $bundle = $this->bundle( $request ); if ( null !== $bundle && $bundle['evidence']->acceptance()->to_recovery_array() === $bind->acceptance()->to_recovery_array() ) { return $bundle['inputs']; } $inputs = null === $this->saved_inputs ? null : ( $this->saved_inputs )( $order, $bind, $request ); return $inputs instanceof FixedPromotionQuoteAcceptInputs ? $inputs : null; }
	private function matches( \WC_Order $order, array $entry ): bool { if ( true !== ( $this->authorize_order )( $order ) || ! $entry['authorization']->unchanged() || $entry['logging_failed'] || ! $entry['local']->unchanged() || ! $entry['original']->unchanged() || ! QuoteNativeOrderHistory::verify( $order ) ) { return false; } $stage = $this->stager->saved_promotion_guard( $order, $entry['bind']->quote_record(), $entry['binding'], $this->authorize_order ); $logging = $entry['logger_receipt']; if ( $logging instanceof QuoteNativeCheckoutLoggingEvidence && ( ! $logging->is_current() || ! $logging->matches_saved( $stage->fresh_order() ) ) ) { return false; } return $stage->snapshot_digest() === $entry['binding']->row()['snapshot_digest'] && $stage->context_digest() === $entry['binding']->row()['context_digest'] && ( $entry['created'] === $order || $entry['native']->same_native_facts( $order, $logging ) ); }
	private function prewarm( \WC_Order $order ): void { $order->get_meta_data(); foreach ( [ 'line_item', 'shipping', 'tax', 'fee', 'coupon' ] as $type ) { foreach ( $order->get_items( $type ) as $item ) { $item->get_meta_data(); } } }
	private static function accepted( object $result, int $revision ): bool { return 'accepted' === $result->attempt->outcome->state && true === $result->attempt->outcome->mutation_accepted && null !== $result->command && null !== $result->quote && $result->binding instanceof QuoteBinding && $revision === $result->binding->revision(); }
	private function coordinate( \WC_Order $order ): string { $id = $order->get_id(); $site = $GLOBALS['blog_id'] ?? 1; if ( ! is_int( $site ) || $site < 1 || $id < 1 ) { self::fail(); } return $site . ':' . $id; }
	private function preserve_pointer( \WC_Order $order, FixedPromotionQuotePlacementEvidence $evidence, string $placement, string $route ): void { $native = self::native_session(); $original = [ 'format' => 1, 'order_id' => $order->get_id(), 'placement_id' => $placement, 'quote_id' => $evidence->header()->id()->value(), 'route' => $route ]; $prior = $native->get( QuotePlacementRuntime::SESSION_CONTINUATION ); if ( null !== $prior && $prior !== $original ) { self::fail(); } $pointer = 'classic' === $route ? 'order_awaiting_payment' : 'store_api_draft_order'; if ( ! in_array( $native->get( $pointer, 0 ), [ 0, $order->get_id() ], true ) ) { self::fail(); } $native->set( QuotePlacementRuntime::SESSION_CONTINUATION, $original ); $native->set( $pointer, $order->get_id() ); $native->save_data(); if ( ! $this->acknowledge_session( $native ) ) { self::fail(); } }
	private function disposition( \WC_Order $order, FixedPromotionQuoteBindCommand $bind, string $route, QuotePlacementSavedEvidenceGuard $guard ): QuotePlacementSavedEvidenceGuard { $native = self::native_session(); $original = $native->get( QuotePlacementRuntime::SESSION_CONTINUATION ); if ( ! is_array( $original ) || ( $original['order_id'] ?? null ) !== $order->get_id() || ( $original['placement_id'] ?? null ) !== $bind->binding()->row()['placement_uuid'] || ( $original['quote_id'] ?? null ) !== $bind->header()->id()->value() ) { self::fail(); } if ( 'store_api' === $route ) { if ( ! in_array( $native->get( 'store_api_draft_order', 0 ), [ 0, $order->get_id() ], true ) ) { self::fail(); } $native->set( 'store_api_draft_order', 0 ); } elseif ( 'classic' === $route && $native->get( 'order_awaiting_payment', 0 ) !== $order->get_id() ) { self::fail(); } $native->save_data(); if ( ! $this->acknowledge_session( $native ) ) { self::fail(); } return new QuoteNativeSessionPlacementGuard( $guard, $native, QuotePlacementRuntime::session_bytes( $native ), $native->get_customer_id(), QuotePlacementRuntime::session_expiry( $native ) ); }
	private static function native_session(): \WC_Session_Handler { $native = function_exists( 'WC' ) ? ( WC()->session ?? null ) : null; if ( ! $native instanceof \WC_Session_Handler || \WC_Session_Handler::class !== get_class( $native ) || ! $native->has_session() ) { self::fail(); } return $native; }
	private function acknowledge_session( \WC_Session_Handler $native ): bool {
		$session = null; $closed = false;
		try {
			$bytes = QuotePlacementRuntime::session_bytes( $native ); $expiry = QuotePlacementRuntime::session_expiry( $native ); $key = $native->get_customer_id();
			$session = $this->factory->open(); $pin = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			\CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema::tables( $pin[1] ); \CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema::charset_details( $pin[2] );
			$check = static function( bool $active, bool $retired = false ) use ( $session, $pin ): void { if ( $pin[0] !== ( $GLOBALS['blog_id'] ?? 1 ) || $pin !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] || $session->in_transaction() !== $active || $session->is_retired() !== $retired ) { self::fail(); } };
			$check( false ); if ( ! $session->begin() ) { return false; } $check( true );
			if ( ! $session->validate_tables( [ $pin[1] . 'woocommerce_sessions' ] ) || ! QuoteNativeSessionPlacementGuard::row_matches( $session, $key, $bytes, $expiry ) ) { return false; } $check( true );
			if ( ! $session->rollback() ) { return false; } $check( false ); if ( ! $session->retire() ) { return false; } $check( false, true );
			$closed = true; return QuotePlacementRuntime::session_bytes( $native ) === $bytes && QuotePlacementRuntime::session_expiry( $native ) === $expiry;
		} catch ( \Throwable ) { return false; }
		finally { if ( null !== $session && ! $closed ) { try { $session->rollback(); } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	private static function fail(): never { throw new \UnexpectedValueException( 'Native promotion placement needs review.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Native promotion placement is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native promotion placement requires explicit trusted composition.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Native promotion placement requires explicit trusted composition.' ); }
	public function __debugInfo(): array { return [ 'private_native_promotion_placement' => 'redacted' ]; }
}
