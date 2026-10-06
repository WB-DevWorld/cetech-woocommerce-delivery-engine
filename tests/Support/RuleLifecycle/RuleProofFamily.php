<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyProfile;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleMatch;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSchema;

/** Approved disposable availability family; never registered by production. */
final class RuleProofFamily implements RuleFamilyProfile {
	public bool $allowed = true;
	public bool $author_allowed = true;
	public int $authorization_calls = 0;
	public ?int $revoke_at_call = null;
	public bool $unknown_overlap = false;
	private ?\Closure $authorizer;
	private ?\Closure $author_grant;

	public function __construct( ?callable $authorizer = null, ?callable $author_grant = null ) {
		$this->authorizer = null === $authorizer ? null : \Closure::fromCallable( $authorizer );
		$this->author_grant = null === $author_grant ? null : \Closure::fromCallable( $author_grant );
	}
	public function family(): string { return 'fixture_availability_v1'; }
	public function version(): int { return 1; }
	public function policy_hash(): string { return hash( 'sha256', 'fixture_availability_v1:scope-v1:allow-deny:specificity-desc:priority-asc:equal-refuse' ); }
	public function scope_schema(): RuleSchema { return new RuleSchema( [ 'type' => [ 'enum' => [ 'global', 'product', 'variation' ] ], 'id' => 'nonnegative_int', 'parent_id' => 'nonnegative_int' ] ); }
	public function subject_schema(): RuleSchema { return $this->scope_schema(); }
	public function payload_schema(): RuleSchema { return new RuleSchema( [ 'availability' => [ 'enum' => [ 'allow', 'deny' ] ] ] ); }
	public function authorize( OperationIdentity $identity, array $scope ): bool {
		++$this->authorization_calls;
		return $this->allowed && ( null === $this->revoke_at_call || $this->authorization_calls < $this->revoke_at_call )
			&& ( null === $this->authorizer || true === ( $this->authorizer )( $identity, $scope ) );
	}
	public function authorize_author( int $site, int $author, array $scope ): bool {
		return $this->author_allowed && $site > 0 && $author > 0
			&& ( null === $this->author_grant || true === ( $this->author_grant )( $site, $author, $scope ) );
	}
	public function match( array $scope, array $subject ): RuleMatch {
		$scope = $this->scope_schema()->validate( $scope ); $subject = $this->subject_schema()->validate( $subject );
		$matched = 'global' === $scope['type']
			|| ( 'product' === $scope['type'] && ( 'product' === $subject['type'] ? $scope['id'] === $subject['id'] : 'variation' === $subject['type'] && $scope['id'] === $subject['parent_id'] ) )
			|| ( 'variation' === $scope['type'] && 'variation' === $subject['type'] && $scope['id'] === $subject['id'] && $scope['parent_id'] === $subject['parent_id'] );
		return new RuleMatch( $matched, match ( $scope['type'] ) { 'variation' => 3, 'product' => 2, default => 1 } );
	}
	public function overlaps( array $left, array $right ): ?bool {
		if ( $this->unknown_overlap ) { return null; }
		$left = $this->scope_schema()->validate( $left ); $right = $this->scope_schema()->validate( $right );
		if ( 'global' === $left['type'] || 'global' === $right['type'] ) { return true; }
		if ( $left['type'] === $right['type'] ) { return $left['id'] === $right['id'] && ( 'product' === $left['type'] || $left['parent_id'] === $right['parent_id'] ); }
		$product = 'product' === $left['type'] ? $left : $right; $variation = 'variation' === $left['type'] ? $left : $right;
		return $product['id'] === $variation['parent_id'];
	}
	public function precedence(): array { return [ [ 'field' => 'specificity', 'direction' => 'desc' ], [ 'field' => 'priority', 'direction' => 'asc' ] ]; }
	public function equal_rank_policy(): string { return 'refuse'; }
	public function unavailable_reason(): string { return 'rule_unavailable'; }
}
