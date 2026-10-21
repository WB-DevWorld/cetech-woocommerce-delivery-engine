<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest as Request, FixedPromotionBudgetReservation as Reservation, FixedPromotionBudgetState as State};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetRecordCodec as Codec, FixedPromotionBudgetSchema as Schema};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Private storage boundaries, without provisioning, current authority or a SQL server. */
final class FixedPromotionBudgetPersistenceContractsTest extends TestCase {
	private static ?Request $request = null;
	private function request(): Request { if ( null === self::$request ) { $provider = F::provider(); self::$request = Request::from_captured( F::header( $provider ), $provider->context(), $provider->terms(), F::key(), QuoteFixtures::digest( 'storage account' ) ); } return self::$request; }
	private function account(): State { $r = $this->request(); return State::create( $r->site_id(), $r->account_key(), $r->funding_source(), QuoteMoney::from_array( [ 'amount' => '10.00', 'currency' => 'GHS', 'precision' => 2 ] ) ); }
	private function reservation(): Reservation { $r = $this->request(); return Reservation::hold( $r, $r->quote_created_at(), $r->quote_created_at()->plus_seconds( 60 ) ); }
	private function row( string $kind ): array { return [ 'id' => '12' ] + ( 'account' === $kind ? Codec::account_values( $this->account() ) : Codec::reservation_values( $this->reservation() ) ); }
	private function decode( string $kind, array $row ): State|Reservation { return 'account' === $kind ? Codec::account_from_row( $row ) : Codec::reservation_from_row( $row ); }

	public function test_explicit_two_table_ddl_has_no_global_connection_or_default_provisioning(): void {
		$ddl = Schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'owned_delivery_engine_' );
		self::assertSame( Schema::SUFFIXES, array_keys( $ddl ) ); self::assertSame( 2, ( new \ReflectionMethod( Schema::class, 'create_table_statements' ) )->getNumberOfRequiredParameters() );
		self::assertSame( [ 'wp_7_delivery_engine_promotion_budget_accounts', 'wp_7_delivery_engine_promotion_budget_reservations' ], Schema::tables( 'wp_7_' ) );
		foreach ( $ddl as $suffix => $sql ) { self::assertStringContainsString( 'CREATE TABLE owned_delivery_engine_' . $suffix, $sql ); self::assertStringContainsString( 'ENGINE=InnoDB', $sql ); self::assertStringNotContainsString( 'FOREIGN KEY', $sql ); self::assertDoesNotMatchRegularExpression( '/^  .* DEFAULT /m', $sql ); self::assertStringContainsString( 'CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $sql ); }
		self::assertSame( [ 'site_id', 'account_key' ], Schema::indexes( Schema::ACCOUNTS_SUFFIX )['site_account']['columns'] ); self::assertSame( [ 'site_id', 'claim_key' ], Schema::indexes( Schema::RESERVATIONS_SUFFIX )['site_claim']['columns'] );
		self::assertSame( [ 'site_id', 'account_key', 'id' ], Schema::indexes( Schema::RESERVATIONS_SUFFIX )['account_claims']['columns'] );
	}
	public function test_budget_installation_is_registered_only_in_its_coordinated_migration_and_manifest(): void {
		self::assertSame( '12', SchemaVersion::TARGET ); self::assertCount( 41, DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES ); self::assertCount( 39, DataLifecycleManifest::RETAINED_SHIPMENT_PROMISE_DOMAIN_TABLE_SUFFIXES ); self::assertCount( 23, DataLifecycleManifest::FEATURE_FLAG_OPTIONS );
		$root = dirname( __DIR__, 3 ); $activation = (string) file_get_contents( $root . '/src/Bootstrap/Activator.php' );
		foreach ( Schema::SUFFIXES as $suffix ) { self::assertContains( $suffix, DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES ); self::assertNotContains( $suffix, DataLifecycleManifest::RETAINED_SHIPMENT_PROMISE_DOMAIN_TABLE_SUFFIXES ); self::assertStringNotContainsString( $suffix, $activation ); }
		self::assertStringNotContainsString( 'FixedPromotionBudgetSchema', $activation );
		$matches = [];
		foreach ( glob( $root . '/database/migrations/*.php' ) as $file ) { if ( str_contains( (string) file_get_contents( $file ), 'FixedPromotionBudget' ) ) { $matches[] = basename( $file ); } }
		self::assertSame( [ '20261021213000_create_promotion_budget_tables.php' ], $matches );
		$migration = require $root . '/database/migrations/' . $matches[0];
		self::assertInstanceOf( \CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface::class, $migration ); self::assertSame( '12', $migration->get_version() );
	}
	public static function invalid_prefixes(): array { return [ 'empty' => [ '' ], 'SQL' => [ 'wp_;DROP' ], 'dot' => [ 'db.wp_' ], 'backtick' => [ 'wp_`' ], 'oversized' => [ str_repeat( 'a', 30 ) ] ]; }
	#[DataProvider( 'invalid_prefixes' )]
	public function test_unbounded_or_nonidentifier_site_prefixes_refuse( string $prefix ): void { $this->expectException( \InvalidArgumentException::class ); Schema::tables( $prefix ); }
	public function test_ddl_rejects_an_unqualified_prefix_and_invalid_charset(): void { try { Schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4', 'wp_' ); self::fail( 'Unqualified prefix accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); } $this->expectException( \InvalidArgumentException::class ); Schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4; DROP TABLE x', 'wp_delivery_engine_' ); }
	public function test_roundtrip_uses_exact_canonical_private_body_and_domain_separated_digest(): void {
		foreach ( [ 'account', 'reservation' ] as $kind ) { $row = $this->row( $kind ); foreach ( [ 'site_id', 'format_version', 'revision' ] as $field ) { $row[$field] = (string) $row[$field]; } $body = 'account' === $kind ? $this->account()->to_private_json() : $this->reservation()->to_private_json(); self::assertSame( $body, $this->decode( $kind, $row )->to_private_json() ); $field = 'account' === $kind ? 'state_digest' : 'reservation_digest'; self::assertNotSame( hash( 'sha256', $body ), $row[$field] ); }
		self::assertArrayNotHasKey( 'id', Codec::account_values( $this->account() ) ); self::assertArrayNotHasKey( 'id', Codec::reservation_values( $this->reservation() ) );
	}
	public static function corrupt_rows(): array { $out = []; foreach ( [ 'account', 'reservation' ] as $kind ) { foreach ( [ 'id' => '0', 'site_id' => '2', 'account_key' => str_repeat( 'f', 64 ), 'format_version' => '2', 'revision' => '02' ] as $field => $value ) { $out[$kind . ' ' . $field] = [ $kind, $field, $value ]; } $out[$kind . ' body digest'] = [ $kind, 'account' === $kind ? 'state_digest' : 'reservation_digest', str_repeat( '0', 64 ) ]; } $out['reservation claim'] = [ 'reservation', 'claim_key', str_repeat( 'f', 64 ) ]; $out['reservation state'] = [ 'reservation', 'state', 'committed' ]; return $out; }
	#[DataProvider( 'corrupt_rows' )]
	public function test_every_identity_revision_state_or_body_digest_mirror_is_checked( string $kind, string $field, mixed $value ): void { $row = $this->row( $kind ); $row[$field] = $value; $this->expectException( \InvalidArgumentException::class ); $this->decode( $kind, $row ); }
	public static function row_shapes(): array { return [ 'account extra' => [ 'account', 'extra' ], 'account missing' => [ 'account', 'missing' ], 'reservation extra' => [ 'reservation', 'extra' ], 'reservation missing' => [ 'reservation', 'missing' ], 'account whitespace' => [ 'account', 'whitespace' ], 'reservation whitespace' => [ 'reservation', 'whitespace' ], 'account large' => [ 'account', 'large' ], 'reservation large' => [ 'reservation', 'large' ] ]; }
	#[DataProvider( 'row_shapes' )]
	public function test_unknown_fields_missing_fields_noncanonical_or_oversized_bodies_refuse( string $kind, string $mutation ): void {
		$row = $this->row( $kind ); $field = 'account' === $kind ? 'state_json' : 'reservation_json';
		if ( 'extra' === $mutation ) { $row['extra'] = 'x'; } elseif ( 'missing' === $mutation ) { unset( $row['id'] ); } elseif ( 'whitespace' === $mutation ) { $row[$field] = "\n" . $row[$field]; } else { $row[$field] = str_repeat( 'x', 'account' === $kind ? 4097 : 16385 ); }
		$this->expectException( \InvalidArgumentException::class ); $this->decode( $kind, $row );
	}
	public function test_sql_transport_bounds_payload_before_php_and_checks_exact_octet_marker(): void {
		foreach ( [ 'account' => Schema::ACCOUNTS_SUFFIX, 'reservation' => Schema::RESERVATIONS_SUFFIX ] as $kind => $suffix ) { $field = 'account' === $kind ? 'state_json' : 'reservation_json'; $cap = 'account' === $kind ? 4096 : 16384; self::assertStringContainsString( "CASE WHEN OCTET_LENGTH(`{$field}`) <= {$cap}", Codec::projection( $suffix ) ); self::assertStringContainsString( "OCTET_LENGTH(`{$field}`) AS `__{$field}_bytes`", Codec::projection( $suffix ) ); $row = $this->row( $kind ); self::assertSame( $row, Codec::bounded_row( $suffix, $row + [ '__' . $field . '_bytes' => (string) strlen( $row[$field] ) ] ) ); }
	}
	public static function bad_markers(): array { return [ 'missing' => [ 'missing' ], 'zero' => [ 0 ], 'short' => [ 1 ], 'oversize' => [ 4097 ], 'noncanonical' => [ '01' ], 'float' => [ 1.0 ], 'null' => [ null ], 'negative' => [ -1 ], 'extra' => [ 'extra' ], 'null body' => [ 'null body' ] ]; }
	#[DataProvider( 'bad_markers' )]
	public function test_truncated_unbounded_or_untrusted_transport_markers_refuse( mixed $value ): void { $row = $this->row( 'account' ); $marker = '__state_json_bytes'; if ( 'missing' !== $value ) { $row[$marker] = $value; } if ( 'extra' === $value ) { $row[$marker] = strlen( $row['state_json'] ); $row['other'] = 'x'; } if ( 'null body' === $value ) { $row[$marker] = 4097; $row['state_json'] = null; } $this->expectException( \InvalidArgumentException::class ); Codec::bounded_row( Schema::ACCOUNTS_SUFFIX, $row ); }
}
