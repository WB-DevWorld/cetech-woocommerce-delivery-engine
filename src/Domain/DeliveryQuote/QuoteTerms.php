<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Immutable captured terms, not a pricing, tax, conversion or cost calculator. */
final readonly class QuoteTerms implements \JsonSerializable {
	public const FORMAT = 1;
	private function __construct( private string $json, private bool $acceptable ) {}
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data ); QuoteShape::fields( $data, [ 'format_version', 'groups' ] ); if ( self::FORMAT !== $data['format_version'] ) { QuoteShape::invalid(); }
		$groups = QuoteShape::list( $data['groups'], 200, 1 ); $seen = []; $acceptable = true;
		foreach ( $groups as &$group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'customer_label', 'provider', 'policy_digest', 'list', 'final', 'tax', 'total', 'promotion', 'cost', 'route', 'native_tax_receipt', 'native_money_receipt' ] );
			$key = QuoteShape::digest( $group['component_key'] ); if ( isset( $seen[$key] ) ) { QuoteShape::invalid(); } $seen[$key] = true;
			$label = $group['customer_label']; if ( ! is_string( $label ) || '' === trim( $label ) || strlen( $label ) > 120 || 1 !== preg_match( '//u', $label ) || 1 === preg_match( '/[\x00-\x1F\x7F<>]/u', $label ) ) { QuoteShape::invalid(); }
			$group['provider'] = self::provider( $group['provider'] ); QuoteShape::digest( $group['policy_digest'] ); $money = [];
			foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { $money[$field] = QuoteMoney::from_array( QuoteShape::object( $group[$field] ) ); $group[$field] = $money[$field]->facts(); }
			if ( ! $money['final']->add( $money['tax'] )->equals( $money['total'] ) ) { QuoteShape::invalid(); }
			$promotion = QuoteShape::object( $group['promotion'] );
			if ( 'unavailable' === ( $promotion['state'] ?? null ) ) { QuoteShape::fields( $promotion, [ 'state', 'reason' ] ); QuoteShape::choice( $promotion['reason'], [ 'promotion_context_unavailable', 'unsupported_context' ] ); $acceptable = false; }
			else {
				QuoteShape::fields( $promotion, [ 'state', 'amount', 'provider' ] ); QuoteShape::choice( $promotion['state'], [ 'none', 'applied' ] );
				$discount = QuoteMoney::from_array( QuoteShape::object( $promotion['amount'] ) ); $promotion['amount'] = $discount->facts(); $promotion['provider'] = self::provider( $promotion['provider'] );
				if ( ( 'none' === $promotion['state'] && ! $discount->zero() ) || ! $money['list']->subtract( $discount )->equals( $money['final'] ) ) { QuoteShape::invalid(); }
			} $group['promotion'] = $promotion;
			$group['cost'] = self::cost( $group['cost'] ); $group['route'] = self::route( $group['route'] );
			$group['native_tax_receipt'] = self::tax_receipt( $group['native_tax_receipt'], $money['tax'], $money['total'] ); $acceptable = $acceptable && 'recorded' === $group['native_tax_receipt']['state'];
			$group['native_money_receipt'] = self::money_receipt( $group['native_money_receipt'], $money['total'] ); $acceptable = $acceptable && 'recorded' === $group['native_money_receipt']['state'];
		} unset( $group );
		usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) ); $data['groups'] = $groups;
		return new self( QuoteJson::encode( $data ), $acceptable );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-quote-terms-v1:' . $this->json ); }
	public function checkout_acceptable(): bool { return $this->acceptable; }
	/** Explicit display whitelist; caller must authorize the containing quote. */
	public function display_money(): array {
		$out = []; foreach ( $this->private_facts()['groups'] as $group ) { $out[] = [ 'component_key' => $group['component_key'], 'customer_label' => $group['customer_label'], 'list' => $group['list'], 'final' => $group['final'], 'tax' => $group['tax'], 'total' => $group['total'], 'promotion' => [ 'state' => $group['promotion']['state'], 'amount' => 'unavailable' === $group['promotion']['state'] ? null : $group['promotion']['amount'] ], 'rounded_tax' => 'recorded' === $group['native_tax_receipt']['state'] ? $group['native_tax_receipt']['rounded_tax'] : null, 'display_total' => 'recorded' === $group['native_money_receipt']['state'] ? $group['native_money_receipt']['display_total'] : null ]; }
		return $out;
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote terms projection is required.' ); }
	private static function provider( mixed $value ): array { $data = QuoteShape::object( $value ); QuoteShape::fields( $data, [ 'code', 'version' ] ); QuoteShape::machine( $data['code'] ); QuoteShape::integer( $data['version'], 1, 1000000 ); return $data; }
	private static function cost( mixed $value ): array {
		$data = QuoteShape::object( $value ); $state = $data['state'] ?? null;
		if ( 'unavailable' === $state ) { QuoteShape::fields( $data, [ 'state', 'reason' ] ); QuoteShape::choice( $data['reason'], [ 'cost_provider_unavailable', 'unsupported_context' ] ); }
		elseif ( 'known' === $state ) { QuoteShape::fields( $data, [ 'state', 'amount', 'provider' ] ); $data['amount'] = QuoteMoney::from_array( QuoteShape::object( $data['amount'] ) )->facts(); $data['provider'] = self::provider( $data['provider'] ); }
		else { QuoteShape::invalid(); } return $data;
	}
	private static function route( mixed $value ): array {
		$data = QuoteShape::object( $value ); $state = $data['state'] ?? null;
		if ( 'not_recorded' === $state ) { QuoteShape::fields( $data, [ 'state' ] ); }
		elseif ( 'known' === $state ) {
			QuoteShape::fields( $data, [ 'state', 'distance', 'distance_unit', 'duration_seconds', 'provider' ] ); QuoteShape::choice( $data['distance_unit'], [ 'km', 'm' ] ); $data['provider'] = self::provider( $data['provider'] );
			foreach ( [ 'distance', 'duration_seconds' ] as $field ) { $amount = QuoteMoney::from_array( [ 'amount' => $data[$field], 'currency' => 'USD', 'precision' => 6 ] )->amount(); $data[$field] = str_contains( $amount, '.' ) ? rtrim( rtrim( $amount, '0' ), '.' ) : $amount; }
		} else { QuoteShape::invalid(); } return $data;
	}
	private static function tax_receipt( mixed $value, QuoteMoney $tax, QuoteMoney $total ): array {
		$data = QuoteShape::object( $value ); $state = $data['state'] ?? null;
		if ( 'not_recorded' === $state ) { QuoteShape::fields( $data, [ 'state' ] ); return $data; }
		if ( 'unavailable' === $state ) { QuoteShape::fields( $data, [ 'state', 'reason' ] ); QuoteShape::choice( $data['reason'], [ 'tax_context_unavailable', 'unsupported_context' ] ); return $data; }
		if ( 'recorded' !== $state ) { QuoteShape::invalid(); }
		QuoteShape::fields( $data, [ 'state', 'source', 'context_digest', 'exempt', 'tax_status', 'tax_class', 'location_digest', 'rounding', 'rates', 'rounded_tax', 'display_precision' ] );
		QuoteShape::choice( $data['source'], [ 'woocommerce' ] ); QuoteShape::digest( $data['context_digest'] ); QuoteShape::digest( $data['location_digest'] ); if ( ! is_bool( $data['exempt'] ) ) { QuoteShape::invalid(); }
		QuoteShape::choice( $data['tax_status'], [ 'taxable', 'none' ] ); QuoteShape::choice( $data['rounding'], [ 'per_line', 'subtotal' ] );
		if ( ! is_string( $data['tax_class'] ) || strlen( $data['tax_class'] ) > 64 || ( '' !== $data['tax_class'] && 1 !== preg_match( '/\A[a-z0-9][a-z0-9_-]*\z/D', $data['tax_class'] ) ) ) { QuoteShape::invalid(); }
		$rounded = QuoteMoney::from_array( QuoteShape::object( $data['rounded_tax'] ) ); $display_precision = QuoteShape::integer( $data['display_precision'], 0, 6 ); if ( $rounded->currency() !== $total->currency() || $rounded->precision() !== $display_precision ) { QuoteShape::invalid(); } $data['rounded_tax'] = $rounded->facts();
		$sum = QuoteMoney::from_array( [ 'amount' => '0', 'currency' => $tax->currency(), 'precision' => $tax->precision() ] ); $rates = QuoteShape::list( $data['rates'], 200 ); $seen = [];
		foreach ( $rates as &$rate ) { $rate = QuoteShape::object( $rate ); QuoteShape::fields( $rate, [ 'rate_id', 'amount' ] ); $id = QuoteShape::integer( $rate['rate_id'] ); if ( isset( $seen[$id] ) ) { QuoteShape::invalid(); } $seen[$id] = true; $money = QuoteMoney::from_array( QuoteShape::object( $rate['amount'] ) ); $sum = $sum->add( $money ); $rate['amount'] = $money->facts(); } unset( $rate );
		if ( ! $sum->equals( $tax ) || ( ( $data['exempt'] || 'none' === $data['tax_status'] ) && ( ! $tax->zero() || [] !== $rates ) ) ) { QuoteShape::invalid(); }
		usort( $rates, static fn( array $a, array $b ): int => $a['rate_id'] <=> $b['rate_id'] ); $data['rates'] = $rates; return $data;
	}
	private static function money_receipt( mixed $value, QuoteMoney $total ): array {
		$data = QuoteShape::object( $value ); $state = $data['state'] ?? null;
		if ( 'not_recorded' === $state ) { QuoteShape::fields( $data, [ 'state' ] ); return $data; }
		if ( 'unavailable' === $state ) { QuoteShape::fields( $data, [ 'state', 'reason' ] ); QuoteShape::choice( $data['reason'], [ 'money_context_unavailable', 'unsupported_context' ] ); return $data; }
		if ( 'recorded' !== $state ) { QuoteShape::invalid(); }
		QuoteShape::fields( $data, [ 'state', 'source', 'evidence_digest', 'native_total', 'display_total', 'display_precision' ] ); QuoteShape::choice( $data['source'], [ 'woocommerce' ] ); QuoteShape::digest( $data['evidence_digest'] );
		$native = QuoteMoney::from_array( QuoteShape::object( $data['native_total'] ) ); $display = QuoteMoney::from_array( QuoteShape::object( $data['display_total'] ) );
		if ( ! $native->equals( $total ) || $display->currency() !== $total->currency() || $display->precision() !== QuoteShape::integer( $data['display_precision'], 0, 6 ) ) { QuoteShape::invalid(); }
		$data['native_total'] = $native->facts(); $data['display_total'] = $display->facts(); return $data;
	}
}
