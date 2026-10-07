<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlProjection;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;

/** Separate audited control: ordinary feature-flag saves never write its state. */
final class EmergencyControlSettings {

	public const ACTION = 'cetech_de_change_checkout_control';
	private const DRAFT_SCOPE = 'cetech-checkout-control';

	public function __construct(
		private readonly EmergencyControlService $service,
		private readonly AdminActionHandler $actions,
		private readonly bool $writer_enabled = true
	) {}

	public function handle_actions(): void {
		if ( ! $this->actions->verify_post( self::ACTION, self::ACTION, 'manage_delivery_settings', DeliverySettingsPage::SLUG ) ) { return; }
		if ( ! $this->writer_enabled ) {
			$this->actions->notices()->flash_error( __( 'Checkout controls cannot be changed right now.', 'cetech-woocommerce-delivery-engine' ) );
			$this->actions->redirect( DeliverySettingsPage::SLUG );
		}
		$context = RequestContext::create();
		try {
			$input = self::parse_form( $_POST );
			$identity = EmergencyControlCommand::identity( $this->site(), get_current_user_id(), $input['request_token'] );
			$payload = array_diff_key( $input, [ 'request_token' => true ] );
			$result = $this->service->transition( $identity, $payload, $context );
			AdminPageAccess::require_capability( 'manage_delivery_settings' );
			if ( 'unconfirmed' === $result->outcome->state ) {
				// An uncertain retry retains every original precondition and token.
				$this->actions->notices()->stash_form_draft( self::DRAFT_SCOPE, $input );
				$this->actions->notices()->flash_warning( __( 'The control change could not be confirmed. Check the current state or retry this request.', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'accepted' === $result->outcome->state ) {
				$this->actions->notices()->consume_form_draft( self::DRAFT_SCOPE );
				$this->actions->notices()->flash_success( $result->replayed
					? __( 'This request was already completed. The current checkout state is shown below.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Checkout controls were saved. The current checkout state is shown below.', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'not_applicable' === $result->outcome->state ) {
				$this->actions->notices()->consume_form_draft( self::DRAFT_SCOPE );
				$this->actions->notices()->flash_success( __( 'The checkout control was already in that state. Nothing changed.', 'cetech-woocommerce-delivery-engine' ) );
			} else {
				// A known refusal has no uncertain accepted effect. Render a fresh envelope
				// while retaining only the requested semantic draft.
				$this->actions->notices()->stash_form_draft( self::DRAFT_SCOPE, [ 'desired_state' => $input['desired_state'], 'reason_code' => $input['reason_code'] ] );
				$this->actions->notices()->flash_error( __( 'These controls were not changed. Reload the current state before submitting again.', 'cetech-woocommerce-delivery-engine' ) );
			}
		} catch ( \InvalidArgumentException ) {
			$this->actions->notices()->flash_error( __( 'The checkout-control form is invalid. Reload the current state and try again.', 'cetech-woocommerce-delivery-engine' ) );
		} catch ( \Throwable ) {
			$this->actions->notices()->flash_error( __( 'Checkout controls could not be confirmed. Reload the current state before trying again.', 'cetech-woocommerce-delivery-engine' ) );
		}
		$this->actions->redirect( DeliverySettingsPage::SLUG );
	}

	public function render(): void {
		$raw = null;
		$site = $this->site();
		$view = EmergencyControlProjection::for_settings( $site, RequestContext::create(),
			static fn( int $requested, string $capability, string $purpose ): bool => $requested === ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 )
				&& is_admin() && ! AdminPageAccess::current_user_is_restricted() && current_user_can( $capability ),
			function () use ( $site, &$raw ): EmergencyControlReadResult { return $raw = $this->service->read( $site ); }
		);
		if ( $view instanceof ContractError ) { return; }
		AdminPageLayout::open_section( __( 'Emergency checkout control', 'cetech-woocommerce-delivery-engine' ),
			__( 'Pause new delivery and pickup checkouts and payments on unpaid managed orders. Existing paid orders and shipment work continue.', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p role="status">' . esc_html( $view['message'] ) . '</p>';
		echo '<p class="description">' . esc_html__( 'This control applies to the whole store across Classic checkout, Blocks and Store API. It does not change your saved delivery settings.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( ! $this->writer_enabled || ! $view['ready'] || null === $raw?->state ) {
			echo '<p>' . esc_html__( 'Controls are unavailable. The recorded state is preserved.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			AdminPageLayout::close_section();
			return;
		}
		$draft = $this->actions->notices()->consume_form_draft( self::DRAFT_SCOPE ) ?? [];
		$state = $raw->state;
		$input = [ 'opened_row_id' => $state->row_id, 'opened_revision' => $state->revision, 'opened_bytes' => $state->original_bytes(),
			'desired_state' => 'checkout_suspended' === $state->state ? 'enabled' : 'checkout_suspended',
			'reason_code' => 'checkout_suspended' === $state->state ? 'resume_verified' : 'operator_pause',
			'request_token' => RequestContext::create()->request_id ];
		if ( isset( $draft['request_token'] ) ) { $input = $draft; }
		else { foreach ( [ 'desired_state', 'reason_code' ] as $key ) { if ( isset( $draft[$key] ) ) { $input[$key] = $draft[$key]; } } }
		AdminPageAccess::require_capability( 'manage_delivery_settings' );
		echo '<form id="cetech-de-emergency-control" method="post" action="">';
		AdminFormHelper::nonce_field( self::ACTION );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" />';
		foreach ( [ 'opened_row_id', 'opened_revision', 'request_token' ] as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $input[$key] ) . '" />';
		}
		echo '<input type="hidden" name="opened_bytes" value="' . esc_attr( base64_encode( $input['opened_bytes'] ) ) . '" />';
		echo '<p><label for="cetech-de-emergency-state">' . esc_html__( 'Requested state', 'cetech-woocommerce-delivery-engine' ) . '</label> <select id="cetech-de-emergency-state" name="desired_state">';
		foreach ( [ 'enabled' => 'Enable checkouts', 'checkout_suspended' => 'Pause checkouts and unpaid delivery-order payments' ] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $input['desired_state'], $value, false ) . '>' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</option>';
		}
		echo '</select></p><p><label for="cetech-de-emergency-reason">' . esc_html__( 'Reason', 'cetech-woocommerce-delivery-engine' ) . '</label> <select id="cetech-de-emergency-reason" name="reason_code">';
		foreach ( [ 'operator_pause' => 'Operator pause', 'incident_pause' => 'Incident pause', 'maintenance_pause' => 'Maintenance pause', 'resume_verified' => 'Resume after verification' ] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $input['reason_code'], $value, false ) . '>' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</option>';
		}
		echo '</select></p><p><button type="submit" class="button button-primary">' . esc_html__( 'Save checkout control', 'cetech-woocommerce-delivery-engine' ) . '</button></p></form>';
		AdminPageLayout::close_section();
	}

	/** Strict form transport only; caller identity/site/capability are never accepted. */
	public static function parse_form( array $post ): array {
		$input = [];
		foreach ( [ 'opened_row_id', 'opened_revision', 'opened_bytes', 'desired_state', 'reason_code', 'request_token' ] as $key ) {
			if ( ! isset( $post[$key] ) || ! is_string( $post[$key] ) || strlen( $post[$key] ) > 4096 ) { throw new \InvalidArgumentException( 'Invalid control form.' ); }
			$input[$key] = function_exists( 'wp_unslash' ) ? wp_unslash( $post[$key] ) : $post[$key];
		}
		foreach ( [ 'opened_row_id', 'opened_revision' ] as $key ) {
			if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,18})$/D', $input[$key] ) || (string) (int) $input[$key] !== $input[$key] ) { throw new \InvalidArgumentException( 'Invalid control form.' ); }
			$input[$key] = (int) $input[$key];
		}
		$bytes = base64_decode( $input['opened_bytes'], true );
		if ( false === $bytes || strlen( $bytes ) > 2048 || ! RequestContext::is_valid_identifier( $input['request_token'] )
			|| ! in_array( $input['desired_state'], [ 'enabled', 'checkout_suspended' ], true )
			|| ! in_array( $input['reason_code'], [ 'operator_pause', 'incident_pause', 'maintenance_pause', 'resume_verified' ], true )
			|| ( 'enabled' === $input['desired_state'] ) !== ( 'resume_verified' === $input['reason_code'] ) ) { throw new \InvalidArgumentException( 'Invalid control form.' ); }
		$input['opened_bytes'] = $bytes;
		return $input;
	}

	private function site(): int { return function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1; }
}
