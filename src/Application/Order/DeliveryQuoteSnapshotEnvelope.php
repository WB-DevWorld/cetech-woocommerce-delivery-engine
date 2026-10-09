<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseQuotePacket;

/** Protected captured history. Recognition never proves sealed placement or payment. */
final readonly class DeliveryQuoteSnapshotEnvelope implements \JsonSerializable {
	public const FORMAT = 1;
	public const META_FORMAT = '_cetech_de_delivery_quote_format';
	public const MEMBER = 'delivery_quote';
	public const MAX_BYTES = 65536;
	public const PROFILE = [ 'code' => 'legacy_fixed_base_v1', 'version' => 1 ];
	public const PROMISE_FORMAT = 2;
	public const PROMISE_PROFILE = [ 'code' => 'service_promise_v1', 'version' => 1 ];
	public const PROMISE_OUTER_VERSION = '3';
	public const PROMISE_DIGEST_DOMAIN = 'cetech-required-promise-snapshot-v1:';
	private function __construct( private string $json ) {}

	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data, self::MAX_BYTES );
		if ( self::PROMISE_FORMAT === ( $data['format'] ?? null ) ) { return self::from_promise_array( $data ); }
		QuoteShape::fields( $data, [ 'format', 'quote_id', 'profile', 'issued_at', 'expires_at', 'accepted_at', 'body_digest', 'material_digest', 'placement_id', 'context_digest', 'money_receipt', 'provenance_receipt' ] );
		if ( self::FORMAT !== $data['format'] || QuoteShape::object( $data['profile'] ) !== self::PROFILE ) { QuoteShape::invalid(); }
		foreach ( [ 'quote_id', 'placement_id' ] as $field ) { if ( ! is_string( $data[$field] ) ) { QuoteShape::invalid(); } QuoteId::from_string( $data[$field] ); }
		foreach ( [ 'body_digest', 'material_digest', 'context_digest' ] as $field ) { QuoteShape::digest( $data[$field] ); }
		foreach ( [ 'issued_at', 'expires_at', 'accepted_at' ] as $field ) { if ( ! is_string( $data[$field] ) ) { QuoteShape::invalid(); } }
		$issued = QuoteTime::parse( $data['issued_at'] ); $expires = QuoteTime::parse( $data['expires_at'] ); $accepted = QuoteTime::parse( $data['accepted_at'] );
		if ( ! $issued->plus_seconds( QuoteHeader::TTL_SECONDS )->equals( $expires ) || $accepted->compare( $issued ) < 0 || $accepted->compare( $expires ) >= 0 ) { QuoteShape::invalid(); }
		$money = QuoteShape::object( $data['money_receipt'] ); $provenance = QuoteShape::object( $data['provenance_receipt'] );
		foreach ( [ $money, $provenance ] as $receipt ) { QuoteShape::fields( $receipt, [ 'format', 'groups' ] ); if ( 1 !== $receipt['format'] ) { QuoteShape::invalid(); } }
		$prices = QuoteShape::list( $money['groups'], 200, 1 ); $sources = QuoteShape::list( $provenance['groups'], 200, 1 ); $source_index = [];
		foreach ( $sources as $source ) {
			$source = QuoteShape::object( $source );
			QuoteShape::fields( $source, [ 'component_key', 'provider', 'policy_digest', 'candidate_digest', 'native_tax_receipt', 'native_money_receipt', 'promotion_provider', 'cost', 'route' ] );
			$key = QuoteShape::digest( $source['component_key'] ); QuoteShape::digest( $source['policy_digest'] ); QuoteShape::digest( $source['candidate_digest'] );
			if ( isset( $source_index[$key] ) || QuoteShape::object( $source['provider'] ) !== self::PROFILE
				|| QuoteShape::object( $source['promotion_provider'] ) !== [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ]
				|| QuoteShape::object( $source['cost'] ) !== [ 'reason' => 'cost_provider_unavailable', 'state' => 'unavailable' ]
				|| QuoteShape::object( $source['route'] ) !== [ 'state' => 'not_recorded' ] ) { QuoteShape::invalid(); }
			$source_index[$key] = $source;
		}
		$seen = []; $currency = null; $tax_digest = null; $money_digest = null;
		foreach ( $prices as $price ) {
			$price = QuoteShape::object( $price );
			QuoteShape::fields( $price, [ 'component_key', 'customer_label', 'list', 'final', 'tax', 'total', 'promotion', 'rounded_tax', 'display_total' ] );
			$key = QuoteShape::digest( $price['component_key'] ); $source = $source_index[$key] ?? null;
			if ( isset( $seen[$key] ) || null === $source ) { QuoteShape::invalid(); } $seen[$key] = true;
			$promotion = QuoteShape::object( $price['promotion'] ); QuoteShape::fields( $promotion, [ 'state', 'amount' ] );
			if ( 'none' !== $promotion['state'] || ! QuoteMoney::from_array( QuoteShape::object( $promotion['amount'] ) )->zero() ) { QuoteShape::invalid(); }
			$term = [ 'component_key' => $key, 'customer_label' => $price['customer_label'], 'provider' => $source['provider'], 'policy_digest' => $source['policy_digest'], 'list' => $price['list'], 'final' => $price['final'], 'tax' => $price['tax'], 'total' => $price['total'], 'promotion' => $promotion + [ 'provider' => $source['promotion_provider'] ], 'cost' => $source['cost'], 'route' => $source['route'], 'native_tax_receipt' => $source['native_tax_receipt'], 'native_money_receipt' => $source['native_money_receipt'] ];
			$validated = QuoteTerms::from_array( [ 'format_version' => 1, 'groups' => [ $term ] ] );
			if ( ! $validated->checkout_acceptable() ) { QuoteShape::invalid(); }
			$term = $validated->private_facts()['groups'][0];
			$list = QuoteMoney::from_array( $term['list'] ); $final = QuoteMoney::from_array( $term['final'] );
			$rounded = QuoteMoney::from_array( QuoteShape::object( $price['rounded_tax'] ) ); $display = QuoteMoney::from_array( QuoteShape::object( $price['display_total'] ) );
			if ( ! $list->equals( $final ) || ! $rounded->equals( QuoteMoney::from_array( $term['native_tax_receipt']['rounded_tax'] ) )
				|| ! $display->equals( QuoteMoney::from_array( $term['native_money_receipt']['display_total'] ) )
				|| ( null !== $currency && $currency !== $final->currency() )
				|| ( null !== $tax_digest && $tax_digest !== $term['native_tax_receipt']['context_digest'] )
				|| ( null !== $money_digest && $money_digest !== $term['native_money_receipt']['evidence_digest'] ) ) { QuoteShape::invalid(); }
			$currency = $final->currency(); $tax_digest = $term['native_tax_receipt']['context_digest']; $money_digest = $term['native_money_receipt']['evidence_digest'];
		}
		if ( count( $seen ) !== count( $source_index ) ) { QuoteShape::invalid(); }
		// Exact original captured facts are detached; normalization never writes Woo meta.
		return new self( QuoteJson::encode( $data, self::MAX_BYTES ) );
	}

	/** The retained native money receipt stays distinct from the new promise authority. */
	private static function from_promise_array( array $data ): self {
		QuoteShape::fields( $data, [ 'format', 'quote_id', 'profile', 'issued_at', 'expires_at', 'accepted_at', 'body_digest', 'material_digest', 'placement_id', 'context_digest', 'money_receipt', 'provenance_receipt', 'promise_packet', 'promise_packet_digest' ] );
		if ( QuoteShape::object( $data['profile'] ) !== self::PROMISE_PROFILE ) { QuoteShape::invalid(); }
		$packet = PromiseQuotePacket::from_array( QuoteShape::object( $data['promise_packet'] ) );
		if ( ! hash_equals( QuoteShape::digest( $data['promise_packet_digest'] ), self::promise_digest( $packet ) ) ) { QuoteShape::invalid(); }
		$legacy = $data; unset( $legacy['promise_packet'], $legacy['promise_packet_digest'] );
		$legacy['format'] = self::FORMAT; $legacy['profile'] = self::PROFILE;
		self::from_array( $legacy ); // The exact v1 money/provenance validator is unchanged.
		$keys = array_column( $data['money_receipt']['groups'], 'component_key' ); $packet->assert_component_keys( $keys );
		$shared = null;
		foreach ( $packet->private_facts()['groups'] as $group ) {
			$input = PromiseJson::decode( $group['packet']['input_json'] );
			if ( $input['evaluated_at'] !== $data['issued_at'] || $input['anchor']['quote_expires_at'] !== $data['expires_at'] || $input['material']['group_id'] !== $group['component_key'] ) { QuoteShape::invalid(); }
			$facts = [ $input['site_id'], $input['owner'], $input['material']['material_digest'] ];
			if ( null !== $shared && $facts !== $shared ) { QuoteShape::invalid(); } $shared = $facts;
		}
		$data['promise_packet'] = $packet->private_facts();
		return new self( QuoteJson::encode( $data, self::MAX_BYTES ) );
	}
	public static function promise_digest( PromiseQuotePacket $packet ): string { return hash( 'sha256', self::PROMISE_DIGEST_DOMAIN . $packet->to_private_json() ); }

	public static function from_json( string $json ): self {
		$data = QuoteJson::decode( $json, self::MAX_BYTES );
		$object = json_decode( $json, false, QuoteJson::MAX_DEPTH + 2, JSON_THROW_ON_ERROR );
		foreach ( [ 'money_receipt', 'provenance_receipt' ] as $name ) {
			$receipt = $object->{$name} ?? null;
			if ( ! $receipt instanceof \stdClass || ! isset( $receipt->groups ) || ! is_array( $receipt->groups ) ) { QuoteShape::invalid(); }
			foreach ( $receipt->groups as $group ) {
				if ( ! $group instanceof \stdClass ) { QuoteShape::invalid(); }
				if ( 'provenance_receipt' === $name && ( ! ( $group->native_tax_receipt ?? null ) instanceof \stdClass || ! is_array( $group->native_tax_receipt->rates ?? null ) ) ) { QuoteShape::invalid(); }
			}
		}
		if ( self::PROMISE_FORMAT === ( $data['format'] ?? null ) ) {
			$packet = $object->promise_packet ?? null;
			if ( ! $packet instanceof \stdClass || ! is_array( $packet->groups ?? null ) ) { QuoteShape::invalid(); }
			foreach ( $packet->groups as $group ) { if ( ! $group instanceof \stdClass || ! ( $group->packet ?? null ) instanceof \stdClass || ! is_array( $group->packet->public_views ?? null ) ) { QuoteShape::invalid(); } foreach ( $group->packet->public_views as $view ) { if ( ! $view instanceof \stdClass || ! ( $view->fields ?? null ) instanceof \stdClass ) { QuoteShape::invalid(); } } }
		}
		return self::from_array( $data );
	}

	/** Pure packet construction from verified captured facts. This never writes or seals an order. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $accepted_at, QuoteId $placement_id, string $context_digest ): self {
		$header->assert_body( $context, $terms );
		$profile = [ 'code' => $header->profile(), 'version' => $header->profile_version() ]; $promise = self::PROMISE_PROFILE === $profile;
		if ( 'checkout' !== $header->purpose() || ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() || ( ! $promise && self::PROFILE !== $profile ) ) { QuoteShape::invalid(); }
		$components = []; foreach ( $context->private_facts()['groups'] as $group ) {
			if ( $group['service_id'] !== $group['offer_id'] ) { QuoteShape::invalid(); }
			$components[$group['component_key']] = $group;
		}
		$money = []; $provenance = [];
		foreach ( $terms->private_facts()['groups'] as $term ) {
			$group = $components[$term['component_key']] ?? null; if ( null === $group ) { QuoteShape::invalid(); }
			$money[] = [ 'component_key' => $term['component_key'], 'customer_label' => $term['customer_label'], 'list' => $term['list'], 'final' => $term['final'], 'tax' => $term['tax'], 'total' => $term['total'], 'promotion' => [ 'state' => $term['promotion']['state'], 'amount' => $term['promotion']['amount'] ?? null ], 'rounded_tax' => $term['native_tax_receipt']['rounded_tax'] ?? null, 'display_total' => $term['native_money_receipt']['display_total'] ?? null ];
			$provenance[] = [ 'component_key' => $term['component_key'], 'provider' => $term['provider'], 'policy_digest' => $term['policy_digest'], 'candidate_digest' => $group['candidate_digest'], 'native_tax_receipt' => $term['native_tax_receipt'], 'native_money_receipt' => $term['native_money_receipt'], 'promotion_provider' => $term['promotion']['provider'] ?? null, 'cost' => $term['cost'], 'route' => $term['route'] ];
		}
		$data = [ 'format' => $promise ? self::PROMISE_FORMAT : self::FORMAT, 'quote_id' => $header->id()->value(), 'profile' => $profile, 'issued_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => $accepted_at->sql(), 'body_digest' => $header->body_digest(), 'material_digest' => $header->material_digest(), 'placement_id' => $placement_id->value(), 'context_digest' => $context_digest, 'money_receipt' => [ 'format' => 1, 'groups' => $money ], 'provenance_receipt' => [ 'format' => 1, 'groups' => $provenance ] ];
		if ( $promise ) {
			$packet = PromiseQuotePacket::from_array( [ 'format' => 1, 'groups' => $terms->private_facts()['promise_packets'] ] );
			$packet->assert_quote_capture( $header, $context );
			$data['promise_packet'] = $packet->private_facts(); $data['promise_packet_digest'] = self::promise_digest( $packet );
		}
		return self::from_array( $data );
	}

	public function private_facts(): array { return QuoteJson::decode( $this->json, self::MAX_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function format(): int { return $this->private_facts()['format']; }
	public function is_promise(): bool { return self::PROMISE_FORMAT === $this->format(); }
	public function promise_packet(): ?PromiseQuotePacket { return $this->is_promise() ? PromiseQuotePacket::from_array( $this->private_facts()['promise_packet'] ) : null; }
	public function matches( self $other ): bool { return hash_equals( $this->json, $other->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'A safe historical delivery quote projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Protected delivery quote snapshots cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Protected delivery quote snapshots require strict decoding.' ); }
}
