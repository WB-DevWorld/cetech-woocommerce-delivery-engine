<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;

/** Exact, bounded private scope/payload codec. Never a public projection schema. */
final readonly class RuleSchema {
	private array $fields;

	/** Finite OperationSchema specs plus ['string'=>maxBytes], ['nullable'=>spec], ['list'=>['item'=>spec,'max'=>limit]]. */
	public function __construct( array $fields ) {
		$nodes = 0;
		$this->fields = self::fields( $fields, 0, $nodes );
	}

	public function validate( array $value ): array {
		$nodes = 0;
		return self::object( $value, $this->fields, false, 0, $nodes );
	}

	public function encode( array $value, int $max_bytes = 16384 ): string {
		self::budget( $max_bytes );
		$nodes = 0;
		$validated = self::object( $value, $this->fields, false, 0, $nodes );
		$json = OperationJson::encode( self::json_object( $validated, $this->fields ) );
		if ( strlen( $json ) > $max_bytes ) {
			throw new \InvalidArgumentException( 'Rule content exceeds its byte budget.' );
		}
		return $json;
	}

	public function decode( string $json, int $max_bytes = 16384 ): array {
		self::budget( $max_bytes );
		if ( strlen( $json ) > $max_bytes ) {
			throw new \InvalidArgumentException( 'Rule content exceeds its byte budget.' );
		}
		$nodes = 0;
		return self::object( get_object_vars( OperationJson::decode( $json ) ), $this->fields, true, 0, $nodes );
	}

	public function fingerprint(): string {
		return hash( 'sha256', 'cetech-rule-schema-v1:' . json_encode( $this->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	private static function budget( int $value ): void {
		if ( $value < 2 || $value > 16384 ) {
			throw new \InvalidArgumentException( 'Invalid rule content budget.' );
		}
	}

	private static function fields( array $fields, int $depth, int &$nodes ): array {
		if ( $depth > 5 || count( $fields ) > 32 || ( [] !== $fields && array_is_list( $fields ) ) ) {
			throw new \InvalidArgumentException( 'Invalid rule schema.' );
		}
		$out = [];
		foreach ( $fields as $key => $spec ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,47}\z/D', $key ) ) {
				throw new \InvalidArgumentException( 'Invalid rule schema.' );
			}
			$out[ $key ] = self::spec( $spec, $depth, $nodes );
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	private static function spec( mixed $spec, int $depth, int &$nodes ): mixed {
		if ( $depth > 5 || ++$nodes > 128 ) {
			throw new \InvalidArgumentException( 'Invalid rule schema.' );
		}
		if ( is_string( $spec ) && in_array( $spec, [ 'integer', 'positive_int', 'nonnegative_int', 'bool', 'uuid', 'sha256', 'utc' ], true ) ) {
			return $spec;
		}
		if ( ! is_array( $spec ) || 1 !== count( $spec ) ) {
			throw new \InvalidArgumentException( 'Invalid rule schema.' );
		}
		$type = array_key_first( $spec );
		$data = $spec[ $type ];
		if ( in_array( $type, [ 'enum', 'list_enum' ], true ) && is_array( $data ) ) {
			return [ $type => OperationSchema::vocabulary( $data ) ];
		}
		if ( 'object' === $type && is_array( $data ) ) {
			return [ 'object' => self::fields( $data, $depth + 1, $nodes ) ];
		}
		if ( 'string' === $type && is_int( $data ) && $data > 0 && $data <= 16384 ) {
			return [ 'string' => $data ];
		}
		if ( 'nullable' === $type ) {
			return [ 'nullable' => self::spec( $data, $depth + 1, $nodes ) ];
		}
		if ( 'list' === $type && is_array( $data ) && count( $data ) === 2 && array_key_exists( 'item', $data ) && isset( $data['max'] ) && is_int( $data['max'] ) && $data['max'] > 0 && $data['max'] <= 128 ) {
			return [ 'list' => [ 'item' => self::spec( $data['item'], $depth + 1, $nodes ), 'max' => $data['max'] ] ];
		}
		throw new \InvalidArgumentException( 'Invalid rule schema.' );
	}

	private static function object( array $value, array $fields, bool $json, int $depth, int &$nodes ): array {
		if ( $depth > 5 || ++$nodes > 128 || count( $value ) !== count( $fields ) || [] !== array_diff( array_keys( $fields ), array_keys( $value ) ) ) {
			throw new \InvalidArgumentException( 'Incomplete or unknown rule content fields.' );
		}
		$out = [];
		foreach ( $fields as $key => $spec ) {
			$out[ $key ] = self::value( $value[ $key ], $spec, $json, $depth, $nodes );
		}
		return $out;
	}

	private static function value( mixed $value, mixed $spec, bool $json, int $depth, int &$nodes ): mixed {
		if ( $depth > 5 || ++$nodes > 128 ) {
			throw new \InvalidArgumentException( 'Rule content exceeds its structural budget.' );
		}
		if ( is_string( $spec ) ) {
			$valid = match ( $spec ) {
				'integer' => is_int( $value ),
				'positive_int' => is_int( $value ) && $value > 0,
				'nonnegative_int' => is_int( $value ) && $value >= 0,
				'bool' => is_bool( $value ),
				'uuid' => RequestContext::is_valid_identifier( $value ),
				'sha256' => is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/D', $value ),
				'utc' => is_string( $value ) && RuleTime::parse( $value ) instanceof RuleTime,
			};
			if ( ! $valid ) {
				throw new \InvalidArgumentException( 'Invalid typed rule content.' );
			}
			return $value;
		}
		$type = array_key_first( $spec );
		$data = $spec[ $type ];
		if ( 'nullable' === $type ) {
			return null === $value ? null : self::value( $value, $data, $json, $depth + 1, $nodes );
		}
		if ( 'object' === $type ) {
			if ( $json ) {
				if ( ! $value instanceof \stdClass ) {
					throw new \InvalidArgumentException( 'Invalid rule object.' );
				}
				$value = get_object_vars( $value );
			} elseif ( ! is_array( $value ) ) {
				throw new \InvalidArgumentException( 'Invalid rule object.' );
			}
			return self::object( $value, $data, $json, $depth + 1, $nodes );
		}
		if ( 'string' === $type ) {
			if ( ! is_string( $value ) || strlen( $value ) > $data || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
				throw new \InvalidArgumentException( 'Invalid bounded rule text.' );
			}
			return $value;
		}
		if ( 'enum' === $type ) {
			if ( ! is_string( $value ) || ! in_array( $value, $data, true ) ) {
				throw new \InvalidArgumentException( 'Invalid rule vocabulary value.' );
			}
			return $value;
		}
		$limit = 'list' === $type ? $data['max'] : 32;
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > $limit ) {
			throw new \InvalidArgumentException( 'Invalid bounded rule list.' );
		}
		$out = [];
		foreach ( $value as $item ) {
			$out[] = self::value( $item, 'list' === $type ? $data['item'] : [ 'enum' => $data ], $json, $depth + 1, $nodes );
		}
		return $out;
	}

	private static function json_object( array $value, array $fields ): \stdClass {
		$out = new \stdClass();
		foreach ( $fields as $key => $spec ) {
			$out->{$key} = self::json_value( $value[ $key ], $spec );
		}
		return $out;
	}

	private static function json_value( mixed $value, mixed $spec ): mixed {
		if ( is_string( $spec ) ) { return $value; }
		$type = array_key_first( $spec );
		if ( 'nullable' === $type ) { return null === $value ? null : self::json_value( $value, $spec['nullable'] ); }
		if ( 'object' === $type ) { return self::json_object( $value, $spec['object'] ); }
		if ( 'list' === $type ) { return array_map( static fn( mixed $item ): mixed => self::json_value( $item, $spec['list']['item'] ), $value ); }
		return $value;
	}
}
