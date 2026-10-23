<?php
declare(strict_types=1);
// Loaded-native-shape boundary fixture; this is not physical Woo acceptance.
$promotion_probe_mode = $argv[1] ?? 'off_prepare'; $argv[1] = 'baseline'; ob_start(); require __DIR__ . '/native-cart-environment-probe.php'; ob_end_clean(); $mode = $promotion_probe_mode;
require_once dirname( __DIR__ ) . '/DeliveryCommercial/FixedPromotionQuoteFixture.php';
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, NativeCartQuoteEnvironment, QuoteIssueCommand, QuoteNativeCaptureSource, QuoteNativeState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteTime};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); } }
$cart->cart_contents['line_one']['cetech_de_delivery_selection_hash'] = CartDeliverySelectionFingerprint::fromIntent( $selection );
$environment = new NativeCartQuoteEnvironment( $factory, $access, null, 'off_prepare' === $mode ? null : static fn(): string => FixedPromotionQuoteProvider::CODE );
$draft = $environment->draft(); if ( null === $draft ) { throw new RuntimeException( 'Loaded draft unavailable.' ); }
$refused = false;
if ( in_array( $mode, [ 'off_prepare', 'generic_profile3' ], true ) ) {
	try { if ( 'off_prepare' === $mode ) { $environment->prepare_promotion_at( $draft, QuoteTime::now() ); } else { $environment->prepare_at( $draft, QuoteTime::now() ); } } catch ( Throwable ) { $refused = true; }
} else {
	$context = F::original_context()->private_facts(); $context['destination']['key_epoch'] = $draft->owner()->key_epoch(); $context['selection_digest'] = hash( 'sha256', QuoteJson::encode( [ 'line_one' => $cart->cart_contents['line_one']['cetech_de_delivery_selection_hash'] ] ) ); $context = QuoteContext::from_array( $context );
	$owner = $draft->owner(); $terms = F::original_terms(); $id = QuoteId::generate();
	$legacy = QuoteIssueCommand::create( $owner, $context, 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1, 'private-base-provenance' );
	$header = QuoteHeader::issue( $id, $owner, $context, $terms, QuoteTime::now(), $legacy->namespace_hashes( $id ), 'legacy_fixed_base_v1' );
	$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, [ [ 'component_key' => F::key(), 'plan' => F::plan() ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
	$facts = F::facts(); $facts['key_epoch'] = $owner->key_epoch();
	$native_source = new class( $owner, $facts ) implements QuoteNativeCaptureSource {
		public function __construct( private QuoteOwner $owner, private array $facts ) {}
		public function current_owner(): QuoteOwner { return $this->owner; }
		public function capture(): QuoteNativeState { return QuoteNativeState::from_array( $this->facts ); }
		public function unchanged(): bool { return true; }
	};
	$provider = FixedPromotionQuoteProvider::from_native_result( ( new FixedPromotionNativeCapture( $native_source ) )->capture( $owner, $requests ), F::rules() );
	$command = QuoteIssueCommand::fixed_promotion( $owner, $provider->context(), QuoteId::generate()->value(), [ [ 'component_key' => F::key(), 'account_key' => hash( 'sha256', 'private-owned-account' ) ] ], $header->expires_at() );
	if ( 'changed_owner_inputs' === $mode ) { $command = QuoteIssueCommand::fixed_promotion( QuoteOwner::from_array( array_replace( $owner->facts(), [ 'session_hash' => hash( 'sha256', 'other-session' ) ] ) ), $provider->context(), QuoteId::generate()->value(), $command->promotion_account_slots(), $header->expires_at() ); }
	if ( 'changed_selection_inputs' === $mode ) { $cart->cart_contents['line_one']['cetech_de_delivery_selection']['rule_id'] = 2; $draft = $environment->draft(); }
	if ( 'rotated_session_inputs' === $mode ) { $_COOKIE[LOGGED_IN_COOKIE] = 'name|2000000000|second-auth-session|signature'; }
	$refused = null === $environment->promotion_inputs( $command, $draft );
}
echo json_encode( [ 'refused' => $refused, 'getters' => NativeCalls::$getters, 'queries' => NativeCalls::$queries, 'controls' => $access->observations ], JSON_THROW_ON_ERROR );
