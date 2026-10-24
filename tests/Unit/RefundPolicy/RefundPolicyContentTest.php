<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RefundPolicy;

use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundPolicyContentTest extends TestCase {

	public function test_supplied_unicode_and_multiline_text_roundtrips_without_normalization(): void {
		$facts = self::facts();
		$facts['name'] = '  Fixture café 📦  ';
		$facts['customer_title'] = '  示例 — Fixture  ';
		$facts['summary'] = "Résumé\r\nSecond line\twith spacing  ";
		$facts['full_text'] = "  Synthetic fixture α\n\nSecond paragraph\rThird line\t✓  ";
		$facts['exceptions'] = "Fixture exception\r\n\tبيانات";
		$content = RefundPolicyContent::from_array( $facts );

		self::assertSame( 1, RefundPolicyContent::FORMAT );
		self::assertSame( 'refund_policy_v1', RefundPolicyContent::FAMILY );
		self::assertSame( $facts, $content->facts() );
		self::assertSame( self::encode( $facts ), $content->json() );
		self::assertSame( $facts, RefundPolicyContent::from_json( $content->json() )->facts() );
		self::assertSame( [ 'content_json' => $content->json() ], $content->to_rule_payload() );
		self::assertSame( $content->json(), RefundPolicyContent::from_rule_payload( $content->to_rule_payload() )->json() );
		self::assertInstanceOf( RuleSchema::class, RefundPolicyContent::payload_schema() );
	}

	public function test_empty_exceptions_are_explicit_and_are_never_filled_with_default_terms(): void {
		$facts = self::facts();
		$content = RefundPolicyContent::from_array( $facts );
		self::assertSame( '', $content->facts()['exceptions'] );
		self::assertCount( 5, $content->facts() );
		self::assertSame( $facts, RefundPolicyContent::from_rule_payload( $content->to_rule_payload() )->facts() );
	}

	public function test_key_order_and_json_whitespace_do_not_change_canonical_content_or_digest(): void {
		$facts = self::facts();
		$content = RefundPolicyContent::from_array( $facts );
		$reversed = array_reverse( $facts, true );
		$pretty = json_encode( (object) $reversed, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT );
		self::assertSame( $content->json(), RefundPolicyContent::from_array( $reversed )->json() );
		self::assertSame( $content->json(), RefundPolicyContent::from_json( " \r\n" . $pretty . "\t " )->json() );
		self::assertSame( $content->digest(), RefundPolicyContent::from_json( $pretty )->digest() );
		self::assertSame( hash( 'sha256', 'cetech-refund-policy-content-v1:' . $content->json() ), $content->digest() );
	}

	public function test_identical_authored_text_has_a_separate_domain_digest_from_return_policy(): void {
		$facts = self::facts();
		$refund = RefundPolicyContent::from_array( $facts );
		$return = \CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent::from_array( $facts );
		self::assertSame( $return->json(), $refund->json() );
		self::assertNotSame( $return->digest(), $refund->digest() );
		self::assertNotSame( $return::FAMILY, $refund::FAMILY );
		self::assertNotInstanceOf( $return::class, $refund );
	}

	#[DataProvider('content_fields')]
	public function test_every_supplied_field_is_committed_to_the_content_digest( string $field ): void {
		$facts = self::facts();
		$original = RefundPolicyContent::from_array( $facts );
		$facts[ $field ] .= ' changed';
		$changed = RefundPolicyContent::from_array( $facts );
		self::assertNotSame( $original->digest(), $changed->digest() );
		self::assertNotSame( $original->json(), $changed->json() );
		self::assertSame( $facts[ $field ], $changed->facts()[ $field ] );
	}

	public static function content_fields(): array {
		return array_combine( array_keys( self::facts() ), array_map( static fn( string $field ): array => [ $field ], array_keys( self::facts() ) ) );
	}

	public function test_input_references_and_exported_arrays_cannot_mutate_existing_content(): void {
		$facts = self::facts();
		$summary = $facts['summary'];
		$facts['summary'] =& $summary;
		$content = RefundPolicyContent::from_array( $facts );
		$original = $content->json();
		$digest = $content->digest();
		$summary = 'Different fixture input';
		$facts['full_text'] = 'Different fixture body';
		$exported = $content->facts();
		$exported['exceptions'] = 'Different exported fixture';
		$payload = $content->to_rule_payload();
		$payload['content_json'] = '{}';
		self::assertSame( $original, $content->json() );
		self::assertSame( $digest, $content->digest() );
		self::assertSame( self::facts(), $content->facts() );
	}

	#[DataProvider('invalid_facts')]
	public function test_incomplete_unknown_untyped_or_unsafe_supplied_content_is_rejected( array $facts ): void {
		$this->expectException( \InvalidArgumentException::class );
		RefundPolicyContent::from_array( $facts );
	}

	public static function invalid_facts(): array {
		$cases = [];
		foreach ( array_keys( self::facts() ) as $field ) {
			$data = self::facts(); unset( $data[ $field ] ); $cases[ 'missing_' . $field ] = [ $data ];
		}
		foreach ( [ 'format_version' => 2, 'legal_default' => 'invented', 0 => 'numeric key' ] as $key => $value ) {
			$data = self::facts(); $data[ $key ] = $value; $cases[ 'unknown_' . $key ] = [ $data ];
		}
		foreach ( [ 'name' => 1, 'customer_title' => true, 'summary' => [], 'full_text' => null, 'exceptions' => new \stdClass() ] as $field => $value ) {
			$data = self::facts(); $data[ $field ] = $value; $cases[ 'type_' . $field ] = [ $data ];
		}
		foreach ( [ 'name' => '', 'customer_title' => " \u{00a0}\u{3000}", 'summary' => " \r\n\t", 'full_text' => "\u{2003}\u{2028}" ] as $field => $value ) {
			$data = self::facts(); $data[ $field ] = $value; $cases[ 'blank_' . $field ] = [ $data ];
		}
		foreach ( [
			'name_lf' => [ 'name', "Fixture\nname" ],
			'title_tab' => [ 'customer_title', "Fixture\ttitle" ],
			'name_unicode_line_separator' => [ 'name', "Fixture\u{2028}name" ],
			'title_unicode_paragraph_separator' => [ 'customer_title', "Fixture\u{2029}title" ],
			'summary_nul' => [ 'summary', "Fixture\0text" ],
			'full_text_vertical_tab' => [ 'full_text', "Fixture\x0btext" ],
			'exceptions_delete' => [ 'exceptions', "Fixture\x7ftext" ],
			'full_text_unicode_control' => [ 'full_text', "Fixture\u{0085}text" ],
			'summary_invalid_utf8' => [ 'summary', "Fixture\xc3\x28" ],
		] as $case => [ $field, $value ] ) {
			$data = self::facts(); $data[ $field ] = $value; $cases[ $case ] = [ $data ];
		}
		return $cases;
	}

	#[DataProvider('invalid_json')]
	public function test_invalid_json_top_level_duplicate_keys_and_wire_values_are_rejected( string $json ): void {
		$this->expectException( \InvalidArgumentException::class );
		RefundPolicyContent::from_json( $json );
	}

	public static function invalid_json(): array {
		$valid = self::encode( self::facts() );
		return [
			'truncated' => [ substr( $valid, 0, -1 ) ],
			'list_root' => [ '[]' ],
			'null_root' => [ 'null' ],
			'scalar_root' => [ '"fixture"' ],
			'duplicate_name' => [ '{"name":"Other fixture",' . substr( $valid, 1 ) ],
			'escaped_duplicate_name' => [ '{"\\u006eame":"Other fixture",' . substr( $valid, 1 ) ],
			'invalid_utf8' => [ str_replace( 'Fixture name', "Fixture\xff", $valid ) ],
			'float_field' => [ str_replace( '"Fixture name"', '1.25', $valid ) ],
			'nonfinite' => [ str_replace( '"Fixture name"', 'NaN', $valid ) ],
		];
	}

	public function test_complete_body_limit_is_measured_in_encoded_bytes_without_truncation(): void {
		$data = self::facts(); $data['full_text'] = '';
		$data['full_text'] = str_repeat( 'x', OperationJson::MAX_BYTES - strlen( self::encode( $data ) ) );
		$json = self::encode( $data );
		self::assertSame( OperationJson::MAX_BYTES, strlen( $json ) );
		self::assertSame( $json, RefundPolicyContent::from_array( $data )->json() );
		self::assertSame( $json, RefundPolicyContent::from_json( $json )->json() );
		$data['full_text'] .= 'x';
		$this->expectException( \InvalidArgumentException::class );
		RefundPolicyContent::from_array( $data );
	}

	public function test_input_json_whitespace_counts_toward_the_body_wire_limit(): void {
		$json = RefundPolicyContent::from_array( self::facts() )->json();
		$at_limit = str_pad( $json, OperationJson::MAX_BYTES, ' ' );
		self::assertSame( $json, RefundPolicyContent::from_json( $at_limit )->json() );
		$this->expectException( \InvalidArgumentException::class );
		RefundPolicyContent::from_json( $at_limit . ' ' );
	}

	public function test_multibyte_text_cannot_bypass_the_complete_body_byte_limit(): void {
		$data = self::facts(); $data['full_text'] = str_repeat( '📦', 4096 );
		self::assertSame( OperationJson::MAX_BYTES, strlen( $data['full_text'] ) );
		$this->expectException( \InvalidArgumentException::class );
		RefundPolicyContent::from_array( $data );
	}

	public function test_exact_escaped_rule_payload_limit_is_enforced_separately_from_the_body(): void {
		$data = self::facts(); $data['full_text'] = '';
		$outer = self::encode( [ 'content_json' => self::encode( $data ) ] );
		$data['full_text'] = str_repeat( 'x', OperationJson::MAX_BYTES - strlen( $outer ) );
		$content = RefundPolicyContent::from_array( $data );
		$payload = $content->to_rule_payload();
		$wire = RefundPolicyContent::payload_schema()->encode( $payload );
		self::assertSame( OperationJson::MAX_BYTES, strlen( $wire ) );
		self::assertLessThan( OperationJson::MAX_BYTES, strlen( $content->json() ) );
		self::assertSame( $content->json(), RefundPolicyContent::from_rule_payload( $payload )->json() );
		$data['full_text'] .= 'x';
		$one_over = RefundPolicyContent::from_array( $data );
		self::assertSame( OperationJson::MAX_BYTES + 1, strlen( self::encode( [ 'content_json' => $one_over->json() ] ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$one_over->to_rule_payload();
	}

	public function test_json_escaping_cannot_hide_an_oversized_rule_payload(): void {
		$data = self::facts(); $data['full_text'] = str_repeat( '"', 5000 );
		$content = RefundPolicyContent::from_array( $data );
		self::assertLessThan( OperationJson::MAX_BYTES, strlen( $content->json() ) );
		self::assertGreaterThan( OperationJson::MAX_BYTES, strlen( self::encode( [ 'content_json' => $content->json() ] ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$content->to_rule_payload();
	}

	#[DataProvider('invalid_rule_payloads')]
	public function test_rule_payload_requires_one_typed_canonical_bounded_content_field( array $payload ): void {
		$this->expectException( \InvalidArgumentException::class );
		RefundPolicyContent::from_rule_payload( $payload );
	}

	public static function invalid_rule_payloads(): array {
		$json = self::encode( self::facts() );
		$oversized = self::facts(); $oversized['full_text'] = str_repeat( '"', 5000 );
		return [
			'missing' => [ [] ],
			'unknown' => [ [ 'content_json' => $json, 'status' => 'published' ] ],
			'untyped' => [ [ 'content_json' => self::facts() ] ],
			'noncanonical_whitespace' => [ [ 'content_json' => ' ' . $json ] ],
			'noncanonical_key_order' => [ [ 'content_json' => self::encode( array_reverse( self::facts(), true ) ) ] ],
			'duplicate_inner_key' => [ [ 'content_json' => '{"name":"Other fixture",' . substr( $json, 1 ) ] ],
			'escaped_outer_wire_oversized' => [ [ 'content_json' => self::encode( $oversized ) ] ],
		];
	}

	public function test_private_content_cannot_be_implicitly_exported_as_json(): void {
		$this->expectException( \LogicException::class );
		json_encode( RefundPolicyContent::from_array( self::facts() ), JSON_THROW_ON_ERROR );
	}

	public function test_private_content_cannot_be_implicitly_serialized(): void {
		$this->expectException( \LogicException::class );
		serialize( RefundPolicyContent::from_array( self::facts() ) );
	}

	public function test_unserialize_cannot_bypass_validated_factories(): void {
		$class = RefundPolicyContent::class;
		$this->expectException( \LogicException::class );
		unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' );
	}

	public function test_debug_projection_contains_no_supplied_text(): void {
		$content = RefundPolicyContent::from_array( self::facts() );
		self::assertSame( [ 'refund_policy_content' => 'redacted' ], $content->__debugInfo() );
	}

	private static function facts(): array {
		return [ 'name' => 'Fixture name', 'customer_title' => 'Fixture title', 'summary' => 'Synthetic fixture summary', 'full_text' => 'Synthetic fixture body only.', 'exceptions' => '' ];
	}

	private static function encode( array $data ): string {
		return json_encode( (object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
