<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCurrentEvidenceGuard, QuoteNativeCaptureSource, QuoteNativeState, QuotePlacementEvidence, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteId, QuoteJson, QuoteOwner, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** A structural accepted3 row must never become the inherited native placement carrier. */
final class FixedPromotionPlacementBoundaryTest extends TestCase {

	private function forward(): QuoteStoredRow {
		$provider = F::provider(); $reference = Q::reference( QuoteId::generate() ); $header = F::header( $provider, $reference );
		return QuoteStoredRow::from_row( array_replace( QuoteStorageFixtures::quote( state: 'accepted' )->row(), [
			'quote_uuid' => $header->id()->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'header_json' => $header->to_private_json(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(),
			'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ),
			'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'],
		] ) );
	}
	public static function forward_positions(): array { return [ 'coherent accepted3' => [ 'all' ], 'row3 alone' => [ 'row' ], 'opened header3 alone' => [ 'header' ], 'current context3 alone' => [ 'current' ] ]; }
	#[DataProvider( 'forward_positions' )]
	public function test_forward_facts_refuse_before_authorizer_guards_or_native_ports( string $position ): void {
		$forward = $this->forward(); $retained = QuoteStorageFixtures::quote( state: 'accepted' ); $row = in_array( $position, [ 'all', 'row' ], true ) ? $forward : $retained;
		$header = in_array( $position, [ 'all', 'header' ], true ) ? $forward->header() : $retained->header(); $current = in_array( $position, [ 'all', 'current' ], true ) ? $forward->context() : $retained->context();
		$guard = new PlacementBoundaryCurrentProbe(); $saved = new PlacementBoundarySavedProbe(); $native = new PlacementBoundaryNativeProbe( $header->owner() ); $authorizations = 0;
		$prepared = QuoteStorageFixtures::binding( $row ); $binding = QuoteBinding::from_row( array_replace( $prepared->row(), [ 'state' => 'sealed', 'revision' => 3, 'snapshot_digest' => Q::digest( 'existing native snapshot' ), 'context_digest' => Q::digest( 'existing native context' ), 'verified_at' => Q::time()->plus_seconds( 2 )->sql(), 'sealed_at' => Q::time()->plus_seconds( 2 )->sql() ] ), $row );
		try {
			new QuotePlacementEvidence( $row, Q::reference( $header->id() ), $header, $current, $guard, static function() use ( &$authorizations, $native ): bool { ++$authorizations; $native->current_owner(); return true; }, Q::time()->plus_seconds( 2 ), saved_binding: $binding, saved_evidence: $saved );
			self::fail( 'Forward history must not cross the native placement boundary.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote placement unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $authorizations ); self::assertSame( 0, $guard->calls ); self::assertSame( 0, $saved->calls ); self::assertSame( 0, $native->calls );
	}
	public static function retained_formats(): array { return [ [ 1 ], [ 2 ] ]; }
	#[DataProvider( 'retained_formats' )]
	public function test_retained_native_carriers_keep_their_existing_authorization_behavior( int $format ): void {
		$row = 1 === $format ? QuoteStorageFixtures::quote( state: 'accepted' ) : QuoteStoredRow::from_row( PromiseHandoffFixture::row( 'accepted' ) ); $header = $row->header(); $guard = new PlacementBoundaryCurrentProbe(); $native = new PlacementBoundaryNativeProbe( $header->owner() ); $authorizations = 0;
		$evidence = new QuotePlacementEvidence( $row, Q::reference( $header->id() ), $header, $row->context(), $guard, static function() use ( &$authorizations, $native ): bool { ++$authorizations; $native->current_owner(); return true; }, $row->accepted_at() );
		self::assertSame( $row, $evidence->quote_record() ); self::assertSame( $format, $evidence->header()->format_version() ); self::assertSame( 1, $authorizations ); self::assertSame( 1, $native->calls ); self::assertSame( 0, $guard->calls );
	}
}

final class PlacementBoundaryCurrentProbe implements QuoteCurrentEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Placement construction cannot read current SQL.' ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { ++$this->calls; throw new \LogicException( 'Placement construction cannot run current proof.' ); }
}
final class PlacementBoundarySavedProbe implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0;
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Forward placement cannot request saved native tables.' ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'Forward placement cannot run saved native proof.' ); }
}
final class PlacementBoundaryNativeProbe implements QuoteNativeCaptureSource {
	public int $calls = 0;
	public function __construct( private QuoteOwner $owner ) {}
	public function current_owner(): QuoteOwner { ++$this->calls; return $this->owner; }
	public function capture(): QuoteNativeState { ++$this->calls; throw new \LogicException( 'Forward placement cannot capture native facts.' ); }
	public function unchanged(): bool { ++$this->calls; throw new \LogicException( 'Forward placement cannot read native state.' ); }
}
