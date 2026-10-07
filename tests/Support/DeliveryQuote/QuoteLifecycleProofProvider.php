<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderInterface;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;

/** Finite synthetic provider only; no native tariff, external call, cart or order claim. */
final class QuoteLifecycleProofProvider implements QuoteProviderInterface {
	public int $captures = 0;
	public ?\Closure $before_capture = null;
	public ?string $counter_file = null;
	public ?string $capture_ready = null;
	public ?string $capture_release = null;
	public function code(): string { return 'fixture_v1'; }
	public function version(): int { return 1; }
	public function profile(): string { return 'fixture_v1'; }
	public function profile_version(): int { return 1; }
	public function evidence_providers(): array { return [ 'fixture_none_v1' => [ 1 ] ]; }
	public function capture( QuoteContext $context ): QuoteTerms {
		++$this->captures;
		if ( null !== $this->counter_file ) {
			$file = fopen( $this->counter_file, 'c+' ); if ( false === $file || ! flock( $file, LOCK_EX ) ) { throw new \RuntimeException( 'Quote capture counter refused.' ); }
			$count = (int) stream_get_contents( $file ); rewind( $file ); ftruncate( $file, 0 ); fwrite( $file, (string) ( $count + 1 ) ); fflush( $file ); flock( $file, LOCK_UN ); fclose( $file );
		}
		if ( null !== $this->before_capture ) { ( $this->before_capture )( $context ); }
		if ( null !== $this->capture_ready && null !== $this->capture_release ) { OperationProofBarrier::pause( $this->capture_ready, $this->capture_release ); }
		$facts = $context->private_facts(); $groups = [];
		foreach ( $facts['groups'] as $group ) {
			$term = QuoteFixtures::terms()->private_facts()['groups'][0]; $term['component_key'] = $group['component_key']; $term['policy_digest'] = $group['policy_digest'];
			$term['native_tax_receipt']['context_digest'] = $facts['tax']['context_digest']; $term['native_money_receipt']['evidence_digest'] = $facts['tax']['native_money_digest']; $groups[] = $term;
		}
		return QuoteTerms::from_array( [ 'format_version' => 1, 'groups' => $groups ] );
	}
}
