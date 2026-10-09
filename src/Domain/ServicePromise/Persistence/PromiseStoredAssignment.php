<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\PromisePolicyReference;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;
use CetechDeliveryEngine\Domain\ServicePromise\ServiceIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;

/** Exact assignment generation. An unaccepted baseline is transaction-local, never readable authority. */
final readonly class PromiseStoredAssignment implements \JsonSerializable {
	public const KEY_FIELDS = [ 'site_id', 'site_key', 'scope_kind', 'scope_id', 'service_kind', 'service_code', 'endpoint', 'endpoint_kind' ];
	public const FIELDS = [ 'id', 'site_id', 'site_key', 'scope_kind', 'scope_id', 'service_kind', 'service_code', 'endpoint', 'endpoint_kind', 'revision', 'generation', 'state', 'policy_object_id', 'policy_version_id', 'policy_reference_json', 'created_at', 'updated_at', 'source_receipt_json', 'source_receipt_digest' ];
	private function __construct( private array $data, private ?PromisePolicyReference $policy, private ?PromiseSourceReceipt $source ) {}
	public static function from_row( array $row, ?PromiseSiteBinding $binding = null, bool $allow_baseline = false ): self {
		PromiseStorageCodec::exact( $row, self::FIELDS ); PromiseStorageCodec::budget( $row ); $data = [];
		foreach ( self::FIELDS as $field ) { $data[$field] = $row[$field]; }
		foreach ( [ 'id', 'site_id', 'revision' ] as $field ) { $data[$field] = PromiseStorageCodec::integer( $row[$field] ); }
		foreach ( [ 'scope_id', 'generation' ] as $field ) { $data[$field] = PromiseStorageCodec::integer( $row[$field], 0 ); }
		foreach ( [ 'policy_object_id', 'policy_version_id' ] as $field ) { $data[$field] = PromiseStorageCodec::nullable_integer( $row[$field] ); }
		self::validate_key( array_intersect_key( $data, array_flip( self::KEY_FIELDS ) ) );
		$state = PromiseShape::choice( $row['state'], [ 'assigned', 'inherit', 'disabled' ] ); $policy = null;
		if ( 'assigned' === $state ) {
			$json = PromiseStorageCodec::bytes( $row['policy_reference_json'], 4096 ); $policy = PromisePolicyReference::from_json( $json );
			if ( null === $data['policy_object_id'] || null === $data['policy_version_id'] || $policy->site_id() !== $row['site_key'] || $json !== $policy->to_private_json() ) { PromiseShape::invalid(); }
		} elseif ( null !== $data['policy_object_id'] || null !== $data['policy_version_id'] || null !== $row['policy_reference_json'] ) { PromiseShape::invalid(); }
		$created = PromiseStorageCodec::time( $row['created_at'] ); $updated = PromiseStorageCodec::time( $row['updated_at'] ); if ( $created->compare( $updated ) > 0 || $data['generation'] === PHP_INT_MAX || $data['revision'] !== $data['generation'] + 1 ) { PromiseShape::invalid(); }
		$source = null;
		if ( 0 === $data['generation'] ) {
			if ( ! $allow_baseline || 'inherit' !== $state || null !== $row['source_receipt_json'] || null !== $row['source_receipt_digest'] || ! $created->equals( $updated ) ) { PromiseShape::invalid(); }
		} else {
			$json = PromiseStorageCodec::bytes( $row['source_receipt_json'], PromiseSourceReceipt::MAX_BYTES ); $source = PromiseSourceReceipt::from_json( $json ); $facts = $source->private_facts();
			if ( $json !== $source->to_private_json() || ! hash_equals( $source->digest(), PromiseShape::digest( $row['source_receipt_digest'] ) ) || 'assignment' !== $source->kind() || $facts['site_id'] !== $data['site_id'] || $facts['site_key'] !== $row['site_key'] || $facts['assignment_key_hash'] !== self::key_digest( array_intersect_key( $data, array_flip( self::KEY_FIELDS ) ) ) || $facts['mode'] !== $state || $facts['after_revision'] !== $data['revision'] || $facts['generation_after'] !== $data['generation'] || ! $source->accepted_at()->equals( $updated ) || ( null === $policy ) !== ( null === $facts['policy_publication'] ) || ( null !== $policy && $facts['policy_publication']['reference'] !== $policy->private_facts() ) ) { PromiseShape::invalid(); }
		}
		$binding?->assert_row( $data ); return new self( $data, $policy, $source );
	}
	public static function validate_key( array $key ): void {
		PromiseStorageCodec::exact( $key, self::KEY_FIELDS ); PromiseShape::integer( $key['site_id'], 1 ); PromiseShape::id( $key['site_key'] );
		$scope = PromiseShape::choice( $key['scope_kind'], [ 'global', 'product', 'variation' ] ); PromiseShape::integer( $key['scope_id'], 'global' === $scope ? 0 : 1 );
		if ( 'global' === $scope && 0 !== $key['scope_id'] ) { PromiseShape::invalid(); }
		$service = PromiseShape::choice( $key['service_kind'], [ 'built_in', 'merchant' ] ); PromiseShape::id( $key['service_code'] );
		if ( ( 'built_in' === $service ) !== in_array( $key['service_code'], ServiceIdentity::BUILT_IN_CODES, true ) ) { PromiseShape::invalid(); }
		PromiseShape::id( $key['endpoint'] ); PromiseShape::choice( $key['endpoint_kind'], [ 'doorstep', 'pickup', 'port', 'handover' ] );
	}
	public static function key_digest( array $key ): string { self::validate_key( $key ); return hash( 'sha256', 'cetech-promise-assignment-key-v1:' . PromiseJson::encode( $key, 2048 ) ); }
	public function assert_policy( PromiseStoredVersion $version ): void {
		$body = $version->body(); $row = $version->row();
		if ( ! $body instanceof ServicePromisePolicy || null === $this->policy || $row['site_id'] !== $this->data['site_id'] || $row['site_key'] !== $this->data['site_key'] || $row['object_id'] !== $this->data['policy_object_id'] || $version->id() !== $this->data['policy_version_id'] || $version->reference()->to_private_json() !== $this->policy->to_private_json() ) { PromiseShape::invalid(); }
		$facts = $body->private_facts(); $publication = $this->source?->private_facts()['policy_publication'] ?? null;
		if ( $facts['scope']['kind'] !== $this->data['scope_kind'] || ( $facts['scope']['target_id'] ?? 0 ) !== $this->data['scope_id'] || $facts['service']['kind'] !== $this->data['service_kind'] || $facts['service']['code'] !== $this->data['service_code'] || $facts['endpoint'] !== $this->data['endpoint'] || $facts['endpoint_kind'] !== $this->data['endpoint_kind'] || ! $version->history_available() || null === $publication || $version->publication_receipt()?->digest() !== $publication['publication_digest'] || $row['published_at'] !== $publication['published_at'] ) { PromiseShape::invalid(); }
	}
	public function row(): array { return $this->data; }
	public function id(): int { return $this->data['id']; }
	public function revision(): int { return $this->data['revision']; }
	public function generation(): int { return $this->data['generation']; }
	public function state(): string { return $this->data['state']; }
	public function reference(): ?PromisePolicyReference { return $this->policy; }
	public function source_receipt(): ?PromiseSourceReceipt { return $this->source; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise assignment projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized promise assignment projection is required.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise assignment facts require strict row hydration.' ); }
}
