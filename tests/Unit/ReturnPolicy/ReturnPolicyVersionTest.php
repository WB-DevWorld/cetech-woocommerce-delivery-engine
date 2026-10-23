<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ReturnPolicy;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyVersion;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyProfile;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleMatch;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSchema;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersionReference;
use CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnPolicyVersionTest extends TestCase {
	#[DataProvider('states')]
	public function test_existing_c01_states_and_immutable_identities_are_preserved_without_activation( string $state ): void {
		$candidate = ReturnPolicyFixture::candidate( $state );
		$version = ReturnPolicyVersion::from_candidate( $candidate, ReturnPolicyFixture::profile() );
		$reference = new RuleVersionReference( $candidate );

		self::assertInstanceOf( RuleVersionReference::class, $version->reference() );
		self::assertSame( $reference->facts(), $version->reference()->facts() );
		self::assertSame( $state, $version->metadata()['state'] );
		self::assertSame( $candidate->version->effective_from?->sql(), $version->metadata()['effective_from'] );
		self::assertSame( $candidate->version->effective_until?->sql(), $version->metadata()['effective_until'] );
		self::assertSame( $candidate->version->payload['content_json'], $version->content()->json() );
		self::assertSame( $state, $candidate->version->state->value );
	}

	public static function states(): array {
		return [ 'draft' => [ 'draft' ], 'scheduled' => [ 'scheduled' ], 'published' => [ 'published' ], 'retired' => [ 'retired' ] ];
	}

	public function test_authored_utc_window_author_and_reason_are_reported_exactly_even_for_an_inactive_draft(): void {
		$from = '2031-05-17 12:34:56.123456';
		$until = '2031-05-18 12:34:56.123457';
		$candidate = ReturnPolicyFixture::candidate( 'draft', [], [
			'start_mode' => 'at', 'effective_from' => $from, 'effective_until' => $until,
			'author_user_id' => 37, 'change_reason' => 'Private authored merchant notice',
		] );
		$timezone = date_default_timezone_get();
		try {
			date_default_timezone_set( 'Pacific/Auckland' );
			$version = ReturnPolicyVersion::from_candidate( $candidate, ReturnPolicyFixture::profile() );
			$metadata = $version->metadata();
		} finally {
			date_default_timezone_set( $timezone );
		}
		$expected = ( new RuleVersionReference( $candidate ) )->facts() + [
			'state' => 'draft', 'effective_from' => $from, 'effective_until' => $until,
			'author_user_id' => 37, 'change_reason' => 'Private authored merchant notice',
			'created_at' => $candidate->version->created_at->sql(), 'updated_at' => $candidate->version->updated_at->sql(),
		];
		ksort( $expected );
		ksort( $metadata );
		self::assertSame( $expected, $metadata );
		self::assertSame( $from, $candidate->version->effective_from?->sql() );
		self::assertSame( 'draft', $version->metadata()['state'] );
	}

	public function test_supplied_customer_and_internal_text_is_not_replaced_by_policy_defaults(): void {
		$content = [
			'name' => 'Internal merchant terms — revision β',
			'customer_title' => 'Returns for this declared offer',
			'summary' => "Merchant supplied summary.\nA second line.",
			'full_text' => "Merchant supplied full terms.\r\nNo invented return window or refund value.",
			'exceptions' => 'Merchant supplied exceptions: fragile goods.',
		];
		$candidate = ReturnPolicyFixture::candidate( 'published', $content );
		$version = ReturnPolicyVersion::from_candidate( $candidate, ReturnPolicyFixture::profile() );

		self::assertInstanceOf( ReturnPolicyContent::class, $version->content() );
		self::assertSame( ReturnPolicyContent::from_array( $content )->facts(), $version->content()->facts() );
		self::assertSame( $candidate->version->payload['content_json'], $version->content()->json() );
		self::assertSame( $candidate->version->payload, $version->content()->to_rule_payload() );
	}

	public function test_equal_content_does_not_erase_distinct_authored_actor_metadata(): void {
		$first = ReturnPolicyVersion::from_candidate( ReturnPolicyFixture::candidate( 'published', [], [ 'author_user_id' => 21, 'change_reason' => 'First supplied reason' ] ), ReturnPolicyFixture::profile() );
		$second = ReturnPolicyVersion::from_candidate( ReturnPolicyFixture::candidate( 'published', [], [ 'author_user_id' => 22, 'change_reason' => 'Second supplied reason' ] ), ReturnPolicyFixture::profile() );

		self::assertSame( $first->content()->json(), $second->content()->json() );
		self::assertSame( 21, $first->metadata()['author_user_id'] );
		self::assertSame( 22, $second->metadata()['author_user_id'] );
		self::assertSame( 'First supplied reason', $first->metadata()['change_reason'] );
		self::assertSame( 'Second supplied reason', $second->metadata()['change_reason'] );
		self::assertArrayNotHasKey( 'audit_appended', $first->metadata() );
		self::assertArrayNotHasKey( 'authority_granted', $first->metadata() );
	}

	public function test_returned_metadata_and_content_facts_are_detached_from_the_private_version(): void {
		$version = ReturnPolicyVersion::from_candidate( ReturnPolicyFixture::candidate(), ReturnPolicyFixture::profile() );
		$original = $version->metadata();
		$metadata = $version->metadata();
		$metadata['author_user_id'] = 999;
		$metadata['content_hash'] = str_repeat( '0', 64 );
		$facts = $version->content()->facts();
		$facts['summary'] = 'Changed outside the captured version';

		self::assertSame( $original, $version->metadata() );
		self::assertNotSame( $facts, $version->content()->facts() );
		self::assertSame( [], get_object_vars( $version ) );
	}

	#[DataProvider('foreign_families')]
	public function test_legitimate_c01_candidates_of_other_families_cannot_be_relabelled_as_return_policies( string $family ): void {
		$candidate = ReturnPolicyFixture::candidate( 'published', [], [], [], ReturnPolicyFixture::profile( $family ) );
		self::assertInstanceOf( RuleCandidate::class, $candidate );
		self::assertSame( $family, $candidate->logical->family_code );
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyVersion::from_candidate( $candidate, ReturnPolicyFixture::profile() );
	}

	#[DataProvider('candidate_modes')]
	public function test_same_numeric_ids_cannot_pair_return_scope_with_a_foreign_c01_content_identity( bool $hypothetical ): void {
		$return = ReturnPolicyFixture::candidate();
		$foreign_profile = ReturnPolicyFixture::profile( 'refund_policy_v1' );
		$foreign = ReturnPolicyFixture::candidate( 'published', [], [], [], $foreign_profile );
		$version = $hypothetical ? RuleVersion::hypothetical( $foreign->version->row(), $foreign->logical, $foreign_profile ) : $foreign->version;
		// Both records passed their genuine C01 codecs. The generic joined value checks only id and site.
		$mixed = new RuleCandidate( $return->logical, $version );
		self::assertSame( $return->logical->id, $foreign->logical->id );
		self::assertNotSame( $return->version->content_hash, $foreign->version->content_hash );
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyVersion::from_candidate( $mixed, ReturnPolicyFixture::profile() );
	}

	public static function candidate_modes(): array {
		return [ 'ordinary row' => [ false ], 'explicit hypothetical row' => [ true ] ];
	}

	public function test_explicit_c01_hypothetical_identity_stays_labelled_hypothetical(): void {
		$profile = ReturnPolicyFixture::profile();
		$base = ReturnPolicyFixture::candidate();
		$candidate = new RuleCandidate( $base->logical, RuleVersion::hypothetical( $base->version->row(), $base->logical, $profile ) );
		$version = ReturnPolicyVersion::from_candidate( $candidate, $profile );
		self::assertTrue( $version->reference()->hypothetical );
		self::assertTrue( $version->metadata()['hypothetical'] );
		self::assertSame( $base->version->payload['content_json'], $version->content()->json() );
		self::assertFalse( method_exists( $version, 'admitted' ) );
	}

	#[DataProvider('changed_original_declarations')]
	public function test_supplied_original_profile_must_match_policy_hash_scope_and_payload_contracts( string $aspect ): void {
		$candidate = ReturnPolicyFixture::candidate();
		$profile = self::read_profile( $aspect );
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyVersion::from_candidate( $candidate, $profile );
	}

	public static function changed_original_declarations(): array {
		return [ 'different policy hash' => [ 'policy' ], 'different scope schema' => [ 'scope' ], 'different payload schema' => [ 'payload' ] ];
	}

	public function test_read_validation_does_not_invoke_original_profiles_write_authorizers(): void {
		$candidate = ReturnPolicyFixture::candidate();
		// This equivalent original declaration throws if either write authorizer is invoked.
		$version = ReturnPolicyVersion::from_candidate( $candidate, self::read_profile( 'unchanged' ) );
		self::assertSame( $candidate->version->payload['content_json'], $version->content()->json() );
		self::assertSame( 'published', $version->metadata()['state'] );
	}

	public static function foreign_families(): array {
		return [ 'refund policy' => [ 'refund_policy_v1' ], 'other policy' => [ 'foreign_policy_v1' ] ];
	}

	public function test_future_format_is_refused_by_existing_c01_validation_before_a_candidate_can_reach_the_wrapper(): void {
		$profile = ReturnPolicyFixture::profile( ReturnPolicyContent::FAMILY, 2 );
		$rows = ReturnPolicyFixture::rows( 'published', [], [], [], $profile );
		self::assertSame( 2, $rows['logical']['scope_format'] );
		self::assertSame( 2, $rows['version']['payload_format'] );
		// The real C01 registry accepts only format1. Do not bypass it to claim unreachable wrapper coverage.
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyFixture::candidate( 'published', [], [], [], $profile );
	}

	#[DataProvider('invalid_inner_content')]
	public function test_authentic_c01_hashes_do_not_make_invalid_inner_policy_content_readable( string $inner ): void {
		$candidate = ReturnPolicyFixture::candidate( 'published', [], [ 'payload_json' => json_encode( [ 'content_json' => $inner ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ] );
		self::assertInstanceOf( RuleCandidate::class, $candidate );
		self::assertSame( $inner, $candidate->version->payload['content_json'] );
		$this->expectException( \InvalidArgumentException::class );
		ReturnPolicyVersion::from_candidate( $candidate, ReturnPolicyFixture::profile() );
	}

	public static function invalid_inner_content(): array {
		return [
			'truncated JSON' => [ '{' ],
			'array instead of object' => [ '[]' ],
			'missing authored fields' => [ '{"name":"Only a name"}' ],
			'noncanonical JSON' => [ '{ "customer_title":"Title","exceptions":"","full_text":"Full text","name":"Name","summary":"Summary"}' ],
			'unknown refund field' => [ '{"customer_title":"Title","exceptions":"","full_text":"Full text","name":"Name","refund_percent":100,"summary":"Summary"}' ],
		];
	}

	#[DataProvider('implicit_serializers')]
	public function test_private_version_has_no_implicit_serialization_surface( string $operation ): void {
		$version = ReturnPolicyVersion::from_candidate( ReturnPolicyFixture::candidate(), ReturnPolicyFixture::profile() );
		$this->expectException( \LogicException::class );
		match ( $operation ) {
			'json' => json_encode( $version, JSON_THROW_ON_ERROR ),
			'php' => serialize( $version ),
			'unserialize' => $version->__unserialize( [ 'content' => 'unvalidated private policy' ] ),
		};
	}

	public static function implicit_serializers(): array {
		return [ 'JSON' => [ 'json' ], 'PHP serialization' => [ 'php' ], 'unvalidated restoration' => [ 'unserialize' ] ];
	}

	public function test_debug_output_redacts_content_identity_and_authored_reason(): void {
		$candidate = ReturnPolicyFixture::candidate( 'published', [ 'full_text' => 'PRIVATE RETURN POLICY TEXT' ], [ 'change_reason' => 'PRIVATE AUTHOR REASON' ] );
		$version = ReturnPolicyVersion::from_candidate( $candidate, ReturnPolicyFixture::profile() );
		ob_start();
		try { var_dump( $version ); $debug = (string) ob_get_contents(); }
		finally { ob_end_clean(); }
		self::assertStringContainsString( 'redacted', $debug );
		foreach ( [ 'PRIVATE RETURN POLICY TEXT', 'PRIVATE AUTHOR REASON', $candidate->logical->logical_uuid, $candidate->version->version_uuid, $candidate->version->content_hash ] as $private ) {
			self::assertStringNotContainsString( $private, $debug );
		}
	}

	public function test_wrapper_is_final_readonly_and_supplies_no_effectivity_refund_or_writer_authority(): void {
		$version = ReturnPolicyVersion::from_candidate( ReturnPolicyFixture::candidate(), ReturnPolicyFixture::profile() );
		$type = new \ReflectionClass( $version ); // Inspection only; construction always used validated C01 rows.
		self::assertTrue( $type->isFinal() );
		self::assertTrue( $type->isReadOnly() );
		foreach ( [ 'eligible_at', 'effective_at', 'admitted', 'refund', 'can_refund', 'save', 'publish', 'activate', 'persist', 'append_audit' ] as $method ) {
			self::assertFalse( method_exists( $version, $method ), $method );
		}
		$this->expectException( \Error::class );
		$version->unauthored_policy = 'cannot attach mutable policy defaults';
	}
	private static function read_profile( string $aspect ): RuleFamilyProfile {
		return new readonly class( ReturnPolicyFixture::profile(), $aspect ) implements RuleFamilyProfile {
			public function __construct( private RuleFamilyProfile $original, private string $aspect ) {}
			public function family(): string { return $this->original->family(); }
			public function version(): int { return $this->original->version(); }
			public function policy_hash(): string { return 'policy' === $this->aspect ? hash( 'sha256', 'another original policy' ) : $this->original->policy_hash(); }
			public function scope_schema(): RuleSchema { return 'scope' === $this->aspect ? new RuleSchema( [ 'different_target' => 'positive_int' ] ) : $this->original->scope_schema(); }
			public function payload_schema(): RuleSchema { return 'payload' === $this->aspect ? new RuleSchema( [ 'content_json' => [ 'string' => 8192 ] ] ) : $this->original->payload_schema(); }
			public function subject_schema(): RuleSchema { return $this->original->subject_schema(); }
			public function authorize( OperationIdentity $identity, array $scope ): bool { throw new \LogicException( 'A content read cannot invoke write authority.' ); }
			public function authorize_author( int $site_id, int $author_user_id, array $scope ): bool { throw new \LogicException( 'A content read cannot invoke author authority.' ); }
			public function match( array $scope, array $subject ): RuleMatch { return $this->original->match( $scope, $subject ); }
			public function overlaps( array $left_scope, array $right_scope ): ?bool { return $this->original->overlaps( $left_scope, $right_scope ); }
			public function precedence(): array { return $this->original->precedence(); }
			public function equal_rank_policy(): string { return $this->original->equal_rank_policy(); }
			public function unavailable_reason(): string { return $this->original->unavailable_reason(); }
		};
	}

}
