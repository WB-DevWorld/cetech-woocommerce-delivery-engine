<?php
/** Native wp-admin context for the explicitly disposable WP-CLI qualification. */
declare(strict_types=1);

if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) ) {
	throw new RuntimeException( 'Native qualification requires its explicit fixture flag.' );
}

if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
