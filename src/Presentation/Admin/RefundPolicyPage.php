<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyAdminWorkflow;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Native private authoring. A posted selector or review ticket never grants authority. */
final class RefundPolicyPage {

	public const SLUG = 'cetech-de-refund-policies';
	public const ACTION_PREFIX = 'cetech_de_refund_policy_';
	public const VERBS = [ 'new', 'open', 'create', 'edit', 'publish', 'retire', 'reconcile', 'retry' ];
	private const FIELDS = [ 'name', 'customer_title', 'summary', 'full_text', 'exceptions' ];
	private string $message = '';
	private string $message_type = 'info';

	public function __construct( private RefundPolicyAdminWorkflow $workflow, private AdminActionHandler $actions ) {}

	/** Admin-init only; every recognized POST has its own action-bound nonce. */
	public function handle_actions(): void {
		$action = $_POST['cetech_de_action'] ?? null;
		if ( ! is_string( $action ) || ! str_starts_with( $action, self::ACTION_PREFIX ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return; }
		$verb = substr( $action, strlen( self::ACTION_PREFIX ) );
		if ( ! in_array( $verb, self::VERBS, true ) ) { return; }
		if ( ! is_string( $_POST['cetech_de_nonce'] ?? null ) ) { return; }
		if ( ! $this->actions->verify_post( $action, $action, 'manage_delivery_settings', self::SLUG ) ) { return; }
		try {
			if ( ! $this->workflow->can_access() ) { throw new \RuntimeException( 'Permission unavailable.' ); }
			$input = self::parse_form( $_POST );
			if ( 'new' === $verb || 'open' === $verb ) {
				$this->workflow->open( 'new' === $verb ? null : $input['policy_uuid'], 'new' === $verb ? null : $input['version_uuid'] );
				$this->message = __( 'Review the policy below before saving a change.', 'cetech-woocommerce-delivery-engine' );
				return;
			}
			if ( 'reconcile' === $verb || 'retry' === $verb ) {
				$result = $this->workflow->recover( $verb, RequestContext::create() );
			} else {
				$fields = [];
				foreach ( [ ...self::FIELDS, 'reason' ] as $key ) { $fields[ $key ] = $input[ $key ]; }
				$result = $this->workflow->submit( $input['ticket'], $verb, $fields, RequestContext::create() );
			}
			// The workflow rechecks native authority after the owned C03 session retires.
			$state = $result->outcome->state;
			$this->message_type = match ( $state ) { 'accepted', 'not_applicable' => 'success', 'unconfirmed' => 'warning', default => 'error' };
			$this->message = match ( $state ) {
				'accepted' => __( 'The policy change was confirmed. Open the current policy before the next change.', 'cetech-woocommerce-delivery-engine' ),
				'not_applicable' => __( 'The original request was confirmed with no change needed.', 'cetech-woocommerce-delivery-engine' ),
				'unconfirmed' => __( 'The result is uncertain. Check the original request before starting another change.', 'cetech-woocommerce-delivery-engine' ),
				default => __( 'The request was refused. Review the current policy and your permissions before trying again.', 'cetech-woocommerce-delivery-engine' ),
			};
		} catch ( \Throwable ) {
			$this->message_type = 'error';
			$this->message = __( 'The request could not be confirmed. Reload this page to review the current policy or recover the original request.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	/** Fetch a newly authorized projection instead of retaining an earlier private result. */
	public function render(): void {
		if ( ! $this->workflow->can_access() ) { self::deny(); }
		try { $view = $this->workflow->view(); }
		catch ( \Throwable ) {
			if ( ! $this->workflow->can_access() ) { self::deny(); }
			$view = [ 'rows' => [], 'review' => null, 'blocked' => true ];
			$this->message_type = 'warning';
			$this->message = __( 'The current policy state is unavailable. Reload before starting a new change.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( ! $this->workflow->can_access() ) { self::deny(); }
		AdminPageLayout::open_page( 'cetech-de-refund-policies' );
		AdminPageLayout::render_page_header( __( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ), __( 'Refund policies', 'cetech-woocommerce-delivery-engine' ), __( 'Write and review refund terms, save drafts, then publish the version you have reviewed.', 'cetech-woocommerce-delivery-engine' ) );
		if ( '' !== $this->message ) { echo '<div class="notice notice-' . esc_attr( $this->message_type ) . '"><p role="status">' . esc_html( $this->message ) . '</p></div>'; }
		if ( $view['blocked'] ) {
			$this->render_recovery();
		} else {
			$this->form_start( 'new' ); $this->button( 'New refund policy' ); echo '</form>';
			if ( null !== $view['review'] ) { $this->render_review( $view['review'] ); }
			$this->render_rows( $view['rows'] );
		}
		AdminPageLayout::close_page();
	}

	/** Raw authored text remains exact; neither sanitization nor a POST field supplies authority. */
	public static function parse_form( array $input ): array {
		$allowed = [ 'cetech_de_action', 'cetech_de_nonce', '_wp_http_referer', 'ticket', 'policy_uuid', 'version_uuid', ...self::FIELDS, 'reason' ];
		if ( array_diff( array_keys( $input ), $allowed ) ) { throw new \InvalidArgumentException( 'Unsupported refund policy form.' ); }
		$parsed = [];
		foreach ( $allowed as $key ) {
			$value = $input[ $key ] ?? '';
			$max = match ( $key ) { 'reason' => 512, 'ticket' => 128, 'policy_uuid', 'version_uuid' => 36, 'cetech_de_action', 'cetech_de_nonce' => 128, '_wp_http_referer' => 2048, default => 16384 };
			// WordPress slashes POST strings. Bound that transport before accepting the unslashed bytes.
			if ( ! is_string( $value ) || strlen( $value ) > 2 * $max ) { throw new \InvalidArgumentException( 'Unsupported refund policy form.' ); }
			$value = wp_unslash( $value );
			if ( ! is_string( $value ) || strlen( $value ) > $max || 1 !== preg_match( '//u', $value ) ) { throw new \InvalidArgumentException( 'Unsupported refund policy form.' ); }
			$parsed[ $key ] = $value;
		}
		return $parsed;
	}

	private function render_recovery(): void {
		AdminPageLayout::open_section( __( 'Check the original request', 'cetech-woocommerce-delivery-engine' ), __( 'A request is still awaiting confirmation. New changes are paused until its original result is confirmed.', 'cetech-woocommerce-delivery-engine' ) );
		$this->form_start( 'reconcile' ); $this->button( 'Check original request' ); echo '</form>';
		$this->form_start( 'retry' );
		echo '<p class="description">' . esc_html__( 'Retry submits exactly the same original request. It does not create a replacement change.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->button( 'Retry original request', false ); echo '</form>';
		AdminPageLayout::close_section();
	}

	private function render_review( array $review ): void {
		AdminPageLayout::open_section( __( 'Review refund policy', 'cetech-woocommerce-delivery-engine' ) );
		$verbs = $review['verbs'];
		$write = in_array( 'create', $verbs, true ) ? 'create' : ( in_array( 'edit', $verbs, true ) ? 'edit' : null );
		if ( null !== $write ) {
			$this->form_start( $write ); $this->hidden( 'ticket', $review['ticket'] );
			$this->render_fields( $review['fields'], $review['reason'] );
			$this->button( 'create' === $write ? 'Save new draft' : 'Save draft changes' ); echo '</form>';
		} else {
			$this->render_private_text( $review['fields'] );
			echo '<p><strong>' . esc_html__( 'Saved reason:', 'cetech-woocommerce-delivery-engine' ) . '</strong> ' . esc_html( $review['reason'] ) . '</p>';
		}
		foreach ( [ 'publish' => 'Publish reviewed draft', 'retire' => 'Retire reviewed version' ] as $verb => $label ) {
			if ( ! in_array( $verb, $verbs, true ) ) { continue; }
			$this->form_start( $verb ); $this->hidden( 'ticket', $review['ticket'] );
			echo '<p class="description">' . esc_html__( 'This action uses the saved wording and original author and reason. Unsaved edits above are not included.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			$this->button( $label, false ); echo '</form>';
		}
		AdminPageLayout::close_section();
	}

	private function render_fields( array $fields, string $reason ): void {
		AdminPageLayout::open_form_panel( __( 'Policy wording', 'cetech-woocommerce-delivery-engine' ), __( 'Text is stored as written. HTML is treated as text in this editor.', 'cetech-woocommerce-delivery-engine' ) );
		AdminFormHelper::text_field( 'name', __( 'Internal policy name', 'cetech-woocommerce-delivery-engine' ), $fields['name'], true );
		AdminFormHelper::text_field( 'customer_title', __( 'Customer title', 'cetech-woocommerce-delivery-engine' ), $fields['customer_title'], true );
		AdminFormHelper::textarea_field( 'summary', __( 'Short summary', 'cetech-woocommerce-delivery-engine' ), $fields['summary'], 3, '', true );
		AdminFormHelper::textarea_field( 'full_text', __( 'Full refund terms', 'cetech-woocommerce-delivery-engine' ), $fields['full_text'], 8, '', true );
		AdminFormHelper::textarea_field( 'exceptions', __( 'Exceptions', 'cetech-woocommerce-delivery-engine' ), $fields['exceptions'], 4 );
		AdminFormHelper::text_field( 'reason', __( 'Reason for this draft', 'cetech-woocommerce-delivery-engine' ), $reason, true );
		AdminPageLayout::close_form_panel();
	}

	private function render_rows( array $rows ): void {
		AdminPageLayout::open_section( __( 'Current policy versions', 'cetech-woocommerce-delivery-engine' ), __( 'Shows current draft, scheduled and published versions. Retired policies are kept in storage and are not listed here.', 'cetech-woocommerce-delivery-engine' ) );
		if ( [] === $rows ) { echo '<p>' . esc_html__( 'No current refund policy versions are available.', 'cetech-woocommerce-delivery-engine' ) . '</p>'; }
		foreach ( $rows as $row ) {
			echo '<article class="cetech-de-content-panel"><h3>' . esc_html( $row['fields']['name'] ) . '</h3><p>' . esc_html( $row['fields']['customer_title'] ) . '</p>';
			echo '<p>' . esc_html( sprintf( __( 'Version %1$d · %2$s', 'cetech-woocommerce-delivery-engine' ), $row['sequence'], $row['state'] ) ) . '</p>';
			$this->form_start( 'open' ); $this->hidden( 'policy_uuid', $row['policy_uuid'] ); $this->hidden( 'version_uuid', $row['version_uuid'] ); $this->button( 'Open policy', false ); echo '</form></article>';
		}
		AdminPageLayout::close_section();
	}

	private function render_private_text( array $fields ): void {
		foreach ( [ 'name' => 'Internal policy name', 'customer_title' => 'Customer title', 'summary' => 'Short summary', 'full_text' => 'Full refund terms', 'exceptions' => 'Exceptions' ] as $key => $label ) {
			echo '<h3>' . esc_html__( $label, 'cetech-woocommerce-delivery-engine' ) . '</h3><p style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( $fields[ $key ] ) . '</p>';
		}
	}

	private function form_start( string $verb ): void {
		$action = self::ACTION_PREFIX . $verb;
		echo '<form method="post" action="" class="cetech-de-refund-policy-form">';
		AdminFormHelper::nonce_field( $action ); $this->hidden( 'cetech_de_action', $action );
	}
	private function hidden( string $key, string $value ): void { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />'; }
	private function button( string $label, bool $primary = true ): void { echo '<p><button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html__( $label, 'cetech-woocommerce-delivery-engine' ) . '</button></p>'; }
	private static function deny(): never { wp_die( esc_html__( 'You do not have permission to access this page.', 'cetech-woocommerce-delivery-engine' ) ); }
}
