<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Handoff;

use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader,QuoteId};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures,QuoteFixtures,QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;

/** Original bytes exported from the approved actual P03 master, independently of the new readers. */
final class LegacyWirePreservationTest extends TestCase {

	public function test_original_quote_and_snapshot_bytes_and_digest_domains_are_unchanged(): void {
		$baseline = $this->baseline();
		$id = QuoteId::from_string( '00000000-0000-4000-8000-000000000001' );
		foreach ( [ 'fixture', 'legacy' ] as $kind ) {
			$context = 'legacy' === $kind ? LegacyQuoteProviderFixtures::context() : QuoteFixtures::context();
			$terms = 'legacy' === $kind ? LegacyQuoteProviderFixtures::terms() : QuoteFixtures::terms();
			$header = QuoteHeader::issue( $id, QuoteFixtures::owner(), $context, $terms, QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'issue' ), 'accept' => QuoteFixtures::digest( 'accept' ), 'invalidate' => QuoteFixtures::digest( 'invalidate' ) ], 'legacy' === $kind ? 'legacy_fixed_base_v1' : 'fixture_v1', 1, QuoteFixtures::reference( $id ) );
			$expected = $baseline['vectors'][$kind];
			self::assertSame( $expected['context_json'], $context->to_private_json() );
			self::assertSame( $expected['context_digest'], $context->digest() );
			self::assertSame( $expected['terms_json'], $terms->to_private_json() );
			self::assertSame( $expected['terms_digest'], $terms->digest() );
			self::assertSame( $expected['header_json'], $header->to_private_json() );
			self::assertSame( $expected['body_digest'], $header->body_digest() );
			if ( 'legacy' === $kind ) {
				$envelope = DeliveryQuoteSnapshotEnvelope::from_captured( $header, $context, $terms, QuoteFixtures::time()->plus_seconds( 1 ), QuoteId::from_string( '00000000-0000-4000-8000-000000000002' ), QuoteFixtures::digest( 'context' ) );
				self::assertSame( $expected['envelope_json'], $envelope->to_private_json() );
			}
		}
	}

	public function test_original_issued_accepted_and_stripped_durable_rows_are_unchanged(): void {
		$baseline = $this->baseline();
		$id = QuoteId::from_string( '00000000-0000-4000-8000-000000000001' );
		foreach ( [ 'issued', 'accepted', 'stripped' ] as $state ) {
			self::assertSame( $baseline['vectors']['stored_' . $state], QuoteStorageFixtures::quote( 1, 1, $id, $state )->row() );
		}
	}

	private function baseline(): array {
		$file = dirname( __DIR__, 3 ) . '/Support/ServicePromise/Handoff/legacy-v1-wire-c625.json';
		self::assertSame( '3d5f929cc05b65aa3b88d30511453102f83a518bfb7dd1f6f184a51a91cd2536', hash_file( 'sha256', $file ) );
		$baseline = json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'c625a59bb61493e00d10b2802a08bac852b27b26', $baseline['source_head'] );
		return $baseline;
	}
}
