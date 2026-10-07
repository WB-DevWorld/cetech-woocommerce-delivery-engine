<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationRecordRepository;

/** Server-prepared finite selectors. SQL text and column names are never inputs. */
final readonly class LegacyQuoteSourcePlan implements \JsonSerializable {
	public const ROUTE_OPTIONS = [ 'cetech_de_enable_effective_configuration_runtime', 'cetech_de_enable_variable_product_ecr_runtime', 'cetech_de_enable_category_rules', 'cetech_de_enable_site_fallback_rule' ];
	public const OPTIONS = [ ...self::ROUTE_OPTIONS, 'cetech_de_enable_product_delivery_selector', 'cetech_de_global_configuration_version', 'cetech_de_sitewide_defaults', 'woocommerce_currency', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_round_at_subtotal', 'woocommerce_shipping_tax_class', 'woocommerce_default_country', 'woocommerce_default_customer_address', 'woocommerce_tax_based_on' ];
	public const SOURCES = [ 'product' => '@posts', 'product_meta' => '@postmeta', 'term_relationships' => '@term_relationships', 'term_taxonomy' => '@term_taxonomy', 'options' => '@options', 'offers' => 'delivery_offers', 'origins' => 'origins', 'suppliers' => 'suppliers', 'profiles' => 'logistics_profiles', 'geo_locations' => 'geography_locations', 'geo_packs' => 'geography_packs', 'scopes' => 'configuration_scopes', 'scope_fields' => 'configuration_fields', 'scope_collections' => 'configuration_collections', 'legacy_rules' => 'product_delivery_rules', 'zones' => 'destination_zones', 'zone_rules' => 'destination_rules', 'coverage_groups' => 'destination_coverage_groups', 'coverage_members' => 'destination_coverage_members', 'coverage_postcodes' => 'destination_coverage_postcodes' ];
	private function __construct( private QuoteOwner $owner, private QuoteContext $context, private array $members, private array $ranges, private array $fences ) {}
	public static function create( QuoteOwner $owner, QuoteContext $context, array $member_proofs, array $rate_ranges, array $fences ): self {
		if ( ! $context->checkout_acceptable() || $owner->key_epoch() !== $context->private_facts()['destination']['key_epoch'] || ! array_is_list( $member_proofs ) || ! array_is_list( $rate_ranges ) || ! array_is_list( $fences ) || count( $member_proofs ) > 200 || [] === $rate_ranges || count( $rate_ranges ) > 200 || count( $fences ) > count( self::SOURCES ) ) { QuoteShape::invalid(); }
		$groups = []; foreach ( $context->private_facts()['groups'] as $group ) { foreach ( $group['line_keys'] as $line_key ) { $groups[$line_key] = $group; } }
		$members = [];
		foreach ( $member_proofs as $proof ) {
			QuoteShape::fields( $proof, [ 'line_key', 'offer_id', 'service_id', 'choice', 'origin', 'supplier', 'profile', 'destination_zone_id', 'endpoint_digest' ] ); $key = QuoteShape::machine( $proof['line_key'], 128 );
			if ( isset( $members[$key] ) || ! isset( $groups[$key] ) ) { QuoteShape::invalid(); } $group = $groups[$key];
			foreach ( [ 'offer_id', 'service_id', 'choice', 'origin', 'supplier', 'profile', 'destination_zone_id', 'endpoint_digest' ] as $field ) { if ( QuoteJson::encode( [ 'value' => $proof[$field] ] ) !== QuoteJson::encode( [ 'value' => $group[$field] ] ) ) { QuoteShape::invalid(); } }
			// The first provider's service identity is explicitly offer-specific; service_level is fenced.
			if ( $proof['service_id'] !== $proof['offer_id'] || 'delivery' !== $proof['choice'] ) { QuoteShape::invalid(); } $members[$key] = QuoteJson::decode( QuoteJson::encode( $proof ) );
		}
		if ( count( $members ) !== count( $context->private_facts()['lines'] ) ) { QuoteShape::invalid(); } ksort( $members, SORT_STRING );
		$ranges = [];
		foreach ( $rate_ranges as $range ) { QuoteShape::fields( $range, [ 'delivery_offer_id', 'destination_zone_id', 'base_currency' ] ); $range = [ 'delivery_offer_id' => QuoteShape::integer( $range['delivery_offer_id'] ), 'destination_zone_id' => QuoteShape::integer( $range['destination_zone_id'] ), 'base_currency' => QuoteShape::currency( $range['base_currency'] ) ]; $key = self::range_key( $range ); if ( isset( $ranges[$key] ) ) { QuoteShape::invalid(); } $ranges[$key] = $range; }
		foreach ( $groups as $group ) { if ( ! isset( $ranges[self::range_key( [ 'delivery_offer_id' => $group['offer_id'], 'destination_zone_id' => $group['destination_zone_id'], 'base_currency' => $context->private_facts()['currency']['base'] ] )] ) ) { QuoteShape::invalid(); } } ksort( $ranges, SORT_STRING );
		$selectors = [];
		foreach ( $fences as $fence ) {
			if ( ! is_array( $fence ) || ! is_string( $fence['source'] ?? null ) || ! isset( self::SOURCES[$fence['source']] ) || isset( $selectors[$fence['source']] ) ) { QuoteShape::invalid(); } $source = $fence['source'];
			if ( 'options' === $source ) { QuoteShape::fields( $fence, [ 'source', 'names' ] ); if ( ! is_array( $fence['names'] ) || ! array_is_list( $fence['names'] ) || [] === $fence['names'] || count( $fence['names'] ) > count( self::OPTIONS ) ) { QuoteShape::invalid(); } foreach ( $fence['names'] as $name ) { if ( ! in_array( $name, self::OPTIONS, true ) ) { QuoteShape::invalid(); } } $values = array_values( array_unique( $fence['names'] ) ); if ( count( $values ) !== count( $fence['names'] ) ) { QuoteShape::invalid(); } sort( $values, SORT_STRING ); $fence = [ 'source' => $source, 'names' => $values ]; }
			elseif ( in_array( $source, [ 'scopes', 'legacy_rules' ], true ) ) {
				QuoteShape::fields( $fence, [ 'source', 'targets' ] ); if ( ! is_array( $fence['targets'] ) || ! array_is_list( $fence['targets'] ) || [] === $fence['targets'] || count( $fence['targets'] ) > 600 ) { QuoteShape::invalid(); } $targets = [];
				foreach ( $fence['targets'] as $target ) { QuoteShape::fields( $target, [ 'type', 'id' ] ); $type = QuoteShape::choice( $target['type'], 'scopes' === $source ? [ 'global', 'product', 'variation' ] : [ 'product', 'variation', 'category' ] ); $id = QuoteShape::integer( $target['id'], 'global' === $type ? 0 : 1 ); if ( 'global' === $type && 0 !== $id ) { QuoteShape::invalid(); } $key = $type . ':' . str_pad( (string) $id, 20, '0', STR_PAD_LEFT ); if ( isset( $targets[$key] ) ) { QuoteShape::invalid(); } $targets[$key] = [ 'type' => $type, 'id' => $id ]; } ksort( $targets, SORT_STRING ); $fence = [ 'source' => $source, 'targets' => array_values( $targets ) ];
			} elseif ( in_array( $source, [ 'scope_fields', 'scope_collections', 'zones', 'zone_rules', 'coverage_groups', 'coverage_members', 'coverage_postcodes' ], true ) ) { QuoteShape::fields( $fence, [ 'source' ] ); }
			else { QuoteShape::fields( $fence, [ 'source', 'ids' ] ); if ( ! is_array( $fence['ids'] ) || ! array_is_list( $fence['ids'] ) || [] === $fence['ids'] || count( $fence['ids'] ) > 600 ) { QuoteShape::invalid(); } $ids = []; foreach ( $fence['ids'] as $id ) { $ids[] = QuoteShape::integer( $id ); } if ( count( array_unique( $ids ) ) !== count( $ids ) ) { QuoteShape::invalid(); } sort( $ids, SORT_NUMERIC ); $fence = [ 'source' => $source, 'ids' => $ids ]; }
			$selectors[$source] = $fence;
		}
		self::coverage( $context, $selectors ); ksort( $selectors, SORT_STRING ); return new self( $owner, $context, array_values( $members ), array_values( $ranges ), array_values( $selectors ) );
	}
	private static function coverage( QuoteContext $context, array $selectors ): void {
		foreach ( [ 'product', 'product_meta', 'term_relationships', 'options', 'offers', 'zones', 'zone_rules' ] as $source ) { if ( ! isset( $selectors[$source] ) ) { QuoteShape::invalid(); } }
		foreach ( self::ROUTE_OPTIONS as $name ) { if ( ! in_array( $name, $selectors['options']['names'], true ) ) { QuoteShape::invalid(); } }
		$facts = $context->private_facts();
		foreach ( $facts['lines'] as $line ) {
			foreach ( array_unique( array_filter( [ $line['product_id'], $line['variation_id'], $line['parent_id'] ] ) ) as $id ) { foreach ( [ 'product', 'product_meta', 'term_relationships' ] as $source ) { if ( ! in_array( $id, $selectors[$source]['ids'], true ) ) { QuoteShape::invalid(); } } }
			$source = 'ecr' === $line['source']['route'] ? 'scopes' : 'legacy_rules'; if ( ! isset( $selectors[$source] ) ) { QuoteShape::invalid(); }
			foreach ( [ [ 'product', $line['product_id'] ], [ 'variation', $line['variation_id'] ], [ 'product', $line['parent_id'] ], ...( 'scopes' === $source ? [ [ 'global', 0 ] ] : [] ) ] as [ $type, $id ] ) { if ( null === $id ) { continue; } if ( ! in_array( [ 'type' => $type, 'id' => $id ], $selectors[$source]['targets'], true ) ) { QuoteShape::invalid(); } }
			if ( 'scopes' === $source && ( ! isset( $selectors['scope_fields'] ) || ! isset( $selectors['scope_collections'] ) ) ) { QuoteShape::invalid(); }
		}
		foreach ( $facts['groups'] as $group ) { if ( ! in_array( $group['offer_id'], $selectors['offers']['ids'], true ) ) { QuoteShape::invalid(); } foreach ( [ 'origin' => 'origins', 'supplier' => 'suppliers', 'profile' => 'profiles' ] as $field => $source ) { if ( 'known' === $group[$field]['state'] && ( ! isset( $selectors[$source] ) || ! in_array( $group[$field]['id'], $selectors[$source]['ids'], true ) ) ) { QuoteShape::invalid(); } } }
	}
	public function owner(): QuoteOwner { return $this->owner; }
	public function context(): QuoteContext { return $this->context; }
	public function member_proofs(): array { return $this->members; }
	public function rate_ranges(): array { return $this->ranges; }
	public function fences(): array { return $this->fences; }
	/** Only the separately sealed native receipt and this capture's policy slots may be filled. */
	public function matches_context( QuoteContext $context ): bool { $a = $this->context->private_facts(); $b = $context->private_facts(); foreach ( [ &$a, &$b ] as &$facts ) { unset( $facts['tax'] ); foreach ( $facts['groups'] as &$group ) { unset( $group['candidate_digest'], $group['candidate_count'], $group['policy_digest'] ); } unset( $group ); } unset( $facts ); return QuoteJson::encode( $a ) === QuoteJson::encode( $b ); }
	public function tables( OperationSession $session ): array { if ( $session->site_id() !== $this->owner->site_id() ) { QuoteShape::invalid(); } $tables = [ WpdbOperationRecordRepository::table_name( $session, 'rate_cards' ) ]; foreach ( $this->fences as $fence ) { $tables[] = self::table( $session, $fence['source'] ); } $tables = array_values( array_unique( $tables ) ); sort( $tables, SORT_STRING ); if ( count( $tables ) > 1 + count( self::SOURCES ) ) { QuoteShape::invalid(); } return $tables; }
	public static function table( OperationSession $session, string $source ): string { $suffix = self::SOURCES[$source] ?? null; if ( null === $suffix ) { QuoteShape::invalid(); } $table = str_starts_with( $suffix, '@' ) ? $session->table_prefix() . substr( $suffix, 1 ) : WpdbOperationRecordRepository::table_name( $session, $suffix ); if ( strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { QuoteShape::invalid(); } return $table; }
	public static function range_key( array $range ): string { return str_pad( (string) $range['delivery_offer_id'], 20, '0', STR_PAD_LEFT ) . ':' . str_pad( (string) $range['destination_zone_id'], 20, '0', STR_PAD_LEFT ) . ':' . $range['base_currency']; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized source projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native source plans cannot be serialized generically.' ); }
}
