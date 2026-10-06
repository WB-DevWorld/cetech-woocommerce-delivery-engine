<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Finite facts only: no free text, arbitrary objects, payload or context blobs. */
final readonly class OperationSchema {
	private array $fields;

	/**
	 * Scalar specs: integer, positive_int, nonnegative_int, bool, uuid, sha256.
	 * Finite specs: ['enum'=>codes], ['list_enum'=>codes], ['object'=>fields].
	 */
	public function __construct( array $fields ) {
		$nodes = 0;
		$this->fields = self::validate_fields( $fields, 0, $nodes );
	}

	/** Validate and detach all references. Omitted or additional fields refuse. */
	public function validate( array $facts ): array {
		return self::validate_object( $facts, $this->fields );
	}

	public function is_empty(): bool {
		return [] === $this->fields;
	}

	/** JSON must declare an object, including an empty object, rather than a list. */
	public function from_json_value( mixed $facts ): array {
		if ( ! $facts instanceof \stdClass ) {
			throw new \InvalidArgumentException( 'Invalid operation facts object.' );
		}
		return self::validate_object( get_object_vars( $facts ), $this->fields, true );
	}

	public function json_value( array $facts ): \stdClass {
		return self::as_object( $this->validate( $facts ), $this->fields );
	}

	public static function vocabulary( array $values ): array {
		if ( ! array_is_list( $values ) || [] === $values || count( $values ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid operation vocabulary.' );
		}
		$copy = [];
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-z][a-z0-9_.-]{0,95}\z/D', $value ) || in_array( $value, $copy, true ) ) {
				throw new \InvalidArgumentException( 'Invalid operation vocabulary.' );
			}
			$copy[] = $value;
		}
		return $copy;
	}

	private static function validate_fields( array $fields, int $depth, int &$nodes ): array {
		if ( $depth > 5 || count( $fields ) > 32 || ( [] !== $fields && array_is_list( $fields ) ) ) {
			throw new \InvalidArgumentException( 'Invalid operation schema.' );
		}
		$copy = [];
		foreach ( $fields as $field => $spec ) {
			if ( ++$nodes > 128 || ! is_string( $field ) || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,47}\z/D', $field ) ) {
				throw new \InvalidArgumentException( 'Invalid operation schema.' );
			}
			if ( is_string( $spec ) && in_array( $spec, [ 'integer', 'positive_int', 'nonnegative_int', 'bool', 'uuid', 'sha256' ], true ) ) {
				$copy[ $field ] = $spec;
				continue;
			}
			if ( ! is_array( $spec ) || 1 !== count( $spec ) ) {
				throw new \InvalidArgumentException( 'Invalid operation schema.' );
			}
			$type = array_key_first( $spec );
			if ( in_array( $type, [ 'enum', 'list_enum' ], true ) && is_array( $spec[ $type ] ) ) {
				$copy[ $field ] = [ $type => self::vocabulary( $spec[ $type ] ) ];
			} elseif ( 'object' === $type && is_array( $spec['object'] ) ) {
				$copy[ $field ] = [ 'object' => self::validate_fields( $spec['object'], $depth + 1, $nodes ) ];
			} else {
				throw new \InvalidArgumentException( 'Invalid operation schema.' );
			}
		}
		return $copy;
	}

	private static function validate_object( array $facts, array $fields, bool $json = false ): array {
		if ( count( $facts ) !== count( $fields ) || [] !== array_diff( array_keys( $fields ), array_keys( $facts ) ) ) {
			throw new \InvalidArgumentException( 'Operation facts fields are incomplete or unknown.' );
		}
		$copy = [];
		foreach ( $fields as $field => $spec ) {
			$value = $facts[ $field ];
			if ( is_string( $spec ) ) {
				$valid = match ( $spec ) {
					'integer' => is_int( $value ),
					'positive_int' => is_int( $value ) && $value > 0,
					'nonnegative_int' => is_int( $value ) && $value >= 0,
					'bool' => is_bool( $value ),
					'uuid' => RequestContext::is_valid_identifier( $value ),
					'sha256' => is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/D', $value ),
				};
				if ( ! $valid ) {
					throw new \InvalidArgumentException( 'Invalid typed operation fact.' );
				}
				$copy[ $field ] = $value;
				continue;
			}
			$type = array_key_first( $spec );
			if ( 'object' === $type ) {
				if ( $json ) {
					if ( ! $value instanceof \stdClass ) {
						throw new \InvalidArgumentException( 'Invalid operation facts object.' );
					}
					$value = get_object_vars( $value );
				} elseif ( ! is_array( $value ) ) {
					throw new \InvalidArgumentException( 'Invalid operation facts object.' );
				}
				$copy[ $field ] = self::validate_object( $value, $spec['object'], $json );
			} elseif ( 'enum' === $type ) {
				if ( ! is_string( $value ) || ! in_array( $value, $spec['enum'], true ) ) {
					throw new \InvalidArgumentException( 'Invalid finite operation fact.' );
				}
				$copy[ $field ] = $value;
			} else {
				if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 32 ) {
					throw new \InvalidArgumentException( 'Invalid finite operation fact list.' );
				}
				$copy[ $field ] = [];
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || ! in_array( $item, $spec['list_enum'], true ) ) {
						throw new \InvalidArgumentException( 'Invalid finite operation fact list.' );
					}
					$copy[ $field ][] = $item;
				}
			}
		}
		return $copy;
	}

	private static function as_object( array $facts, array $fields ): \stdClass {
		$object = new \stdClass();
		foreach ( $fields as $field => $spec ) {
			$object->$field = is_array( $spec ) && isset( $spec['object'] ) ? self::as_object( $facts[ $field ], $spec['object'] ) : $facts[ $field ];
		}
		return $object;
	}
}
