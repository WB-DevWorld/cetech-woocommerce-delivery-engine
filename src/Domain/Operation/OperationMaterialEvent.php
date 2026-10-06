<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Aggregate first-effect audit. No before/after values or raw context is stored. */
final readonly class OperationMaterialEvent {
	public const FORMAT = 1;
	private function __construct(
		public string $operation,
		public int $operation_version,
		public array $actor,
		public array $target,
		public string $reason_code,
		public int $before_revision,
		public int $after_revision,
		public array $changed_fields,
		public string $request_id,
		public string $correlation_id,
		private string $encoded
	) {}

	public static function from_mutation( OperationProfile $profile, OperationIdentity $identity, RequestContext $context, array $actor, array $target, string $reason_code, int $before_revision, int $after_revision, array $changed_fields ): self {
		if ( $identity->operation !== $profile->operation() || $identity->operation_version !== $profile->version() ) {
			throw new \InvalidArgumentException( 'Material event operation does not match its profile.' );
		}
		return self::from_json( OperationJson::encode( (object) [
			'format' => self::FORMAT, 'operation' => $identity->operation, 'operation_version' => $identity->operation_version,
			'actor' => $profile->actor_schema()->json_value( $actor ), 'target' => $profile->target_schema()->json_value( $target ),
			'reason_code' => $reason_code, 'before_revision' => $before_revision, 'after_revision' => $after_revision,
			'changed_fields' => $changed_fields, 'request_id' => $context->request_id, 'correlation_id' => $context->correlation_id,
		] ), $profile );
	}

	public static function from_json( string $json, OperationProfile $profile ): self {
		if ( $profile->actor_schema()->is_empty() || $profile->target_schema()->is_empty() ) {
			throw new \InvalidArgumentException( 'Material event requires explicit actor and target facts.' );
		}
		$data = OperationJson::exact_fields( OperationJson::decode( $json ), [ 'format', 'operation', 'operation_version', 'actor', 'target', 'reason_code', 'before_revision', 'after_revision', 'changed_fields', 'request_id', 'correlation_id' ] );
		if ( self::FORMAT !== $data['format'] || $profile->operation() !== $data['operation'] || $profile->version() !== $data['operation_version']
			|| ! is_int( $data['before_revision'] ) || $data['before_revision'] < 1 || ! is_int( $data['after_revision'] ) || $data['after_revision'] <= $data['before_revision']
			|| ! RequestContext::is_valid_identifier( $data['request_id'] ) || ! RequestContext::is_valid_identifier( $data['correlation_id'] )
			|| ! is_string( $data['reason_code'] ) || ! in_array( $data['reason_code'], OperationSchema::vocabulary( $profile->reason_codes() ), true )
			|| ! is_array( $data['changed_fields'] ) || ! array_is_list( $data['changed_fields'] ) || [] === $data['changed_fields'] || count( $data['changed_fields'] ) > 32 ) {
			throw new \InvalidArgumentException( 'Invalid operation material event.' );
		}
		$allowed = OperationSchema::vocabulary( $profile->changed_fields() );
		$fields = [];
		foreach ( $data['changed_fields'] as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, $allowed, true ) || in_array( $field, $fields, true ) ) {
				throw new \InvalidArgumentException( 'Invalid operation changed fields.' );
			}
			$fields[] = $field;
		}
		$actor = $profile->actor_schema()->from_json_value( $data['actor'] );
		$target = $profile->target_schema()->from_json_value( $data['target'] );
		$encoded = OperationJson::encode( (object) [
			'format' => self::FORMAT, 'operation' => $data['operation'], 'operation_version' => $data['operation_version'],
			'actor' => $profile->actor_schema()->json_value( $actor ), 'target' => $profile->target_schema()->json_value( $target ),
			'reason_code' => $data['reason_code'], 'before_revision' => $data['before_revision'], 'after_revision' => $data['after_revision'],
			'changed_fields' => $fields, 'request_id' => $data['request_id'], 'correlation_id' => $data['correlation_id'],
		] );
		return new self( $data['operation'], $data['operation_version'], $actor, $target, $data['reason_code'], $data['before_revision'], $data['after_revision'], $fields, $data['request_id'], $data['correlation_id'], $encoded );
	}

	public function to_json(): string {
		return $this->encoded;
	}
}
