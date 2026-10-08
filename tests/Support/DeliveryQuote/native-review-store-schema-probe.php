<?php
declare(strict_types=1);

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteResult,CartQuoteReviewService};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime;

define( 'ARRAY_A', 'ARRAY_A' );
function __( string $text, string $domain = '' ): string { return $text; }
function woocommerce_store_api_register_endpoint_data( array $args ): void { $GLOBALS['endpoints'][] = $args; }
function woocommerce_store_api_register_update_callback( array $args ): void { $GLOBALS['updates'][] = $args; }

/**
 * Pinned Woo 11.1.2 AbstractSchema request-default and nullable recursion semantics.
 * Readonly is deliberately not consulted: the native consumer does not consult it.
 * The finite type check below covers only the native traversal reached by omission;
 * actual Woo/WP validation and final POST are independently qualified in WordPress.
 */
final class NativeReviewSchemaConsumer {
	public function defaults( array $properties ): array {
		$defaults = [];
		foreach ( $properties as $key => $property ) {
			if ( isset( $property['arg_options']['default'] ) ) { $defaults[$key] = $property['arg_options']['default']; }
			elseif ( isset( $property['properties'] ) ) { $defaults[$key] = $this->defaults( $property['properties'] ); }
		}
		return $defaults;
	}
	public function validates( array $properties, mixed $values ): bool {
		foreach ( $properties as $key => $property ) {
			$current = $values[$key] ?? null;
			$types = is_array( $property['type'] ) ? $property['type'] : [ $property['type'] ];
			if ( empty( $current ) && in_array( 'null', $types, true ) ) { continue; }
			$valid = false;
			foreach ( $types as $type ) {
				$valid = $valid || match ( $type ) { 'object' => is_array( $current ) || is_object( $current ), 'integer' => is_int( $current ), 'string' => is_string( $current ), 'boolean' => is_bool( $current ), 'array' => is_array( $current ), 'null' => null === $current, default => false };
			}
			if ( ! $valid || isset( $property['properties'] ) && ! $this->validates( $property['properties'], $current ) ) { return false; }
		}
		return true;
	}
}

final class NativeReviewSchemaService implements CartQuoteReviewService {
	public array $calls = [];
	public function current( RequestContext $request ): CartQuoteResult { $this->calls[] = 'current'; return CartQuoteResult::create( 'changed', 7, $request ); }
	public function refresh( string $token, int $generation, RequestContext $request ): CartQuoteResult { $this->calls[] = 'refresh'; throw new LogicException( 'Spoofed projection reached mutation.' ); }
	public function confirm( int $generation, RequestContext $request ): CartQuoteResult { $this->calls[] = 'confirm'; throw new LogicException( 'Spoofed projection reached mutation.' ); }
	public function retry( int $generation, RequestContext $request ): CartQuoteResult { $this->calls[] = 'retry'; throw new LogicException( 'Spoofed projection reached mutation.' ); }
}

$GLOBALS['endpoints'] = []; $GLOBALS['updates'] = [];
$service = new NativeReviewSchemaService(); $runtime = new QuoteReviewRuntime( $service, true ); $runtime->register_store_api();
$consumer = new NativeReviewSchemaConsumer(); $out = [ 'endpoints' => array_column( $GLOBALS['endpoints'], 'endpoint' ) ];
if ( 'omitted' === ( $argv[1] ?? '' ) ) {
	foreach ( $GLOBALS['endpoints'] as $endpoint ) {
		// This object|null namespace is authored by Woo ExtendSchema, not this plugin.
		$schema = [ $endpoint['namespace'] => [ 'type' => [ 'object', 'null' ], 'properties' => ( $endpoint['schema_callback'] )() ] ];
		$defaults = $consumer->defaults( $schema );
		$out['empty_namespace_defaults'][] = [] === $defaults[$endpoint['namespace']];
		$out['native_validation'][] = $consumer->validates( $schema, $defaults );
	}
} elseif ( 'spoof' === ( $argv[1] ?? '' ) ) {
	$projection = [ 'contract_version' => 1, 'status' => 'confirmed', 'generation' => 999, 'quote' => [ 'quote_id' => '3b319753-c651-4dd6-8a23-9af9bc6f48c1', 'currently_applicable' => true, 'money' => [] ], 'can_confirm' => true ];
	foreach ( $GLOBALS['endpoints'] as $endpoint ) {
		$facts = ( $endpoint['data_callback'] )( $projection );
		$out['response_statuses'][] = $facts['status']; $out['response_generations'][] = $facts['generation']; $out['response_quotes'][] = $facts['quote'];
	}
	foreach ( [ [ 'action' => 'confirm', 'generation' => 7 ], [ 'action' => 'refresh', 'generation' => 7, 'review_token' => '3b319753-c651-4dd6-8a23-9af9bc6f48c1' ] ] as $command ) {
		try { $runtime->handle_store_api_update( [ ...$command, 'quote' => $projection['quote'] ] ); $out['spoofed_commands_refused'][] = false; }
		catch ( RuntimeException ) { $out['spoofed_commands_refused'][] = true; }
	}
} else { throw new LogicException( 'Unknown schema probe mode.' ); }
$out['service_calls'] = $service->calls;
echo json_encode( $out, JSON_THROW_ON_ERROR ), "\n";
