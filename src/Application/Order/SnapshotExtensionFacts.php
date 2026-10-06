<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

/** Detached recorded historical facts, never a live policy lookup or public serializer. */
final readonly class SnapshotExtensionFacts implements \JsonSerializable {
	public const NAMES = [ 'quote_policy', 'promise', 'fulfilment_labels', 'return_policy', 'refund_policy' ];
	private function __construct( private string $namespace, private array $facts ) {}

	public static function from_payload( string $namespace, array $data ): self {
		$required = match ( $namespace ) {
			'quote_policy' => [ 'reference', 'currency_code', 'amount' ],
			'promise' => [ 'reference', 'from', 'until' ],
			'fulfilment_labels' => [ 'reference', 'labels' ],
			'return_policy', 'refund_policy' => [ 'reference', 'customer_text' ],
			default => self::invalid(),
		};
		$optional = match ( $namespace ) { 'quote_policy' => [ 'customer_text' ], 'promise' => [ 'label' ], default => [] };
		self::fields( $data, $required, $optional );
		$reference = $data['reference'];
		if ( ! is_array( $reference ) ) { self::invalid(); }
		self::fields( $reference, [ 'id', 'version', 'content_hash' ] );
		$id = self::text( $reference['id'], 128 ); $version = self::text( $reference['version'], 64 ); $hash = $reference['content_hash'];
		if ( 1 !== preg_match( '/\A[A-Za-z0-9._:-]+\z/D', $id ) || ! is_string( $hash ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $hash ) ) { self::invalid(); }
		$facts = [ 'reference' => [ 'id' => (string) $id, 'version' => (string) $version, 'content_hash' => (string) $hash ] ];
		switch ( $namespace ) {
			case 'quote_policy':
				$currency = $data['currency_code']; $amount = $data['amount'];
				if ( ! is_string( $currency ) || 1 !== preg_match( '/\A[A-Z]{3}\z/D', $currency ) || ! is_string( $amount ) || strlen( $amount ) > 16384 || 1 !== preg_match( '/\A[0-9]+(?:\.[0-9]{1,4})?\z/D', $amount ) ) { self::invalid(); }
				$facts['currency_code'] = (string) $currency; $facts['amount'] = (string) $amount;
				if ( array_key_exists( 'customer_text', $data ) ) { $facts['customer_text'] = self::text( $data['customer_text'], 8192, true ); }
				break;
			case 'promise':
				$from = self::utc( $data['from'] ); $until = self::utc( $data['until'] );
				if ( $until <= $from ) { self::invalid(); }
				$facts['from'] = (string) $data['from']; $facts['until'] = (string) $data['until'];
				if ( array_key_exists( 'label', $data ) ) { $facts['label'] = self::text( $data['label'], 512 ); }
				break;
			case 'fulfilment_labels':
				if ( ! is_array( $data['labels'] ) || ! array_is_list( $data['labels'] ) || [] === $data['labels'] || count( $data['labels'] ) > 16 ) { self::invalid(); }
				$facts['labels'] = [];
				foreach ( $data['labels'] as $label ) { $facts['labels'][] = self::text( $label, 256 ); }
				break;
			default:
				$facts['customer_text'] = self::text( $data['customer_text'], 8192, true );
		}
		// Enforce the standalone fact boundary too; callers cannot skip parser budgets.
		if ( strlen( json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) > 16384 ) { self::invalid(); }
		return new self( $namespace, $facts );
	}

	public function namespace(): string { return $this->namespace; }
	public function internal_facts(): array { return $this->facts; }
	public function customer_facts(): array { $public = $this->facts; unset( $public['reference'] ); return $public; }
	public function jsonSerialize(): never { throw new \LogicException( 'Snapshot extension facts require an explicit projection.' ); }

	private static function fields( array $data, array $required, array $optional = [] ): void {
		if ( array_is_list( $data ) || [] !== array_diff( $required, array_keys( $data ) ) || [] !== array_diff( array_keys( $data ), [ ...$required, ...$optional ] ) ) { self::invalid(); }
	}
	private static function text( mixed $value, int $limit, bool $multiline = false ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $limit || 1 !== preg_match( '//u', $value ) || 1 === preg_match( $multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value ) ) { self::invalid(); }
		return (string) $value;
	}
	private static function utc( mixed $value ): \DateTimeImmutable {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,6}))?Z\z/D', $value, $parts ) || (int) $parts[1] < 1 || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) { self::invalid(); }
		$canonical = substr( $value, 0, 19 ) . '.' . str_pad( $parts[7] ?? '', 6, '0' ) . 'Z';
		$instant = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s.u\Z', $canonical, new \DateTimeZone( 'UTC' ) );
		if ( false === $instant ) { self::invalid(); }
		return $instant;
	}
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid snapshot extension facts.' ); }
}
