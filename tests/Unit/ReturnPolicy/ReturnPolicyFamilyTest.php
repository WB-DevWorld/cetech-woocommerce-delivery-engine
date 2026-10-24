<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ReturnPolicy;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyContent, ReturnPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnPolicyFamilyTest extends TestCase {

	private const POLICY = '11111111-1111-4111-8111-111111111111';
	private const OTHER = '22222222-2222-4222-8222-222222222222';

	public function test_catalog_declaration_is_explicit_and_is_not_registered_by_construction(): void {
		$family = self::family();
		self::assertSame( [], ( new RuleFamilyRegistry() )->profiles() );
		self::assertSame( ReturnPolicyContent::FAMILY, $family->family() );
		self::assertSame( 1, $family->version() );
		self::assertSame( ReturnPolicyFamily::definition_hash(), $family->policy_hash() );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $family->policy_hash() );
		self::assertSame( $family, ( new RuleFamilyRegistry( [ $family ] ) )->get( ReturnPolicyContent::FAMILY ) );
		self::assertSame( [ [ 'field' => 'priority', 'direction' => 'desc' ] ], $family->precedence() );
		self::assertSame( 'refuse', $family->equal_rank_policy() );
		self::assertSame( ReturnPolicyContent::payload_schema()->fingerprint(), $family->payload_schema()->fingerprint() );
		self::assertSame( $family->scope_schema()->fingerprint(), $family->subject_schema()->fingerprint() );
	}

	#[DataProvider( 'writes' )]
	public function test_each_write_needs_a_separate_author_binding_and_original_callbacks( string $operation ): void {
		$seen = [];
		$family = new ReturnPolicyFamily(
			static function ( OperationIdentity $identity, array $scope ) use ( &$seen ): bool { $seen[] = [ 'scope', $identity, $scope ]; return true; },
			static function ( int $site, int $author, array $scope ) use ( &$seen ): bool { $seen[] = [ 'author', $site, $author, $scope ]; return true; },
			static function ( OperationIdentity $identity, int $author, array $scope ) use ( &$seen ): bool { $seen[] = [ 'binding', $identity, $author, $scope ]; return true; }
		);
		$identity = self::identity( $operation );
		self::assertFalse( $family->authorize( $identity, self::scope() ) );
		self::assertSame( [], $seen );
		self::assertTrue( $family->for_author( 17 )->authorize( $identity, self::scope() ) );
		self::assertSame( [ [ 'scope', $identity, self::scope() ], [ 'author', 1, 17, self::scope() ], [ 'binding', $identity, 17, self::scope() ] ], $seen );
		self::assertFalse( $family->authorize( $identity, self::scope() ) );
	}

	public static function writes(): array {
		return array_map( static fn( string $operation ): array => [ $operation ], [ 'rule.draft.create', 'rule.draft.edit', 'rule.publish', 'rule.schedule', 'rule.retire' ] );
	}

	public function test_author_eligibility_is_not_an_identity_author_relationship_or_write_grant(): void {
		$family = new ReturnPolicyFamily( static fn(): bool => true, static fn(): bool => true, static fn(): bool => false );
		self::assertTrue( $family->authorize_author( 1, 17, self::scope() ) );
		self::assertFalse( $family->authorize( self::identity(), self::scope() ) );
		self::assertFalse( $family->for_author( 17 )->authorize( self::identity(), self::scope() ) );
	}

	public function test_trusted_binding_can_allow_a_manager_without_parsing_the_principal_as_the_author(): void {
		$family = new ReturnPolicyFamily(
			static fn( OperationIdentity $identity ): bool => 1 === $identity->site_id && 'opaque-manager-capability' === $identity->principal,
			static fn( int $site, int $author ): bool => 1 === $site && 17 === $author,
			static fn( OperationIdentity $identity, int $author, array $scope ): bool => 'opaque-manager-capability' === $identity->principal && 17 === $author && self::scope() === $scope
		);
		self::assertTrue( $family->for_author( 17 )->authorize( self::identity( 'rule.publish', 'opaque-manager-capability' ), self::scope() ) );
		self::assertFalse( $family->for_author( 17 )->authorize( self::identity( 'rule.publish', '17' ), self::scope() ) );
		self::assertFalse( $family->for_author( 18 )->authorize( self::identity( 'rule.publish', 'opaque-manager-capability' ), self::scope() ) );
	}

	public function test_bound_profiles_are_independent_and_preserve_the_original_declaration(): void {
		$family = new ReturnPolicyFamily( static fn(): bool => true, static fn(): bool => true, static fn( OperationIdentity $identity, int $author ): bool => 'capability-' . $author === $identity->principal );
		$first = $family->for_author( 17 );
		$second = $first->for_author( 29 );
		self::assertNotSame( $family, $first );
		self::assertNotSame( $first, $second );
		self::assertSame( RuleFamilyRegistry::declaration( $family ), RuleFamilyRegistry::declaration( $first ) );
		self::assertSame( RuleFamilyRegistry::declaration( $first ), RuleFamilyRegistry::declaration( $second ) );
		self::assertTrue( $first->authorize( self::identity( 'rule.retire', 'capability-17' ), self::scope() ) );
		self::assertFalse( $first->authorize( self::identity( 'rule.retire', 'capability-29' ), self::scope() ) );
		self::assertTrue( $second->authorize( self::identity( 'rule.retire', 'capability-29' ), self::scope() ) );
		self::assertFalse( $family->authorize( self::identity( 'rule.retire', 'capability-17' ), self::scope() ) );
	}

	public function test_identical_declarations_do_not_attest_callback_authority(): void {
		$allowed = self::family()->for_author( 17 );
		$denied = ( new ReturnPolicyFamily( static fn(): bool => false, static fn(): bool => true, static fn(): bool => true ) )->for_author( 17 );
		self::assertSame( RuleFamilyRegistry::declaration( $allowed ), RuleFamilyRegistry::declaration( $denied ) );
		self::assertTrue( $allowed->authorize( self::identity(), self::scope() ) );
		self::assertFalse( $denied->authorize( self::identity(), self::scope() ) );
	}

	public function test_every_guard_call_observes_revoked_scope_author_and_binding_grants(): void {
		$grants = [ 'scope' => true, 'author' => true, 'binding' => true ];
		$family = ( new ReturnPolicyFamily(
			static function () use ( &$grants ): bool { return $grants['scope']; },
			static function () use ( &$grants ): bool { return $grants['author']; },
			static function () use ( &$grants ): bool { return $grants['binding']; }
		) )->for_author( 17 );
		self::assertTrue( $family->authorize( self::identity(), self::scope() ) );
		foreach ( array_keys( $grants ) as $stage ) {
			$grants[ $stage ] = false;
			self::assertFalse( $family->authorize( self::identity(), self::scope() ), $stage );
			$grants[ $stage ] = true;
			self::assertTrue( $family->authorize( self::identity(), self::scope() ), $stage );
		}
	}

	#[DataProvider( 'refusals' )]
	public function test_all_grants_require_strict_true_and_exceptions_refuse_without_escaping( string $stage, string $failure ): void {
		$check = static function ( string $current ) use ( $stage, $failure ): mixed {
			if ( $current !== $stage ) { return true; }
			return match ( $failure ) {
				'false' => false, 'integer' => 1, 'text' => 'true', 'null' => null, 'array' => [ true ],
				'exception' => throw new \RuntimeException( 'Private host callback detail.' ),
			};
		};
		$family = ( new ReturnPolicyFamily( static fn(): mixed => $check( 'scope' ), static fn(): mixed => $check( 'author' ), static fn(): mixed => $check( 'binding' ) ) )->for_author( 17 );
		self::assertFalse( $family->authorize( self::identity(), self::scope() ) );
	}

	public static function refusals(): array {
		$cases = [];
		foreach ( [ 'scope', 'author', 'binding' ] as $stage ) {
			foreach ( [ 'false', 'integer', 'text', 'null', 'array', 'exception' ] as $failure ) { $cases[ $stage . '-' . $failure ] = [ $stage, $failure ]; }
		}
		return $cases;
	}

	public function test_catalog_read_checks_only_the_original_scope_callback_without_granting_writes(): void {
		$seen = [];
		$family = new ReturnPolicyFamily(
			static function ( OperationIdentity $identity, array $scope ) use ( &$seen ): bool { $seen[] = [ $identity, $scope ]; return 'catalog-reader' === $identity->principal; },
			static function (): never { throw new \LogicException( 'Read must not request author eligibility.' ); },
			static function (): never { throw new \LogicException( 'Read must not request author binding.' ); }
		);
		$read = self::identity( 'return_policy.catalog.read', 'catalog-reader' );
		self::assertTrue( $family->authorize( $read, self::scope() ) );
		self::assertTrue( $family->for_author( 17 )->authorize( $read, self::scope() ) );
		self::assertSame( [ [ $read, self::scope() ], [ $read, self::scope() ] ], $seen );
		self::assertFalse( $family->authorize( self::identity( 'return_policy.catalog.read', 'another-reader' ), self::scope() ) );
		self::assertFalse( $family->for_author( 17 )->authorize( self::identity( 'rule.publish', 'catalog-reader' ), self::scope() ) );
	}

	public function test_activation_is_always_refused_without_even_invoking_host_callbacks(): void {
		$called = 0;
		$grant = static function () use ( &$called ): bool { ++$called; return true; };
		$family = new ReturnPolicyFamily( $grant, $grant, $grant );
		self::assertFalse( $family->authorize( self::identity( 'rule.activate' ), self::scope() ) );
		self::assertFalse( $family->for_author( 17 )->authorize( self::identity( 'rule.activate' ), self::scope() ) );
		self::assertSame( 0, $called );
	}

	public function test_callback_reference_parameters_cannot_rewrite_later_scope_or_author_checks(): void {
		$seen = [];
		$family = new ReturnPolicyFamily(
			static function ( OperationIdentity $identity, array &$scope ): bool { $scope['policy_uuid'] = self::OTHER; return true; },
			static function ( int $site, int &$author, array &$scope ) use ( &$seen ): bool { $seen[] = [ $site, $author, $scope ]; $author = 999; $scope['policy_uuid'] = self::OTHER; return true; },
			static function ( OperationIdentity $identity, int $author, array $scope ) use ( &$seen ): bool { $seen[] = [ $identity, $author, $scope ]; return 17 === $author && self::scope() === $scope; }
		);
		$identity = self::identity();
		$scope = self::scope();
		self::assertTrue( $family->for_author( 17 )->authorize( $identity, $scope ) );
		self::assertSame( self::scope(), $scope );
		self::assertSame( [ [ 1, 17, self::scope() ], [ $identity, 17, self::scope() ] ], $seen );
	}

	public function test_invalid_site_or_author_never_calls_the_eligibility_callback(): void {
		$called = 0;
		$family = new ReturnPolicyFamily( static fn(): bool => true, static function () use ( &$called ): bool { ++$called; return true; }, static fn(): bool => true );
		foreach ( [ [ 0, 17 ], [ -1, 17 ], [ 1, 0 ], [ 1, -1 ] ] as [ $site, $author ] ) { self::assertFalse( $family->authorize_author( $site, $author, self::scope() ) ); }
		self::assertSame( 0, $called );
	}

	#[DataProvider( 'invalid_authors' )]
	public function test_nonpositive_command_author_cannot_be_bound( int $author ): void {
		$this->expectException( \InvalidArgumentException::class );
		self::family()->for_author( $author );
	}
	public static function invalid_authors(): array { return [ [ 0 ], [ -1 ], [ PHP_INT_MIN ] ]; }

	public function test_unsupported_write_version_refuses_before_callbacks(): void {
		$called = 0; $callback = static function () use ( &$called ): bool { ++$called; return true; };
		$family = ( new ReturnPolicyFamily( $callback, $callback, $callback ) )->for_author( 17 );
		self::assertFalse( $family->authorize( self::identity( version: 2 ), self::scope() ) );
		self::assertSame( 0, $called );
	}

	public function test_policy_uuid_matching_has_zero_specificity_and_no_product_or_global_inheritance(): void {
		$family = self::family();
		self::assertTrue( $family->match( self::scope(), self::scope() )->matched );
		self::assertSame( 0, $family->match( self::scope(), self::scope() )->specificity );
		self::assertFalse( $family->match( self::scope(), [ 'policy_uuid' => self::OTHER ] )->matched );
		self::assertSame( 0, $family->match( self::scope(), [ 'policy_uuid' => self::OTHER ] )->specificity );
		self::assertTrue( $family->overlaps( self::scope(), self::scope() ) );
		self::assertFalse( $family->overlaps( self::scope(), [ 'policy_uuid' => self::OTHER ] ) );
	}

	#[DataProvider( 'invalid_scopes' )]
	public function test_incomplete_ambiguous_or_coerced_catalog_scope_never_becomes_a_grant( array $scope ): void {
		$called = 0; $callback = static function () use ( &$called ): bool { ++$called; return true; };
		$family = ( new ReturnPolicyFamily( $callback, $callback, $callback ) )->for_author( 17 );
		self::assertFalse( $family->authorize( self::identity(), $scope ) );
		self::assertFalse( $family->authorize_author( 1, 17, $scope ) );
		self::assertNull( $family->overlaps( self::scope(), $scope ) );
		self::assertSame( 0, $called );
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyFamily::checked_scope( $scope );
	}
	public static function invalid_scopes(): array {
		return [ 'missing' => [ [] ], 'product' => [ [ 'product_id' => 17 ] ], 'global' => [ [ 'scope' => 'global' ] ], 'extra' => [ [ 'policy_uuid' => self::POLICY, 'product_id' => 17 ] ], 'empty' => [ [ 'policy_uuid' => '' ] ], 'integer' => [ [ 'policy_uuid' => 17 ] ], 'uppercase' => [ [ 'policy_uuid' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA' ] ], 'nil' => [ [ 'policy_uuid' => '00000000-0000-0000-0000-000000000000' ] ] ];
	}

	public function test_callbacks_and_private_author_are_not_exposed_by_debug_or_public_properties(): void {
		$secret = 'private-host-binding-secret';
		$callback = static fn(): bool => '' !== $secret;
		$family = ( new ReturnPolicyFamily( $callback, $callback, $callback ) )->for_author( 17 );
		self::assertSame( [], get_object_vars( $family ) );
		self::assertSame( [ 'return_policy_family' => 'redacted' ], $family->__debugInfo() );
		ob_start(); var_dump( $family ); $debug = ob_get_clean();
		self::assertIsString( $debug );
		self::assertStringNotContainsString( $secret, $debug );
		self::assertStringNotContainsString( 'Closure', $debug );
	}

	private static function family(): ReturnPolicyFamily { return new ReturnPolicyFamily( static fn(): bool => true, static fn(): bool => true, static fn(): bool => true ); }
	private static function scope(): array { return [ 'policy_uuid' => self::POLICY ]; }
	private static function identity( string $operation = 'rule.draft.create', string $principal = 'opaque-operator', int $version = 1 ): OperationIdentity { return new OperationIdentity( 1, 'trusted-return-policy-host', $principal, $operation, $version, 'original-command-target', 'original-idempotency-key' ); }
}
