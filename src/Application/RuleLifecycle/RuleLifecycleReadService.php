<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RuleLifecycle;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleDecision;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyProfile;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleEvaluator;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleRecordCodec;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRuleLifecycleRepository;

/** Internal authorized reads. No reservation, completion, publisher or durable trace. */
final class RuleLifecycleReadService {

	public function __construct(
		private RuleFamilyRegistry $families,
		private OperationConnectionFactory $connections,
		private ?OperationReadiness $readiness = null
	) {}

	public function profile( string $family ): RuleFamilyProfile { return $this->families->get( $family ); }

	/** The supplied scope must be resolved by the trusted host, not treated as a grant. */
	public function capture( OperationIdentity $identity, string $family, array $authorization_scope, RequestContext $request ): RuleSnapshot|ContractError {
		$authorized = $this->authorize( $identity, $family, $authorization_scope, $request );
		if ( $authorized instanceof ContractError ) { return $authorized; }
		[ $profile, $scope ] = $authorized;
		$result = $this->read( $identity, $request, function ( WpdbRuleLifecycleRepository $repository ) use ( $identity, $profile, $request ): RuleSnapshot|ContractError {
			$data = $repository->snapshot_family( $profile->family(), 1001 );
			$guard = null === $data['family'] ? null : RuleFamilyGuard::from_row( $data['family'], $profile );
			if ( null !== $guard ) {
				foreach ( $data['logicals'] as $row ) {
					$logical = LogicalRule::from_row( $row, $guard, $profile );
					if ( ! $this->allowed( $profile, $identity, $logical->scope() ) ) { return self::denied( $request ); }
				}
			}
			return RuleSnapshot::from_rows( $profile, $data['family'], $data['logicals'], $data['versions'], $data['complete'] );
		} );
		if ( $result instanceof ContractError ) { return $result; }
		foreach ( $result->logicals as $logical ) {
			if ( ! $this->allowed( $profile, $identity, $logical->scope() ) ) { return self::denied( $request ); }
		}
		return $this->allowed( $profile, $identity, $scope ) ? $result : self::denied( $request );
	}

	public function decision( OperationIdentity $identity, string $family, array $authorization_scope, array $subject, RuleTime $at, RequestContext $request ): RuleDecision|ContractError {
		try { $profile = $this->profile( $family ); $subject = $profile->subject_schema()->validate( $subject ); }
		catch ( \Throwable ) { return self::invalid( $request ); }
		if ( ! $this->allowed( $profile, $identity, $subject ) ) { return self::denied( $request ); }
		$snapshot = $this->capture( $identity, $family, $authorization_scope, $request );
		if ( $snapshot instanceof ContractError ) { return $snapshot; }
		if ( ! $this->allowed( $profile, $identity, $subject ) ) { return self::denied( $request ); }
		try {
			$decision = ( new RuleLifecycleEvaluator() )->evaluate( $snapshot->profile, $snapshot->candidates(), $subject, $at, $snapshot->guard?->revision ?? 0, $snapshot->complete );
			return $this->allowed( $profile, $identity, $subject ) ? $decision : self::denied( $request );
		} catch ( \Throwable ) { return self::unavailable( $request ); }
	}

	/** Explicit administrative projection, with another current grant check at disclosure. */
	public function admin_decision( OperationIdentity $identity, string $family, array $authorization_scope, array $subject, RuleTime $at, RequestContext $request ): array|ContractError {
		$decision = $this->decision( $identity, $family, $authorization_scope, $subject, $at, $request );
		if ( $decision instanceof ContractError ) { return $decision; }
		try { return $decision->admin( $this->profile( $family ), $identity ); }
		catch ( \Throwable ) { return self::denied( $request ); }
	}

	/** Private original command input for the activation host; never a public/log serializer. */
	public function activation_envelope( OperationIdentity $identity, string $family, string $version_uuid, array $authorization_scope, RequestContext $request ): array|ContractError {
		if ( ! self::activation_actor( $identity, $version_uuid ) ) { return self::denied( $request ); }
		$authorized = $this->authorize( $identity, $family, $authorization_scope, $request );
		if ( $authorized instanceof ContractError ) { return $authorized; }
		[ $profile, $scope ] = $authorized;
		$result = $this->read( $identity, $request, function ( WpdbRuleLifecycleRepository $repository ) use ( $identity, $profile, $scope, $version_uuid ): array {
			$data = $repository->snapshot_version( RuleRecordCodec::uuid( $version_uuid ) );
			if ( null === $data ) { throw new \RuntimeException( 'Rule activation input is unavailable.' ); }
			return $this->envelope( $identity, $profile, $scope, $data, $version_uuid );
		} );
		if ( $result instanceof ContractError ) { return $result; }
		return $this->allowed( $profile, $identity, $scope ) ? $result : self::denied( $request );
	}

	/** A page contains candidates and original envelopes, never successful activation facts. */
	public function scan_due( OperationIdentity $identity, string $family, array $authorization_scope, RuleTime $at, RequestContext $request, ?int $ceiling = null, ?RuleTime $cursor_at = null, ?int $cursor_id = null ): array {
		$observed = $ceiling;
		$error_page = static fn ( ContractError $error ): array => [ 'observed_ceiling' => $observed, 'cursor_at' => $cursor_at?->sql(), 'cursor_id' => $cursor_id, 'items' => [], 'complete' => false, 'error' => $error ];
		if ( null !== $ceiling && $ceiling < 0 || ( null === $cursor_at ) !== ( null === $cursor_id ) || null !== $cursor_id && ( $cursor_id < 1 || null !== $ceiling && $cursor_id > $ceiling ) ) { return $error_page( self::invalid( $request ) ); }
		if ( 'rule.lifecycle.activation.v1' !== $identity->authority || 'rule.activate' !== $identity->operation || 'rule-activation:scan' !== $identity->principal ) { return $error_page( self::denied( $request ) ); }
		$authorized = $this->authorize( $identity, $family, $authorization_scope, $request );
		if ( $authorized instanceof ContractError ) { return $error_page( $authorized ); }
		[ $profile, $scope ] = $authorized;
		$result = $this->read( $identity, $request, function ( WpdbRuleLifecycleRepository $repository ) use ( $identity, $profile, $scope, $at, $request, &$observed, $cursor_at, $cursor_id ): array {
			$family_row = $repository->find_family( $profile->family() );
			if ( null === $family_row ) { $observed ??= 0; return [ 'observed_ceiling' => $observed, 'cursor_at' => $cursor_at?->sql(), 'cursor_id' => $cursor_id, 'items' => [], 'complete' => true, 'error' => null ]; }
			$guard = RuleFamilyGuard::from_row( $family_row, $profile );
			$observed ??= $repository->max_version_id( $guard->id );
			$rows = $repository->due_page( $guard->id, $observed, $cursor_at?->sql(), $cursor_id, $at, 25 );
			if ( ! array_is_list( $rows ) || count( $rows ) > 25 ) { throw new \RuntimeException( 'Rule due page is unavailable.' ); }
			$items = [];
			$last_at = $cursor_at;
			$last_id = $cursor_id;
			foreach ( $rows as $row ) {
				$id = RuleRecordCodec::integer( $row['id'] );
				$from = RuleRecordCodec::time( $row['effective_from'] );
				if ( $id > $observed || $from->compare( $at ) > 0 || null !== $last_at && ( $from->compare( $last_at ) < 0 || $from->equals( $last_at ) && $id <= $last_id ) ) { throw new \RuntimeException( 'Rule due cursor is invalid.' ); }
				$last_at = $from;
				$last_id = $id;
				try {
					$uuid = RuleRecordCodec::uuid( $row['version_uuid'] );
					$data = $repository->snapshot_version( $uuid );
					if ( null === $data || RuleRecordCodec::integer( $data['version']['id'] ) !== $id ) { throw new \RuntimeException(); }
					$logical = LogicalRule::from_row( $data['logical'], RuleFamilyGuard::from_row( $data['family'], $profile ), $profile );
					if ( ! $this->allowed( $profile, $identity, $logical->scope() ) ) { throw new \RuntimeException( 'Rule activation is not authorized.' ); }
					$original = $this->envelope( $identity, $profile, $logical->scope(), $data, $uuid );
					$items[] = [ 'candidate_id' => $id, 'state' => 'candidate', 'original' => $original, 'error' => null ];
				} catch ( \Throwable ) {
					$items[] = [ 'candidate_id' => $id, 'state' => 'unavailable', 'original' => null, 'error' => self::unavailable( $request->child() ) ];
				}
			}
			return [ 'observed_ceiling' => $observed, 'cursor_at' => $last_at?->sql(), 'cursor_id' => $last_id, 'items' => $items, 'complete' => count( $rows ) < 25, 'error' => null ];
		} );
		if ( $result instanceof ContractError ) {
			return [ 'observed_ceiling' => $observed, 'cursor_at' => $cursor_at?->sql(), 'cursor_id' => $cursor_id, 'items' => [], 'complete' => false, 'error' => $result ];
		}
		if ( ! $this->allowed( $profile, $identity, $scope ) ) { return [ 'observed_ceiling' => $observed, 'cursor_at' => $cursor_at?->sql(), 'cursor_id' => $cursor_id, 'items' => [], 'complete' => false, 'error' => self::denied( $request ) ]; }
		foreach ( $result['items'] as &$item ) {
			if ( null !== $item['original'] && ( ! $this->allowed( $profile, $identity, $item['original']['payload']['scope'] ) || ! $this->allowed( $profile, $item['original']['identity'], $item['original']['payload']['scope'] ) ) ) {
				$item['state'] = 'unavailable'; $item['original'] = null; $item['error'] = self::denied( $request->child() );
			}
		}
		unset( $item );
		return $result;
	}

	private function envelope( OperationIdentity $actor, RuleFamilyProfile $profile, array $scope, array $data, string $version_uuid ): array {
		$guard = RuleFamilyGuard::from_row( $data['family'], $profile );
		$logical = LogicalRule::from_row( $data['logical'], $guard, $profile );
		if ( $logical->site_id !== $actor->site_id || ! hash_equals( $logical->scope_hash, RuleContent::scope_hash( $profile, $scope ) ) || ! $this->allowed( $profile, $actor, $logical->scope() ) ) { throw new \RuntimeException( 'Rule activation is not authorized.' ); }
		$version = RuleVersion::from_row( $data['version'], $logical, $profile );
		$predecessor = null === $data['predecessor'] ? null : RuleVersion::from_row( $data['predecessor'], $logical, $profile );
		if ( $version->version_uuid !== $version_uuid ) { throw new \RuntimeException( 'Rule activation is not authorized.' ); }
		$identity = RuleLifecycleCommand::activation_identity( $actor->site_id, $profile, $logical, $version );
		if ( ! $this->allowed( $profile, $identity, $logical->scope() ) ) { throw new \RuntimeException( 'Rule activation is not authorized.' ); }
		return [ 'identity' => $identity, 'payload' => RuleLifecycleCommand::activation_payload( $profile, $logical, $version, $predecessor ) ];
	}

	private function read( OperationIdentity $identity, RequestContext $request, callable $load ): mixed {
		$session = null;
		$owned = false;
		try {
			$session = $this->connections->open();
			if ( $session->site_id() !== $identity->site_id ) { return self::denied( $request ); }
			if ( $session->in_transaction() || $session->is_retired() ) { throw new \RuntimeException( 'Rule read owner is unavailable.' ); }
			if ( null !== $this->readiness ) { $this->readiness->assert_ready( $session ); }
			else { ( new RuleLifecycleReadiness( $session, $this->families ) )->assert_ready(); }
			if ( ! $session->begin() ) { throw new \RuntimeException( 'Rule read unit is unavailable.' ); }
			$owned = true;
			if ( ! $session->validate_tables( RuleLifecycleSchema::tables( $session->table_prefix() ) ) ) { throw new \RuntimeException( 'Rule read unit is unavailable.' ); }
			$result = $load( new WpdbRuleLifecycleRepository( $session ) );
			if ( ! $session->rollback() || ! $session->retire() || ! $session->is_retired() ) { throw new \RuntimeException( 'Rule read outcome is unavailable.' ); }
			return $result;
		} catch ( \Throwable ) { return self::unavailable( $request ); }
		finally {
			if ( $session instanceof OperationSession ) {
				try { if ( $owned && $session->in_transaction() ) { $session->rollback(); } } catch ( \Throwable ) {}
				try { $session->retire(); } catch ( \Throwable ) {}
			}
		}
	}

	private function authorize( OperationIdentity $identity, string $family, array $scope, RequestContext $request ): array|ContractError {
		try { $profile = $this->families->get( $family ); }
		catch ( \Throwable ) { return new ContractError( 'unsupported_contract', $request, 'contact_support' ); }
		try { $scope = $profile->scope_schema()->validate( $scope ); }
		catch ( \Throwable ) { return self::invalid( $request ); }
		return $this->allowed( $profile, $identity, $scope ) ? [ $profile, $scope ] : self::denied( $request );
	}

	private function allowed( RuleFamilyProfile $profile, OperationIdentity $identity, array $scope ): bool {
		try { return $this->families->get( $profile->family() ) === $profile && true === $profile->authorize( $identity, $scope ); }
		catch ( \Throwable ) { return false; }
	}

	private static function activation_actor( OperationIdentity $identity, string $version_uuid ): bool {
		return RequestContext::is_valid_identifier( $version_uuid ) && 'rule.lifecycle.activation.v1' === $identity->authority && 'rule.activate' === $identity->operation && 1 === $identity->operation_version && 'rule-activation:' . $version_uuid === $identity->principal;
	}
	private static function denied( RequestContext $request ): ContractError { return new ContractError( 'not_authorized', $request, 'contact_support' ); }
	private static function invalid( RequestContext $request ): ContractError { return new ContractError( 'invalid_input', $request, 'reload_and_submit', [], [ [ 'field' => 'semantic_payload', 'code' => 'unsupported_value' ] ] ); }
	private static function unavailable( RequestContext $request ): ContractError { return new ContractError( 'temporarily_unavailable', $request, 'contact_support' ); }
}
