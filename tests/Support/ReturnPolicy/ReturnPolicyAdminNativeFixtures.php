<?php

declare(strict_types=1);

/** Native capability test double only. It proves neither WordPress nor WCFM execution. */
if ( ! function_exists( 'user_can' ) ) {
	function user_can( object|int $user, string $capability, mixed ...$arguments ): bool {
		$GLOBALS['cetech_de_test_return_native_calls'] = ( $GLOBALS['cetech_de_test_return_native_calls'] ?? 0 ) + 1;
		if ( null !== ( $observer = $GLOBALS['cetech_de_test_return_native_observer'] ?? null ) ) { $observer( $user, $capability, ...$arguments ); }
		if ( ! empty( $GLOBALS['cetech_de_test_return_user_can_throw'] ) ) { throw new \RuntimeException( 'Native fixture capability unavailable.' ); }
		if ( is_int( $user ) ) { $user = get_userdata( $user ); }
		if ( ! is_object( $user ) ) { return false; }
		return true === ( $user->allcaps[ $capability ] ?? $user->caps[ $capability ] ?? false );
	}
}
