<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RefundPolicy;

use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSchema;

/** Supplied private policy text only; this value grants no effective or writer authority. */
final readonly class RefundPolicyContent implements \JsonSerializable {

	public const FORMAT = 1;
	public const FAMILY = 'refund_policy_v1';
	private const FIELDS = [ 'name', 'customer_title', 'summary', 'full_text', 'exceptions' ];

	private function __construct( private string $json ) {}

	public static function from_array( array $data ): self {
		OperationJson::exact_fields( (object) $data, self::FIELDS );
		$facts = [];
		foreach ( self::FIELDS as $field ) {
			$value = $data[ $field ];
			$multiline = in_array( $field, [ 'summary', 'full_text', 'exceptions' ], true );
			if ( ! is_string( $value ) || strlen( $value ) > OperationJson::MAX_BYTES || 1 !== preg_match( '//u', $value ) ) {
				self::invalid();
			}
			// Preserve supplied text exactly. Only the named multiline fields permit HT/CR/LF.
			$controls = $multiline ? '/[^\P{Cc}\t\r\n]/u' : '/[\p{Cc}\p{Zl}\p{Zp}]/u';
			if ( 1 === preg_match( $controls, $value ) || ( 'exceptions' !== $field && 1 !== preg_match( '/[^\s\p{Z}]/u', $value ) ) ) {
				self::invalid();
			}
			$facts[ $field ] = (string) $value;
		}
		return new self( OperationJson::encode( (object) $facts ) );
	}

	public static function from_json( string $json ): self {
		return self::from_array( get_object_vars( OperationJson::decode( $json ) ) );
	}

	/** Explicit private content export; presentation and authorization belong to the caller. */
	public function facts(): array {
		return get_object_vars( OperationJson::decode( $this->json ) );
	}

	public function json(): string { return $this->json; }

	public function digest(): string {
		return hash( 'sha256', 'cetech-refund-policy-content-v1:' . $this->json );
	}

	/** @return array{content_json:string} */
	public function to_rule_payload(): array {
		$payload = [ 'content_json' => $this->json ];
		// The escaped outer C01 payload has its own complete byte budget.
		self::payload_schema()->encode( $payload, OperationJson::MAX_BYTES );
		return $payload;
	}

	public static function from_rule_payload( array $payload ): self {
		$schema = self::payload_schema();
		$validated = $schema->validate( $payload );
		$schema->encode( $validated, OperationJson::MAX_BYTES );
		$content = self::from_json( $validated['content_json'] );
		if ( $content->json !== $validated['content_json'] ) {
			throw new \InvalidArgumentException( 'Refund policy rule content must use canonical JSON.' );
		}
		return $content;
	}

	public static function payload_schema(): RuleSchema {
		return new RuleSchema( [ 'content_json' => [ 'string' => OperationJson::MAX_BYTES ] ] );
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Refund policy content requires an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Refund policy content cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Refund policy content requires an explicit validated factory.' ); }
	public function __debugInfo(): array { return [ 'refund_policy_content' => 'redacted' ]; }

	private static function invalid(): never {
		throw new \InvalidArgumentException( 'Invalid refund policy content.' );
	}
}
