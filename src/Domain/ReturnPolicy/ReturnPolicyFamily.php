<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ReturnPolicy;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleFamilyProfile, RuleMatch, RuleSchema};

/** Explicit Return-only catalog profile. Trusted callbacks, never its hash, grant access. */
final class ReturnPolicyFamily implements RuleFamilyProfile {

	public const CODE = ReturnPolicyContent::FAMILY;
	public const VERSION = 1;
	private const WRITES = [ 'rule.draft.create', 'rule.draft.edit', 'rule.publish', 'rule.schedule', 'rule.retire' ];
	private \Closure $scope_grant;
	private \Closure $author_eligibility;
	private \Closure $identity_author_binding;
	private ?int $bound_author = null;

	/** The trusted host retains these callbacks; request data cannot supply this profile. */
	public function __construct( callable $scope_grant, callable $author_eligibility, callable $identity_author_binding ) {
		$this->scope_grant = \Closure::fromCallable( $scope_grant );
		$this->author_eligibility = \Closure::fromCallable( $author_eligibility );
		$this->identity_author_binding = \Closure::fromCallable( $identity_author_binding );
	}

	/** Separate command-local binding; the original declaration and callbacks are preserved. */
	public function for_author( int $author_user_id ): self {
		if ( $author_user_id < 1 ) { throw new \InvalidArgumentException( 'Invalid return policy author.' ); }
		$bound = clone $this;
		$bound->bound_author = $author_user_id;
		return $bound;
	}

	public function family(): string { return self::CODE; }
	public function version(): int { return self::VERSION; }
	public function policy_hash(): string { return self::definition_hash(); }

	/** A semantic declaration, not a callback-origin or authorization attestation. */
	public static function definition_hash(): string {
		$scope = self::catalog_schema()->fingerprint();
		return hash( 'sha256', 'cetech-return-policy-family-v1:catalog-policy-uuid-only:canonical-content-v1:name,customer_title,summary,full_text,exceptions:inner-and-outer-16384:priority-zero:priority-desc:specificity-zero:equal-rank-refuse:no-activation:explicit-scope-author-and-binding-grants:'
			. self::CODE . ':' . $scope . ':' . ReturnPolicyContent::payload_schema()->fingerprint() . ':' . $scope );
	}

	public function scope_schema(): RuleSchema { return self::catalog_schema(); }
	public function subject_schema(): RuleSchema { return self::catalog_schema(); }
	public function payload_schema(): RuleSchema { return ReturnPolicyContent::payload_schema(); }

	public static function checked_scope( array $scope ): array { return self::catalog_schema()->validate( $scope ); }

	public function authorize( OperationIdentity $identity, array $scope ): bool {
		if ( 'rule.activate' === $identity->operation ) { return false; }
		try {
			$scope = self::checked_scope( $scope );
			$write = in_array( $identity->operation, self::WRITES, true );
			if ( $write && ( null === $this->bound_author || 1 !== $identity->operation_version ) ) { return false; }
			if ( ! $this->scope_allowed( $identity, $scope ) ) { return false; }
			if ( ! $write ) { return true; }
			return $this->authorize_author( $identity->site_id, $this->bound_author, $scope )
				&& $this->binding_allowed( $identity, $this->bound_author, $scope );
		} catch ( \Throwable ) { return false; }
	}

	/** Eligibility alone is not an identity/author relationship or write grant. */
	public function authorize_author( int $site_id, int $author_user_id, array $scope ): bool {
		try {
			$scope = self::checked_scope( $scope );
			return $site_id > 0 && $author_user_id > 0
				&& true === ( $this->author_eligibility )( $site_id, $author_user_id, $scope );
		} catch ( \Throwable ) { return false; }
	}

	public function match( array $scope, array $subject ): RuleMatch {
		return new RuleMatch( self::checked_scope( $scope )['policy_uuid'] === self::checked_scope( $subject )['policy_uuid'], 0 );
	}

	public function overlaps( array $left_scope, array $right_scope ): ?bool {
		try { return self::checked_scope( $left_scope )['policy_uuid'] === self::checked_scope( $right_scope )['policy_uuid']; }
		catch ( \Throwable ) { return null; }
	}

	public function precedence(): array { return [ [ 'field' => 'priority', 'direction' => 'desc' ] ]; }
	public function equal_rank_policy(): string { return 'refuse'; }
	public function unavailable_reason(): string { return 'return_policy_catalog_unavailable'; }
	public function __debugInfo(): array { return [ 'return_policy_family' => 'redacted' ]; }

	/** Local copies prevent a callback with reference parameters from changing later checks. */
	private function scope_allowed( OperationIdentity $identity, array $scope ): bool { return true === ( $this->scope_grant )( $identity, $scope ); }
	private function binding_allowed( OperationIdentity $identity, int $author_user_id, array $scope ): bool { return true === ( $this->identity_author_binding )( $identity, $author_user_id, $scope ); }
	private static function catalog_schema(): RuleSchema { return new RuleSchema( [ 'policy_uuid' => 'uuid' ] ); }
}
