<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionResult;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlProjection;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Purpose-bound Woo refusal; never discloses control actors or storage errors. */
final class EmergencyControlResponse {
	private ?\Closure $notice;
	private ?\Closure $url;
	private ?\Closure $redirect;
	private ?\Closure $terminate;
	public function __construct( ?callable $notice = null, ?callable $url = null, ?callable $redirect = null, ?callable $terminate = null ) {
		$this->notice = null === $notice ? null : \Closure::fromCallable( $notice );
		$this->url = null === $url ? null : \Closure::fromCallable( $url );
		$this->redirect = null === $redirect ? null : \Closure::fromCallable( $redirect );
		$this->terminate = null === $terminate ? null : \Closure::fromCallable( $terminate );
	}
	public static function message(): string { return __( 'Delivery and pickup checkouts are temporarily unavailable. Please return to your cart and try again later.', 'cetech-woocommerce-delivery-engine' ); }
	public static function revalidation_message(): string { return __( 'Your delivery choices need to be checked again. Please return to checkout.', 'cetech-woocommerce-delivery-engine' ); }
	public static function shopper_projection( EmergencyAdmissionResult $decision, ?RequestContext $request = null ): array { return EmergencyControlProjection::for_shopper( $decision, $request ?? RequestContext::create() ); }
	public static function shopper_message( EmergencyAdmissionResult $decision, ?RequestContext $request = null ): string {
		$view = self::shopper_projection( $decision, $request );
		return ( $view['message'] ?? self::message() ) . ' ' . sprintf( __( 'Reference: %s', 'cetech-woocommerce-delivery-engine' ), $view['correlation_id'] );
	}
	public static function reject_classic( ?EmergencyAdmissionResult $decision = null ): never { throw new \Exception( null === $decision ? self::message() : self::shopper_message( $decision ) ); }
	public static function reject_store_api( ?EmergencyAdmissionResult $decision = null ): never {
		$message = null === $decision ? self::message() : self::shopper_message( $decision );
		$class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $class ) ) { throw new $class( 'cetech_de_checkout_control', $message, 409 ); }
		throw new \RuntimeException( $message );
	}
	/** before_pay_action is outside Woo's try/catch: successful refusal terminates. */
	public function refuse_order_pay( ?EmergencyAdmissionResult $decision = null ): never {
		$message = null === $decision ? self::revalidation_message() : self::shopper_message( $decision ); $redirected = false;
		try {
			if ( null !== $this->notice ) { ( $this->notice )( $message, 'error' ); }
			elseif ( function_exists( 'wc_add_notice' ) ) { wc_add_notice( $message, 'error' ); }
			$url = null !== $this->url ? ( $this->url )() : ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : null );
			if ( is_string( $url ) && self::valid_server_url( $url ) ) {
				$redirected = null !== $this->redirect ? true === ( $this->redirect )( $url, 303 ) : ( function_exists( 'wp_safe_redirect' ) && wp_safe_redirect( $url, 303 ) );
			}
		} catch ( \Throwable ) { $redirected = false; }
		if ( null !== $this->terminate ) { ( $this->terminate )( $redirected, $message ); }
		if ( ! $redirected ) {
			if ( function_exists( 'wp_die' ) ) { wp_die( function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), '', [ 'response' => 503 ] ); }
			if ( ! headers_sent() ) { http_response_code( 503 ); }
			echo function_exists( 'esc_html' ) ? esc_html( $message ) : htmlspecialchars( $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}
		exit;
	}
	private static function valid_server_url( string $url ): bool {
		if ( strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f]/', $url ) ) { return false; }
		$parts = parse_url( $url );
		return is_array( $parts ) && isset( $parts['host'], $parts['scheme'] ) && in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] );
	}
}
