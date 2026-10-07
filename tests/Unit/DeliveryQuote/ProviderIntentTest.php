<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class ProviderIntentTest extends TestCase {
	public function test_canonical_original_intent_excludes_token_attempt_clock_and_correlation(): void {
		$first = $this->command(); $retry = $this->command( token: 'different_token' ); $request_a = RequestContext::create(); $request_b = RequestContext::create(); $id = QuoteId::generate();
		self::assertNotSame( $request_a->correlation_id, $request_b->correlation_id ); self::assertSame( $first->intent_digest(), $retry->intent_digest() ); self::assertNotSame( $first->namespace_hashes( $id )['issue'], $retry->namespace_hashes( $id )['issue'] );
		self::assertSame( $first->intent_digest(), $this->command()->intent_digest() ); self::assertSame( $first->namespace_hashes( $id ), $this->command()->namespace_hashes( $id ) ); self::assertSame( 3, count( array_unique( $first->namespace_hashes( $id ) ) ) );
	}
	public function test_namespaces_and_original_intent_bind_exact_site_customer_session_and_key_epoch(): void {
		$owner = QuoteFixtures::owner()->facts(); $first = $this->command(); $id = QuoteId::generate();
		foreach ( [ 'site_id' => 2, 'kind' => 'customer', 'principal_hash' => QuoteFixtures::digest( 'other_principal' ), 'session_hash' => QuoteFixtures::digest( 'other_session' ) ] as $key => $value ) {
			$changed = $this->command( owner: QuoteOwner::from_array( array_replace( $owner, [ $key => $value ] ) ) ); self::assertNotSame( $first->intent_digest(), $changed->intent_digest() ); foreach ( [ 'issue', 'accept', 'invalidate' ] as $purpose ) { self::assertNotSame( $first->namespace_hashes( $id )[$purpose], $changed->namespace_hashes( $id )[$purpose] ); }
		}
		$context = QuoteFixtures::context()->private_facts(); $context['destination']['key_epoch'] = 'new_key'; $owner['key_epoch'] = 'new_key'; $changed = $this->command( QuoteOwner::from_array( $owner ), QuoteContext::from_array( $context ) ); self::assertNotSame( $first->intent_digest(), $changed->intent_digest() ); self::assertNotSame( $first->namespace_hashes( $id ), $changed->namespace_hashes( $id ) );
	}
	public function test_equal_canonical_members_and_material_or_provider_edits_have_correct_intent_identity(): void {
		$base = QuoteFixtures::context()->private_facts(); $base['lines'][] = array_replace( $base['lines'][0], [ 'line_key' => 'line_two', 'product_id' => 11 ] ); $base['groups'][0]['line_keys'][] = 'line_two';
		$reordered = $base; $reordered['lines'] = array_reverse( $reordered['lines'] ); $reordered['groups'][0]['line_keys'] = array_reverse( $reordered['groups'][0]['line_keys'] );
		$a = $this->command( context: QuoteContext::from_array( $base ) ); $b = $this->command( context: QuoteContext::from_array( $reordered ) ); self::assertSame( $a->intent_digest(), $b->intent_digest() );
		$changed = $base; $changed['lines'][0]['quantity'] = '3'; self::assertNotSame( $a->intent_digest(), $this->command( context: QuoteContext::from_array( $changed ) )->intent_digest() );
		$changed = $base; $changed['destination']['digest'] = QuoteFixtures::digest( 'other_destination' ); self::assertNotSame( $a->intent_digest(), $this->command( context: QuoteContext::from_array( $changed ) )->intent_digest() );
		self::assertNotSame( $a->intent_digest(), QuoteIssueCommand::create( QuoteFixtures::owner(), QuoteContext::from_array( $base ), 'other_provider', 1, 'fixture_v1', 1, 'token' )->intent_digest() );
	}
	public function test_quote_specific_transition_namespaces_never_alias_different_quotes(): void {
		$command = $this->command(); $a = $command->namespace_hashes( QuoteId::generate() ); $b = $command->namespace_hashes( QuoteId::generate() );
		self::assertSame( $a['issue'], $b['issue'] ); self::assertNotSame( $a['accept'], $b['accept'] ); self::assertNotSame( $a['invalidate'], $b['invalidate'] );
	}
	public function test_command_generic_serialization_is_refused(): void { $this->expectException( \LogicException::class ); json_encode( $this->command(), JSON_THROW_ON_ERROR ); }
	private function command( ?QuoteOwner $owner = null, ?QuoteContext $context = null, string $token = 'original_token' ): QuoteIssueCommand { return QuoteIssueCommand::create( $owner ?? QuoteFixtures::owner(), $context ?? QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $token ); }
}
