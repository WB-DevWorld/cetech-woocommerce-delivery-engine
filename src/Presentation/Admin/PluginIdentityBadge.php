<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Core\Versioning\SchemaVersion;

/**
 * One line that tells staff which plugin build and database schema this site runs.
 *
 * Rendered on the Settings and Site-wide Defaults pages so acceptance screenshots and
 * support requests carry the installed identity without opening System Status.
 */
final class PluginIdentityBadge {

	public const UNKNOWN_VERSION = 'unknown';

	public static function version(): string {
		return defined( 'CETECH_DE_VERSION' ) ? (string) CETECH_DE_VERSION : self::UNKNOWN_VERSION;
	}

	public static function installed_schema(): string {
		return SchemaVersion::get();
	}

	public static function target_schema(): string {
		return SchemaVersion::target();
	}

	public static function schema_is_current(): bool {
		return self::installed_schema() === self::target_schema();
	}

	/**
	 * Plain-text form, for example "Delivery Engine 1.0.0-m2.1 · schema 6".
	 */
	public static function line(): string {
		$line = sprintf(
			/* translators: 1: plugin version, 2: installed database schema version */
			__( 'Delivery Engine %1$s · schema %2$s', 'cetech-woocommerce-delivery-engine' ),
			self::version(),
			self::installed_schema()
		);

		if ( ! self::schema_is_current() ) {
			$line .= ' ' . sprintf(
				/* translators: %s: target database schema version */
				__( '(target %s, migration pending)', 'cetech-woocommerce-delivery-engine' ),
				self::target_schema()
			);
		}

		return $line;
	}

	public static function render(): void {
		$class = 'cetech-de-plugin-identity description';
		if ( ! self::schema_is_current() ) {
			$class .= ' cetech-de-plugin-identity--pending';
		}

		echo '<p class="' . esc_attr( $class ) . '" data-cetech-de-plugin-version="' . esc_attr( self::version() ) . '" data-cetech-de-schema="' . esc_attr( self::installed_schema() ) . '">';
		echo esc_html( self::line() );
		echo '</p>';
	}
}
