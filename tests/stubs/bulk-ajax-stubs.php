<?php

declare(strict_types=1);

// Response sentinels terminate the fixture exactly where WordPress exits.
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void {
		unset( $flags );
		$GLOBALS['cetech_de_test_ajax_response'] = [ 'success' => false, 'data' => $data, 'status' => $status_code ?? 200 ];
		throw new RuntimeException( 'cetech_de_test_ajax_response' );
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void {
		unset( $flags );
		$GLOBALS['cetech_de_test_ajax_response'] = [ 'success' => true, 'data' => $data, 'status' => $status_code ?? 200 ];
		throw new RuntimeException( 'cetech_de_test_ajax_response' );
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action, string|false $query_arg = false, bool $stop = true ): int|false {
		$nonce = (string) ( $_POST[ $query_arg ?: '_ajax_nonce' ] ?? '' );
		if ( wp_verify_nonce( $nonce, $action ) ) {
			return 1;
		}
		if ( $stop ) {
			wp_send_json_error( [ 'message' => 'bad_nonce' ], 403 );
		}
		return false;
	}
}
