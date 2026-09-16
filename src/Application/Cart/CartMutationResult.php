<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

/**
 * Outcome of a centralized cart-line customer-context mutation.
 *
 * @phpstan-type CartContents array<string, array<string, mixed>>
 */
final class CartMutationResult {

	public const CODE_OK = 'ok';

	public const CODE_MISSING_LINE = 'missing_line';

	public const CODE_INVALID_QUANTITY = 'invalid_quantity';

	public const CODE_INVALID_CONTEXT = 'invalid_context';

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly string $code,
		public readonly array $contents,
		public readonly ?string $source_key = null,
		public readonly ?string $target_key = null
	) {
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	public static function ok( array $contents, string $source_key, string $target_key ): self {
		return new self( true, self::CODE_OK, $contents, $source_key, $target_key );
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	public static function fail( string $code, array $contents ): self {
		return new self( false, $code, $contents );
	}
}
