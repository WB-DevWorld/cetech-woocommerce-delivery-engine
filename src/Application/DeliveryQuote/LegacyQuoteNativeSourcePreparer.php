<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Runtime\EcrProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\EcrToRuntimeConfigurationAdapter;
use CetechDeliveryEngine\Application\Runtime\LegacyCategoryRuntimeCompatibilityGuard;
use CetechDeliveryEngine\Application\Runtime\LegacyProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\WooCommerceProductTypeInspector;
use CetechDeliveryEngine\Application\Runtime\WooCommerceVariationRelationshipInspector;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDeliveryOfferRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationZoneRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPickupLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Destination\WooCommerceStateCatalog;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;

/** Current native derivation bracketed by complete physical captures; never a checkout adopter. */
final class LegacyQuoteNativeSourcePreparer {
	private LegacyQuoteSourceSnapshotReader $reader;
	public function __construct( private OperationConnectionFactory $factory, ?LegacyQuoteSourceSnapshotReader $reader = null ) { $this->reader = $reader ?? new LegacyQuoteSourceSnapshotReader(); }

	public function prepare( QuoteOwner $owner, QuoteContext $server_selection_context ): LegacyQuoteSourceSnapshot {
		try {
			if ( ! $owner->equals( ( new QuoteNativeOwnerResolver() )->current() ) || ! $server_selection_context->checkout_acceptable() ) { self::fail(); }
			$local = LegacyQuoteSourceLocalBinding::capture();
			$seed = $this->capture( $owner, $server_selection_context, $this->seed_plan( $owner, $server_selection_context ) ); $this->supported_geography( $seed );
			$first_plan = $this->derive( $owner, $server_selection_context, $seed );
			$first = $this->capture( $owner, $server_selection_context, $first_plan );
			// Fresh resolver objects and exact cache invalidation occur after acknowledged retirement.
			$second_plan = $this->derive( $owner, $server_selection_context, $first );
			$second = $this->capture( $owner, $server_selection_context, $second_plan );
			if ( ! $first->matches( $second ) || ! $local->unchanged() || ! $owner->equals( ( new QuoteNativeOwnerResolver() )->current() ) ) { self::fail(); }
			$this->supported_geography( $second );
			return $second->with_local_binding( $local );
		} catch ( \Throwable ) { self::fail(); }
	}

	/** Exact internal receipt grammar shared with native server-context construction. */
	public static function source_facts( ProductDeliveryRuntimeResolution $runtime, ResolvedProductDeliveryRule $rule ): array {
		$ecr = RuntimeConfigurationSource::ECR === $runtime->source;
		if ( ! $runtime->result->success || ( $ecr && ( ! is_string( $runtime->configuration_fingerprint ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $runtime->configuration_fingerprint ) ) ) ) { self::fail(); }
		return QuoteJson::detach( [ 'route' => $ecr ? 'ecr' : 'legacy', 'identity_digest' => $ecr ? $runtime->configuration_fingerprint : hash( 'sha256', QuoteJson::encode( $rule->toArray() ) ), 'revision' => 1 ] );
	}
	public static function inventory_facts( \WC_Product $product, int $quantity ): array {
		if ( $quantity < 1 || $quantity > 1000000000 ) { self::fail(); }
		$stock = $product->get_stock_quantity(); if ( null !== $stock && ! is_int( $stock ) ) { self::fail(); }
		$receipt = [ 'id' => $product->get_id(), 'status' => $product->get_status(), 'purchasable' => $product->is_purchasable(), 'stock_status' => $product->get_stock_status(), 'manage_stock' => $product->managing_stock(), 'stock_quantity' => $stock, 'backorders' => $product->get_backorders(), 'quantity' => $quantity ];
		$eligible = $receipt['purchasable'] && $product->is_in_stock() && ( ! $receipt['manage_stock'] || $product->backorders_allowed() || $product->has_enough_stock( $quantity ) );
		return QuoteJson::detach( [ 'status' => $eligible ? 'eligible' : 'ineligible', 'evidence_digest' => hash( 'sha256', QuoteJson::encode( $receipt ) ) ] );
	}
	/** Native stock demand is aggregated by the real stock owner, including parent-managed variations. */
	public static function inventory_demand_available( array $members ): bool {
		try {
			if ( ! array_is_list( $members ) || [] === $members || count( $members ) > 200 ) { return false; } $demand = [];
			foreach ( $members as $member ) { if ( ! is_array( $member ) || ! ( $member['product'] ?? null ) instanceof \WC_Product || ! is_int( $member['quantity'] ?? null ) || $member['quantity'] < 1 || $member['quantity'] > 1000000000 ) { return false; } $id = $member['product']->get_stock_managed_by_id(); if ( ! is_int( $id ) || $id < 1 ) { return false; } $demand[$id] = ( $demand[$id] ?? 0 ) + $member['quantity']; }
			foreach ( $members as $member ) { $product = $member['product']; if ( $product->managing_stock() && ! $product->backorders_allowed() && ! $product->has_enough_stock( $demand[$product->get_stock_managed_by_id()] ) ) { return false; } } return true;
		} catch ( \Throwable ) { return false; }
	}

	private function derive( QuoteOwner $owner, QuoteContext $context, LegacyQuoteSourceSnapshot $captured ): LegacyQuoteSourcePlan {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wp_cache_delete' ) || ! class_exists( '\WC_Cache_Helper' ) || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \wpdb ) { self::fail(); }
		$db = $GLOBALS['wpdb']; $wc = WC(); $cart = $wc->cart ?? null; if ( ! $cart instanceof \WC_Cart || ! $cart->has_calculated_shipping() ) { self::fail(); }
		$facts = $context->private_facts(); $items = $cart->get_cart(); if ( ! is_array( $items ) || count( $items ) !== count( $facts['lines'] ) || count( $items ) > 200 ) { self::fail(); }
		$product_ids = []; foreach ( $facts['lines'] as $line ) { foreach ( array_filter( [ $line['product_id'], $line['variation_id'], $line['parent_id'] ] ) as $id ) { $product_ids[$id] = $id; } } if ( count( $product_ids ) > 600 ) { self::fail(); }
		foreach ( $product_ids as $id ) { foreach ( [ 'posts', 'post_meta', 'product_cat_relationships', 'product_type_relationships' ] as $group ) { wp_cache_delete( $id, $group ); } \WC_Cache_Helper::invalidate_cache_group( 'product_' . $id ); }
		foreach ( LegacyQuoteSourcePlan::OPTIONS as $name ) { wp_cache_delete( $name, 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		$view = new LegacyQuoteCapturedSourceView( $captured ); $runtime = $this->router( $view ); $validator = new ProductDeliverySelectionValidator( new FeatureFlags(), new Requirements(), $runtime, new ProductDeliveryOptionsBuilder( $view->offers() ) );
		$destinations = []; foreach ( $wc->shipping()->get_packages() as $package ) { if ( ! is_array( $package['contents'] ?? null ) || ! is_array( $package['destination'] ?? null ) ) { self::fail(); } foreach ( $package['contents'] as $key => $_ ) { if ( isset( $destinations[$key] ) ) { self::fail(); } $destinations[$key] = $package['destination']; } }
		$identity = QuoteNativeContextIdentity::from_server(); if ( $owner->key_epoch() !== $identity->key_epoch() ) { self::fail(); }
		$matcher = new PackageDestinationZoneResolver( new DestinationZoneMatcher( $view->zones(), $view->zone_rules(), new RegionCodeLabelMatcher( new WooCommerceStateCatalog() ) ) );
		$groups = []; foreach ( $facts['groups'] as $group ) { foreach ( $group['line_keys'] as $key ) { $groups[$key] = $group; } }
		$members = []; $ranges = []; $targets = []; $scopes = [ 'global:0' => [ 'type' => 'global', 'id' => 0 ] ]; $offers = []; $dimensions = [ 'origins' => [], 'suppliers' => [], 'profiles' => [] ]; $selection = []; $inventory_members = [];
		foreach ( $facts['lines'] as $line ) {
			$key = $line['line_key']; $item = $items[$key] ?? null; $group = $groups[$key]; $destination = $destinations[$key] ?? null;
			if ( ! is_array( $item ) || ! is_array( $destination ) || ( $item['product_id'] ?? null ) !== $line['product_id'] || ( ( $item['variation_id'] ?? 0 ) ?: null ) !== $line['variation_id'] || ! is_int( $item['quantity'] ?? null ) || (string) $item['quantity'] !== $line['quantity'] ) { self::fail(); }
			$intent = $item[CartDeliverySelectionCapture::CART_SELECTION_KEY] ?? null; $customer = CustomerCartContext::fromCartItem( $item );
			if ( ! is_array( $intent ) || ! $customer instanceof CustomerCartContext || ! $customer->isDelivery() || $customer->delivery_offer_id !== $group['offer_id'] || 'delivery' !== ( $intent['fulfilment_choice'] ?? null ) || ! is_string( $intent['display_key'] ?? null ) ) { self::fail(); }
			$valid = $validator->validate( $line['product_id'], $line['variation_id'], $intent['display_key'] );
			if ( ! $valid->valid || ! is_array( $valid->intent ) || ! CartDeliverySelectionFingerprint::matches( $intent, $valid->intent ) ) { self::fail(); }
			$hash = CartDeliverySelectionFingerprint::fromIntent( $valid->intent ); if ( $hash !== ( $item[CartDeliverySelectionCapture::CART_HASH_KEY] ?? null ) ) { self::fail(); } $selection[$key] = $hash;
			$id = $line['variation_id'] ?? $line['product_id']; $product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ( null !== $line['variation_id'] && ( ! $product->is_type( 'variation' ) || $product->get_parent_id() !== $line['parent_id'] ) ) || ( null === $line['variation_id'] && ! $product->is_type( 'simple' ) ) ) { self::fail(); }
			$stock_id = $product->get_stock_managed_by_id(); if ( ! is_int( $stock_id ) || ! isset( $product_ids[$stock_id] ) ) { self::fail(); } $inventory_members[] = [ 'product' => $product, 'quantity' => $item['quantity'] ];
			$resolved = $runtime->resolve( null === $line['variation_id'] ? 'product' : 'variation', $id ); $availability = $valid->intent['fulfilment_availability'] ?? null; $rule = is_string( $availability ) ? ( $resolved->result->chosen_rules[$availability] ?? null ) : null;
			if ( ! $rule instanceof ResolvedProductDeliveryRule || ! in_array( $group['offer_id'], $rule->delivery_offer_ids, true ) || null !== $rule->pickup_location_id || QuoteJson::encode( self::source_facts( $resolved, $rule ) ) !== QuoteJson::encode( $line['source'] ) || QuoteJson::encode( self::inventory_facts( $product, $item['quantity'] ) ) !== QuoteJson::encode( $line['inventory'] ) ) { self::fail(); }
			$endpoint = $identity->destination_digest( $owner->site_id(), $destination ); if ( $endpoint !== $facts['destination']['digest'] || $endpoint !== $group['endpoint_digest'] || null === $customer->delivery_address || $endpoint !== $identity->destination_digest( $owner->site_id(), $customer->delivery_address->toWcPackageDestination() ) ) { self::fail(); }
			$zones = $matcher->resolve_zone_ids( $destination ); if ( [] === $zones || count( $zones ) > 200 || $zones[0] !== $group['destination_zone_id'] ) { self::fail(); }
			$resolved_dimensions = $resolved->quote_dimensions_for( $availability ); $proof = [ 'line_key' => $key, 'offer_id' => $group['offer_id'], 'service_id' => $group['offer_id'], 'choice' => 'delivery', 'origin' => self::dimension( $resolved_dimensions['origin_id'] ), 'supplier' => self::dimension( $resolved_dimensions['supplier_id'] ), 'profile' => self::dimension( $resolved_dimensions['logistics_profile_id'] ), 'destination_zone_id' => $zones[0], 'endpoint_digest' => $endpoint ]; $members[] = $proof;
			foreach ( $zones as $zone ) { $range = [ 'delivery_offer_id' => $group['offer_id'], 'destination_zone_id' => $zone, 'base_currency' => $facts['currency']['base'] ]; $ranges[LegacyQuoteSourcePlan::range_key( $range )] = $range; }
			foreach ( $resolved->result->chosen_rules as $chosen ) { foreach ( $chosen->delivery_offer_ids as $offer_id ) { $offers[$offer_id] = $offer_id; } foreach ( [ 'origin_id' => 'origins', 'supplier_id' => 'suppliers', 'logistics_profile_id' => 'profiles' ] as $field => $source ) { $dimension = $chosen->$field; if ( null !== $dimension && $dimension > 0 ) { $dimensions[$source][$dimension] = $dimension; } } }
			$parent = wc_get_product( $line['product_id'] ); if ( ! $parent instanceof \WC_Product ) { self::fail(); }
			foreach ( $parent->get_category_ids() as $category_id ) { $targets['category:' . $category_id] = [ 'type' => 'category', 'id' => $category_id ]; }
			foreach ( [ [ 'product', $line['product_id'] ], [ 'variation', $line['variation_id'] ] ] as [ $type, $target_id ] ) { if ( null !== $target_id ) { $targets[$type . ':' . $target_id] = [ 'type' => $type, 'id' => $target_id ]; $scopes[$type . ':' . $target_id] = [ 'type' => $type, 'id' => $target_id ]; } }
		}
		if ( ! self::inventory_demand_available( $inventory_members ) ) { self::fail(); }
		ksort( $selection, SORT_STRING ); if ( hash( 'sha256', QuoteJson::encode( $selection ) ) !== $facts['selection_digest'] || $facts['currency']['base'] !== get_option( 'woocommerce_currency' ) ) { self::fail(); }
		$tax_ids = $db->get_col( 'SELECT DISTINCT term_taxonomy_id FROM `' . $db->term_relationships . '` WHERE object_id IN (' . implode( ',', $product_ids ) . ') ORDER BY term_taxonomy_id ASC LIMIT 601' ); if ( ! is_array( $tax_ids ) || count( $tax_ids ) > 600 || '' !== $db->last_error ) { self::fail(); }
		$fences = [ [ 'source' => 'product', 'ids' => array_values( $product_ids ) ], [ 'source' => 'product_meta', 'ids' => array_values( $product_ids ) ], [ 'source' => 'term_relationships', 'ids' => array_values( $product_ids ) ], [ 'source' => 'options', 'names' => LegacyQuoteSourcePlan::OPTIONS ], [ 'source' => 'offers', 'ids' => array_values( $offers ) ], [ 'source' => 'legacy_rules', 'targets' => array_values( $targets ) ], [ 'source' => 'scopes', 'targets' => array_values( $scopes ) ], [ 'source' => 'scope_fields' ], [ 'source' => 'scope_collections' ], [ 'source' => 'zones' ], [ 'source' => 'zone_rules' ], [ 'source' => 'coverage_groups' ], [ 'source' => 'coverage_members' ], [ 'source' => 'coverage_postcodes' ] ];
		if ( [] !== $tax_ids ) { $fences[] = [ 'source' => 'term_taxonomy', 'ids' => array_map( 'intval', $tax_ids ) ]; } foreach ( $dimensions as $source => $ids ) { if ( [] !== $ids ) { $fences[] = [ 'source' => $source, 'ids' => array_values( $ids ) ]; } }
		return LegacyQuoteSourcePlan::create( $owner, $context, $members, array_values( $ranges ), $fences );
	}

	private function capture( QuoteOwner $owner, QuoteContext $context, LegacyQuoteSourcePlan $plan ): LegacyQuoteSourceSnapshot {
		$session = null; $begun = false;
		try {
			$session = $this->factory->open(); $db = $GLOBALS['wpdb'] ?? null;
			if ( ! $db instanceof \wpdb || $session->table_prefix() !== $db->prefix || $db->posts !== $db->prefix . 'posts' || $db->postmeta !== $db->prefix . 'postmeta' || $db->options !== $db->prefix . 'options' || $db->term_relationships !== $db->prefix . 'term_relationships' || $db->term_taxonomy !== $db->prefix . 'term_taxonomy' || $session->site_id() !== $owner->site_id() || $session->in_transaction() || $session->is_retired() || ! $session->begin() ) { self::fail(); } $begun = true;
			$captured = $this->reader->capture( $session, $owner, $context, $plan ); if ( ! $session->rollback() ) { self::fail(); } $begun = false; if ( ! $session->retire() ) { self::fail(); } return $captured;
		} finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	private function router( LegacyQuoteCapturedSourceView $view ): ProductDeliveryRuntimeConfigurationRouter {
		$legacy = new ProductDeliveryRuleResolver( $view->legacy_rules(), new ProductTargetResolver( new Requirements() ) ); $variation = new WooCommerceVariationRelationshipInspector();
		$effective = new EffectiveConfigurationResolver( $view->scopes(), new EffectiveConfigurationValidator(), new HardFulfilmentConstraintService( $view->offers() ), new SiteWideDefaultsSettings() );
		return new ProductDeliveryRuntimeConfigurationRouter( new FeatureFlags(), new LegacyProductDeliveryConfigurationSource( $legacy ), new EcrProductDeliveryConfigurationSource( $effective, new EcrToRuntimeConfigurationAdapter(), $variation ), new LegacyCategoryRuntimeCompatibilityGuard( $legacy ), new WooCommerceProductTypeInspector(), new LegacyProductDeliveryConfigurationSource( $legacy, RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY ), $variation );
	}
	private function supported_geography( LegacyQuoteSourceSnapshot $snapshot ): void {
		// First retained provider supports the basic legacy zone rules only. Complete universes are fenced.
		foreach ( $snapshot->rows_for( 'zone_rules' ) as $rule ) { if ( ! in_array( $rule['rule_type'] ?? null, [ 'country', 'region', 'city', 'postcode' ], true ) || ! in_array( $rule['match_mode'] ?? null, [ 'exact', 'prefix' ], true ) ) { self::fail(); } }
		foreach ( $snapshot->rows_for( 'coverage_groups' ) as $group ) { if ( 'active' === ( $group['status'] ?? null ) ) { self::fail(); } }
	}
	private static function dimension( ?int $id ): array { return [ 'state' => null === $id || $id < 1 ? 'absent' : 'known', 'id' => null === $id || $id < 1 ? null : $id ]; }
	/** Establish finite complete rule/configuration/geography inputs before invoking retained resolvers. */
	private function seed_plan( QuoteOwner $owner, QuoteContext $context ): LegacyQuoteSourcePlan {
		$db = $GLOBALS['wpdb'] ?? null; if ( ! $db instanceof \wpdb || ! is_string( $db->prefix ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $db->prefix ) || $db->term_relationships !== $db->prefix . 'term_relationships' || $db->term_taxonomy !== $db->prefix . 'term_taxonomy' ) { self::fail(); }
		$ids = []; $targets = []; $scopes = [ 'global:0' => [ 'type' => 'global', 'id' => 0 ] ]; $offers = []; $dimensions = [ 'origins' => [], 'suppliers' => [], 'profiles' => [] ]; $members = []; $ranges = []; $facts = $context->private_facts();
		foreach ( $facts['lines'] as $line ) { foreach ( array_filter( [ $line['product_id'], $line['variation_id'], $line['parent_id'] ] ) as $id ) { $ids[$id] = $id; } foreach ( [ [ 'product', $line['product_id'] ], [ 'variation', $line['variation_id'] ] ] as [ $type, $id ] ) { if ( null !== $id ) { $targets[$type . ':' . $id] = [ 'type' => $type, 'id' => $id ]; $scopes[$type . ':' . $id] = [ 'type' => $type, 'id' => $id ]; } } }
		$terms = $db->get_results( 'SELECT DISTINCT tr.term_taxonomy_id,tt.term_id,tt.taxonomy FROM `' . $db->term_relationships . '` tr INNER JOIN `' . $db->term_taxonomy . '` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id IN (' . implode( ',', $ids ) . ') ORDER BY tr.term_taxonomy_id ASC LIMIT 601', ARRAY_A ); if ( ! is_array( $terms ) || count( $terms ) > 600 || '' !== $db->last_error ) { self::fail(); } $taxonomy = []; foreach ( $terms as $term ) { $taxonomy[(int) $term['term_taxonomy_id']] = (int) $term['term_taxonomy_id']; if ( 'product_cat' === $term['taxonomy'] ) { $targets['category:' . $term['term_id']] = [ 'type' => 'category', 'id' => (int) $term['term_id'] ]; } }
		foreach ( $facts['groups'] as $group ) { foreach ( $group['line_keys'] as $key ) { $member = [ 'line_key' => $key ]; foreach ( [ 'offer_id', 'service_id', 'choice', 'origin', 'supplier', 'profile', 'destination_zone_id', 'endpoint_digest' ] as $field ) { $member[$field] = $group[$field]; } $members[] = $member; } $offers[$group['offer_id']] = $group['offer_id']; foreach ( [ 'origin' => 'origins', 'supplier' => 'suppliers', 'profile' => 'profiles' ] as $field => $source ) { if ( 'known' === $group[$field]['state'] ) { $dimensions[$source][$group[$field]['id']] = $group[$field]['id']; } } $range = [ 'delivery_offer_id' => $group['offer_id'], 'destination_zone_id' => $group['destination_zone_id'], 'base_currency' => $facts['currency']['base'] ]; $ranges[LegacyQuoteSourcePlan::range_key( $range )] = $range; }
		$fences = [ [ 'source' => 'product', 'ids' => array_values( $ids ) ], [ 'source' => 'product_meta', 'ids' => array_values( $ids ) ], [ 'source' => 'term_relationships', 'ids' => array_values( $ids ) ], [ 'source' => 'options', 'names' => LegacyQuoteSourcePlan::OPTIONS ], [ 'source' => 'offers', 'ids' => array_values( $offers ) ], [ 'source' => 'legacy_rules', 'targets' => array_values( $targets ) ], [ 'source' => 'scopes', 'targets' => array_values( $scopes ) ], [ 'source' => 'scope_fields' ], [ 'source' => 'scope_collections' ], [ 'source' => 'zones' ], [ 'source' => 'zone_rules' ], [ 'source' => 'coverage_groups' ], [ 'source' => 'coverage_members' ], [ 'source' => 'coverage_postcodes' ] ]; if ( [] !== $taxonomy ) { $fences[] = [ 'source' => 'term_taxonomy', 'ids' => array_values( $taxonomy ) ]; } foreach ( $dimensions as $source => $values ) { if ( [] !== $values ) { $fences[] = [ 'source' => $source, 'ids' => array_values( $values ) ]; } } return LegacyQuoteSourcePlan::create( $owner, $context, $members, array_values( $ranges ), $fences );
	}
	private static function fail(): never { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
}
