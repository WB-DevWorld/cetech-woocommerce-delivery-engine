<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

/**
 * Presentation-only translator for administrator-entered Delivery Engine public copy.
 *
 * Does not mutate canonical DE tables. Does not depend on enable_wpml_adapter.
 * Returns the canonical source text when WPML/String Translation is absent,
 * no translation exists, or the source is blank.
 */
final class WpmlDynamicStringTranslator {

	public function __construct(
		private WpmlStringTranslationApi $api = new WpmlWordPressStringTranslationApi()
	) {
	}

	public function is_wpml_present(): bool {
		return $this->api->wpml_present();
	}

	public function is_string_translation_available(): bool {
		return $this->api->string_translation_available();
	}

	public function register( string $name, string $value ): void {
		$value = $this->normalize_source( $value );

		if ( '' === $value || ! $this->is_string_translation_available() ) {
			return;
		}

		$this->api->register_single_string( WpmlPublicStringNames::CONTEXT, $name, $value );
	}

	public function translate( string $name, string $source ): string {
		$source = $this->normalize_source( $source );

		if ( '' === $source || ! $this->is_string_translation_available() ) {
			return $source;
		}

		$translated = $this->api->translate_single_string(
			$source,
			WpmlPublicStringNames::CONTEXT,
			$name
		);
		$translated = $this->normalize_source( $translated );

		return '' !== $translated ? $translated : $source;
	}

	private function normalize_source( string $value ): string {
		return trim( $value );
	}
}
