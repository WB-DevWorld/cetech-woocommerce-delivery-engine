<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson, PromisePolicyReference, PromiseShape, ServiceIdentity};

/** One exact assignment CAS; this command does not infer inheritance or variation parents. */
final readonly class PromiseAssignmentCommand implements OperationCommand, \JsonSerializable {
	public const OPERATION = 'promise.assignment.set';
	private function __construct( public OperationIdentity $identity, public PromiseSiteBinding $binding, private array $facts, private CanonicalIntent $canonical ) {}
	public static function from_array( OperationIdentity $identity, PromiseSiteBinding $binding, array $data ): self {
		$data = PromiseJson::detach( $data ); PromiseShape::fields( $data, [ 'key', 'mode', 'policy_reference', 'expected_revision', 'expected_generation', 'author_user_id', 'reason' ] );
		if ( self::OPERATION !== $identity->operation || 1 !== $identity->operation_version || $identity->site_id !== $binding->site_id() ) { PromiseShape::invalid(); }
		$key = self::key( PromiseShape::object( $data['key'] ) ); $data['key'] = $key; PromiseShape::choice( $data['mode'], [ 'assigned', 'inherit', 'disabled' ] );
		if ( 'assigned' === $data['mode'] ) { $ref = PromisePolicyReference::from_array( PromiseShape::object( $data['policy_reference'] ) ); if ( $ref->site_id() !== $binding->site_key() ) { PromiseShape::invalid(); } $data['policy_reference'] = $ref->private_facts(); }
		elseif ( null !== $data['policy_reference'] ) { PromiseShape::invalid(); }
		PromiseShape::integer( $data['expected_revision'], 0, PHP_INT_MAX - 1 ); PromiseShape::integer( $data['expected_generation'], 0, PHP_INT_MAX - 1 ); PromiseShape::integer( $data['author_user_id'], 1 ); PromiseShape::text( $data['reason'], 256 );
		if ( ( 0 === $data['expected_revision'] ) !== ( 0 === $data['expected_generation'] ) || $identity->target_key !== self::target_key( $binding, $key ) ) { PromiseShape::invalid(); }
		$semantic = $data; unset( $semantic['expected_revision'], $semantic['expected_generation'] );
		return new self( $identity, $binding, $data, CanonicalIntent::from_command( $identity, [ 'site_key' => $binding->site_key(), 'assignment_key_hash' => self::key_hash( $key ) ], [ 'revision' => $data['expected_revision'], 'generation' => $data['expected_generation'] ], $semantic ) );
	}
	public static function key( array $key ): array {
		PromiseShape::fields( $key, [ 'scope_kind', 'scope_id', 'service_kind', 'service_code', 'endpoint', 'endpoint_kind' ] ); PromiseVersionCommand::scope( [ 'kind' => $key['scope_kind'], 'target_id' => $key['scope_id'] ] );
		ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => $key['service_kind'], 'code' => $key['service_code'], 'customer_label' => 'Identity validation' ] ); PromiseShape::id( $key['endpoint'] ); PromiseShape::choice( $key['endpoint_kind'], [ 'doorstep', 'pickup', 'port', 'handover' ] ); return $key;
	}
	public static function key_hash( array $key ): string { return hash( 'sha256', 'cetech-promise-assignment-key-v1:' . PromiseJson::encode( self::key( $key ) ) ); }
	public static function target_key( PromiseSiteBinding $binding, array $key ): string { return 'promise-assignment:' . $binding->site_key() . ':' . self::key_hash( $key ); }
	public function private_facts(): array { return PromiseJson::detach( $this->facts ); }
	public function intent(): CanonicalIntent { return $this->canonical; }
	public function jsonSerialize(): never { throw new \LogicException( 'Private promise commands require an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private promise commands cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private promise commands require a validated factory.' ); }
}
