<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteId,QuoteJson,QuoteTime};

/** Ordered private prepare/verify/final receipt orchestration; Woo is staged separately. */
final readonly class QuotePlacementService {
	public function __construct( private QuoteDurableService $durable, private QuotePlacementEvidence $evidence ) {}
	/** Retry namespaces are finite and original even after a process is replaced. */
	public function placement_id(): string {
		$h = hash( 'sha256', 'cetech-quote-placement-v1:' . $this->evidence->owner()->digest() . ':' . $this->evidence->header()->id()->value() . ':' . $this->evidence->header()->body_digest() );
		return QuoteId::from_string( substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-4' . substr( $h, 13, 3 ) . '-8' . substr( $h, 17, 3 ) . '-' . substr( $h, 20, 12 ) )->value();
	}
	public function prepare( int $order_id, array $mapping, RequestContext $request ): QuoteDurableResult {
		$q = $this->evidence->quote_record(); $mapping = QuoteBinding::canonical_mapping( $mapping ); $id = $this->placement_id();
		$names = QuoteDurableCommand::binding_namespaces( $this->evidence->owner(), $this->evidence->header(), $id, true );
		$binding = QuoteBinding::from_row( [
			'id' => 1, 'site_id' => $q->site_id(), 'format_version' => 1, 'quote_uuid' => $q->header()->id()->value(), 'order_id' => $order_id, 'placement_uuid' => $id,
			'managed_group_manifest_digest' => QuoteBinding::manifest_digest( $mapping ), 'mapping_json' => QuoteJson::encode( $mapping ), 'native_money_digest' => $q->context()->private_facts()['tax']['native_money_digest'], 'accepted_body_digest' => $q->header()->body_digest(),
			'snapshot_digest' => null, 'context_digest' => null, 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'], 'state' => 'prepared', 'revision' => 1, 'created_at' => $q->accepted_at()->sql(), 'verified_at' => null, 'sealed_at' => null,
		], $q );
		return $this->durable->bind( $this->evidence->owner(), $this->evidence->reference(), $this->evidence->header(), $binding, $request, $this->evidence->current_context() );
	}
	public function verified_binding( QuoteBinding $prepared, string $snapshot_digest, string $context_digest, QuoteTime $verified_at ): QuoteBinding {
		if ( 'prepared' !== $prepared->state() || 1 !== $prepared->revision() ) { throw new \InvalidArgumentException( 'Original prepared binding is required.' ); }
		return QuoteBinding::from_row( array_replace( $prepared->row(), [ 'revision' => 2, 'snapshot_digest' => $snapshot_digest, 'context_digest' => $context_digest, 'verified_at' => $verified_at->sql() ] ), $this->evidence->quote_record() );
	}
	public function verify( QuoteBinding $verified, QuotePlacementSavedEvidenceGuard $saved, RequestContext $request ): QuoteDurableResult { return $this->durable->verify_binding( $this->evidence->owner(), $this->evidence->reference(), $this->evidence->header(), $verified, $request, $this->evidence->current_context(), $saved ); }
	public function seal( QuoteBinding $verified, QuotePlacementProof $proof, RequestContext $request ): QuoteDurableResult { return $this->durable->seal_placement( $this->evidence->owner(), $this->evidence->reference(), $this->evidence->header(), $verified, $request, $this->evidence->current_context(), $proof ); }
}
