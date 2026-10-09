<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseShipmentConfigurationService;
use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseShape, PublicPromiseView};

/** Current shipment estimate is separately labelled and never edits the recorded original. */
final class PromiseShipmentConfigurationSection {
	private array $input = [];
	private ?array $opened = null;
	private string $message = '';
	private string $type = 'info';
	private bool $uncertain = false;
	public function __construct( private PromiseShipmentConfigurationService $service, private AdminActionHandler $actions ) {
		$id = $_GET['shipment_id'] ?? '';
		if ( is_string( $id ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $id ) && (string) (int) $id === $id ) { $this->input['shipment_id'] = $id; }
	}
	public function can_access(): bool { return $this->service->can_access(); }
	public function handles_action(): bool { return is_string( $_POST['cetech_de_action'] ?? null ) && in_array( $_POST['cetech_de_action'], [ 'cetech_de_promise_shipment_read', 'cetech_de_promise_shipment_update', 'cetech_de_promise_shipment_reconcile' ], true ); }
	public function handle_actions(): void {
		if ( ! $this->handles_action() || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { return; }
		$action = $_POST['cetech_de_action']; if ( ! $this->actions->verify_post( $action, $action, 'manage_shipments', PromiseConfigurationPage::SLUG ) ) { return; }
		try {
			$this->input = self::parse_form( $_POST );
			if ( 'cetech_de_promise_shipment_read' === $action ) { $this->opened = $this->service->open( self::integer( $this->input['shipment_id'], 1 ) ); }
			else {
				$reconcile = 'cetech_de_promise_shipment_reconcile' === $action; $available = 'absolute_window' === $this->input['state'];
				$result = $this->service->update( self::integer( $this->input['shipment_id'], 1 ), self::integer( $this->input['expected_revision'], 2 ), $this->input['request_token'],
					[ 'state' => $this->input['state'], 'from' => $available ? $this->input['from'] : null, 'until' => $available ? $this->input['until'] : null, 'display_timezone' => $available ? $this->input['display_timezone'] : null, 'reason' => $this->input['reason'] ], $reconcile, $this->input['original_envelope'] );
				if ( null !== $this->service->original_envelope() ) { $this->input['original_envelope'] = $this->service->original_envelope(); }
				$this->uncertain = 'unconfirmed' === $result->outcome->state;
				$this->type = 'accepted' === $result->outcome->state ? 'success' : ( $this->uncertain ? 'warning' : 'error' );
				$this->message = match ( $result->outcome->state ) { 'accepted' => __( 'The separate current estimate was saved. The original recorded delivery estimate is unchanged.', 'cetech-woocommerce-delivery-engine' ), 'unconfirmed' => __( 'This estimate change is uncertain. Reconcile the original request below before starting another update.', 'cetech-woocommerce-delivery-engine' ), default => __( 'The current estimate was not changed. Open its latest revision before another update.', 'cetech-woocommerce-delivery-engine' ) };
			}
		} catch ( \Throwable ) { $this->opened = null; $this->type = 'error'; $this->message = __( 'This shipment estimate request could not be confirmed. Check your order permission and the original request; no private shipment result is disclosed.', 'cetech-woocommerce-delivery-engine' ); }
	}
	public function render(): void {
		if ( ! $this->can_access() ) { return; }
		if ( null !== $this->opened && ! $this->service->can_disclose( $this->opened['shipment_id'], $this->opened['order_id'] ) ) { $this->opened = null; }
		AdminPageLayout::open_section( __( 'Original recorded delivery estimate and current shipment estimate', 'cetech-woocommerce-delivery-engine' ), __( 'Read the original recorded delivery estimate alongside the separately audited current estimate. An operational change requires its own reason and exact saved revision.', 'cetech-woocommerce-delivery-engine' ) );
		if ( '' !== $this->message ) { echo '<p role="status" class="notice notice-' . esc_attr( $this->type ) . '">' . esc_html( $this->message ) . '</p>'; }
		$this->start( 'read' ); $this->field( 'shipment_id', 'Shipment ID', $this->input['shipment_id'] ?? '', 'number' ); $this->submit( 'Open original and current estimate' );
		if ( null !== $this->opened ) {
			 echo '<h3>' . esc_html__( 'Original recorded delivery estimate', 'cetech-woocommerce-delivery-engine' ) . '</h3><p>' . esc_html( $this->opened['original']['customer_text'] ) . '</p>';
			echo '<h3>' . esc_html__( 'Current shipment estimate', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
			if ( null === $this->opened['current'] ) { echo '<p>' . esc_html__( 'No separate current estimate has been recorded.', 'cetech-woocommerce-delivery-engine' ) . '</p>'; }
			elseif ( 'unavailable' === $this->opened['current']['state'] ) { echo '<p>' . esc_html__( 'Current estimate unavailable.', 'cetech-woocommerce-delivery-engine' ) . '</p>'; }
			else { foreach ( $this->opened['current']['views'] as $view ) { echo '<p>' . esc_html( ( new PublicPromiseFormatter() )->text( PublicPromiseView::from_array( [ 'format_version' => 1, 'state' => 'absolute_window', 'reason_codes' => [] ] + $view ) ) ) . '</p>'; } }
			if ( null !== $this->opened['current_reason'] ) { echo '<p>' . esc_html__( 'Internal update reason:', 'cetech-woocommerce-delivery-engine' ) . ' ' . esc_html( $this->opened['current_reason'] ) . '</p>'; }
		}
		if ( null !== $this->opened || ( '' !== ( $this->input['request_token'] ?? '' ) && '' !== ( $this->input['expected_revision'] ?? '' ) ) ) {
			if ( $this->uncertain ) { $this->start( 'reconcile' ); foreach ( $this->input as $key => $value ) { $this->hidden( $key, $value ); } $this->submit( 'Reconcile original estimate request' ); }
			else {
				$first = $this->opened['original']['views'][0] ?? []; $this->start( 'update' );
				$this->hidden( 'shipment_id', (string) ( $this->opened['shipment_id'] ?? $this->input['shipment_id'] ) ); $this->hidden( 'expected_revision', (string) ( $this->opened['revision'] ?? $this->input['expected_revision'] ) );
				$this->hidden( 'request_token', $this->opened['request_token'] ?? $this->input['request_token'] );
				$this->hidden( 'original_envelope', $this->opened['original_envelope'] ?? $this->input['original_envelope'] );
				$state = '' !== ( $this->input['state'] ?? '' ) ? $this->input['state'] : 'absolute_window';
				echo '<p><label for="promise-shipment-state">' . esc_html__( 'Current estimate state', 'cetech-woocommerce-delivery-engine' ) . '</label> <select id="promise-shipment-state" name="state"><option value="absolute_window"' . selected( $state, 'absolute_window', false ) . '>' . esc_html__( 'Explicit date range', 'cetech-woocommerce-delivery-engine' ) . '</option><option value="unavailable"' . selected( $state, 'unavailable', false ) . '>' . esc_html__( 'Estimate unavailable', 'cetech-woocommerce-delivery-engine' ) . '</option></select></p>';
				$this->field( 'from', 'Earliest completion (UTC: YYYY-MM-DD HH:MM:SS.ffffff)', $this->input['from'] ?? '' ); $this->field( 'until', 'Latest completion (UTC)', $this->input['until'] ?? '' ); $this->field( 'display_timezone', 'Display timezone (IANA)', '' !== ( $this->input['display_timezone'] ?? '' ) ? $this->input['display_timezone'] : ( $first['display_timezone'] ?? '' ) );
				$this->field( 'reason', 'Internal reason for this current estimate', $this->input['reason'] ?? '' ); $this->submit( 'Save separate current estimate' );
			}
		}
		AdminPageLayout::close_section();
	}
	public static function parse_form( array $post ): array { $out = []; foreach ( [ 'shipment_id', 'expected_revision', 'request_token', 'original_envelope', 'state', 'from', 'until', 'display_timezone', 'reason' ] as $key ) { $value = $post[$key] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > ( 'original_envelope' === $key ? 131072 : ( 'reason' === $key ? 1000 : 256 ) ) ) { PromiseShape::invalid(); } $out[$key] = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value; } foreach ( [ 'author_user_id', 'event_at', 'runtime', 'original_packet_digest', 'site_id', 'principal' ] as $forbidden ) { if ( isset( $post[$forbidden] ) ) { PromiseShape::invalid(); } } return $out; }
	private static function integer( string $raw, int $minimum ): int { if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $raw ) || (string) (int) $raw !== $raw ) { PromiseShape::invalid(); } return PromiseShape::integer( (int) $raw, $minimum, PHP_INT_MAX - 1 ); }
	private function start( string $verb ): void { echo '<form method="post" action="" data-promise-action="shipment_' . esc_attr( $verb ) . '">'; $action = 'cetech_de_promise_shipment_' . $verb; AdminFormHelper::nonce_field( $action ); $this->hidden( 'cetech_de_action', $action ); }
	private function hidden( string $key, string $value ): void { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />'; }
	private int $counter = 0;
	private function field( string $key, string $label, string $value, string $type = 'text' ): void { $id = 'promise-shipment-' . $key . '-' . ++$this->counter; echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</label><br /><input class="regular-text" id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" /></p>'; }
	private function submit( string $text ): void { echo '<p><button class="button button-primary" type="submit">' . esc_html( __( $text, 'cetech-woocommerce-delivery-engine' ) ) . '</button></p></form>'; }
}
