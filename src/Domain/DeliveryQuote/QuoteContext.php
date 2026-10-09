<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Complete immutable server capture; submitted fingerprints never reconstruct it. */
final readonly class QuoteContext implements \JsonSerializable {
	public const FORMAT = 1;
	public const MAX_LINES = 200;
	public const MAX_GROUPS = 200;
	public const MAX_ZONE_CANDIDATES = 200;
	public const MAX_RATE_CANDIDATES = 1000;
	private function __construct( private string $json, private bool $acceptable ) {}
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data );
		if ( 2 === ( $data['format_version'] ?? null ) ) { return self::from_v2( $data ); }
		QuoteShape::fields( $data, [ 'format_version', 'kind', 'selection_digest', 'destination', 'currency', 'tax', 'lines', 'groups' ] );
		if ( self::FORMAT !== $data['format_version'] ) { QuoteShape::invalid(); }
		QuoteShape::choice( $data['kind'], [ 'checkout', 'estimate' ] ); QuoteShape::digest( $data['selection_digest'] );
		$destination = QuoteShape::object( $data['destination'] ); QuoteShape::fields( $destination, [ 'kind', 'digest', 'key_epoch' ] );
		QuoteShape::choice( $destination['kind'], [ 'full', 'estimate' ] ); QuoteShape::digest( $destination['digest'] ); QuoteShape::machine( $destination['key_epoch'] );
		$currency = QuoteShape::object( $data['currency'] ); QuoteShape::fields( $currency, [ 'base', 'presentment', 'charged', 'precision' ] );
		foreach ( [ 'base', 'presentment', 'charged' ] as $field ) { QuoteShape::currency( $currency[$field] ); } QuoteShape::integer( $currency['precision'], 0, 6 );
		$tax = QuoteShape::object( $data['tax'] ); QuoteShape::fields( $tax, [ 'context_digest', 'native_money_digest' ] ); foreach ( $tax as $value ) { QuoteShape::digest( $value ); }
		$acceptable = 'checkout' === $data['kind'] && 'full' === $destination['kind'] && $currency['base'] === $currency['presentment'] && $currency['base'] === $currency['charged'];
		$lines = QuoteShape::list( $data['lines'], self::MAX_LINES, 1 ); $line_index = [];
		foreach ( $lines as &$line ) {
			$line = QuoteShape::object( $line ); QuoteShape::fields( $line, [ 'line_key', 'product_id', 'variation_id', 'parent_id', 'quantity', 'component_key', 'source', 'inventory' ] );
			$key = QuoteShape::machine( $line['line_key'], 128 ); if ( isset( $line_index[$key] ) ) { QuoteShape::invalid(); }
			QuoteShape::integer( $line['product_id'] ); QuoteShape::digest( $line['component_key'] );
			if ( null === $line['variation_id'] ) { if ( null !== $line['parent_id'] ) { QuoteShape::invalid(); } }
			else { QuoteShape::integer( $line['variation_id'] ); QuoteShape::integer( $line['parent_id'] ); if ( $line['parent_id'] !== $line['product_id'] || $line['variation_id'] === $line['product_id'] ) { QuoteShape::invalid(); } }
			$line['quantity'] = self::quantity( $line['quantity'] );
			$source = QuoteShape::object( $line['source'] ); QuoteShape::fields( $source, [ 'route', 'identity_digest', 'revision' ] ); QuoteShape::choice( $source['route'], [ 'legacy', 'ecr' ] ); QuoteShape::digest( $source['identity_digest'] ); QuoteShape::integer( $source['revision'] );
			$inventory = QuoteShape::object( $line['inventory'] ); QuoteShape::fields( $inventory, [ 'status', 'evidence_digest' ] ); QuoteShape::choice( $inventory['status'], [ 'eligible', 'ineligible', 'unknown' ] ); QuoteShape::digest( $inventory['evidence_digest'] );
			$acceptable = $acceptable && 'eligible' === $inventory['status']; $line_index[$key] = $line['component_key'];
		} unset( $line );
		$groups = QuoteShape::list( $data['groups'], self::MAX_GROUPS, 1 ); $group_index = []; $covered = []; $zones = []; $rates = 0;
		foreach ( $groups as &$group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'line_keys', 'choice', 'offer_id', 'service_id', 'origin', 'supplier', 'profile', 'destination_zone_id', 'endpoint_digest', 'policy_digest', 'candidate_digest', 'candidate_count' ] );
			$key = QuoteShape::digest( $group['component_key'] ); if ( isset( $group_index[$key] ) ) { QuoteShape::invalid(); } $group_index[$key] = true;
			QuoteShape::choice( $group['choice'], [ 'delivery' ] ); foreach ( [ 'offer_id', 'service_id', 'destination_zone_id' ] as $field ) { QuoteShape::integer( $group[$field] ); }
			foreach ( [ 'endpoint_digest', 'policy_digest', 'candidate_digest' ] as $field ) { QuoteShape::digest( $group[$field] ); }
			$rates += QuoteShape::integer( $group['candidate_count'], 0, self::MAX_RATE_CANDIDATES ); if ( $rates > self::MAX_RATE_CANDIDATES ) { QuoteShape::invalid(); }
			$zones[$group['destination_zone_id']] = true; if ( count( $zones ) > self::MAX_ZONE_CANDIDATES ) { QuoteShape::invalid(); }
			foreach ( [ 'origin', 'supplier', 'profile' ] as $field ) { $group[$field] = self::dimension( $group[$field] ); $acceptable = $acceptable && 'unknown' !== $group[$field]['state']; }
			$keys = QuoteShape::list( $group['line_keys'], self::MAX_LINES, 1 );
			foreach ( $keys as $line_key ) { QuoteShape::machine( $line_key, 128 ); if ( isset( $covered[$line_key] ) || ! isset( $line_index[$line_key] ) || $line_index[$line_key] !== $key ) { QuoteShape::invalid(); } $covered[$line_key] = true; }
			sort( $keys, SORT_STRING ); $group['line_keys'] = $keys;
		} unset( $group );
		if ( count( $covered ) !== count( $lines ) ) { QuoteShape::invalid(); }
		usort( $lines, static fn( array $a, array $b ): int => strcmp( $a['line_key'], $b['line_key'] ) ); usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) );
		$data['lines'] = $lines; $data['groups'] = $groups;
		return new self( QuoteJson::encode( $data ), $acceptable );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }
	/** Explicit forward format; the original native material projection is never redefined. */
	public static function from_base_promises( self $base, string $site_key, array $captures ): self {
		if ( 1 !== $base->format_version() ) { QuoteShape::invalid(); } $data = $base->private_facts(); $data['format_version'] = 2; $data['promise_capture'] = [ 'format' => 1, 'site_key' => $site_key, 'base_digest' => $base->digest(), 'groups' => $captures ]; return self::from_array( $data );
	}
	private static function from_v2( array $data ): self {
		QuoteShape::fields( $data, [ 'format_version', 'kind', 'selection_digest', 'destination', 'currency', 'tax', 'lines', 'groups', 'promise_capture' ] ); $base_data = $data; unset( $base_data['promise_capture'] ); $base_data['format_version'] = 1; $base = self::from_array( $base_data ); $capture = QuoteShape::object( $data['promise_capture'] );
		QuoteShape::fields( $capture, [ 'format', 'site_key', 'base_digest', 'groups' ] ); if ( 1 !== $capture['format'] || ! hash_equals( QuoteShape::digest( $capture['base_digest'] ), $base->digest() ) ) { QuoteShape::invalid(); } \CetechDeliveryEngine\Domain\ServicePromise\PromiseShape::id( $capture['site_key'] );
		$groups = QuoteShape::list( $capture['groups'], 200, 1 ); $seen = []; $first = null;
		foreach ( $groups as &$group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'assignment_receipt_digest', 'policy_reference', 'input', 'input_digest' ] ); $key = QuoteShape::digest( $group['component_key'] ); if ( isset( $seen[$key] ) ) { QuoteShape::invalid(); } $seen[$key] = true; QuoteShape::digest( $group['assignment_receipt_digest'] ); if ( ! is_string( $group['input'] ) || ! hash_equals( QuoteShape::digest( $group['input_digest'] ), hash( 'sha256', 'cetech-service-promise-input-v1:' . $group['input'] ) ) ) { QuoteShape::invalid(); }
			$input = \CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalCodec::input( $group['input'] ); $reference = \CetechDeliveryEngine\Domain\ServicePromise\PromisePolicyReference::from_array( QuoteShape::object( $group['policy_reference'] ) )->private_facts(); $policy = $input['policy'];
			$expected = [ 'format_version' => 1, 'site_id' => $policy['site_id'], 'policy_id' => $policy['policy_id'], 'version' => $policy['version'], 'digest' => hash( 'sha256', 'cetech-service-promise-policy-v1:' . \CetechDeliveryEngine\Domain\ServicePromise\PromiseJson::encode( $policy, 32768 ) ) ];
			if ( QuoteJson::encode( $reference ) !== QuoteJson::encode( $expected ) || $input['site_id'] !== $capture['site_key'] || $input['material']['group_id'] !== $key || $input['material']['material_digest'] !== $base->digest() || $input['owner']['key_epoch'] !== $base_data['destination']['key_epoch'] ) { QuoteShape::invalid(); }
			$shared = [ $input['owner'], $input['evaluated_at'], $input['anchor']['quote_expires_at'], $input['runtime'] ]; if ( null !== $first && $first !== $shared ) { QuoteShape::invalid(); } $first = $shared;
		} unset( $group ); usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) );
		$keys = array_column( $base_data['groups'], 'component_key' ); sort( $keys, SORT_STRING ); if ( array_column( $groups, 'component_key' ) !== $keys ) { QuoteShape::invalid(); } $capture['groups'] = $groups; $data['promise_capture'] = $capture; return new self( QuoteJson::encode( $data ), $base->checkout_acceptable() );
	}
	public function format_version(): int { return $this->private_facts()['format_version']; }
	public function base_context(): self { if ( 1 === $this->format_version() ) { return $this; } $data = $this->private_facts(); unset( $data['promise_capture'] ); $data['format_version'] = 1; return self::from_array( $data ); }
	public function base_material_digest(): string { return $this->base_context()->digest(); }
	public function promise_groups(): array { return $this->private_facts()['promise_capture']['groups'] ?? []; }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-quote-material-v' . $this->format_version() . ':' . $this->json ); }
	public function checkout_acceptable(): bool { return $this->acceptable; }
	/** Known ineligibility is conclusive; partial or unknown evidence is not. */
	public function material_evidence_available(): bool {
		$data = $this->private_facts();
		if ( 'checkout' !== $data['kind'] || 'full' !== $data['destination']['kind'] ) { return false; }
		foreach ( $data['lines'] as $line ) { if ( 'unknown' === $line['inventory']['status'] ) { return false; } }
		foreach ( $data['groups'] as $group ) { foreach ( [ 'origin', 'supplier', 'profile' ] as $field ) { if ( 'unknown' === $group[$field]['state'] ) { return false; } } }
		return true;
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote context projection is required.' ); }
	private static function dimension( mixed $value ): array {
		$data = QuoteShape::object( $value ); QuoteShape::fields( $data, [ 'state', 'id' ] ); QuoteShape::choice( $data['state'], [ 'known', 'absent', 'unknown' ] );
		if ( 'known' === $data['state'] ) { QuoteShape::integer( $data['id'] ); }
		elseif ( 'absent' === $data['state'] ) { if ( null !== $data['id'] && 0 !== $data['id'] ) { QuoteShape::invalid(); } $data['id'] = null; }
		elseif ( null !== $data['id'] ) { QuoteShape::invalid(); }
		return $data;
	}
	private static function quantity( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?\z/D', $value, $parts ) ) { QuoteShape::invalid(); }
		$fraction = rtrim( $parts[2] ?? '', '0' ); if ( '0' === $parts[1] && '' === $fraction ) { QuoteShape::invalid(); }
		return $parts[1] . ( '' === $fraction ? '' : '.' . $fraction );
	}
}
