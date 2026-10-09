<?php

declare(strict_types=1);

/** Only loaded inside isolated runtime-composition tests; never a native-hook claim. */
if ( ! function_exists( 'current_filter' ) ) {
	function current_filter(): string { return (string) ( $GLOBALS['cetech_de_runtime_hook'] ?? '' ); }
}
if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool { return true === ( $GLOBALS['cetech_de_runtime_active_hooks'][$hook] ?? false ); }
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $name, mixed $default = '' ): mixed { return $GLOBALS['cetech_de_runtime_query'][$name] ?? $default; }
}
