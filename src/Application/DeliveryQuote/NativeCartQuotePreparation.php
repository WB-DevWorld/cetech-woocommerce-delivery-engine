<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Destination\WooCommerceStateCatalog;

/** Called after the early preparation lease. Current reads never invoke the pricing engine. */
final class NativeCartQuotePreparation {
	private LegacyQuoteNativeSourcePreparer $sources;
	public function __construct( private OperationConnectionFactory $factory, private ?QuoteNativeCaptureSource $native_source = null ) { $this->sources = new LegacyQuoteNativeSourcePreparer( $factory ); }
	public function prepare( QuoteCartDraft $draft ): LegacyQuotePreparedCapture {
		try {
			$facts = $draft->private_facts(); $offers = [];
			foreach ( $facts['lines'] as $line ) { if ( 'delivery' !== $line['selection']['fulfilment_choice'] || ! is_int( $line['selection']['delivery_offer_id'] ) || $line['selection']['delivery_offer_id'] < 1 ) { self::fail(); } $offers[$line['selection']['delivery_offer_id']] = $line['selection']['delivery_offer_id']; }
			$seed = $this->sources->seed_for_cart( $draft->owner(), $facts['lines'], array_values( $offers ) );
			// This provisional context is constructed from real selected source/inventory facts.
			// Its observed seed digest and zero candidate count are replaced by the Q04 stack.
			$packages = self::package_facts( WC()->shipping()->get_packages(), $draft );
			$native = $this->native_receipt( $draft->owner(), $packages );
			$context = $this->context_from_observed( $draft, $seed, $native, $packages );
			return ( new LegacyQuoteProviderStack( $this->factory ) )->prepare_current( $draft->owner(), $context, self::legacy_groups( $packages ) );
		} catch ( \Throwable $error ) { throw new \RuntimeException( 'Cart quote preparation unavailable.', 0, $error ); }
	}
	public function evidence( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence {
		try {
			$owner = $draft->owner(); $context = $original->context();
			if ( ! $this->matches_original( $original, $header, $draft ) ) { return null; }
			$source = $this->sources->prepare( $owner, $context );
			// Complete current source facts may differ from the original quote. Preserve
			// the original envelope; the durable read decides its current applicability.
			$source = self::current_source( $source, $context ); $source_context = $source->context();
			$packages = self::package_facts( WC()->shipping()->get_packages(), $draft );
			$native = $this->native_receipt( $owner, $packages ); $current = $native->bind_context( $source_context );
			if ( ! hash_equals( $source_context->digest(), $current->digest() ) || ! $source->applicable_at( QuoteTime::now() ) || ! $native->unchanged() || ! $source->local_state_unchanged() ) { return null; }
			return new QuoteCartCurrentEvidence( $current, new LegacyQuoteCaptureGuard( $source->guard(), $native->guard() ) );
		} catch ( \Throwable ) { return null; }
	}
	/** Pure binding of a complete captured source packet; no rate calculation or original mutation. */
	private static function current_source( LegacyQuoteSourceSnapshot $source, QuoteContext $original ): LegacyQuoteSourceSnapshot {
		$facts = $original->private_facts();
		foreach ( $facts['groups'] as &$group ) {
			$group['policy_digest'] = $source->policy_digest();
			$group['candidate_digest'] = $source->candidate_digest( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['base'] );
			$group['candidate_count'] = $source->candidate_count( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['base'] );
		} unset( $group );
		return $source->bind_context( QuoteContext::from_array( $facts ) );
	}
	public function matches_original( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): bool {
		try {
			$owner = $draft->owner(); $context = $original->context();
			if ( ! $owner->equals( $original->owner() ) || ! $owner->equals( $header->owner() ) || LegacyFixedBaseQuoteProvider::CODE !== $original->provider_code() || 1 !== $original->provider_version() || LegacyFixedBaseQuoteProvider::CODE !== $original->profile() || LegacyFixedBaseQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 1 !== $original->profile_version() || ! hash_equals( $header->material_digest(), $context->digest() ) || QuoteJson::encode( $header->namespace_hashes() ) !== QuoteJson::encode( $original->namespace_hashes( $header->id() ) ) || ! self::draft_matches_original( $draft, $context ) ) { return false; }
			return true;
		} catch ( \Throwable ) { return false; }
	}
	/** Internal construction from observed native/source facts; never returns an issued header. */
	public function context_from_observed( QuoteCartDraft $draft, LegacyQuoteSourceSeedRows $seed, QuoteNativeReceipt $native, array $packages ): QuoteContext {
		$facts = $draft->private_facts(); $owner = $draft->owner(); $identity = QuoteNativeContextIdentity::from_server();
		if ( ! $native->matches_owner( $owner ) || $identity->key_epoch() !== $owner->key_epoch() || $native->currency_facts()['base'] !== $facts['currency']['code'] || $native->currency_facts()['precision'] !== $facts['currency']['precision'] ) { self::fail(); }
		foreach ( $facts['lines'] as $line ) { foreach ( array_filter( [ $line['product_id'], $line['variation_id'] ] ) as $id ) { foreach ( [ 'posts', 'post_meta', 'product_cat_relationships', 'product_type_relationships' ] as $group ) { wp_cache_delete( $id, $group ); } \WC_Cache_Helper::invalidate_cache_group( 'product_' . $id ); } }
		$view = new LegacyQuoteCapturedSourceView( $seed ); $runtime = $this->sources->runtime_for_seed( $seed );
		$validator = new ProductDeliverySelectionValidator( new FeatureFlags(), new Requirements(), $runtime, new ProductDeliveryOptionsBuilder( $view->offers() ) );
		$matcher = new PackageDestinationZoneResolver( new DestinationZoneMatcher( $view->zones(), $view->zone_rules(), new RegionCodeLabelMatcher( new WooCommerceStateCatalog() ) ) );
		$package_for = []; foreach ( $packages as $package ) { foreach ( $package['line_keys'] as $key ) { $package_for[$key] = $package; } }
		$selection = []; $lines = []; $groups = []; $inventory = []; $destination = null;
		foreach ( $facts['lines'] as $line ) {
			$package = $package_for[$line['line_key']] ?? null; if ( null === $package || 'delivery' !== $line['selection']['fulfilment_choice'] ) { self::fail(); }
			$customer = CustomerCartContext::fromArray( $line['customer_context'] );
			if ( ! $customer instanceof CustomerCartContext || ! $customer->hasCompleteDeliveryAddress() || $customer->delivery_offer_id !== $line['selection']['delivery_offer_id'] ) { self::fail(); }
			$valid = $validator->validate( $line['product_id'], $line['variation_id'], $line['selection']['display_key'] );
			if ( ! $valid->valid || ! is_array( $valid->intent ) || ! CartDeliverySelectionFingerprint::matches( $line['selection'], $valid->intent ) || CartDeliverySelectionFingerprint::fromIntent( $valid->intent ) !== $line['selection_hash'] ) { self::fail(); }
			$id = $line['variation_id'] ?? $line['product_id']; $product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ( null === $line['variation_id'] && ! $product->is_type( 'simple' ) ) || ( null !== $line['variation_id'] && ( ! $product->is_type( 'variation' ) || $product->get_parent_id() !== $line['product_id'] ) ) ) { self::fail(); }
			$quantity = (int) $line['quantity']; $stock = LegacyQuoteNativeSourcePreparer::inventory_facts( $product, $quantity ); if ( 'eligible' !== $stock['status'] ) { self::fail(); } $inventory[] = [ 'product' => $product, 'quantity' => $quantity ];
			$resolved = $runtime->resolve( null === $line['variation_id'] ? 'product' : 'variation', $id ); $availability = $valid->intent['fulfilment_availability'] ?? null; $rule = is_string( $availability ) ? ( $resolved->result->chosen_rules[$availability] ?? null ) : null;
			if ( ! $rule instanceof ResolvedProductDeliveryRule || null !== $rule->pickup_location_id || ! in_array( $customer->delivery_offer_id, $rule->delivery_offer_ids, true ) ) { self::fail(); }
			$endpoint = $identity->destination_digest( $owner->site_id(), $package['destination'] );
			if ( $endpoint !== $identity->destination_digest( $owner->site_id(), $customer->delivery_address->toWcPackageDestination() ) || ( null !== $destination && $endpoint !== $destination ) ) { self::fail(); } $destination = $endpoint;
			$zones = $matcher->resolve_zone_ids( $package['destination'] ); if ( [] === $zones || count( $zones ) > 200 ) { self::fail(); }
			$dimensions = $resolved->quote_dimensions_for( $availability ); $component = self::component_key( $package['legacy_group_id'] );
			$group = [ 'component_key' => $component, 'line_keys' => $package['line_keys'], 'choice' => 'delivery', 'offer_id' => $customer->delivery_offer_id, 'service_id' => $customer->delivery_offer_id, 'origin' => self::dimension( $dimensions['origin_id'] ), 'supplier' => self::dimension( $dimensions['supplier_id'] ), 'profile' => self::dimension( $dimensions['logistics_profile_id'] ), 'destination_zone_id' => $zones[0], 'endpoint_digest' => $endpoint, 'policy_digest' => $seed->digest(), 'candidate_digest' => $seed->digest(), 'candidate_count' => 0 ];
			if ( isset( $groups[$component] ) && QuoteJson::encode( $groups[$component] ) !== QuoteJson::encode( $group ) ) { self::fail(); } $groups[$component] = $group;
			$lines[] = [ 'line_key' => $line['line_key'], 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'parent_id' => null === $line['variation_id'] ? null : $line['product_id'], 'quantity' => $line['quantity'], 'component_key' => $component, 'source' => LegacyQuoteNativeSourcePreparer::source_facts( $resolved, $rule ), 'inventory' => $stock ]; $selection[$line['line_key']] = $line['selection_hash'];
		}
		if ( ! LegacyQuoteNativeSourcePreparer::inventory_demand_available( $inventory ) || $native->destination_facts()['digest'] !== $destination ) { self::fail(); } ksort( $selection, SORT_STRING );
		$context = QuoteContext::from_array( [ 'format_version' => 1, 'kind' => 'checkout', 'selection_digest' => hash( 'sha256', QuoteJson::encode( $selection ) ), 'destination' => $native->destination_facts(), 'currency' => $native->currency_facts(), 'tax' => $native->tax_facts(), 'lines' => $lines, 'groups' => array_values( $groups ) ] );
		return $native->bind_context( $context );
	}
	private function native_receipt( QuoteOwner $owner, array $packages ): QuoteNativeReceipt {
		$source = $this->native_source ?? new QuoteNativeWooSource(); $state = $source->capture(); $facts = $state->facts(); $groups = self::legacy_groups( $packages ); $by_group = [];
		foreach ( $groups as $component => $group ) { $by_group[$group] = $component; } $requests = [];
		foreach ( $facts['packages'] as $package ) { $component = $by_group[$package['group_id']] ?? null; if ( null === $component ) { self::fail(); } $requests[] = new QuoteNativeGroupRequest( $component, $package['group_id'], QuoteMoney::from_array( [ 'amount' => $package['cost'], 'currency' => $facts['currency'], 'precision' => 6 ] ) ); }
		// Reuse the captured native state; do not calculate shipping/totals or capture twice.
		$observed = new class( $source, $state ) implements QuoteNativeCaptureSource {
			public function __construct( private QuoteNativeCaptureSource $source, private QuoteNativeState $state ) {}
			public function current_owner(): QuoteOwner { return $this->source->current_owner(); }
			public function capture(): QuoteNativeState { return $this->state; }
			public function unchanged(): bool { return $this->source->unchanged(); }
		};
		return ( new QuoteNativeReceiptCapture( $observed ) )->capture( $owner, $requests );
	}
	public static function component_key( string $group ): string { if ( '' === $group || strlen( $group ) > 191 ) { self::fail(); } return hash( 'sha256', 'cetech-cart-quote-component-v1:' . $group ); }
	private static function legacy_groups( array $packages ): array { $out = []; foreach ( $packages as $package ) { $key = self::component_key( $package['legacy_group_id'] ); if ( isset( $out[$key] ) ) { self::fail(); } $out[$key] = $package['legacy_group_id']; } return $out; }
	/** The current draft must name exactly the original selected members and endpoint. */
	private static function draft_matches_original( QuoteCartDraft $draft, QuoteContext $context ): bool {
		$facts = $context->private_facts(); $draft_facts = $draft->private_facts(); if ( $facts['currency']['charged'] !== $draft_facts['currency']['code'] || $facts['currency']['precision'] !== $draft_facts['currency']['precision'] || count( $facts['lines'] ) !== count( $draft_facts['lines'] ) ) { return false; }
		$original = []; foreach ( $facts['lines'] as $line ) { $original[$line['line_key']] = $line; } $selection = [];
		foreach ( $draft_facts['lines'] as $line ) { $old = $original[$line['line_key']] ?? null; if ( null === $old || $old['product_id'] !== $line['product_id'] || $old['variation_id'] !== $line['variation_id'] || $old['quantity'] !== $line['quantity'] || 'delivery' !== $line['selection']['fulfilment_choice'] ) { return false; } $selection[$line['line_key']] = $line['selection_hash']; }
		ksort( $selection, SORT_STRING ); return hash_equals( $facts['selection_digest'], hash( 'sha256', QuoteJson::encode( $selection ) ) );
	}
	/** Actual post-admission package layout; never part of the cheap draft. */
	private static function package_facts( array $packages, QuoteCartDraft $draft ): array {
		if ( [] === $packages || count( $packages ) > 200 ) { self::fail(); } $out = []; $members = []; $groups = [];
		foreach ( $packages as $index => $package ) {
			$group = $package['cetech_de']['group_id'] ?? null;
			if ( ! is_int( $index ) || ! is_array( $package ) || true !== ( $package['cetech_de']['managed'] ?? null ) || ! is_string( $group ) || '' === $group || strlen( $group ) > 191 || isset( $groups[$group] ) || ! is_array( $package['contents'] ?? null ) || [] === $package['contents'] || ! is_array( $package['destination'] ?? null ) ) { self::fail(); }
			$groups[$group] = true; $keys = array_keys( $package['contents'] ); sort( $keys, SORT_STRING );
			foreach ( $keys as $key ) { if ( ! is_string( $key ) || isset( $members[$key] ) || count( $members ) >= 200 ) { self::fail(); } $members[$key] = true; }
			$destination = []; foreach ( [ 'country', 'state', 'city', 'postcode', 'address', 'address_2' ] as $field ) { $value = $package['destination'][$field] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > 512 ) { self::fail(); } $destination[$field] = $value; }
			$out[] = [ 'package_key' => (string) $index, 'legacy_group_id' => $group, 'line_keys' => $keys, 'destination' => $destination ];
		}
		$expected = array_column( $draft->private_facts()['lines'], 'line_key' ); sort( $expected, SORT_STRING ); $actual = array_keys( $members ); sort( $actual, SORT_STRING ); if ( $actual !== $expected ) { self::fail(); } return $out;
	}
	private static function dimension( ?int $id ): array { return [ 'state' => null === $id || $id < 1 ? 'absent' : 'known', 'id' => null === $id || $id < 1 ? null : $id ]; }
	private static function fail(): never { throw new \RuntimeException( 'Cart quote preparation unavailable.' ); }
}
