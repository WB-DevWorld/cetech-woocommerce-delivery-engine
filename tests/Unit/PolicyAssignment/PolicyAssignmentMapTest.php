<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentMap as Map;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentDomainFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyAssignmentMapTest extends TestCase {
	public function test_same_scope_selection_uses_offer_then_default_without_scope_inheritance(): void {
		$original = Map::empty( 1, 'return_policy_v1' );
		$map = $original->with_instruction( 0, F::slot() )->with_instruction( 4, [ 'mode' => 'disabled' ] )->with_instruction( 7, [ 'mode' => 'inherit' ] );
		self::assertSame( [ 'instruction' => F::slot(), 'slot' => 0 ], $map->instruction( 2 ) );
		self::assertSame( [ 'instruction' => [ 'mode' => 'disabled' ], 'slot' => 4 ], $map->instruction( 4 ) );
		self::assertSame( [ 'instruction' => F::slot(), 'slot' => 0 ], $map->instruction( 7 ) );
		self::assertSame( [ 'mode' => 'inherit' ], $original->facts()['default'] );
		self::assertNotSame( $original->digest(), $map->digest() );
		self::assertSame( $map->json(), Map::from_json( 1, 'return_policy_v1', $map->json() )->json() );
	}
	public function test_numeric_order_is_canonical_even_near_integer_limit_and_input_mutation_is_detached(): void {
		$map = Map::empty( 1, 'return_policy_v1' ); $data = $map->facts();
		$data['offers']->{(string) PHP_INT_MAX} = F::slot( 102 ); $data['offers']->{(string) ( PHP_INT_MAX - 1 )} = F::slot( 103 );
		$data['offers']->{'2'} = [ 'mode' => 'disabled' ]; $data['offers']->{'1'} = [ 'mode' => 'inherit' ];
		$map = Map::from_array( 1, 'return_policy_v1', $data );
		self::assertSame( [ '1', '2', (string) ( PHP_INT_MAX - 1 ), (string) PHP_INT_MAX ], array_map( 'strval', array_keys( get_object_vars( $map->facts()['offers'] ) ) ) );
		$data['offers']->{'2'} = F::slot();
		$projection = $map->facts(); $projection['offers']->{'2'} = F::slot();
		self::assertSame( 'disabled', $map->instruction( 2 )['instruction']['mode'] );
	}
	public function test_family_site_definition_and_every_slot_bind_digest(): void {
		$return = Map::empty( 1, 'return_policy_v1' )->with_instruction( 1, F::slot() );
		$refund = Map::empty( 1, 'refund_policy_v1' )->with_instruction( 1, F::slot() );
		$site = Map::empty( 2, 'return_policy_v1' )->with_instruction( 1, F::slot() );
		self::assertCount( 3, array_unique( [ $return->digest(), $refund->digest(), $site->digest() ] ) );
		self::assertNotSame( $return->digest(), $return->with_instruction( 2, F::slot() )->digest() );
	}
	#[DataProvider( 'bad_maps' )]
	public function test_foreign_or_ambiguous_map_frames_are_refused( \Closure $change ): void {
		$data = Map::empty( 1, 'return_policy_v1' )->facts(); $change( $data );
		$this->expectException( \InvalidArgumentException::class ); Map::from_array( 1, 'return_policy_v1', $data );
	}
	public static function bad_maps(): array {
		return [
			'wrong format' => [ static function( &$d ) { $d['format'] = 2; } ],
			'string format' => [ static function( &$d ) { $d['format'] = '1'; } ],
			'foreign site' => [ static function( &$d ) { $d['site_id'] = 2; } ],
			'foreign family' => [ static function( &$d ) { $d['family'] = 'refund_policy_v1'; } ],
			'foreign definition' => [ static function( &$d ) { $d['definition_hash'] = str_repeat( 'a', 64 ); } ],
			'extra field' => [ static function( &$d ) { $d['author'] = 9; } ],
			'array offers' => [ static function( &$d ) { $d['offers'] = []; } ],
			'zero offer' => [ static function( &$d ) { $d['offers']->{'0'} = [ 'mode' => 'inherit' ]; } ],
			'leading zero' => [ static function( &$d ) { $d['offers']->{'01'} = [ 'mode' => 'inherit' ]; } ],
			'negative offer' => [ static function( &$d ) { $d['offers']->{'-1'} = [ 'mode' => 'inherit' ]; } ],
			'plus offer' => [ static function( &$d ) { $d['offers']->{'+1'} = [ 'mode' => 'inherit' ]; } ],
			'float offer' => [ static function( &$d ) { $d['offers']->{'1.0'} = [ 'mode' => 'inherit' ]; } ],
			'exponent offer' => [ static function( &$d ) { $d['offers']->{'1e2'} = [ 'mode' => 'inherit' ]; } ],
			'overflow offer' => [ static function( &$d ) { $d['offers']->{(string) PHP_INT_MAX . '0'} = [ 'mode' => 'inherit' ]; } ],
			'over offer count' => [ static function( &$d ) { for ( $i = 1; $i <= 33; ++$i ) { $d['offers']->{$i} = [ 'mode' => 'inherit' ]; } } ],
			'unsupported mode' => [ static function( &$d ) { $d['default'] = [ 'mode' => 'automatic' ]; } ],
			'null default' => [ static function( &$d ) { $d['default'] = null; } ],
			'missing uuid' => [ static function( &$d ) { $d['default'] = [ 'mode' => 'assigned' ]; } ],
			'inherit extra reference' => [ static function( &$d ) { $d['default'] = [ 'mode' => 'inherit', 'policy_uuid' => F::uuid( 1 ) ]; } ],
			'disabled extra reference' => [ static function( &$d ) { $d['default'] = [ 'mode' => 'disabled', 'policy_uuid' => null ]; } ],
			'bad uuid' => [ static function( &$d ) { $d['default'] = [ 'mode' => 'assigned', 'policy_uuid' => 'not-a-uuid' ]; } ],
		];
	}
	#[DataProvider( 'bad_json' )]
	public function test_noncanonical_or_duplicate_persisted_bytes_are_refused( string $kind ): void {
		$json = Map::empty( 1, 'return_policy_v1' )->json();
		$json = match ( $kind ) {
			'whitespace' => ' ' . $json,
			'duplicate' => str_replace( '"format":1', '"format":1,"format":1', $json ),
			'list offers' => str_replace( '"offers":{}', '"offers":[]', $json ),
			'oversize' => str_repeat( ' ', Map::MAX_BYTES + 1 ),
		};
		$this->expectException( \InvalidArgumentException::class ); Map::from_json( 1, 'return_policy_v1', $json );
	}
	public static function bad_json(): array { return array_map( static fn( $x ) => [ $x ], [ 'whitespace', 'duplicate', 'list offers', 'oversize' ] ); }
	public function test_boundary_offer_map_and_outer_envelope_fit_without_truncation(): void {
		$map = Map::empty( 1, 'return_policy_v1' )->with_instruction( 0, F::slot() );
		for ( $i = 0; $i < 32; ++$i ) { $map = $map->with_instruction( PHP_INT_MAX - $i, F::slot( 100 + $i ) ); }
		self::assertLessThanOrEqual( 4096, strlen( $map->json() ) ); self::assertCount( 32, get_object_vars( $map->facts()['offers'] ) );
		$this->expectException( \InvalidArgumentException::class ); $map->with_instruction( 1, F::slot() );
	}
}
