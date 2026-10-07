<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use Throwable;

/** Internal serializers only. Adopters must authorize shopper ownership before loading. */
final class QuoteProjection {
	private const SECTION_CAPABILITIES = [ 'cost' => 'view_private_delivery_costs', 'origin' => 'view_private_origins', 'rate' => 'manage_delivery_rate_cards' ];

	public static function for_shopper( DeliveryQuote $quote, QuoteTime $at, RequestContext $request ): QuoteProjectionResult {
		return QuoteProjectionResult::shopper( $quote, $at, $request );
	}

	/** A diagnostic is neither quote acceptance nor a material mutation/audit. */
	public static function for_diagnostic_log( DeliveryQuote $quote, QuoteTime $at, RequestContext $request ): QuoteProjectionResult {
		return QuoteProjectionResult::diagnostic( $quote, $at, $request );
	}

	/**
	 * $load_header returns QuoteHeader only. Each optional section loader returns
	 * one QuotePrivateSection, never an arbitrary body/array or full QuoteTerms.
	 * Current purpose/site/object authority is supplied by the trusted adopter.
	 */
	public static function for_admin( QuoteProjectionTarget $target, RequestContext $request, callable $authorize, callable $load_header, array $section_loaders = [] ): QuoteProjectionResult|ContractError {
		if ( count( $section_loaders ) > 3 ) { return self::error( 'invalid_input', $request ); }
		foreach ( $section_loaders as $section => $loader ) {
			if ( ! is_string( $section ) || ! isset( self::SECTION_CAPABILITIES[ $section ] ) || ! is_callable( $loader ) ) { return self::error( 'invalid_input', $request ); }
		}
		if ( ! self::authorized( $authorize, $target, 'view_delivery_diagnostics', 'quote_explanation' ) ) { return self::error( 'not_authorized', $request ); }
		try { $header = $load_header( $target ); } catch ( Throwable ) { return self::load_error( $authorize, $target, $request ); }
		if ( ! $header instanceof QuoteHeader || ! $target->matches_header( $header )
			|| ! self::authorized( $authorize, $target, 'view_delivery_diagnostics', 'quote_explanation' ) ) { return self::error( 'not_authorized', $request ); }
		$sections = [];
		foreach ( $section_loaders as $section => $loader ) {
			$capability = self::SECTION_CAPABILITIES[ $section ];
			$purpose = 'quote_' . $section;
			if ( ! self::authorized( $authorize, $target, 'view_delivery_diagnostics', 'quote_explanation' )
				|| ! self::authorized( $authorize, $target, $capability, $purpose ) ) { return self::error( 'not_authorized', $request ); }
			try { $facts = $loader( $target ); } catch ( Throwable ) { return self::load_error( $authorize, $target, $request, $capability, $purpose ); }
			if ( ! $facts instanceof QuotePrivateSection || $section !== $facts->section() || ! $facts->matches( $target )
				|| ! self::authorized( $authorize, $target, 'view_delivery_diagnostics', 'quote_explanation' )
				|| ! self::authorized( $authorize, $target, $capability, $purpose ) ) { return self::error( 'not_authorized', $request ); }
			$sections[] = $facts;
		}
		if ( ! self::authorized( $authorize, $target, 'view_delivery_diagnostics', 'quote_explanation' ) ) { return self::error( 'not_authorized', $request ); }
		foreach ( $section_loaders as $section => $_loader ) {
			if ( ! self::authorized( $authorize, $target, self::SECTION_CAPABILITIES[ $section ], 'quote_' . $section ) ) { return self::error( 'not_authorized', $request ); }
		}
		return QuoteProjectionResult::admin( $header, $target, $request, $sections );
	}

	private static function authorized( callable $authorize, QuoteProjectionTarget $target, string $capability, string $purpose ): bool {
		try { return true === $authorize( $target, $capability, $purpose ); } catch ( Throwable ) { return false; }
	}

	private static function load_error( callable $authorize, QuoteProjectionTarget $target, RequestContext $request, ?string $capability = null, ?string $purpose = null ): ContractError {
		$allowed = self::authorized( $authorize, $target, 'view_delivery_diagnostics', 'quote_explanation' );
		if ( null !== $capability && null !== $purpose ) { $allowed = $allowed && self::authorized( $authorize, $target, $capability, $purpose ); }
		return self::error( $allowed ? 'temporarily_unavailable' : 'not_authorized', $request );
	}

	private static function error( string $code, RequestContext $request ): ContractError { return new ContractError( $code, $request, 'invalid_input' === $code ? 'reload_and_submit' : 'contact_support' ); }
}
