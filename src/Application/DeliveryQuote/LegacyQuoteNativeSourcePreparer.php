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
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec;
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

	/** Observe a complete bounded retained source view before constructing a genuine quote input. */
	public function seed_for_cart( QuoteOwner $owner, array $lines, array $selected_offer_ids ): LegacyQuoteSourceSeedRows {
		try {
			if ( ! $owner->equals( ( new QuoteNativeOwnerResolver() )->current() ) || ! array_is_list( $lines ) || [] === $lines || count( $lines ) > 200 || ! array_is_list( $selected_offer_ids ) || [] === $selected_offer_ids || count( $selected_offer_ids ) > 200 ) { self::fail(); }
			$ids = []; $targets = []; $scopes = [ 'global:0' => [ 'type' => 'global', 'id' => 0 ] ]; $offers = []; $dimensions = [ 'origins' => [], 'suppliers' => [], 'profiles' => [] ];
			foreach ( $selected_offer_ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { self::fail(); } $offers[$id] = $id; }
			foreach ( $lines as $line ) {
				if ( ! is_array( $line ) || ! is_int( $line['product_id'] ?? null ) || $line['product_id'] < 1 || ( null !== ( $line['variation_id'] ?? null ) && ( ! is_int( $line['variation_id'] ) || $line['variation_id'] < 1 || $line['variation_id'] === $line['product_id'] ) ) ) { self::fail(); }
				foreach ( [ [ 'product', $line['product_id'] ], [ 'variation', $line['variation_id'] ?? null ] ] as [ $type, $id ] ) { if ( null !== $id ) { $ids[$id] = $id; $targets[$type . ':' . $id] = [ 'type' => $type, 'id' => $id ]; $scopes[$type . ':' . $id] = [ 'type' => $type, 'id' => $id ]; } }
			}
			$db = $GLOBALS['wpdb'] ?? null;
			if ( ! $db instanceof \wpdb || ! is_string( $db->prefix ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $db->prefix ) || $db->term_relationships !== $db->prefix . 'term_relationships' || $db->term_taxonomy !== $db->prefix . 'term_taxonomy' ) { self::fail(); }
			$terms = $db->get_results( 'SELECT DISTINCT tr.term_taxonomy_id,tt.term_id,tt.taxonomy FROM `' . $db->term_relationships . '` tr INNER JOIN `' . $db->term_taxonomy . '` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id IN (' . implode( ',', $ids ) . ') ORDER BY tr.term_taxonomy_id ASC LIMIT 601', ARRAY_A );
			if ( ! is_array( $terms ) || count( $terms ) > 600 || '' !== $db->last_error ) { self::fail(); } $taxonomy = [];
			foreach ( $terms as $term ) { $tax_id = QuoteStorageCodec::integer( $term['term_taxonomy_id'] ?? null ); $taxonomy[$tax_id] = $tax_id; if ( 'product_cat' === ( $term['taxonomy'] ?? null ) ) { $category = QuoteStorageCodec::integer( $term['term_id'] ?? null ); $targets['category:' . $category] = [ 'type' => 'category', 'id' => $category ]; } }
			[ $offers, $dimensions ] = $this->discover_references( $db, array_values( $targets ), array_values( $scopes ), $offers, $dimensions );
			$fences = [ [ 'source' => 'product', 'ids' => array_values( $ids ) ], [ 'source' => 'product_meta', 'ids' => array_values( $ids ) ], [ 'source' => 'term_relationships', 'ids' => array_values( $ids ) ], [ 'source' => 'options', 'names' => LegacyQuoteSourcePlan::OPTIONS ], [ 'source' => 'offers', 'ids' => array_values( $offers ) ], [ 'source' => 'legacy_rules', 'targets' => array_values( $targets ) ], [ 'source' => 'scopes', 'targets' => array_values( $scopes ) ], [ 'source' => 'scope_fields' ], [ 'source' => 'scope_collections' ], [ 'source' => 'zones' ], [ 'source' => 'zone_rules' ], [ 'source' => 'coverage_groups' ], [ 'source' => 'coverage_members' ], [ 'source' => 'coverage_postcodes' ] ];
			if ( [] !== $taxonomy ) { $fences[] = [ 'source' => 'term_taxonomy', 'ids' => array_values( $taxonomy ) ]; } foreach ( $dimensions as $source => $values ) { if ( [] !== $values ) { $fences[] = [ 'source' => $source, 'ids' => array_values( $values ) ]; } }
			$session = null; $begun = false;
			try {
				$session = $this->factory->open(); if ( $session->site_id() !== $owner->site_id() || $session->table_prefix() !== $db->prefix || $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { self::fail(); } $begun = true;
				$seed = $this->reader->capture_seed( $session, $owner, $fences ); if ( ! $session->rollback() ) { self::fail(); } $begun = false; if ( ! $session->retire() ) { self::fail(); } return $seed;
			} finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
		} catch ( \Throwable ) { self::fail(); }
	}

	/** Retained resolver construction uses only the observed view; final current fences are still required. */
	public function runtime_for_seed( LegacyQuoteSourceSeedRows $seed ): ProductDeliveryRuntimeConfigurationRouter {
		foreach ( $seed->rows_for( 'zone_rules' ) as $rule ) { if ( ! in_array( $rule['rule_type'] ?? null, [ 'country', 'region', 'city', 'postcode' ], true ) || ! in_array( $rule['match_mode'] ?? null, [ 'exact', 'prefix' ], true ) ) { self::fail(); } }
		foreach ( $seed->rows_for( 'coverage_groups' ) as $group ) { if ( 'active' === ( $group['status'] ?? null ) ) { self::fail(); } }
		foreach ( LegacyQuoteSourcePlan::OPTIONS as $name ) { wp_cache_delete( $name, 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		return $this->router( new LegacyQuoteCapturedSourceView( $seed ) );
	}

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
		} catch ( \Throwable $error ) { throw new \RuntimeException( 'Delivery quote source unavailable.', 0, $error ); }
	}

	/** Exact-order authorized revalidation. The original private draft replaces no live cart. */
	public function prepare_saved( QuoteOwner $owner, QuoteContext $context, QuoteCartDraft $draft, callable $authorized ): LegacyQuoteSourceSnapshot {
		try {
			if ( true !== $authorized() || ! $owner->equals( $draft->owner() ) || ! $context->checkout_acceptable() ) { self::fail(); }
			$local = LegacyQuoteSourceLocalBinding::capture();
			$seed = $this->capture( $owner, $context, $this->seed_plan( $owner, $context ) ); $this->supported_geography( $seed );
			if ( true !== $authorized() ) { self::fail(); }
			$first = $this->capture( $owner, $context, $this->derive( $owner, $context, $seed, $draft ) );
			if ( true !== $authorized() ) { self::fail(); }
			$second = $this->capture( $owner, $context, $this->derive( $owner, $context, $first, $draft ) );
			if ( ! $first->matches( $second ) || ! $local->unchanged() || true !== $authorized() ) { self::fail(); }
			$this->supported_geography( $second ); return $second->bind_context( $context )->with_local_binding( $local );
		} catch ( \Throwable $error ) { throw new \RuntimeException( 'Delivery quote source unavailable.', 0, $error ); }
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

	private function derive( QuoteOwner $owner, QuoteContext $context, LegacyQuoteSourceSnapshot $captured, ?QuoteCartDraft $saved_draft = null ): LegacyQuoteSourcePlan {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wp_cache_delete' ) || ! class_exists( '\WC_Cache_Helper' ) || ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] instanceof \wpdb ) { self::fail(); }
		$db = $GLOBALS['wpdb']; $wc = WC(); $cart = $wc->cart ?? null; $items = []; $destinations = [];
		if ( null === $saved_draft ) { if ( ! $cart instanceof \WC_Cart || ! $cart->has_calculated_shipping() ) { self::fail(); } $items = $cart->get_cart(); }
		else {
			if ( ! $saved_draft->owner()->equals( $owner ) ) { self::fail(); }
			foreach ( $saved_draft->private_facts()['lines'] as $line ) {
				$customer = CustomerCartContext::fromArray( $line['customer_context'] ); if ( null === $customer || ! $customer->hasCompleteDeliveryAddress() ) { self::fail(); }
				$items[$line['line_key']] = [ 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'] ?? 0, 'quantity' => (int) $line['quantity'], CartDeliverySelectionCapture::CART_SELECTION_KEY => $line['selection'], CartDeliverySelectionCapture::CART_HASH_KEY => $line['selection_hash'], CustomerCartContext::CART_KEY => $line['customer_context'] ];
				$destinations[$line['line_key']] = $customer->delivery_address->toWcPackageDestination();
			}
		}
		$facts = $context->private_facts(); if ( ! is_array( $items ) || count( $items ) !== count( $facts['lines'] ) || count( $items ) > 200 ) { self::fail(); }
		$product_ids = []; foreach ( $facts['lines'] as $line ) { foreach ( array_filter( [ $line['product_id'], $line['variation_id'], $line['parent_id'] ] ) as $id ) { $product_ids[$id] = $id; } } if ( count( $product_ids ) > 600 ) { self::fail(); }
		foreach ( $product_ids as $id ) { foreach ( [ 'posts', 'post_meta', 'product_cat_relationships', 'product_type_relationships' ] as $group ) { wp_cache_delete( $id, $group ); } \WC_Cache_Helper::invalidate_cache_group( 'product_' . $id ); }
		foreach ( LegacyQuoteSourcePlan::OPTIONS as $name ) { wp_cache_delete( $name, 'options' ); } wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		$view = new LegacyQuoteCapturedSourceView( $captured ); $runtime = $this->router( $view ); $validator = new ProductDeliverySelectionValidator( new FeatureFlags(), new Requirements(), $runtime, new ProductDeliveryOptionsBuilder( $view->offers() ) );
		if ( null === $saved_draft ) { foreach ( $wc->shipping()->get_packages() as $package ) { if ( ! is_array( $package['contents'] ?? null ) || ! is_array( $package['destination'] ?? null ) ) { self::fail(); } foreach ( $package['contents'] as $key => $_ ) { if ( isset( $destinations[$key] ) ) { self::fail(); } $destinations[$key] = $package['destination']; } } }
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
		[ $offers, $dimensions ] = self::reference_sources( $captured->rows_for( 'legacy_rules' ), $captured->rows_for( 'scope_fields' ), $captured->rows_for( 'scope_collections' ), $offers, $dimensions );
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
		[ $offers, $dimensions ] = $this->discover_references( $db, array_values( $targets ), array_values( $scopes ), $offers, $dimensions );
		$fences = [ [ 'source' => 'product', 'ids' => array_values( $ids ) ], [ 'source' => 'product_meta', 'ids' => array_values( $ids ) ], [ 'source' => 'term_relationships', 'ids' => array_values( $ids ) ], [ 'source' => 'options', 'names' => LegacyQuoteSourcePlan::OPTIONS ], [ 'source' => 'offers', 'ids' => array_values( $offers ) ], [ 'source' => 'legacy_rules', 'targets' => array_values( $targets ) ], [ 'source' => 'scopes', 'targets' => array_values( $scopes ) ], [ 'source' => 'scope_fields' ], [ 'source' => 'scope_collections' ], [ 'source' => 'zones' ], [ 'source' => 'zone_rules' ], [ 'source' => 'coverage_groups' ], [ 'source' => 'coverage_members' ], [ 'source' => 'coverage_postcodes' ] ]; if ( [] !== $taxonomy ) { $fences[] = [ 'source' => 'term_taxonomy', 'ids' => array_values( $taxonomy ) ]; } foreach ( $dimensions as $source => $values ) { if ( [] !== $values ) { $fences[] = [ 'source' => $source, 'ids' => array_values( $values ) ]; } } return LegacyQuoteSourcePlan::create( $owner, $context, $members, array_values( $ranges ), $fences );
	}
	/** Read-only bounded discovery; the later current capture verifies every reference again. */
	private function discover_references( \wpdb $db, array $targets, array $scopes, array $offers, array $dimensions ): array {
		$parts = []; foreach ( $targets as $target ) { $parts[] = $db->prepare( '(target_type=%s AND target_id=%d)', $target['type'], $target['id'] ); }
		$count = 0; $bytes = 0; $rules = self::discovery_rows( $db, 'SELECT origin_id,supplier_id,logistics_profile_id,CASE WHEN OCTET_LENGTH(delivery_offer_ids)<=8192 THEN delivery_offer_ids ELSE NULL END AS delivery_offer_ids,CASE WHEN OCTET_LENGTH(delivery_offer_ids)>8192 THEN 1 ELSE 0 END AS source_oversized FROM `' . $db->prefix . 'delivery_engine_product_delivery_rules` WHERE (' . implode( ' OR ', $parts ) . ') ORDER BY id ASC LIMIT 1001', $count, $bytes );
		$parts = []; foreach ( $scopes as $scope ) { $parts[] = $db->prepare( '(scope_type=%s AND scope_id=%d)', $scope['type'], $scope['id'] ); }
		$scope_rows = self::discovery_rows( $db, 'SELECT id,0 AS source_oversized FROM `' . $db->prefix . 'delivery_engine_configuration_scopes` WHERE (' . implode( ' OR ', $parts ) . ') ORDER BY id ASC LIMIT 1001', $count, $bytes );
		$ids = []; foreach ( $scope_rows as $row ) { $ids[] = QuoteStorageCodec::integer( $row['id'] ); } $fields = []; $collections = [];
		if ( [] !== $ids ) {
			$where = 'scope_row_id IN (' . implode( ',', $ids ) . ')';
			$fields = self::discovery_rows( $db, 'SELECT field_key,mode,value_type,CASE WHEN OCTET_LENGTH(value_text)<=8192 THEN value_text ELSE NULL END AS value_text,CASE WHEN OCTET_LENGTH(value_text)>8192 THEN 1 ELSE 0 END AS source_oversized FROM `' . $db->prefix . 'delivery_engine_configuration_fields` WHERE ' . $where . ' ORDER BY id ASC LIMIT 1001', $count, $bytes );
			$collections = self::discovery_rows( $db, 'SELECT field_key,mode,CASE WHEN OCTET_LENGTH(members_json)<=8192 THEN members_json ELSE NULL END AS members_json,CASE WHEN OCTET_LENGTH(members_json)>8192 THEN 1 ELSE 0 END AS source_oversized FROM `' . $db->prefix . 'delivery_engine_configuration_collections` WHERE ' . $where . ' ORDER BY id ASC LIMIT 1001', $count, $bytes );
		}
		return self::reference_sources( $rules, $fields, $collections, $offers, $dimensions );
	}
	private static function discovery_rows( \wpdb $db, string $sql, int &$count, int &$bytes ): array {
		$rows = $db->get_results( $sql, ARRAY_A ); if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) > 1000 || '' !== $db->last_error ) { self::fail(); }
		foreach ( $rows as &$row ) { if ( ! is_array( $row ) || ! in_array( $row['source_oversized'] ?? null, [ 0, '0' ], true ) ) { self::fail(); } unset( $row['source_oversized'] ); ++$count; $bytes += strlen( QuoteJson::encode( $row ) ); if ( $count > LegacyQuoteSourceSnapshotReader::MAX_SOURCE_ROWS || $bytes > LegacyQuoteSourceSnapshotReader::MAX_CAPTURE_BYTES ) { self::fail(); } } unset( $row ); return $rows;
	}
	/** Selected and referenced identities are fenced; they never add price ranges. */
	private static function reference_sources( array $rules, array $fields, array $collections, array $offers, array $dimensions ): array {
		if ( count( $rules ) > 1000 || count( $fields ) > 1000 || count( $collections ) > 1000 ) { self::fail(); }
		$decoder = new WpdbProductDeliveryRuleRepository(); $mapping = [ 'origin_id' => 'origins', 'supplier_id' => 'suppliers', 'logistics_profile_id' => 'profiles' ];
		foreach ( $rules as $rule ) {
			$raw = $rule['delivery_offer_ids'] ?? null; if ( null !== $raw && ( ! is_string( $raw ) || strlen( $raw ) > 8192 ) ) { self::fail(); }
			foreach ( $decoder->decode_offer_ids( $raw ) as $id ) { $offers[$id] = $id; if ( count( $offers ) > 200 ) { self::fail(); } }
			foreach ( $mapping as $field => $source ) { $raw = $rule[$field] ?? null; if ( null !== $raw ) { $id = QuoteStorageCodec::integer( $raw, 0 ); if ( $id > 0 ) { $dimensions[$source][$id] = $id; } } }
		}
		foreach ( $collections as $row ) { if ( 'delivery_offer_ids' !== ( $row['field_key'] ?? null ) ) { continue; } $raw = $row['members_json'] ?? null; if ( null !== $raw && ( ! is_string( $raw ) || strlen( $raw ) > 8192 ) ) { self::fail(); } $instruction = CollectionFieldInstruction::fromStorage( $row['field_key'], $row['mode'], $raw ); foreach ( $instruction->members as $id ) { $offers[$id] = $id; if ( count( $offers ) > 200 ) { self::fail(); } } }
		foreach ( $fields as $row ) { $source = $mapping[$row['field_key'] ?? ''] ?? null; if ( null === $source ) { continue; } $instruction = ScalarFieldInstruction::fromStorage( $row['field_key'], $row['mode'], $row['value_text'], $row['value_type'] ); if ( null !== $instruction->value ) { $id = QuoteStorageCodec::integer( $instruction->value, 0 ); if ( $id > 0 ) { $dimensions[$source][$id] = $id; } } }
		if ( count( $offers ) > 200 ) { self::fail(); } foreach ( $dimensions as $ids ) { if ( count( $ids ) > 600 ) { self::fail(); } } return [ $offers, $dimensions ];
	}
	private static function fail(): never { throw new \RuntimeException( 'Delivery quote source unavailable.' ); }
}
