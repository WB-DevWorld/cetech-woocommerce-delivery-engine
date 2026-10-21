<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetReadiness as Readiness, FixedPromotionBudgetSchema as Schema};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\FixedPromotionBudgetMetadataSession as Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/FixedPromotionBudgetMetadataSession.php';

/** Exact participant metadata on a passed active owner; no SQL transaction/concurrency claim. */
final class FixedPromotionBudgetReadinessTest extends TestCase {
	public function test_exact_metadata_is_verified_after_participant_validation_without_transaction_control(): void { $s = new Session(); ( new Readiness() )->assert_ready( $s ); self::assertSame( [ Schema::tables( 'wp_' ) ], $s->validated ); self::assertSame( 'VALIDATE TABLES', $s->reads[0] ); self::assertCount( 7, $s->reads ); self::assertSame( 0, $s->controls ); }
	public function test_integer_display_widths_are_the_only_type_normalization(): void { $s = new Session(); $s->metadata = static function ( string $sql, array $rows ): array { if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { foreach ( $rows as &$row ) { $row['Type'] = str_replace( [ 'bigint unsigned', 'smallint unsigned' ], [ 'bigint(20) unsigned', 'smallint(5) unsigned' ], $row['Type'] ); } } return $rows; }; ( new Readiness() )->assert_ready( $s ); self::assertSame( 0, $s->controls ); }
	public function test_default_charset_collation_is_read_on_the_same_owner(): void { $s = new Session(); $s->charset = 'DEFAULT CHARACTER SET utf8mb4'; ( new Readiness() )->assert_ready( $s ); self::assertSame( "SHOW CHARACTER SET LIKE 'utf8mb4'", $s->reads[1] ); }
	public static function bad_metadata(): array {
		$out = [];
		foreach ( [ 'engine', 'table collation', 'table identity', 'missing table', 'extra column', 'missing column', 'duplicate column', 'missing default key', 'missing collation key', 'column type', 'column null', 'column default', 'column extra', 'column collation', 'empty columns', 'too many columns', 'extra index', 'missing index', 'duplicate index part', 'missing subpart key', 'missing expression column', 'wrong unique', 'float unique', 'wrong index column', 'index prefix', 'index expression', 'index type', 'descending index', 'invisible index', 'null visibility', 'ignored index', 'index position', 'empty indexes' ] as $mutation ) { $out[$mutation] = [ $mutation ]; }
		return $out;
	}
	#[DataProvider( 'bad_metadata' )]
	public function test_incomplete_or_different_metadata_never_grants_readiness( string $mutation ): void {
		$s = new Session(); $s->metadata = static function ( string $sql, array $rows ) use ( $mutation ): array|null {
			if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { if ( 'engine' === $mutation ) { $rows['Engine'] = 'MyISAM'; } elseif ( 'table collation' === $mutation ) { $rows['Collation'] = 'utf8mb4_general_ci'; } elseif ( 'table identity' === $mutation ) { $rows['Name'] = 'foreign'; } elseif ( 'missing table' === $mutation ) { return null; } }
			if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) {
				if ( 'extra column' === $mutation ) { $rows[] = $rows[0]; $rows[array_key_last( $rows )]['Field'] = 'foreign'; }
				elseif ( 'missing column' === $mutation ) { array_pop( $rows ); }
				elseif ( 'duplicate column' === $mutation ) { $rows[] = $rows[0]; }
				elseif ( 'missing default key' === $mutation ) { unset( $rows[0]['Default'] ); }
				elseif ( 'missing collation key' === $mutation ) { unset( $rows[0]['Collation'] ); }
				elseif ( 'column type' === $mutation ) { $rows[0]['Type'] = 'bigint'; }
				elseif ( 'column null' === $mutation ) { $rows[0]['Null'] = 'YES'; }
				elseif ( 'column default' === $mutation ) { $rows[0]['Default'] = '0'; }
				elseif ( 'column extra' === $mutation ) { $rows[0]['Extra'] = ''; }
				elseif ( 'column collation' === $mutation ) { $rows[0]['Collation'] = 'ascii_bin'; }
				elseif ( 'empty columns' === $mutation ) { return []; }
				elseif ( 'too many columns' === $mutation ) { return array_fill( 0, 17, $rows[0] ); }
			}
			if ( str_starts_with( $sql, 'SHOW INDEX' ) ) {
				if ( 'extra index' === $mutation ) { $rows[0]['Key_name'] = 'foreign'; }
				elseif ( 'missing index' === $mutation ) { array_pop( $rows ); }
				elseif ( 'duplicate index part' === $mutation ) { $rows[] = $rows[0]; }
				elseif ( 'missing subpart key' === $mutation ) { unset( $rows[0]['Sub_part'] ); }
				elseif ( 'missing expression column' === $mutation ) { unset( $rows[0]['Column_name'] ); }
				elseif ( 'wrong unique' === $mutation ) { $rows[0]['Non_unique'] = '1'; }
				elseif ( 'float unique' === $mutation ) { $rows[0]['Non_unique'] = 0.0; }
				elseif ( 'wrong index column' === $mutation ) { $rows[0]['Column_name'] = 'site_id'; }
				elseif ( 'index prefix' === $mutation ) { $rows[0]['Sub_part'] = 1; }
				elseif ( 'index expression' === $mutation ) { $rows[0]['Expression'] = 'id + 1'; }
				elseif ( 'index type' === $mutation ) { $rows[0]['Index_type'] = 'HASH'; }
				elseif ( 'descending index' === $mutation ) { $rows[0]['Collation'] = 'D'; }
				elseif ( 'invisible index' === $mutation ) { $rows[0]['Visible'] = 'NO'; }
				elseif ( 'null visibility' === $mutation ) { $rows[0]['Visible'] = null; }
				elseif ( 'ignored index' === $mutation ) { $rows[0]['Ignored'] = 'YES'; }
				elseif ( 'index position' === $mutation ) { $rows[0]['Seq_in_index'] = '01'; }
				elseif ( 'empty indexes' === $mutation ) { return []; }
			}
			return $rows;
		};
		$this->expectException( \RuntimeException::class ); ( new Readiness() )->assert_ready( $s );
	}
	public static function owner_changes(): array { return [ 'retirement' => [ 'retired', true ], 'completion' => [ 'transaction', false ], 'site switch' => [ 'site', 2 ], 'prefix switch' => [ 'prefix', 'wp_2_' ], 'charset switch' => [ 'charset', 'DEFAULT CHARSET=utf8mb4' ] ]; }
	#[DataProvider( 'owner_changes' )]
	public function test_owner_loss_after_participant_validation_stops_before_metadata( string $field, mixed $value ): void { $s = new Session(); $s->on_query = static function ( string $sql, Session $s ) use ( $field, $value ): void { if ( 'VALIDATE TABLES' === $sql ) { $s->$field = $value; } }; try { ( new Readiness() )->assert_ready( $s ); self::fail( 'Changed owner accepted.' ); } catch ( \RuntimeException ) { self::assertSame( [ 'VALIDATE TABLES' ], $s->reads ); self::assertSame( 0, $s->controls ); } }
	#[DataProvider( 'owner_changes' )]
	public function test_owner_loss_during_metadata_stops_subsequent_queries( string $field, mixed $value ): void { $s = new Session(); $s->on_query = static function ( string $sql, Session $s ) use ( $field, $value ): void { if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { $s->$field = $value; } }; try { ( new Readiness() )->assert_ready( $s ); self::fail( 'Changed owner accepted.' ); } catch ( \RuntimeException ) { self::assertCount( 2, $s->reads ); self::assertSame( 0, $s->controls ); } }
	public function test_false_participant_validation_never_reads_schema(): void { $s = new Session(); $s->valid_tables = false; try { ( new Readiness() )->assert_ready( $s ); self::fail( 'Unvalidated participant accepted.' ); } catch ( \RuntimeException ) { self::assertSame( [ 'VALIDATE TABLES' ], $s->reads ); } }
	public function test_query_error_refuses_even_if_metadata_looks_valid(): void { $s = new Session(); $s->error = 2006; $this->expectException( \RuntimeException::class ); ( new Readiness() )->assert_ready( $s ); }
	public static function invalid_owners(): array { return [ 'site zero' => [ 'site', 0 ], 'retired' => [ 'retired', true ], 'not active' => [ 'transaction', false ], 'prefix' => [ 'prefix', 'wp_;' ] ]; }
	#[DataProvider( 'invalid_owners' )]
	public function test_invalid_initial_owner_runs_no_metadata_or_validation( string $field, mixed $value ): void { $s = new Session(); $s->$field = $value; try { ( new Readiness() )->assert_ready( $s ); self::fail( 'Invalid owner accepted.' ); } catch ( \RuntimeException ) { self::assertSame( [], $s->reads ); self::assertSame( [], $s->validated ); } }
	public function test_readiness_is_not_cached_between_owned_transactions(): void { $s = new Session(); $readiness = new Readiness(); $readiness->assert_ready( $s ); $s->reads = []; $s->valid_tables = false; try { $readiness->assert_ready( $s ); self::fail( 'Cached readiness survived a new participant check.' ); } catch ( \RuntimeException ) { self::assertCount( 2, $s->validated ); self::assertSame( [ 'VALIDATE TABLES' ], $s->reads ); } }
}
