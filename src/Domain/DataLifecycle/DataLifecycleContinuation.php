<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

/** Private trusted-host continuation. Possession does not grant cleanup authority. */
final readonly class DataLifecycleContinuation implements \JsonSerializable {
	public function __construct( public ?DataLifecycleProgress $before, public DataLifecycleProgress $after, public string $kind = 'checkpoint', public bool $uncertain = false, public bool $retirement_confirmed = true ) {
		if ( ! in_array( $kind, [ 'checkpoint', 'start', 'batch', 'preview' ], true ) || ( in_array( $kind, [ 'checkpoint', 'batch' ], true ) && null === $before ) || ( null !== $before && $before->site_id !== $after->site_id ) || ( 'batch' === $kind && ( ! hash_equals( $before->run_manifest_hash, $after->run_manifest_hash ) || $after->revision !== $before->revision + 1 ) ) || ( 'checkpoint' === $kind && ! $before->same_checkpoint( $after ) ) || ( 'preview' === $kind && $uncertain ) ) { throw new \InvalidArgumentException( 'Lifecycle continuation is invalid.' ); }
	}
	public static function checkpoint( DataLifecycleProgress $progress ): self { return new self( $progress, $progress ); }
	public function to_private_array(): array { return [ 'format' => 1, 'before' => $this->before?->to_array(), 'after' => $this->after->to_array(), 'kind' => $this->kind, 'uncertain' => $this->uncertain, 'retirement_confirmed' => $this->retirement_confirmed ]; }
	public static function from_private_array( array $data ): self {
		$fields = [ 'format', 'before', 'after', 'kind', 'uncertain', 'retirement_confirmed' ];
		if ( [] !== array_diff( $fields, array_keys( $data ) ) || [] !== array_diff( array_keys( $data ), $fields ) || 1 !== $data['format'] || ( null !== $data['before'] && ! is_array( $data['before'] ) ) || ! is_array( $data['after'] ) || ! is_string( $data['kind'] ) || ! is_bool( $data['uncertain'] ) || ! is_bool( $data['retirement_confirmed'] ) ) { throw new \InvalidArgumentException( 'Lifecycle continuation is invalid.' ); }
		return new self( null === $data['before'] ? null : DataLifecycleProgress::from_array( $data['before'] ), DataLifecycleProgress::from_array( $data['after'] ), $data['kind'], $data['uncertain'], $data['retirement_confirmed'] );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'A lifecycle continuation requires a private projection.' ); }
}
