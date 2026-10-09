<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseCalendarReference;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;
use CetechDeliveryEngine\Domain\ServicePromise\PromisePolicyReference;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Private original command/source facts. Parsing is not an acknowledged C03 commit. */
final readonly class PromiseSourceReceipt implements \JsonSerializable {
	public const MAX_BYTES = 16384;
	private const COMMON = [ 'format_version', 'kind', 'site_id', 'site_key', 'namespace_hash', 'intent_hash', 'operation', 'target_digest', 'accepted_at', 'author_user_id', 'authority_hash', 'principal_hash', 'reason', 'before_revision', 'after_revision' ];
	private const VERSION = [ 'role', 'object_id', 'version_uuid', 'domain_version', 'content_digest', 'logical_digest', 'version_before_revision', 'version_after_revision', 'state', 'declared_from', 'declared_until', 'calendar_publications' ];
	private const ASSIGNMENT = [ 'assignment_key_hash', 'mode', 'generation_before', 'generation_after', 'policy_publication' ];
	public const VERSION_OPERATIONS = [ 'promise.version.create', 'promise.version.seal', 'promise.version.publish', 'promise.version.schedule', 'promise.version.activate', 'promise.version.retire' ];
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, self::MAX_BYTES );
		$kind = PromiseShape::choice( $data['kind'] ?? null, [ 'version', 'assignment' ] );
		PromiseShape::fields( $data, array_merge( self::COMMON, 'version' === $kind ? self::VERSION : self::ASSIGNMENT ) );
		if ( 1 !== $data['format_version'] ) { PromiseShape::invalid(); }
		PromiseShape::integer( $data['site_id'], 1 ); PromiseShape::id( $data['site_key'] ); PromiseShape::integer( $data['author_user_id'], 1 ); PromiseShape::text( $data['reason'], 256 );
		foreach ( [ 'namespace_hash', 'intent_hash', 'target_digest', 'authority_hash', 'principal_hash' ] as $field ) { PromiseShape::digest( $data[$field] ); }
		$accepted = PromiseShape::instant( $data['accepted_at'] );
		$before = PromiseShape::integer( $data['before_revision'], 1, PHP_INT_MAX - 1 );
		if ( PromiseShape::integer( $data['after_revision'], 1 ) !== $before + 1 ) { PromiseShape::invalid(); }
		if ( 'version' === $kind ) {
			$operation = PromiseShape::choice( $data['operation'], self::VERSION_OPERATIONS );
			$state = match ( $operation ) { 'promise.version.create' => 'draft', 'promise.version.seal' => 'sealed', 'promise.version.schedule' => 'scheduled', 'promise.version.publish', 'promise.version.activate' => 'published', 'promise.version.retire' => 'retired' };
			$role = PromiseShape::choice( $data['role'], [ 'primary', 'superseded' ] );
			if ( 'superseded' === $role ) { if ( ! in_array( $operation, [ 'promise.version.publish', 'promise.version.activate' ], true ) || 'retired' !== $data['state'] ) { PromiseShape::invalid(); } }
			elseif ( $state !== $data['state'] ) { PromiseShape::invalid(); }
			PromiseShape::integer( $data['object_id'], 1 ); PromiseStorageCodec::uuid( $data['version_uuid'] ); PromiseShape::integer( $data['domain_version'], 1, PromiseLimits::VERSION_MAX );
			PromiseShape::digest( $data['content_digest'] ); PromiseShape::digest( $data['logical_digest'] );
			$version_before = PromiseShape::integer( $data['version_before_revision'], 0, PHP_INT_MAX - 1 );
			if ( PromiseShape::integer( $data['version_after_revision'], 1 ) !== $version_before + 1 || ( 'promise.version.create' === $operation ) !== ( 0 === $version_before ) ) { PromiseShape::invalid(); }
			$from = PromiseShape::instant( $data['declared_from'] ); $until = null === $data['declared_until'] ? null : PromiseShape::instant( $data['declared_until'] ); PromiseStorageCodec::chronological( $from, $until );
			$publications = PromiseShape::list( $data['calendar_publications'], 0, PromiseLimits::CALENDARS ); $sorted = [];
			foreach ( $publications as $publication ) {
				$publication = self::publication( $publication, $data['site_key'], $accepted, true ); $key = ':' . $publication['reference']['calendar_id'];
				if ( isset( $sorted[$key] ) ) { PromiseShape::invalid(); } $sorted[$key] = $publication;
			}
			ksort( $sorted, SORT_STRING ); $data['calendar_publications'] = array_values( $sorted );
		} else {
			if ( 'promise.assignment.set' !== $data['operation'] ) { PromiseShape::invalid(); }
			PromiseShape::digest( $data['assignment_key_hash'] ); $mode = PromiseShape::choice( $data['mode'], [ 'assigned', 'inherit', 'disabled' ] );
			$generation = PromiseShape::integer( $data['generation_before'], 0, PHP_INT_MAX - 1 );
			if ( PromiseShape::integer( $data['generation_after'], 1 ) !== $generation + 1 || $data['before_revision'] !== $generation + 1 || $data['after_revision'] !== $data['generation_after'] + 1 ) { PromiseShape::invalid(); }
			if ( 'assigned' === $mode ) { $data['policy_publication'] = self::publication( $data['policy_publication'], $data['site_key'], $accepted, false ); }
			elseif ( null !== $data['policy_publication'] ) { PromiseShape::invalid(); }
		}
		return new self( PromiseJson::encode( $data, self::MAX_BYTES ) );
	}
	private static function publication( mixed $value, string $site_key, RuleTime $accepted, bool $calendar ): array {
		$publication = PromiseShape::object( $value ); PromiseShape::fields( $publication, [ 'reference', 'publication_digest', 'published_at' ] );
		$reference = $calendar ? PromiseCalendarReference::from_array( PromiseShape::object( $publication['reference'] ) ) : PromisePolicyReference::from_array( PromiseShape::object( $publication['reference'] ) );
		if ( $reference->site_id() !== $site_key || PromiseShape::instant( $publication['published_at'] )->compare( $accepted ) > 0 ) { PromiseShape::invalid(); }
		PromiseShape::digest( $publication['publication_digest'] ); $publication['reference'] = $reference->private_facts(); return $publication;
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, self::MAX_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, self::MAX_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-source-receipt-v1:' . $this->json ); }
	public function kind(): string { return $this->private_facts()['kind']; }
	public function operation(): string { return $this->private_facts()['operation']; }
	public function namespace_hash(): string { return $this->private_facts()['namespace_hash']; }
	public function accepted_at(): RuleTime { return PromiseShape::instant( $this->private_facts()['accepted_at'] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise source projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized promise source projection is required.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise source facts require strict hydration.' ); }
}
