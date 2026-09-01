<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

/**
 * Narrow seam over WPML String Translation public hooks.
 *
 * Production uses {@see WpmlWordPressStringTranslationApi}. Tests inject a fake.
 * Never writes wp_icl_strings directly.
 */
interface WpmlStringTranslationApi {

	public function wpml_present(): bool;

	public function string_translation_available(): bool;

	public function register_single_string( string $context, string $name, string $value ): void;

	public function translate_single_string( string $original, string $context, string $name ): string;
}
