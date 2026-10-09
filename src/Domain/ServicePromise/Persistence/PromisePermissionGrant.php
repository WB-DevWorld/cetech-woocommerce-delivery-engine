<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;

/** Request-local host grant captured before SQL ownership; never a persisted authorization proof. */
final readonly class PromisePermissionGrant {
	private function __construct( private OperationIdentity $identity, private PromiseSiteBinding $binding, private array $scope, private int $author_user_id, private bool $original_author_allowed ) {}
	public static function capture( PromiseVersionCommand|PromiseAssignmentCommand $command, PromisePersistenceAuthorizer $authorizer ): self {
		$data = $command->private_facts();
		$scope = $command instanceof PromiseVersionCommand ? $data['scope'] : [ 'kind' => $data['key']['scope_kind'], 'target_id' => $data['key']['scope_id'] ];
		if ( true !== $authorizer->authorize( $command->identity, $command->binding, $scope, $data['author_user_id'] ) ) { throw new \CetechDeliveryEngine\Domain\Operation\OperationRefusal( 'not_authorized', 'contact_support' ); }
		$author = true; if ( 'promise.version.activate' === $command->identity->operation ) { try { $author = true === $authorizer->authorize_author( $command->binding, $data['scheduled_author_user_id'], $scope ); } catch ( \Throwable ) { $author = false; } }
		return new self( $command->identity, $command->binding, $scope, $data['author_user_id'], $author );
	}
	public function permits( OperationIdentity $identity, PromiseVersionCommand|PromiseAssignmentCommand $command ): bool {
		$data = $command->private_facts(); $scope = $command instanceof PromiseVersionCommand ? $data['scope'] : [ 'kind' => $data['key']['scope_kind'], 'target_id' => $data['key']['scope_id'] ];
		return $identity === $this->identity && $command->identity === $this->identity && $command->binding->private_facts() === $this->binding->private_facts() && $scope === $this->scope && $data['author_user_id'] === $this->author_user_id;
	}
	public function original_author_allowed(): bool { return $this->original_author_allowed; }
	public function __serialize(): never { throw new \LogicException( 'Permission snapshots cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Permission snapshots require a host grant.' ); }
}
