<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Exact adoption and upstream flags, reread through the final owned SQL unit. */
final readonly class QuotePlacementPolicyFence implements QuotePlacementSavedEvidenceGuard {
	public function __construct( private int $site, private string $prefix, private array $rows ) {}
	public static function names(): array {
		return [ QuotePlacementActivation::OPTION, ...array_map( static fn( string $flag ): string => 'cetech_de_' . $flag, [ ...ClassicCheckoutRuntimeActivation::CHAIN, 'enable_blocks_adapter' ] ) ];
	}
	public static function read( OperationSession $session ): array {
		$prefix = $session->table_prefix();
		if ( $session->is_retired() || ! $session->in_transaction() || ! preg_match( '/\A[a-zA-Z0-9_]{1,30}\z/D', $prefix ) ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
		$names = implode( ',', array_map( static fn( string $name ): string => $session->prepare( '%s', $name ), self::names() ) );
		$rows = $session->get_results( "SELECT option_id,option_name,LEFT(option_value,1025) AS option_value,autoload FROM `{$prefix}options` WHERE option_name IN ({$names}) ORDER BY option_name LIMIT 11 FOR UPDATE" );
		if ( ! is_array( $rows ) || count( $rows ) > 10 ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_keys( $row ) !== [ 'option_id', 'option_name', 'option_value', 'autoload' ] ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
			foreach ( $row as &$cell ) { if ( ! is_string( $cell ) && ! is_int( $cell ) ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); } $cell = (string) $cell; if ( strlen( $cell ) > 1024 ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); } } unset( $cell );
			if ( ! in_array( $row['option_name'], self::names(), true ) || isset( $out[$row['option_name']] ) || ! preg_match( '/\A[1-9][0-9]{0,18}\z/D', $row['option_id'] ) ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
			$out[$row['option_name']] = $row;
		}
		return $out;
	}
	public static function flags_ready( array $rows ): bool {
		foreach ( self::names() as $name ) { if ( QuotePlacementActivation::OPTION !== $name && '1' !== ( $rows[$name]['option_value'] ?? null ) ) { return false; } }
		return true;
	}
	public function tables( OperationSession $session ): array {
		if ( $this->site !== $session->site_id() || $this->prefix !== $session->table_prefix() ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
		return [ $this->prefix . 'options' ];
	}
	public function verify( OperationSession $session, QuoteBinding $binding ): bool {
		try { $this->tables( $session ); return $binding->row()['site_id'] === $this->site && $this->rows === self::read( $session ) && self::flags_ready( $this->rows ) && QuotePlacementActivation::decode( $this->rows[QuotePlacementActivation::OPTION]['option_value'] ?? null )['enabled']; }
		catch ( \Throwable ) { return false; }
	}
}
