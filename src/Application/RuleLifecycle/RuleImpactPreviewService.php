<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleClock;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyProfile;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleImpactPreview;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleEvaluator;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** One bounded coherent snapshot, evaluated twice without a database mutation. */
final class RuleImpactPreviewService {

	public function __construct(
		private RuleLifecycleReadService $reader,
		private ?RuleLifecycleEvaluator $evaluator = null,
		private ?RuleClock $clock = null
	) {}

	public function preview( OperationIdentity $identity, string $family, array $authorization_scope, iterable $subjects, ?RuleCandidate $proposed, RuleTime $at, RequestContext $request ): RuleImpactPreview|ContractError {
		try {
			$profile = $this->reader->profile( $family );
			$scope = $profile->scope_schema()->validate( $authorization_scope );
		} catch ( \Throwable ) { return new ContractError( 'unsupported_contract', $request, 'contact_support' ); }
		if ( ! self::allowed( $profile, $identity, $scope ) ) { return self::denied( $request ); }
		$validated = [];
		try {
			foreach ( $subjects as $subject ) {
				// Observe only one excess subject, even when the input never ends.
				if ( count( $validated ) >= 100 || ! is_array( $subject ) ) { return self::invalid( $request ); }
				$subject = $profile->subject_schema()->validate( $subject );
				if ( ! self::allowed( $profile, $identity, $subject ) ) { return self::denied( $request ); }
				$validated[] = $subject;
			}
		} catch ( \Throwable ) { return self::invalid( $request ); }
		$snapshot = $this->reader->capture( $identity, $family, $scope, $request );
		if ( $snapshot instanceof ContractError ) { return $snapshot; }
		try {
			if ( null !== $proposed && ! $this->valid_overlay( $snapshot, $proposed, $identity ) ) { return self::invalid( $request ); }
			$baseline = $snapshot->candidates();
			$overlay = $this->overlay( $baseline, $proposed, $at );
			$evaluator = $this->evaluator ?? new RuleLifecycleEvaluator();
			$comparisons = [];
			foreach ( $validated as $subject ) {
				$comparisons[] = [
					'subject' => $subject,
					'baseline' => $evaluator->evaluate( $profile, $baseline, $subject, $at, $snapshot->guard?->revision ?? 0, $snapshot->complete ),
					'proposed' => $evaluator->evaluate( $profile, $overlay, $subject, $at, $snapshot->guard?->revision ?? 0, $snapshot->complete ),
				];
			}
			$now = $this->clock?->now() ?? RuleTime::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) );
			$result = new RuleImpactPreview( $snapshot, $at, $comparisons, $at->compare( $now ) > 0, $proposed );
		} catch ( \Throwable ) { return new ContractError( 'temporarily_unavailable', $request, 'contact_support' ); }
		// A grant can be revoked during capture/evaluation. No private result leaks.
		try { if ( $this->reader->profile( $family ) !== $profile ) { return self::denied( $request ); } }
		catch ( \Throwable ) { return self::denied( $request ); }
		if ( ! self::allowed( $profile, $identity, $scope ) ) { return self::denied( $request ); }
		foreach ( $validated as $subject ) { if ( ! self::allowed( $profile, $identity, $subject ) ) { return self::denied( $request ); } }
		return $result;
	}

	public function admin_preview( OperationIdentity $identity, string $family, array $authorization_scope, iterable $subjects, ?RuleCandidate $proposed, RuleTime $at, RequestContext $request ): array|ContractError {
		$result = $this->preview( $identity, $family, $authorization_scope, $subjects, $proposed, $at, $request );
		if ( $result instanceof ContractError ) { return $result; }
		try { return $result->admin( $this->reader->profile( $family ), $identity ); }
		catch ( \Throwable ) { return self::denied( $request ); }
	}

	private function valid_overlay( RuleSnapshot $snapshot, RuleCandidate $proposed, OperationIdentity $identity ): bool {
		if ( null === $snapshot->guard || ! $proposed->version->hypothetical || $proposed->logical->site_id !== $identity->site_id || $proposed->logical->family_guard_id !== $snapshot->guard->id || ! self::allowed( $snapshot->profile, $identity, $proposed->logical->scope() ) ) { return false; }
		foreach ( $snapshot->logicals as $logical ) {
			if ( $logical->id === $proposed->logical->id && $logical->logical_uuid === $proposed->logical->logical_uuid && $logical->revision === $proposed->logical->revision && hash_equals( $logical->scope_hash, $proposed->logical->scope_hash ) ) {
				foreach ( $snapshot->versions as $version ) {
					if ( $version->id === $proposed->version->id && $version->version_uuid === $proposed->version->version_uuid && $version->row_revision === $proposed->version->row_revision ) { return true; }
				}
			}
		}
		return false;
	}

	private function overlay( array $baseline, ?RuleCandidate $proposed, RuleTime $at ): array {
		if ( null === $proposed ) { return $baseline; }
		$out = [];
		foreach ( $baseline as $candidate ) {
			if ( $candidate->version->id === $proposed->version->id ) { continue; }
			// A future proposal cannot shorten the predecessor before its cutover.
			if ( $proposed->version->eligible_at( $at ) && $candidate->logical->id === $proposed->logical->id && $candidate->version->id === $proposed->version->supersedes_version_id ) { continue; }
			$out[] = $candidate;
		}
		$out[] = $proposed;
		return $out;
	}

	private static function allowed( RuleFamilyProfile $profile, OperationIdentity $identity, array $scope ): bool { try { return true === $profile->authorize( $identity, $scope ); } catch ( \Throwable ) { return false; } }
	private static function denied( RequestContext $request ): ContractError { return new ContractError( 'not_authorized', $request, 'contact_support' ); }
	private static function invalid( RequestContext $request ): ContractError { return new ContractError( 'invalid_input', $request, 'reload_and_submit', [], [ [ 'field' => 'semantic_payload', 'code' => 'out_of_range' ] ] ); }
}
