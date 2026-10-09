<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteHeader,QuoteOwner,QuoteReference,QuoteStoredRow,QuoteTerms,QuoteTime};

/** Private, currently authorized accepted facts. Presence is never a placement receipt. */
final readonly class QuotePlacementEvidence implements \JsonSerializable {
	private \Closure $authorizer;
	public function __construct( private QuoteStoredRow $row, private QuoteReference $original_reference, private QuoteHeader $original_header, private QuoteContext $current, private QuoteCurrentEvidenceGuard $current_guard, callable $authorize, private QuoteTime $evaluated, private ?QuoteCartDraft $native_draft = null, private ?QuoteBinding $saved_binding = null, private ?QuotePlacementSavedEvidenceGuard $saved_evidence = null, private ?QuoteNativeTaxSource $native_tax_source = null ) {
		$this->authorizer = \Closure::fromCallable( $authorize );
		if ( 'accepted' !== $row->state() || null === $row->accepted_at() || null === $row->context() || null === $row->terms() || ! $original_header->matches_reference( $original_reference ) || $original_header->to_private_json() !== $row->header()->to_private_json() || ! hash_equals( $original_header->material_digest(), $current->digest() ) || ! $original_header->valid_at( $evaluated ) || ! $current->material_evidence_available() || ( null !== $native_draft && ! $native_draft->owner()->equals( $original_header->owner() ) ) || ! $this->authorize( $original_header->owner(), 'delivery_quote.read' ) ) { throw new \RuntimeException( 'Delivery quote placement unavailable.' ); }
		if ( ( null === $saved_binding ) !== ( null === $saved_evidence ) || null !== $saved_binding && ( 'sealed' !== $saved_binding->state() || QuoteBinding::from_row( $saved_binding->row(), $row )->row() !== $saved_binding->row() ) ) { throw new \RuntimeException( 'Delivery quote placement unavailable.' ); }
		if ( null !== $native_tax_source && ! $native_tax_source->matches_context( $current ) ) { throw new \RuntimeException( 'Delivery quote placement unavailable.' ); }
	}
	public function owner(): QuoteOwner { return $this->original_header->owner(); }
	public function reference(): QuoteReference { return $this->original_reference; }
	public function header(): QuoteHeader { return $this->original_header; }
	public function current_context(): QuoteContext { return $this->current; }
	public function guard(): QuoteCurrentEvidenceGuard { return $this->current_guard; }
	public function quote_record(): QuoteStoredRow { return $this->row; }
	public function context(): QuoteContext { return $this->row->context(); }
	public function terms(): QuoteTerms { return $this->row->terms(); }
	public function accepted_at(): QuoteTime { return $this->row->accepted_at(); }
	public function evaluated_at(): QuoteTime { return $this->evaluated; }
	public function native_draft(): ?QuoteCartDraft { return $this->native_draft; }
	public function draft_facts(): ?array { return $this->native_draft?->private_facts(); }
	public function binding(): ?QuoteBinding { return $this->saved_binding; }
	public function saved_guard(): ?QuotePlacementSavedEvidenceGuard { return $this->saved_evidence; }
	public function tax_source(): ?QuoteNativeTaxSource { return $this->native_tax_source; }
	public function tax_source_facts(): ?array { return $this->native_tax_source?->private_facts(); }
	public function tax_source_json(): ?string { return $this->native_tax_source?->to_private_json(); }
	public function authorize( QuoteOwner $owner, string $operation ): bool { try { return $owner->equals( $this->owner() ) && true === ( $this->authorizer )( $owner, $operation ); } catch ( \Throwable ) { return false; } }
	public function jsonSerialize(): never { throw new \LogicException( 'Placement evidence requires an explicit private consumer.' ); }
	public function __serialize(): never { throw new \LogicException( 'Placement evidence cannot be serialized generically.' ); }
}
