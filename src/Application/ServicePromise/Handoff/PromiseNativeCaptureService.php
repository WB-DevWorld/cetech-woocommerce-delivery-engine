<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Handoff;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseOperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRefusal};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseJson, PromiseLimits, PromiseResult, PromiseShape, PublicPromiseView};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePersistenceAuthorizer, PromiseSiteBinding};
use CetechDeliveryEngine\Domain\ServicePromise\Port\PromiseCapacityObserver;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPromiseHandoffSources;
use CetechDeliveryEngine\Integrations\ServicePromise\NativePromiseRuntimeCapture;

/** Capture/calculation is complete before quote SQL ownership. No browser field supplies source authority. */
final readonly class PromiseNativeCaptureService {
	public function __construct( private PromiseSiteBinding $binding, private OperationConnectionFactory $connections, private PromisePersistenceAuthorizer $authority, private ?PromiseCapacityObserver $capacity = null, private ?PromiseCapacityCurrentFence $capacity_fence = null ) {}
	public function current_fence( QuoteContext $context, RuleTime $at ): PromiseHandoffSourceFence { return PromiseHandoffSourceFence::for_context( $this->binding, $context, $at, $this->capacity_fence ); }
	public function capture( QuoteContext $base, QuoteOwner $native_owner, RuleTime $at, array $demands ): PromiseNativeCapture {
		if ( 1 !== $base->private_facts()['format_version'] || ! $base->checkout_acceptable() || $native_owner->site_id() !== $this->binding->site_id() || ! array_is_list( $demands ) || count( $demands ) !== count( $base->private_facts()['groups'] ) ) { PromiseShape::invalid(); }
		$index = []; $authorizations = [];
		foreach ( $demands as $demand ) {
			if ( ! $demand instanceof PromiseCaptureDemand || isset( $index[$demand->component_key()] ) ) { PromiseShape::invalid(); } $index[$demand->component_key()] = $demand;
			foreach ( WpdbPromiseHandoffSources::keys( $base, $demand->component_key(), $demand->service_endpoint(), $this->binding ) as $key ) {
				$private_key = array_diff_key( $key, array_flip( [ 'site_id', 'site_key' ] ) ); $scope = [ 'kind' => $key['scope_kind'], 'target_id' => $key['scope_id'] ];
				$identity = new OperationIdentity( $this->binding->site_id(), 'service_promise.native_capture.v1', $native_owner->digest(), 'promise.assignment.read', 1, PromiseAssignmentCommand::target_key( $this->binding, $private_key ), 'native-capture' );
				$authorizations[] = [ $identity, $scope ];
			}
		}
		ksort( $index, SORT_STRING ); foreach ( $base->private_facts()['groups'] as $group ) { if ( ! isset( $index[$group['component_key']] ) ) { PromiseShape::invalid(); } }
		foreach ( $authorizations as [ $identity, $scope ] ) { $this->authorize( $identity, $scope ); }
		$sources = $this->sources( $base, $at, $index ); $runtime = ( new NativePromiseRuntimeCapture() )->capture();
		foreach ( $authorizations as [ $identity, $scope ] ) { $this->authorize( $identity, $scope ); }
		$inputs = []; $calendars = [];
		foreach ( $index as $component => $demand ) {
			$effective = $sources[$component]['effective']; $policy = $effective->policy(); $facts = $policy->private_facts(); $expires = RuleTime::from_epoch_microseconds( $at->epoch_microseconds() + 300000000 );
			$deadline = null !== $policy->effective_until() && $policy->effective_until()->compare( $expires ) < 0 ? $policy->effective_until() : $expires;
			$service_day = $this->service_day( $facts, $at ); if ( null !== $service_day && RuleTime::parse( $service_day['end_at'] )->compare( $deadline ) < 0 ) { $deadline = RuleTime::parse( $service_day['end_at'] ); }
			$anchor = [ 'format_version' => 1, 'kind' => $policy->anchor(), 'evaluated_at' => $at->sql(), 'quote_expires_at' => $expires->sql() ];
			$anchor += match ( $policy->anchor() ) { 'order_accepted' => [ 'accept_until' => $deadline->sql() ], 'checkout_capture' => [ 'capture_at' => $at->sql() ], 'payment_confirmed' => [ 'awaited_event' => 'woocommerce_payment_confirmed' ] };
			$receipts = []; foreach ( $facts['graph']['components'] as $part ) { $receipts[$part['source']['source_id']] = $part['source']; } if ( null !== $policy->capacity_source() ) { $receipts[$policy->capacity_source()['source_id']] = $policy->capacity_source(); }
			$owner = $native_owner->facts(); $owner['site_id'] = $this->binding->site_key();
			$input = [ 'format_version' => 1, 'site_id' => $this->binding->site_key(), 'evaluated_at' => $at->sql(), 'owner' => $owner, 'material' => [ 'group_id' => $component, 'material_digest' => $base->digest() ], 'origin' => $demand->origin( $base ), 'destination' => $demand->destination( $base ), 'policy' => $facts, 'calendar_refs' => array_map( static fn( $calendar ): array => $calendar->reference()->private_facts(), $effective->calendars() ), 'source_receipts' => array_values( $receipts ), 'anchor' => $anchor, 'service_day' => $service_day, 'capacity' => [ 'format_version' => 1, 'mode' => 'none' ], 'runtime' => $runtime ];
			if ( 'required' === $policy->capacity_mode() ) {
				if ( null === $this->capacity || null === $this->capacity_fence || 'payment_confirmed' === $policy->anchor() ) { throw new \RuntimeException( 'Required native promise capacity is unavailable.' ); }
				$input['capacity'] = [ 'format_version' => 1, 'mode' => 'required', 'site_id' => $this->binding->site_key(), 'service_digest' => $policy->service()->digest(), 'endpoint_digest' => PromiseInput::endpoint_digest( $input['destination'] ), 'window' => [ 'from' => $at->sql(), 'until' => $expires->sql(), 'display_timezone' => $policy->promise_timezone() ], 'source' => $policy->capacity_source(), 'revision' => 1, 'state' => 'unknown', 'observed_at' => $at->sql(), 'valid_until' => $expires->sql() ];
				$provisional = PromiseInput::from_array( $input ); $observed = $this->capacity->observe( $provisional );
				if ( 'required' !== $observed->mode() ) { throw new \RuntimeException( 'Required native promise capacity is unavailable.' ); } $input['capacity'] = $observed->private_facts();
			}
			$inputs[] = PromiseInput::from_array( $input ); foreach ( $effective->calendars() as $calendar ) { $calendars[] = $calendar; }
		}
		$calculator = new DeterministicPromiseCalculator( $runtime ); $results = $calculator->calculate_cart( $inputs, $calendars );
		if ( $runtime !== ( new NativePromiseRuntimeCapture() )->capture() ) { throw new \RuntimeException( 'The native promise runtime changed during capture.' ); } $captures = []; $packets = [];
		foreach ( $results as $result ) {
			$input = $result->input(); $component = $input->private_facts()['material']['group_id'];
			if ( $input->policy()->private_facts()['promise_required'] && ! in_array( $result->state(), [ 'absolute_window', 'relative_window' ], true ) ) { throw new \RuntimeException( 'The required native promise is unavailable.' ); }
			$captures[] = [ 'component_key' => $component, 'assignment_receipt_digest' => $sources[$component]['assignment_receipt_digest'], 'policy_reference' => $input->policy()->reference()->private_facts(), 'input' => $input->to_private_json(), 'input_digest' => $input->digest() ];
			$packets[] = [ 'component_key' => $component, 'packet' => PromiseHistoricalPacket::capture( $result, $this->customer_text( $result ) )->private_facts() ];
		}
		// Validate the combined carrier now; no partial winning packet can escape overflow.
		PromiseJson::encode( [ 'captures' => $captures, 'packets' => $packets ], PromiseLimits::PACKET_BYTES );
		return new PromiseNativeCapture( $base, $this->binding->site_key(), $captures, $packets );
	}
	private function sources( QuoteContext $base, RuleTime $at, array $demands ): array {
		$session = null;
		try {
			$session = $this->connections->open(); $this->binding->assert_session( $session );
			if ( $session->is_retired() || $session->in_transaction() ) { throw new OperationStorageException(); }
			( new PromiseOperationReadiness() )->assert_ready( $session ); if ( ! $session->begin() ) { throw new OperationStorageException(); }
			$repository = new WpdbPromiseHandoffSources( $session, $this->binding ); $captured = [];
			$services = []; foreach ( $demands as $component => $demand ) { $services[$component] = $demand->service_endpoint(); } $repository->prime( $base, $services, $at );
			foreach ( $demands as $component => $demand ) { $captured[$component] = $repository->group( $base, $component, $demand->service_endpoint(), $at ); }
			if ( ! $session->rollback() || ! $session->retire() ) { throw new OperationStorageException(); } return $captured;
		} catch ( \Throwable $error ) { throw new OperationStorageException(); }
		finally { if ( null !== $session && ! $session->is_retired() ) { if ( $session->in_transaction() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	private function authorize( OperationIdentity $identity, array $scope ): void { if ( true !== $this->authority->authorize( $identity, $this->binding, $scope, 0 ) ) { throw new OperationRefusal( 'not_authorized', 'contact_support' ); } }
	private function service_day( array $policy, RuleTime $at ): ?array {
		if ( 'none' === $policy['day_constraint'] ) { return null; }
		$zone = new \DateTimeZone( $policy['promise_timezone'] ); $local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $at->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( $zone ); $start = $local->setTime( 0, 0, 0, 0 ); $end = $start->modify( '+1 day' );
		return [ 'local_date' => $local->format( 'Y-m-d' ), 'timezone' => $policy['promise_timezone'], 'start_at' => $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ), 'end_at' => $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ) ];
	}
	private function customer_text( PromiseResult $result ): string {
		$parts = [];
		foreach ( $result->input()->policy()->endpoint_terminal_component_ids() as $terminal ) {
			$view = PublicPromiseView::from_result( $result, $terminal )->fields(); $prefix = $view['service_label'] . ': ';
			if ( 'absolute_window' === $view['state'] ) {
				$zone = new \DateTimeZone( $view['display_timezone'] ); $from = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $view['from'], new \DateTimeZone( 'UTC' ) )->setTimezone( $zone )->format( 'Y-m-d H:i' ); $until = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $view['until'], new \DateTimeZone( 'UTC' ) )->setTimezone( $zone )->format( 'Y-m-d H:i' ); $parts[] = $prefix . $from . ' to ' . $until . ' (' . $view['display_timezone'] . ')';
			} elseif ( 'relative_window' === $view['state'] ) { $parts[] = $prefix . $view['min'] . ' to ' . $view['max'] . ' ' . str_replace( '_', ' ', $view['unit'] ) . ' after payment confirmation'; }
			else { $parts[] = $prefix . 'estimate unavailable'; }
		}
		$text = implode( '; ', $parts ); PromiseShape::text( $text, 2048 ); return $text;
	}
}
