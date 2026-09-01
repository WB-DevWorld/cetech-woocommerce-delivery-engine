<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\WPML;

use CetechDeliveryEngine\Integrations\WPML\WpmlStringTranslationApi;

/**
 * In-memory WPML String Translation seam. Does not define WPML hooks or constants.
 */
final class FakeWpmlStringTranslationApi implements WpmlStringTranslationApi {

	public bool $wpml = false;

	public bool $st = false;

	/** @var array<string, string> */
	public array $registered = [];

	/** @var array<string, string> */
	public array $translations = [];

	public int $register_calls = 0;

	public function wpml_present(): bool {
		return $this->wpml;
	}

	public function string_translation_available(): bool {
		return $this->wpml && $this->st;
	}

	public function register_single_string( string $context, string $name, string $value ): void {
		unset( $context );
		++$this->register_calls;
		$this->registered[ $name ] = $value;
	}

	public function translate_single_string( string $original, string $context, string $name ): string {
		unset( $context );

		return $this->translations[ $name ] ?? $original;
	}
}
