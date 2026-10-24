<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RefundPolicy;

use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationJson, OperationPhaseObserver};
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleLifecycleCommand, RuleSnapshot, RuleStartMode, RuleTime};
use CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyCatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Public catalog boundaries over an owned in-memory protocol model, not physical SQL proofs. */
final class RefundPolicyCatalogSecurityTest extends TestCase {

	private RefundPolicyCatalogFixture $fixture;

	protected function setUp(): void { $this->fixture = new RefundPolicyCatalogFixture(); }

	protected function tearDown(): void { $this->fixture->close(); }

	#[DataProvider( 'entrypoints' )]
	public function test_two_independent_grants_do_not_bind_a_claimed_author( string $entrypoint ): void {
		$f = $this->fixture; $payload = $f->payload();
		$identity = $f->identity( $payload ); $payload['author_user_id'] = 10;
		self::assertTrue( $f->scope_allowed ); self::assertTrue( $f->author_allowed );
		$result = $f->service()->{$entrypoint}( $identity, $payload, RequestContext::create() );
		self::assertSame( 'not_authorized', $result->outcome->error->code );
		self::assertNull( $result->completion ); self::assertSame( 0, $f->factory->opens );
		self::assertGreaterThan( 0, $f->calls['binding'] );
	}

	public static function entrypoints(): array { return [ 'attempt' => [ 'attempt' ], 'reconcile' => [ 'reconcile' ] ]; }

	public function test_trusted_opaque_manager_delegation_preserves_the_original_author(): void {
		$f = $this->fixture; $payload = $f->payload();
		self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $payload )->outcome->state );
		$opened = $f->opened(); $identity = $f->identity( $opened, 'rule.publish', 'delegated-publish', principal: 'opaque-manager-grant-44' );
		$f->binding_grant = static fn ( OperationIdentity $actor, int $author, array $scope ): bool =>
			1 === $actor->site_id && 'opaque-manager-grant-44' === $actor->principal && 9 === $author;
		$published = $f->service()->attempt( $identity, $opened, RequestContext::create() );
		self::assertSame( 'accepted', $published->outcome->state );
		self::assertSame( 9, $published->completion->result['author_user_id'] );
		self::assertSame( 9, (int) $f->row( 'rule_versions' )['author_user_id'] );
		self::assertSame( 'published', $f->row( 'rule_versions' )['state'] );
		$replay = $f->service()->reconcile( $identity, $opened, RequestContext::create() );
		self::assertSame( 'accepted', $replay->outcome->state ); self::assertTrue( $replay->replayed );
		self::assertSame( $published->completion->to_json(), $replay->completion->to_json() );
		self::assertSame( 2, $f->count( 'operation_changes' ) );
	}

	public function test_revoked_binding_hides_an_original_acceptance_before_any_new_sql(): void {
		$f = $this->fixture; $payload = $f->payload(); $identity = $f->identity( $payload );
		self::assertSame( 'accepted', $f->service()->attempt( $identity, $payload, RequestContext::create() )->outcome->state );
		$before = $f->rows( 'rule_versions' ); $opens = $f->factory->opens; $sql = $f->factory->sql;
		$f->binding_allowed = false;
		foreach ( [ 'attempt', 'reconcile' ] as $entrypoint ) {
			$result = $f->service()->{$entrypoint}( $identity, $payload, RequestContext::create() );
			self::assertSame( 'not_authorized', $result->outcome->error->code ); self::assertNull( $result->completion );
		}
		self::assertSame( $opens, $f->factory->opens ); self::assertSame( $sql, $f->factory->sql );
		self::assertSame( $before, $f->rows( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
	}

	public function test_binding_revoked_after_reservation_prevents_the_effect_and_original_retry_works(): void {
		$f = $this->fixture; $payload = $f->payload(); $identity = $f->identity( $payload );
		$observer = $this->revoke_binding_at( 'reservation_committed' );
		$refused = $f->service( $observer )->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'not_authorized', $refused->outcome->error->code ); self::assertNull( $refused->completion );
		self::assertSame( 0, $f->count( 'logical_rules' ) ); self::assertSame( 0, $f->count( 'rule_versions' ) );
		self::assertSame( 0, $f->count( 'operation_changes' ) ); self::assertSame( 1, $f->count( 'operation_records' ) );
		$f->binding_allowed = true;
		self::assertSame( 'accepted', $f->service()->attempt( $identity, $payload, RequestContext::create() )->outcome->state );
		self::assertSame( 1, $f->count( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
	}

	public function test_binding_lost_after_mutation_does_not_falsely_claim_the_durable_effect_was_rejected(): void {
		$f = $this->fixture; $payload = $f->payload(); $identity = $f->identity( $payload );
		// Existing C03 commits after this observer, then rechecks authority before disclosure.
		$unknown = $f->service( $this->revoke_binding_at( 'before_effect_commit' ) )->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'unconfirmed', $unknown->outcome->state ); self::assertNull( $unknown->completion );
		self::assertSame( 1, $f->count( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
		$before = $f->rows( 'rule_versions' ); $f->binding_allowed = true;
		$recovered = $f->service()->reconcile( $identity, $payload, RequestContext::create() );
		self::assertSame( 'accepted', $recovered->outcome->state ); self::assertTrue( $recovered->replayed );
		self::assertSame( $before, $f->rows( 'rule_versions' ) ); self::assertSame( 1, $f->count( 'operation_changes' ) );
	}

	#[DataProvider( 'invalid_original_envelopes' )]
	public function test_invalid_original_envelopes_never_open_a_connection( string $case, string $entrypoint ): void {
		$f = $this->fixture; $payload = $f->payload( [ 'full_text' => 'PRIVATE_BODY_FOR_REDACTION' ] );
		$identity = $f->identity( $payload, token: 'private-original-operation-token' );
		switch ( $case ) {
			case 'non_array': $payload = 'PRIVATE_BODY_FOR_REDACTION'; break;
			case 'malformed_inner': $payload['payload']['content_json'] = '{"PRIVATE_BODY_FOR_REDACTION":'; break;
			case 'duplicate_inner': $payload['payload']['content_json'] = '{"name":"first","name":"second","customer_title":"title","summary":"summary","full_text":"body","exceptions":""}'; break;
			case 'noncanonical_inner': $payload['payload']['content_json'] = ' ' . $payload['payload']['content_json']; break;
			case 'invalid_body_fields': $payload['payload']['content_json'] = '{"private_unrecognized_body":"PRIVATE_BODY_FOR_REDACTION"}'; break;
			case 'inner_overflow':
				$body = json_decode( $payload['payload']['content_json'], true, flags: JSON_THROW_ON_ERROR ); $body['full_text'] = str_repeat( 'x', OperationJson::MAX_BYTES ); ksort( $body );
				$payload['payload']['content_json'] = json_encode( $body, JSON_THROW_ON_ERROR );
				self::assertGreaterThan( OperationJson::MAX_BYTES, strlen( $payload['payload']['content_json'] ) ); break;
			case 'outer_overflow':
				$content = RefundPolicyContent::from_array( [ 'name' => 'Fixture', 'customer_title' => 'Fixture', 'summary' => 'Fixture', 'full_text' => str_repeat( '"', 6000 ), 'exceptions' => '' ] );
				$payload['payload']['content_json'] = $content->json();
				self::assertLessThanOrEqual( OperationJson::MAX_BYTES, strlen( $content->json() ) );
				self::assertGreaterThan( OperationJson::MAX_BYTES, strlen( json_encode( $payload['payload'], JSON_THROW_ON_ERROR ) ) ); break;
			case 'extra_outer': $payload['private_extra'] = 'PRIVATE_BODY_FOR_REDACTION'; break;
			case 'extra_payload': $payload['payload']['private_extra'] = true; break;
			case 'foreign_family': $payload['family'] = 'return_policy_v1'; break;
			case 'future_family': $payload['family'] = 'refund_policy_v2'; break;
			case 'product_scope': $payload['scope'] = [ 'product_id' => 33 ]; break;
			case 'extra_scope': $payload['scope']['product_id'] = 33; break;
			case 'other_policy': $payload['scope']['policy_uuid'] = RefundPolicyCatalogFixture::uuid( 202 ); break;
			case 'invalid_policy_uuid': $payload['scope']['policy_uuid'] = 'not-a-policy-uuid'; break;
			case 'invalid_logical_uuid': $payload['logical_uuid'] = 'caller-machine-identifier'; break;
			case 'priority': $payload['priority'] = 1; break;
			case 'forged_target': $identity = new OperationIdentity( 1, 'wordpress', 'staff:9', 'rule.draft.create', 1, 'forged-other-policy-target', 'private-original-operation-token' ); break;
			case 'author_type': $payload['author_user_id'] = '9'; break;
			case 'activate':
				$payload['start_mode'] = 'at'; $payload['effective_from'] = '2026-10-24 09:00:00.000000';
				$payload['preconditions']['logical_id'] = 1; $payload['preconditions']['logical_revision'] = 1;
				$payload['preconditions']['version_id'] = 1; $payload['preconditions']['version_revision'] = 1;
				$identity = new OperationIdentity( 1, 'rule.lifecycle.activation.v1', 'rule-activation:' . $payload['version_uuid'], 'rule.activate', 1, $identity->target_key, 'private-original-operation-token' ); break;
			default: self::fail( 'Unknown security case.' );
		}
		$result = $f->service()->{$entrypoint}( $identity, $payload, RequestContext::create() );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertNull( $result->completion );
		self::assertContains( $result->outcome->error->code, [ 'invalid_input', 'unsupported_contract' ] );
		self::assertSame( 0, $f->factory->opens ); self::assertSame( [], $f->factory->sql );
		$this->assert_redacted( $result->outcome->error );
	}

	public static function invalid_original_envelopes(): array {
		$out = [];
		foreach ( [ 'non_array', 'malformed_inner', 'duplicate_inner', 'noncanonical_inner', 'invalid_body_fields', 'inner_overflow', 'outer_overflow', 'extra_outer', 'extra_payload', 'foreign_family', 'future_family', 'product_scope', 'extra_scope', 'other_policy', 'invalid_policy_uuid', 'invalid_logical_uuid', 'priority', 'forged_target', 'author_type', 'activate' ] as $case ) {
			foreach ( [ 'attempt', 'reconcile' ] as $entrypoint ) { $out[$case . '_' . $entrypoint] = [ $case, $entrypoint ]; }
		}
		return $out;
	}

	#[DataProvider( 'catalog_states' )]
	public function test_a_per_policy_grant_cannot_authorize_a_family_catalog_even_when_empty( bool $populated ): void {
		$f = $this->fixture; $payload = $f->payload();
		if ( $populated ) { self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $payload )->outcome->state ); }
		$opens = $f->factory->opens; $sql = $f->factory->sql; $f->catalog_allowed = false;
		$error = $f->service()->capture( $f->identity( $payload, 'refund.catalog.read' ), $payload['scope'], RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code );
		self::assertSame( $opens, $f->factory->opens ); self::assertSame( $sql, $f->factory->sql ); $this->assert_redacted( $error );
	}

	public static function catalog_states(): array { return [ 'empty' => [ false ], 'populated' => [ true ] ]; }

	#[DataProvider( 'untrusted_grants' )]
	public function test_catalog_grant_requires_true_and_fails_closed( mixed $grant ): void {
		$f = $this->fixture; $payload = $f->payload(); $f->catalog_allowed = $grant;
		$error = $f->service()->capture( $f->identity( $payload, 'refund.catalog.read' ), $payload['scope'], RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code );
		self::assertSame( 0, $f->factory->opens ); self::assertSame( [], $f->factory->sql );
	}

	public static function untrusted_grants(): array { return [ 'integer' => [ 1 ], 'string' => [ 'yes' ], 'null' => [ null ] ]; }

	#[DataProvider( 'throwing_grants' )]
	public function test_scope_author_and_binding_exceptions_fail_closed_without_leaking_details( string $grant ): void {
		$f = $this->fixture; $payload = $f->payload(); $f->{ 'on_' . $grant } = static function (): never { throw new \RuntimeException( 'PRIVATE_BODY_FOR_REDACTION' ); };
		$result = $f->service()->attempt( $f->identity( $payload ), $payload, RequestContext::create() );
		self::assertSame( 'not_authorized', $result->outcome->error->code ); self::assertNull( $result->completion );
		self::assertSame( 0, $f->factory->opens ); $this->assert_redacted( $result->outcome->error );
	}

	public static function throwing_grants(): array { return [ [ 'scope' ], [ 'author' ], [ 'binding' ] ]; }

	public function test_catalog_exception_is_a_redacted_refusal_before_storage(): void {
		$f = $this->fixture; $payload = $f->payload();
		$f->on_catalog = static function (): never { throw new \RuntimeException( 'PRIVATE_BODY_FOR_REDACTION' ); };
		$error = $f->service()->capture( $f->identity( $payload, 'refund.catalog.read' ), $payload['scope'], RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code );
		self::assertSame( 0, $f->factory->opens ); $this->assert_redacted( $error );
	}

	public function test_catalog_grant_revocation_at_disclosure_hides_the_read_snapshot(): void {
		$f = $this->fixture; $payload = $f->payload(); self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $payload )->outcome->state );
		$before = $f->rows( 'rule_versions' ); $events = $f->count( 'operation_changes' ); $opens = $f->factory->opens;
		$f->on_catalog = static function () use ( $f ): void { if ( 2 === $f->calls['catalog'] ) { $f->catalog_allowed = false; } };
		$error = $f->service()->capture( $f->identity( $payload, 'refund.catalog.read' ), $payload['scope'], RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'not_authorized', $error->code );
		self::assertSame( $opens + 1, $f->factory->opens ); self::assertSame( $before, $f->rows( 'rule_versions' ) );
		self::assertSame( $events, $f->count( 'operation_changes' ) ); $this->assert_redacted( $error );
	}

	#[DataProvider( 'retained_predecessor_corruption' )]
	public function test_capture_checks_retired_predecessor_content_not_only_current_candidates( string $case ): void {
		$f = $this->fixture; $payload = $f->payload(); self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $payload )->outcome->state );
		self::assertSame( 'accepted', $f->attempt( 'rule.publish', $f->opened(), 'publish-first' )->outcome->state );
		$prior = $f->opened(); $next = $f->payload( [ 'full_text' => 'Second fixture policy' ], version: 2 );
		$next['predecessor_uuid'] = $prior['version_uuid']; $next['preconditions'] = $prior['preconditions'];
		$next['preconditions']['predecessor_id'] = $prior['preconditions']['version_id'];
		$next['preconditions']['predecessor_revision'] = $prior['preconditions']['version_revision'];
		$next['preconditions']['version_id'] = 0; $next['preconditions']['version_revision'] = 0;
		self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $next, 'create-second' )->outcome->state );
		self::assertSame( 'accepted', $f->attempt( 'rule.publish', $f->opened(), 'publish-second' )->outcome->state );
		$row = $f->row( 'rule_versions' ); self::assertSame( 'retired', $row['state'] );
		$bad = json_decode( $row['payload_json'], true, flags: JSON_THROW_ON_ERROR ); $priority = (int) $row['priority'];
		if ( 'malformed' === $case ) { $bad['content_json'] = '{"private_unrecognized_body":"PRIVATE_BODY_FOR_REDACTION"}'; }
		elseif ( 'noncanonical' === $case ) { $bad['content_json'] = ' ' . $bad['content_json']; }
		else { $priority = 7; }
		// Preserve a valid generic C01 hash to isolate the Refund-specific validation.
		$hash = RuleContent::hash( $f->family, $bad, RuleStartMode::from( $row['start_mode'] ), null === $row['effective_from'] ? null : RuleTime::parse( $row['effective_from'] ), null === $row['effective_until'] ? null : RuleTime::parse( $row['effective_until'] ), $priority, null === $row['supersedes_version_id'] ? null : (int) $row['supersedes_version_id'] );
		$db = $f->factory->inspection(); $query = $db->prepare( 'UPDATE ' . $f->table( 'rule_versions' ) . ' SET payload_json=?,content_hash=?,priority=? WHERE id=?' );
		$query->execute( [ $f->family->payload_schema()->encode( $bad ), $hash, $priority, $row['id'] ] );
		$before = $f->rows( 'rule_versions' ); $events = $f->count( 'operation_changes' );
		$error = $f->service()->capture( $f->identity( $payload, 'refund.catalog.read' ), $payload['scope'], RequestContext::create() );
		self::assertInstanceOf( ContractError::class, $error ); self::assertSame( 'temporarily_unavailable', $error->code );
		self::assertSame( $before, $f->rows( 'rule_versions' ) ); self::assertSame( $events, $f->count( 'operation_changes' ) );
		$this->assert_redacted( $error );
	}

	public static function retained_predecessor_corruption(): array { return [ [ 'malformed' ], [ 'noncanonical' ], [ 'priority' ] ]; }

	#[DataProvider( 'cross_family_candidates' )]
	public function test_actual_detached_candidate_of_one_family_cannot_be_consumed_as_the_other( string $state, bool $refund_candidate ): void {
		$refund_profile = \CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyFixture::profile();
		$return_profile = \CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyFixture::profile();
		$content = \CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyFixture::content()->facts();
		if ( $refund_candidate ) {
			$candidate = \CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyFixture::candidate( $state, $content, profile: $refund_profile );
			$consumer = \CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyVersion::class;
			$profile = $return_profile;
		} else {
			$candidate = \CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyFixture::candidate( $state, $content, profile: $return_profile );
			$consumer = \CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyVersion::class;
			$profile = $refund_profile;
		}
		self::assertSame( $content, RefundPolicyContent::from_rule_payload( $candidate->version->payload() )->facts() );
		try { $consumer::from_candidate( $candidate, $profile ); self::fail( 'Identical authored text does not confer another family identity.' ); }
		catch ( \InvalidArgumentException $error ) { self::assertStringNotContainsString( 'PRIVATE_', $error->getMessage() ); }
	}

	public static function cross_family_candidates(): array {
		$out = [];
		foreach ( [ 'draft', 'scheduled', 'published', 'retired' ] as $state ) {
			$out[ $state . '_refund_to_return' ] = [ $state, true ];
			$out[ $state . '_return_to_refund' ] = [ $state, false ];
		}
		return $out;
	}

	public function test_two_independent_families_share_stores_without_sharing_effects_or_reusing_global_uuids(): void {
		$f = $this->fixture;
		$return_family = new \CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily( static fn(): bool => true, static fn(): bool => true, static fn(): bool => true );
		$return_service = new \CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService( $return_family, $f->factory, static fn(): bool => true, new \CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyCatalogUnitReadiness() );
		$refund = $f->payload();
		self::assertSame( 'accepted', $f->attempt( 'rule.draft.create', $refund, 'refund-own-original' )->outcome->state );
		$return = $f->payload( logical: 202, version: 2 );
		$return['family'] = \CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent::FAMILY;
		$return_identity = new OperationIdentity( 1, 'wordpress', 'staff:9', 'rule.draft.create', 1,
			RuleLifecycleCommand::make_target_key( $return['family'], RuleContent::scope_hash( $return_family, $return['scope'] ), $return['logical_uuid'] ), 'return-own-original' );
		self::assertSame( $return['payload'], $refund['payload'] );
		self::assertSame( 'accepted', $return_service->attempt( $return_identity, $return, RequestContext::create() )->outcome->state );
		self::assertSame( 2, $f->count( 'rule_family_guards' ) ); self::assertSame( 2, $f->count( 'logical_rules' ) );
		self::assertSame( 2, $f->count( 'rule_versions' ) ); self::assertSame( 2, $f->count( 'operation_changes' ) );
		$before = $f->rows( 'rule_versions' ); $changes = $f->rows( 'operation_changes' ); $opens = $f->factory->opens;
		$foreign = $f->service()->attempt( $return_identity, $return, RequestContext::create() );
		self::assertSame( 'unsupported_contract', $foreign->outcome->error->code ); self::assertNull( $foreign->completion );
		$foreign = $return_service->attempt( $f->identity( $refund ), $refund, RequestContext::create() );
		self::assertSame( 'unsupported_contract', $foreign->outcome->error->code ); self::assertNull( $foreign->completion );
		self::assertSame( $opens, $f->factory->opens );
		$duplicate = $f->payload( logical: 202, version: 2 ); $duplicate['preconditions']['family_revision'] = 2;
		$collision = $f->attempt( 'rule.draft.create', $duplicate, 'cross-family-uuid-collision' );
		self::assertSame( 'rejected', $collision->outcome->state ); self::assertSame( 'temporarily_unavailable', $collision->outcome->error->code );
		self::assertSame( $before, $f->rows( 'rule_versions' ) ); self::assertSame( $changes, $f->rows( 'operation_changes' ) );
		$refund_snapshot = $f->service()->capture( $f->identity( $refund, 'refund.catalog.read' ), $refund['scope'], RequestContext::create() );
		$return_snapshot = $return_service->capture( new OperationIdentity( 1, 'wordpress', 'staff:9', 'return.catalog.read', 1, 'return-policy:catalog', 'read-return' ), $return['scope'], RequestContext::create() );
		self::assertInstanceOf( RuleSnapshot::class, $refund_snapshot ); self::assertInstanceOf( RuleSnapshot::class, $return_snapshot );
		self::assertCount( 1, $refund_snapshot->versions ); self::assertCount( 1, $return_snapshot->versions );
		self::assertSame( RefundPolicyContent::FAMILY, $refund_snapshot->logicals[0]->family_code );
		self::assertSame( \CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent::FAMILY, $return_snapshot->logicals[0]->family_code );
		self::assertSame( $before, $f->rows( 'rule_versions' ) );
	}

	private function revoke_binding_at( string $phase ): OperationPhaseObserver {
		return new class( $this->fixture, $phase ) implements OperationPhaseObserver {
			public function __construct( private RefundPolicyCatalogFixture $fixture, private string $phase ) {}
			public function observe( string $phase, OperationIdentity $identity ): void { if ( $phase === $this->phase ) { $this->fixture->binding_allowed = false; } }
		};
	}

	private function assert_redacted( ContractError $error ): void {
		$public = json_encode( $error->to_array(), JSON_THROW_ON_ERROR );
		foreach ( [ 'PRIVATE_BODY_FOR_REDACTION', 'PRIVATE_REFUND_CATALOG_REASON', 'private-original-operation-token' ] as $secret ) { self::assertStringNotContainsString( $secret, $public ); }
	}
}
