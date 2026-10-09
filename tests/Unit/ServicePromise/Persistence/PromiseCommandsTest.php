<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Persistence;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseAssignmentService, PromiseLifecycleOperationProfile, PromiseVersionLifecycleService, PromiseVersionReadService};
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRefusal};
use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePermissionGrant, PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseVersionCommand};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromiseCommandsTest extends TestCase {
	public function test_body_remains_immutable_and_retry_intent_preserves_original_preconditions(): void {
		$binding = PromiseSiteBinding::bind( 7, 'site-1' ); $data = self::payload(); $identity = self::identity( $binding, 'promise.version.create' );
		$command = PromiseVersionCommand::from_array( $identity, $binding, $data ); $data['reason'] = 'changed'; self::assertSame( 'Private synthetic reason', $command->private_facts()['reason'] );
		self::assertSame( $command->intent()->fingerprint(), PromiseVersionCommand::from_array( $identity, $binding, $command->private_facts() )->intent()->fingerprint() );
		$data = $command->private_facts(); $data['preconditions']['object_revision'] = 4;
		self::assertNotSame( $command->intent()->fingerprint(), PromiseVersionCommand::from_array( $identity, $binding, $data )->intent()->fingerprint() );
	}
	#[DataProvider( 'invalid_payloads' )]
	public function test_closed_command_refuses_body_identity_and_authority_substitution( string $case ): void {
		$binding = PromiseSiteBinding::bind( 7, 'site-1' ); $data = self::payload(); $identity = self::identity( $binding, 'promise.version.create' );
		switch ( $case ) {
			case 'foreign_site': $binding = PromiseSiteBinding::bind( 7, 'other-site' ); $identity = self::identity( $binding, 'promise.version.create' ); break;
			case 'native_site': $identity = new OperationIdentity( 8, 'manager', 'user:11', 'promise.version.create', 1, $identity->target_key, 'original' ); break;
			case 'digest': $data['body_digest'] = str_repeat( 'f', 64 ); break;
			case 'logical_id': $data['logical_id'] = 'other'; $identity = self::identity( $binding, 'promise.version.create', 'other' ); break;
			case 'domain_version': $data['domain_version'] = 2; break;
			case 'body_extra': $facts = self::calendar()->private_facts(); $facts['country'] = 'GH'; $data['body_json'] = json_encode( $facts, JSON_THROW_ON_ERROR ); break;
			case 'revision_string': $data['preconditions']['object_revision'] = '0'; break;
			case 'missing_zero': unset( $data['preconditions']['object_revision'] ); break;
			case 'scheduler_wrong_phase': $data['scheduled_author_user_id'] = 11; break;
			case 'unknown_phase': $identity = self::identity( $binding, 'promise.version.import' ); break;
			case 'extra': $data['price'] = 1; break;
			case 'invalid_uuid': $data['version_uuid'] = 'original-version'; break;
		}
		$this->expectException( \InvalidArgumentException::class ); PromiseVersionCommand::from_array( $identity, $binding, $data );
	}
	public static function invalid_payloads(): array { return array_map( static fn( $v ) => [ $v ], [ 'foreign_site','native_site','digest','logical_id','domain_version','body_extra','revision_string','missing_zero','scheduler_wrong_phase','unknown_phase','extra','invalid_uuid' ] ); }
	public function test_current_permission_is_captured_once_and_profile_never_calls_host_under_owner(): void {
		$b = PromiseSiteBinding::bind( 7, 'site-1' ); $identity = self::identity( $b, 'promise.version.create' ); $command = PromiseVersionCommand::from_array( $identity, $b, self::payload() );
		$host = $this->createMock( PromisePersistenceAuthorizer::class ); $host->expects( self::once() )->method( 'authorize' )->willReturn( true ); $host->expects( self::never() )->method( 'authorize_author' );
		$profile = PromiseLifecycleOperationProfile::for_command( $command, PromisePermissionGrant::capture( $command, $host ) );
		for ( $i = 0; $i < 8; ++$i ) { self::assertTrue( $profile->authorize( $identity ) ); }
		self::assertFalse( $profile->authorize( self::identity( $b, 'promise.version.create' ) ) );
	}
	public function test_activation_current_actor_and_original_scheduler_are_distinct_preflight_grants(): void {
		$b = PromiseSiteBinding::bind( 7, 'site-1' ); $data = self::payload(); $data['body_json'] = null; $data['preconditions'] = [ 'object_revision' => 4, 'version_revision' => 3, 'published_version_id' => 0 ]; $data['scheduled_author_user_id'] = 22; $data['author_user_id'] = 33;
		$identity = self::identity( $b, 'promise.version.activate' ); $command = PromiseVersionCommand::from_array( $identity, $b, $data );
		$host = $this->createMock( PromisePersistenceAuthorizer::class ); $host->expects( self::once() )->method( 'authorize' )->with( $identity, $b, [ 'kind' => 'global', 'target_id' => 0 ], 33 )->willReturn( true ); $host->expects( self::once() )->method( 'authorize_author' )->with( $b, 22, [ 'kind' => 'global', 'target_id' => 0 ] )->willReturn( false );
		$grant = PromisePermissionGrant::capture( $command, $host ); self::assertTrue( $grant->permits( $identity, $command ) ); self::assertFalse( $grant->original_author_allowed() );
	}
	public function test_unavailable_scheduler_snapshot_does_not_prevent_original_receipt_lookup(): void {
		$b = PromiseSiteBinding::bind( 7, 'site-1' ); $data = self::payload(); $data['body_json'] = null; $data['preconditions'] = [ 'object_revision' => 4, 'version_revision' => 3, 'published_version_id' => 0 ]; $data['scheduled_author_user_id'] = 22;
		$identity = self::identity( $b, 'promise.version.activate' ); $command = PromiseVersionCommand::from_array( $identity, $b, $data );
		$host = $this->createMock( PromisePersistenceAuthorizer::class ); $host->method( 'authorize' )->willReturn( true ); $host->method( 'authorize_author' )->willThrowException( new \RuntimeException( 'Unavailable request-local scheduler decision.' ) );
		$grant = PromisePermissionGrant::capture( $command, $host ); $profile = PromiseLifecycleOperationProfile::for_command( $command, $grant ); self::assertTrue( $profile->authorize( $identity ) ); self::assertSame( $command, $profile->validate_command( $identity, $command ) ); self::assertFalse( $grant->original_author_allowed() );
	}

	public function test_denied_writes_and_reads_open_no_storage(): void {
		$b = PromiseSiteBinding::bind( 7, 'site-1' ); $host = $this->createMock( PromisePersistenceAuthorizer::class ); $host->method( 'authorize' )->willReturn( false ); $factory = $this->createMock( OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
		$service = new PromiseVersionLifecycleService( $b, $factory, $host ); self::assertSame( 'not_authorized', $service->attempt( self::identity( $b, 'promise.version.create' ), self::payload(), RequestContext::create() )->outcome->error->code );
		$assignment = [ 'key' => self::key(), 'mode' => 'disabled', 'policy_reference' => null, 'expected_revision' => 0, 'expected_generation' => 0, 'author_user_id' => 11, 'reason' => 'Private reason' ]; $identity = new OperationIdentity( 7, 'manager', 'user:11', PromiseAssignmentCommand::OPERATION, 1, PromiseAssignmentCommand::target_key( $b, self::key() ), 'original' );
		self::assertSame( 'not_authorized', ( new PromiseAssignmentService( $b, $factory, $host ) )->attempt( $identity, $assignment, RequestContext::create() )->outcome->error->code );
		$this->expectException( OperationRefusal::class ); ( new PromiseVersionReadService( $b, $factory, $host ) )->load_calendar_versions( new OperationIdentity( 7, 'manager', 'user:11', 'promise.versions.read', 1, 'promise-versions:site-1', 'read' ), [ self::calendar()->reference() ] );
	}
	#[DataProvider( 'serialization_methods' )]
	public function test_commands_and_grants_cannot_be_implicitly_disclosed( string $method ): void {
		$b = PromiseSiteBinding::bind( 7, 'site-1' ); $command = PromiseVersionCommand::from_array( self::identity( $b, 'promise.version.create' ), $b, self::payload() );
		$this->expectException( \LogicException::class ); 'serialize' === $method ? serialize( $command ) : json_encode( $command, JSON_THROW_ON_ERROR );
	}
	public static function serialization_methods(): array { return [ [ 'serialize' ], [ 'json' ] ]; }
	public static function calendar(): BusinessCalendarVersion { return BusinessCalendarVersion::from_array( [ 'format_version'=>1,'site_id'=>'site-1','calendar_id'=>'picking','version'=>1,'timezone'=>'Africa/Accra','tzdata_version'=>'2026b','weekly_openings'=>array_fill_keys( ['mon','tue','wed','thu','fri','sat','sun'], [] ),'closed_dates'=>[],'exception_openings'=>[],'sources'=>[] ] ); }
	public static function payload(): array { $c = self::calendar(); return [ 'kind'=>'calendar','logical_id'=>'picking','domain_version'=>1,'version_uuid'=>'11111111-1111-4111-8111-111111111111','body_digest'=>$c->digest(),'scope'=>['kind'=>'global','target_id'=>0],'body_json'=>$c->to_private_json(),'declared_from'=>'2026-10-01 00:00:00.000000','declared_until'=>null,'author_user_id'=>11,'reason'=>'Private synthetic reason','scheduled_author_user_id'=>null,'preconditions'=>['object_revision'=>0,'version_revision'=>0,'published_version_id'=>0] ]; }
	public static function identity( PromiseSiteBinding $b, string $op, string $id = 'picking' ): OperationIdentity { return new OperationIdentity( 7, 'manager', 'user:11', $op, 1, PromiseVersionCommand::target_key( $b, 'calendar', $id ), 'original' ); }
	private static function key(): array { return ['scope_kind'=>'global','scope_id'=>0,'service_kind'=>'built_in','service_code'=>'standard','endpoint'=>'doorstep','endpoint_kind'=>'doorstep']; }
}
