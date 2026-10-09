<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Safe structured render facts. Authorization and localization remain the future caller's job. */
final readonly class PublicPromiseView implements \JsonSerializable {
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data );
		$state = PromiseShape::choice( $data['state'] ?? null, [ 'absolute_window', 'relative_window', 'unavailable', 'ineligible' ] );
		$fields = [ 'format_version', 'service_label', 'state', 'display_timezone', 'reason_codes' ];
		$fields = array_merge( $fields, match ( $state ) { 'absolute_window' => [ 'from', 'until' ], 'relative_window' => [ 'relative_explanation', 'min', 'max', 'unit', 'known_zero' ], default => [] } );
		PromiseShape::fields( $data, $fields ); PromiseShape::integer( $data['format_version'], 1, 1 );
		$reasons = PromiseResult::reason_codes( $data['reason_codes'] );
		$winning = in_array( $state, [ 'absolute_window', 'relative_window' ], true );
		if ( $winning !== ( [] === $reasons ) ) { PromiseShape::invalid(); }
		$out = [ 'format_version' => 1, 'service_label' => PromiseShape::text( $data['service_label'], 160 ), 'state' => $state, 'display_timezone' => PromiseShape::timezone( $data['display_timezone'] ), 'reason_codes' => $reasons ];
		if ( 'absolute_window' === $state ) {
			$from = PromiseShape::instant( $data['from'] ); $until = PromiseShape::instant( $data['until'] );
			if ( $from->compare( $until ) > 0 ) { PromiseShape::invalid(); }
			$out += [ 'from' => $from->sql(), 'until' => $until->sql() ];
		} elseif ( 'relative_window' === $state ) {
			$min = PromiseShape::integer( $data['min'], 0 ); $max = PromiseShape::integer( $data['max'], $min ); $zero = PromiseShape::boolean( $data['known_zero'] );
			if ( $zero !== ( 0 === $min && 0 === $max ) ) { PromiseShape::invalid(); }
			$out += [ 'relative_explanation' => PromiseShape::choice( $data['relative_explanation'], [ 'after_payment_confirmation' ] ), 'min' => $min, 'max' => $max, 'unit' => PromiseShape::choice( $data['unit'], [ 'elapsed_minutes', 'calendar_days', 'business_minutes', 'business_days' ] ), 'known_zero' => $zero ];
		}
		return new self( $out );
	}
	/** The explicitly selected sink must belong to the policy's actual customer endpoint. */
	public static function from_result( PromiseResult $result, string $terminal_component_id ): self {
		$policy = $result->input()->policy();
		if ( ! in_array( PromiseShape::id( $terminal_component_id ), $policy->endpoint_terminal_component_ids(), true ) ) { PromiseShape::invalid(); }
		$facts = $result->private_facts();
		$out = [ 'format_version' => 1, 'service_label' => $policy->service()->private_facts()['customer_label'], 'state' => $result->state(), 'display_timezone' => $policy->promise_timezone(), 'reason_codes' => $facts['reason_codes'] ];
		if ( null !== $facts['body'] ) {
			$window = null; foreach ( $facts['body']['terminal_windows'] as $candidate ) { if ( $candidate['component_id'] === $terminal_component_id ) { $window = $candidate; break; } }
			if ( null === $window ) { PromiseShape::invalid(); }
			unset( $window['component_id'], $window['calendar_refs'] ); $out += $window;
			if ( 'relative_window' === $result->state() ) { $out['relative_explanation'] = 'after_payment_confirmation'; }
		}
		return self::from_array( $out );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json ) ); }
	public function fields(): array { return $this->data; }
	public function __serialize(): never { throw new \LogicException( 'Use the explicit public promise JSON projection.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Unvalidated public promise unserialization is forbidden.' ); }
	public function jsonSerialize(): array { return $this->data; }
}
