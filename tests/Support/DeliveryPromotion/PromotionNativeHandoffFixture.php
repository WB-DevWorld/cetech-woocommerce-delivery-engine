<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindProfile, FixedPromotionQuoteBindResult, FixedPromotionQuoteIssueCommand, FixedPromotionQuotePlacementEvidence, FixedPromotionQuoteProvider, NativeCartQuotePreparation, QuoteCartDraft, QuoteCurrentEvidenceGuard, QuoteCurrentEvidenceValidity, QuoteIssueCommand, QuoteNativeCaptureSource, QuoteNativeContextIdentity, QuoteNativeState};
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\CustomerContext\{CustomerCartContext, DeliveryAddress, LocationIdentity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\DeliveryPromotionPlan;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteStoredRow, QuoteTerms};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures, QuoteFixtures, QuoteStorageFixtures};

require_once dirname( __DIR__ ) . '/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__ ) . '/DeliveryQuote/QuoteStorageFixtures.php';

/**
 * Pure compiled economics and typed acknowledgments over declared synthetic facts.
 * No WordPress bootstrap, mutable Woo object, database, or C03 receipt history is supplied.
 */
final class PromotionNativeHandoffFixture {

	/** Only immutable typed/domain seeds are reused; consumers own every native object and SQL session. */
	private static array $seeds = [];

	public static function create( array $options = [] ): array {
		if ( ! array_key_exists( 'private_key', $options ) ) { $options['private_key'] = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'private-promotion-native-handoff-fixture-key'; }
		$options = array_replace( [ 'product_id' => 10, 'variation_id' => null, 'customer_id' => 0, 'site_id' => 1, 'table_prefix' => 'wp_', 'order_id' => 100, 'item_id' => 901, 'line_key' => 'line_one', 'quantity' => '2', 'group' => null, 'private_key' => 'private-promotion-native-handoff-fixture-key',
			'destination' => [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address' => 'PRIVATE-N17-ADDRESS', 'address_2' => '' ] ], $options );
		ksort( $options, SORT_STRING ); $cache = QuoteJson::encode( $options );
		if ( isset( self::$seeds[$cache] ) ) { return self::$seeds[$cache]; }
		$identity = QuoteNativeContextIdentity::from_private_key( $options['private_key'] );
		$owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'site_id' => $options['site_id'], 'key_epoch' => $identity->key_epoch() ] ) );
		$address = $options['destination']; $product = $options['product_id']; $variation = $options['variation_id']; $key = $options['line_key']; $quantity = (string) $options['quantity'];
		$selection = [ 'contract_version' => '1', 'product_id' => $product, 'variation_id' => $variation, 'target_type' => null === $variation ? 'product' : 'variation', 'target_id' => $variation ?? $product, 'display_key' => 'in_warehouse:delivery:20', 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 20, 'rule_id' => 3 ];
		// Exact stable CartDeliverySelectionFingerprint parts for this closed normalized fixture.
		$selection_hash = hash( 'sha256', implode( '|', [ (string) $product, null === $variation ? '' : (string) $variation, 'in_warehouse:delivery:20', 'in_warehouse', 'delivery', '20', '3' ] ) );
		$customer = CustomerCartContext::delivery( 20, address: DeliveryAddress::fromInput( $address ) );
		// Exact historical identity for the closed declared selection; no global sanitize_key dependency.
		$group = 'in_warehouse|delivery|20|' . LocationIdentity::destinationSegment( (string) $customer->delivery_location_identity );
		if ( null !== $options['group'] && $options['group'] !== $group ) { throw new \InvalidArgumentException( 'The fixture group must match its actual destination.' ); }
		$component = NativeCartQuotePreparation::component_key( $group );
		$draft = QuoteCartDraft::from_loaded_cart( $owner, [ $key => [ 'product_id' => $product, 'variation_id' => $variation ?? 0, 'quantity' => (int) $quantity, 'cetech_de_delivery_selection' => $selection, 'cetech_de_delivery_selection_hash' => $selection_hash, 'cetech_de_customer_context' => $customer->toArray() ] ], $address, 'GHS', 2, $identity );
		$context_data = LegacyQuoteProviderFixtures::context()->private_facts();
		$selected = [ $key => $selection_hash ]; ksort( $selected, SORT_STRING ); $context_data['selection_digest'] = hash( 'sha256', QuoteJson::encode( $selected ) );
		$context_data['destination'] = [ 'kind' => 'full', 'digest' => $identity->destination_digest( $owner->site_id(), $address ), 'key_epoch' => $identity->key_epoch() ];
		$context_data['lines'][0] = array_replace( $context_data['lines'][0], [ 'line_key' => $key, 'product_id' => $product, 'variation_id' => $variation, 'parent_id' => null === $variation ? null : $product, 'quantity' => $quantity, 'component_key' => $component ] );
		$context_data['groups'][0]['component_key'] = $component; $context_data['groups'][0]['line_keys'] = [ $key ];
		$context = QuoteContext::from_array( $context_data );
		$terms_data = LegacyQuoteProviderFixtures::terms()->private_facts(); $terms_data['groups'][0]['component_key'] = $component; $terms = QuoteTerms::from_array( $terms_data );
		$time = QuoteFixtures::time();
		$base_header = QuoteHeader::issue( QuoteId::generate(), $owner, $context, $terms, $time, [ 'issue' => QuoteFixtures::digest( 'n17-base-issue' ), 'accept' => QuoteFixtures::digest( 'n17-base-accept' ), 'invalidate' => QuoteFixtures::digest( 'n17-base-invalidate' ) ], 'legacy_fixed_base_v1' );
		$rule = FixedPromotionQuoteFixture::rule();
		$plan = DeliveryPromotionPlan::from_evidence( $terms, $component, $rule, 'fixed_planning_fixture', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'fixed_planning_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $component, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => QuoteFixtures::digest( 'n17-eligible' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => QuoteFixtures::digest( 'n17-budget' ) ] ] );
		$requests = FixedPromotionNativeRequests::from_captured( $base_header, $context, $terms, [ [ 'component_key' => $component, 'plan' => $plan ] ], [ [ 'component_key' => $component, 'legacy_group_id' => $group ] ] );
		$facts = FixedPromotionQuoteFixture::facts(); $facts['key_epoch'] = $identity->key_epoch(); $facts['destination_digest'] = $context_data['destination']['digest'];
		$facts['exempt'] = true; $facts['tax_enabled'] = false; $facts['cart_shipping_tax'] = '0.00';
		$facts['packages'][0] = array_replace( $facts['packages'][0], [ 'group_id' => $group, 'tax_status' => 'none', 'taxes' => [], 'fresh_taxes' => [], 'rounded_tax' => '0.00', 'display_total' => '9.50', 'members' => [ [ 'line_key' => $key, 'product_id' => $product, 'variation_id' => $variation, 'quantity' => $quantity ] ] ] );
		$facts['source_facts']['selectors'] = array_replace( $facts['source_facts']['selectors'], [ 'customer_id' => $options['customer_id'], 'site_id' => $owner->site_id(), 'table_prefix' => $options['table_prefix'] ] );
		$source = new readonly class( $owner, $facts ) implements QuoteNativeCaptureSource {
			public function __construct( private QuoteOwner $owner, private array $facts ) {}
			public function current_owner(): QuoteOwner { return $this->owner; }
			public function capture(): QuoteNativeState { return QuoteNativeState::from_array( $this->facts ); }
			public function unchanged(): bool { return true; }
		};
		$native = ( new FixedPromotionNativeCapture( $source ) )->capture( $owner, $requests );
		$provider = FixedPromotionQuoteProvider::from_native_result( $native, [ [ 'component_key' => $component, 'rule' => $rule ] ] );
		$original = QuoteIssueCommand::fixed_promotion( $owner, $provider->context(), QuoteId::generate()->value(), [ [ 'component_key' => $component, 'account_key' => QuoteFixtures::digest( 'n17-account' ) ] ], $time->plus_seconds( 120 ) );
		$reference = QuoteFixtures::reference( QuoteId::generate() );
		$header = QuoteHeader::issue( $reference->id(), $owner, $provider->context(), $provider->terms(), $time, $original->namespace_hashes( $reference->id() ), $provider->profile(), 1, $reference );
		$issue = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $header->to_private_json(), 'terms_json' => $provider->terms()->to_private_json(), 'reference' => $reference->public_fields() ] );
		$acceptance = FixedPromotionQuoteAcceptCommand::from_issue( $issue );
		$row = QuoteStorageFixtures::quote( quote_id: $header->id(), state: 'accepted' )->row();
		$row = array_replace( $row, [ 'site_id' => $owner->site_id(), 'format_version' => 3, 'profile_code' => $header->profile(), 'profile_version' => 1, 'principal_hash' => $owner->facts()['principal_hash'], 'owner_digest' => $owner->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'] ] );
		$quote = QuoteStoredRow::from_row( $row ); $at = $quote->accepted_at();
		$guard = new readonly class implements QuoteCurrentEvidenceGuard {
			public function tables( OperationSession $session ): array { return []; }
			public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { return true; }
		};
		$evidence = new FixedPromotionQuotePlacementEvidence( $acceptance, $quote, $at, QuoteCurrentEvidenceValidity::capture( $at, $acceptance->hold_until() ), $guard );
		$mapping = QuoteBinding::canonical_mapping( [ 'format_version' => 1, 'groups' => [ [ 'component_key' => $component, 'lines' => [ [ 'line_key' => $key, 'product_id' => $product, 'variation_id' => $variation, 'parent_id' => null === $variation ? null : $product, 'quantity' => $quantity, 'item_id' => $options['item_id'] ] ] ] ] ] );
		return self::$seeds[$cache] = [ 'draft' => $draft, 'tax_source' => $native->native_receipt()->tax_source(), 'quote' => $quote, 'evidence' => $evidence, 'acceptance' => $acceptance, 'owner' => $owner, 'group' => $group, 'component' => $component, 'mapping' => $mapping, 'shipping_final' => '9.50', 'shipping_tax' => '0.00', 'shipping_label' => 'Delivery', 'destination' => $address, 'identity' => $identity ];
	}

	/** Synthetic typed prepared acknowledgment, never evidence of a committed C03 operation. */
	public static function acknowledged( array $fixture, int $order_id, array $mapping, string $order_evidence_digest, ?QuoteId $placement = null ): FixedPromotionQuoteBindResult {
		$command = FixedPromotionQuoteBindCommand::create( $fixture['acceptance'], $fixture['quote'], $order_id, $placement ?? QuoteId::generate(), $mapping, $order_evidence_digest );
		$binding = $command->binding(); $b = $binding->row(); $at = $fixture['quote']->accepted_at();
		$result = [ 'quote_id' => $command->header()->id()->value(), 'quote_revision' => 2, 'state' => 'prepared', 'body_digest' => $command->header()->body_digest(), 'owner_digest' => $command->owner()->digest(), 'namespace_hash' => $command->identity->namespace_digest(), 'completed_at' => $at->epoch_microseconds(), 'control_revision' => 1, 'binding_id' => $binding->id(), 'placement_id' => $b['placement_uuid'], 'order_id' => $order_id, 'binding_revision' => 1, 'manifest_digest' => $b['managed_group_manifest_digest'] ];
		$completion = OperationCompletion::accepted( new FixedPromotionQuoteBindProfile(), $result, [ 'site_id' => $command->owner()->site_id(), 'owner_digest' => $command->owner()->digest(), 'quote_id' => $command->header()->id()->value(), 'purpose' => 'private_quote_invalidation' ] );
		return new FixedPromotionQuoteBindResult( new OperationAttemptResult( OperationOutcome::accepted(), $completion ), $command, $fixture['quote'], $binding );
	}
}
