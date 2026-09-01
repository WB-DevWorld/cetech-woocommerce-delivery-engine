<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

/**
 * WPML String Translation public API adapter.
 *
 * Register: do_action( 'wpml_register_single_string', $context, $name, $value )
 * Translate: apply_filters( 'wpml_translate_single_string', $original, $context, $name )
 *
 * Missing translation returns the original value. Empty register calls are ignored here
 * before they reach WPML. Never fatals when WPML or String Translation is absent.
 */
final class WpmlWordPressStringTranslationApi implements WpmlStringTranslationApi {

	public function wpml_present(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	public function string_translation_available(): bool {
		if ( ! $this->wpml_present() ) {
			return false;
		}

		if ( defined( 'WPML_ST_VERSION' ) ) {
			return true;
		}

		$has_register  = function_exists( 'has_action' ) && false !== has_action( 'wpml_register_single_string' );
		$has_translate = function_exists( 'has_filter' ) && false !== has_filter( 'wpml_translate_single_string' );

		return $has_register || $has_translate;
	}

	public function register_single_string( string $context, string $name, string $value ): void {
		if ( '' === $value || ! function_exists( 'do_action' ) ) {
			return;
		}

		try {
			do_action( 'wpml_register_single_string', $context, $name, $value );
		} catch ( \Throwable ) {
			// Presentation-only. A disabled or partial WPML install must not fatal.
		}
	}

	public function translate_single_string( string $original, string $context, string $name ): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $original;
		}

		try {
			$translated = apply_filters( 'wpml_translate_single_string', $original, $context, $name );
		} catch ( \Throwable ) {
			return $original;
		}

		return is_string( $translated ) ? $translated : $original;
	}
}
