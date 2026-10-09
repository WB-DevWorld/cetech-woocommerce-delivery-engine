<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Handoff;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCurrentEvidenceValidity, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteOwner, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseJson};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Infrastructure\Persistence\{WpdbOperationRecordRepository, WpdbPromiseHandoffSources};
use CetechDeliveryEngine\Integrations\ServicePromise\NativePromiseRuntimeCapture;

/** Precaptured native runtime/time; locked verification reads only the existing authoritative owner. */
final readonly class PromiseHandoffSourceFence implements QuotePlacementSavedEvidenceGuard {
	private function __construct( private PromiseSiteBinding $site_binding, private QuoteContext $context, private RuleTime $at, private array $runtime, private array $inputs, private ?PromiseCapacityCurrentFence $capacity ) {}
	public static function for_context( PromiseSiteBinding $binding, QuoteContext $context, RuleTime $at, ?PromiseCapacityCurrentFence $capacity = null ): self {
		if ( 2 !== $context->format_version() || $binding->site_key() !== $context->private_facts()['promise_capture']['site_key'] ) { throw new \InvalidArgumentException( 'Invalid native promise source fence.' ); }
		$runtime = ( new NativePromiseRuntimeCapture() )->capture(); $inputs = []; foreach ( $context->promise_groups() as $capture ) { $inputs[$capture['component_key']] = PromiseInput::from_json( $capture['input'] ); } return new self( $binding, $context, $at, $runtime, $inputs, $capacity );
	}
	/** Pure time handoff: reuse the captured runtime and inputs, with no collector or clock call. */
	public function at( RuleTime $at ): self {
		if ( $at->compare( $this->at ) < 0 ) { throw new \InvalidArgumentException( 'Promise source observation cannot move backwards.' ); }
		return new self( $this->site_binding, $this->context, $at, $this->runtime, $this->inputs, $this->capacity );
	}
	public function tables( OperationSession $session ): array {
		$this->site_binding->assert_session( $session ); $tables = [];
		foreach ( [ 'promise_objects', 'promise_versions', 'promise_assignments', 'operation_records', 'operation_changes' ] as $suffix ) { $tables[] = WpdbOperationRecordRepository::table_name( $session, $suffix ); }
		if ( null !== $this->capacity ) { foreach ( $this->capacity->tables( $session, $this->site_binding ) as $table ) { if ( ! is_string( $table ) || ! str_starts_with( $table, $session->table_prefix() ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]{1,64}\z/D', $table ) ) { throw new \RuntimeException( 'Invalid native capacity source table.' ); } $tables[] = $table; } }
		return array_values( array_unique( $tables ) );
	}
	public function verify( OperationSession $session, QuoteBinding $binding ): bool {
		return $binding->site_id() === $this->site_binding->site_id() && null !== $this->source_validity( $session );
	}
	public function verify_context( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool {
		return null !== $this->validity_context( $session, $owner, $context );
	}
	/** Validity is emitted only after this owner's exact original source census is verified. */
	public function validity_context( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): ?QuoteCurrentEvidenceValidity {
		try {
			if ( $owner->site_id() !== $this->site_binding->site_id() || $context->to_private_json() !== $this->context->to_private_json() ) { return null; }
			$expected = $owner->facts(); $expected['site_id'] = $this->site_binding->site_key();
			foreach ( $this->inputs as $input ) { if ( PromiseJson::encode( $input->private_facts()['owner'] ) !== PromiseJson::encode( $expected ) ) { return null; } }
			return $this->source_validity( $session );
		} catch ( \Throwable ) { return null; }
	}
	private function source_validity( OperationSession $session ): ?QuoteCurrentEvidenceValidity {
		try {
			$this->tables( $session ); if ( ! $session->in_transaction() || $session->is_retired() ) { return null; }
			$repository = new WpdbPromiseHandoffSources( $session, $this->site_binding ); $base = $this->context->base_context();
			$services = []; foreach ( $this->inputs as $component => $input ) { $policy = $input->policy(); $service = $policy->service()->private_facts(); $services[$component] = [ 'service_kind' => $service['kind'], 'service_code' => $service['code'], 'endpoint' => $policy->endpoint(), 'endpoint_kind' => $policy->endpoint_kind() ]; } $repository->prime( $base, $services, $this->at );
			$until = null;
			foreach ( $this->context->promise_groups() as $capture ) {
				$input = $this->inputs[$capture['component_key']]; $facts = $input->private_facts();
				if ( PromiseJson::encode( $facts['runtime'] ) !== PromiseJson::encode( $this->runtime ) || $this->at->compare( $input->anchor()->evaluated_at() ) < 0 || $this->at->compare( $input->anchor()->quote_expires_at() ) >= 0 || ( null !== $input->anchor()->accept_until() && $this->at->compare( $input->anchor()->accept_until() ) >= 0 ) ) { return null; }
				$policy = $input->policy(); $service = $policy->service()->private_facts();
				$current = $repository->group( $base, $capture['component_key'], [ 'service_kind' => $service['kind'], 'service_code' => $service['code'], 'endpoint' => $policy->endpoint(), 'endpoint_kind' => $policy->endpoint_kind() ], $this->at );
				if ( ! hash_equals( $capture['assignment_receipt_digest'], $current['assignment_receipt_digest'] ) || $current['effective']->policy()->to_private_json() !== $policy->to_private_json() || $current['effective']->policy()->reference()->private_facts() !== $capture['policy_reference'] ) { return null; }
				foreach ( [ $input->anchor()->quote_expires_at(), $input->anchor()->accept_until(), $current['valid_until'] ] as $bound ) { if ( null !== $bound && ( null === $until || $bound->compare( $until ) < 0 ) ) { $until = $bound; } }
				$observation = $input->capacity();
				if ( 'required' === $observation->mode() ) { $capacity = $observation->private_facts(); $bound = RuleTime::parse( $capacity['valid_until'] ); if ( null === $this->capacity || 'available' !== $observation->state() || $this->at->compare( $bound ) >= 0 || ! $this->capacity->verify( $session, $this->site_binding, $observation ) ) { return null; } if ( $bound->compare( $until ) < 0 ) { $until = $bound; } }
			}
			return null === $until ? null : QuoteCurrentEvidenceValidity::capture( QuoteTime::parse( $this->at->sql() ), QuoteTime::parse( $until->sql() ) );
		} catch ( \Throwable ) { return null; }
	}
}
