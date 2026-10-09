<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseJson, PromiseLimits, PromiseShape, ServicePromisePolicy};

/** Original immutable command; retry metadata and accepted execution time are never substituted. */
final readonly class PromiseVersionCommand implements OperationCommand, \JsonSerializable {
	public const OPERATIONS = [ 'promise.version.create', 'promise.version.seal', 'promise.version.publish', 'promise.version.schedule', 'promise.version.activate', 'promise.version.retire' ];
	private function __construct( public OperationIdentity $identity, public PromiseSiteBinding $binding, private array $facts, private CanonicalIntent $canonical ) {}
	public static function from_array( OperationIdentity $identity, PromiseSiteBinding $binding, array $data ): self {
		$data = PromiseJson::detach( $data ); PromiseShape::fields( $data, [ 'kind', 'logical_id', 'domain_version', 'version_uuid', 'body_digest', 'scope', 'body_json', 'declared_from', 'declared_until', 'author_user_id', 'reason', 'scheduled_author_user_id', 'preconditions' ] );
		if ( ! in_array( $identity->operation, self::OPERATIONS, true ) || 1 !== $identity->operation_version || $identity->site_id !== $binding->site_id() ) { PromiseShape::invalid(); }
		PromiseShape::choice( $data['kind'], [ 'policy', 'calendar' ] ); PromiseShape::id( $data['logical_id'] ); PromiseShape::integer( $data['domain_version'], 1, PromiseLimits::VERSION_MAX ); PromiseShape::digest( $data['body_digest'] );
		if ( ! RequestContext::is_valid_identifier( $data['version_uuid'] ) ) { PromiseShape::invalid(); }
		$scope = self::scope( PromiseShape::object( $data['scope'] ) ); if ( 'calendar' === $data['kind'] && 'global' !== $scope['kind'] ) { PromiseShape::invalid(); } $data['scope'] = $scope;
		$from = PromiseShape::instant( $data['declared_from'] ); $until = null === $data['declared_until'] ? null : PromiseShape::instant( $data['declared_until'] ); if ( null !== $until && $from->compare( $until ) >= 0 ) { PromiseShape::invalid(); }
		PromiseShape::integer( $data['author_user_id'], 1 ); PromiseShape::text( $data['reason'], 256 );
		if ( 'promise.version.activate' === $identity->operation ) { PromiseShape::integer( $data['scheduled_author_user_id'], 1 ); } elseif ( null !== $data['scheduled_author_user_id'] ) { PromiseShape::invalid(); }
		$p = PromiseShape::object( $data['preconditions'] ); PromiseShape::fields( $p, [ 'object_revision', 'version_revision', 'published_version_id' ] ); foreach ( $p as $value ) { PromiseShape::integer( $value, 0, PHP_INT_MAX - 1 ); } $data['preconditions'] = $p;
		if ( 'promise.version.create' === $identity->operation ) {
			if ( 0 !== $p['version_revision'] || ! is_string( $data['body_json'] ) ) { PromiseShape::invalid(); }
			$body = 'policy' === $data['kind'] ? ServicePromisePolicy::from_json( $data['body_json'] ) : BusinessCalendarVersion::from_json( $data['body_json'] ); $body_facts = $body->private_facts();
			if ( $body_facts['site_id'] !== $binding->site_key() || $body_facts['policy' === $data['kind'] ? 'policy_id' : 'calendar_id'] !== $data['logical_id'] || $body_facts['version'] !== $data['domain_version'] || $body->digest() !== $data['body_digest'] ) { PromiseShape::invalid(); }
			if ( $body instanceof ServicePromisePolicy && ( $body->effective_from()->sql() !== $data['declared_from'] || $body->effective_until()?->sql() !== $data['declared_until'] || self::policy_scope( $body ) !== $scope ) ) { PromiseShape::invalid(); } $data['body_json'] = $body->to_private_json();
		} elseif ( null !== $data['body_json'] || 0 === $p['object_revision'] || 0 === $p['version_revision'] ) { PromiseShape::invalid(); }
		if ( $identity->target_key !== self::target_key( $binding, $data['kind'], $data['logical_id'] ) ) { PromiseShape::invalid(); }
		$semantic = $data; unset( $semantic['body_json'], $semantic['preconditions'] );
		return new self( $identity, $binding, $data, CanonicalIntent::from_command( $identity, [ 'site_key' => $binding->site_key(), 'kind' => $data['kind'], 'logical_id' => $data['logical_id'], 'domain_version' => $data['domain_version'], 'version_uuid' => $data['version_uuid'] ], $p, $semantic ) );
	}
	public static function target_key( PromiseSiteBinding $binding, string $kind, string $logical_id ): string { PromiseShape::choice( $kind, [ 'policy', 'calendar' ] ); PromiseShape::id( $logical_id ); return 'promise-object:' . $binding->site_key() . ':' . $kind . ':' . $logical_id; }
	public static function scope( array $scope ): array { PromiseShape::fields( $scope, [ 'kind', 'target_id' ] ); PromiseShape::choice( $scope['kind'], [ 'global', 'product', 'variation' ] ); PromiseShape::integer( $scope['target_id'], 'global' === $scope['kind'] ? 0 : 1 ); if ( 'global' === $scope['kind'] && 0 !== $scope['target_id'] ) { PromiseShape::invalid(); } return $scope; }
	public static function policy_scope( ServicePromisePolicy $policy ): array { $scope = $policy->private_facts()['scope']; return [ 'kind' => $scope['kind'], 'target_id' => $scope['target_id'] ?? 0 ]; }
	public function private_facts(): array { return PromiseJson::detach( $this->facts ); }
	public function intent(): CanonicalIntent { return $this->canonical; }
	public function jsonSerialize(): never { throw new \LogicException( 'Private promise commands require an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private promise commands cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private promise commands require a validated factory.' ); }
}
