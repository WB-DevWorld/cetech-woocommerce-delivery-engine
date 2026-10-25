<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\PolicyAssignment\PolicyAssignmentAdminWorkflow;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyEffectiveSelection;

/** Native assignment controls; posted selectors and tickets grant no authority. */
final class PolicyAssignmentPage {
	public const SLUG = 'cetech-de-policy-assignments';
	public const ACTION_PREFIX = 'cetech_de_policy_assignment_';
	public const VERBS = [ 'open', 'save', 'preview', 'retry', 'reconcile' ];
	private string $message = '';
	private string $message_type = 'info';
	private ?string $preview_ticket = null;

	public function __construct( private PolicyAssignmentAdminWorkflow $workflow, private AdminActionHandler $actions ) {}

	public function handle_actions(): void {
		$action = $_POST['cetech_de_action'] ?? null;
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_string( $action ) || ! str_starts_with( $action, self::ACTION_PREFIX ) ) { return; }
		$verb = substr( $action, strlen( self::ACTION_PREFIX ) );
		if ( ! in_array( $verb, self::VERBS, true ) || ! is_string( $_POST['cetech_de_nonce'] ?? null ) ) { return; }
		if ( ! $this->actions->verify_post( $action, $action, 'manage_delivery_settings', self::SLUG ) ) { return; }
		try {
			if ( ! $this->workflow->can_access() ) { throw new \RuntimeException(); }
			$input = self::parse_form( $_POST );
			if ( 'open' === $verb ) {
				$this->workflow->open( $input['scope_type'], self::number( $input['scope_id'] ), self::number( $input['offer_id'] ) );
				$this->message = __( 'Review the saved assignments below before making a change.', 'cetech-woocommerce-delivery-engine' ); return;
			}
			if ( 'preview' === $verb ) {
				// Capture happens once at delayed render, under freshly checked native grants.
				$this->preview_ticket = $input['ticket']; return;
			}
			if ( in_array( $verb, [ 'retry', 'reconcile' ], true ) ) { $result = $this->workflow->recover( $verb, RequestContext::create() ); }
			else {
				$fields = []; foreach ( [ 'return_mode', 'return_policy_uuid', 'refund_mode', 'refund_policy_uuid', 'reason' ] as $key ) { $fields[$key] = $input[$key]; }
				$result = $this->workflow->submit( $input['ticket'], $fields, RequestContext::create() );
			}
			$state = $result->outcome->state;
			$this->message_type = match ( $state ) { 'accepted', 'not_applicable' => 'success', 'unconfirmed' => 'warning', default => 'error' };
			$this->message = match ( $state ) {
				'accepted' => __( 'The policy assignments were confirmed. Open the saved target before another change or preview.', 'cetech-woocommerce-delivery-engine' ),
				'not_applicable' => __( 'The original request was confirmed with no change needed.', 'cetech-woocommerce-delivery-engine' ),
				'unconfirmed' => __( 'The result is uncertain. Check the original request before starting another change.', 'cetech-woocommerce-delivery-engine' ),
				default => __( 'The request was refused. Reopen the current assignments and review your selections.', 'cetech-woocommerce-delivery-engine' ),
			};
			if ( true === $result->outcome->mutation_accepted && $result->outcome->publication_pending ) {
				$this->message = __( 'The assignments were saved. Finish confirmation below before another change.', 'cetech-woocommerce-delivery-engine' );
			}
		} catch ( \Throwable ) {
			$this->message_type = 'error'; $this->message = __( 'The request could not be confirmed. Reload to review the saved assignments or check the original request.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	public function render(): void {
		if ( ! $this->workflow->can_access() ) { self::deny(); }
		try {
			$view = $this->workflow->view();
			if ( null !== $this->preview_ticket ) { $view['preview'] = $this->workflow->preview( $this->preview_ticket, RequestContext::create() ); }
		} catch ( \Throwable ) {
			if ( ! $this->workflow->can_access() ) { self::deny(); }
			$view = [ 'review' => null, 'blocked' => true, 'preview' => null, 'offers' => [] ];
			$this->message_type = 'warning'; $this->message = __( 'The current assignment state is unavailable. Reload before starting a new change.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( ! $this->workflow->can_access() ) { self::deny(); }
		AdminPageLayout::open_page( self::SLUG );
		AdminPageLayout::render_page_header( __( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ), __( 'Return & refund policy assignments', 'cetech-woocommerce-delivery-engine' ), __( 'Choose global defaults or product and variation settings for each delivery choice.', 'cetech-woocommerce-delivery-engine' ) );
		if ( '' !== $this->message ) { echo '<div class="notice notice-' . esc_attr( $this->message_type ) . '"><p role="status">' . esc_html( $this->message ) . '</p></div>'; }
		if ( $view['blocked'] ) { $this->render_recovery(); }
		else {
			$this->render_picker( $view['offers'], $view['review'] );
			if ( null !== $view['review'] ) { $this->render_review( $view['review'] ); }
			if ( null !== $view['preview'] ) { $this->render_preview( $view['preview'] ); }
		}
		AdminPageLayout::close_page();
	}

	/** Each action accepts only its ordinary controls, never command or CAS material. */
	public static function parse_form( array $input ): array {
		$action = $input['cetech_de_action'] ?? null;
		if ( ! is_string( $action ) || ! str_starts_with( $action, self::ACTION_PREFIX ) || ! in_array( $verb = substr( $action, strlen( self::ACTION_PREFIX ) ), self::VERBS, true ) ) { throw new \InvalidArgumentException(); }
		$fields = match ( $verb ) {
			'open' => [ 'scope_type', 'scope_id', 'offer_id' ],
			'save' => [ 'ticket', 'return_mode', 'return_policy_uuid', 'refund_mode', 'refund_policy_uuid', 'reason' ],
			'preview' => [ 'ticket' ], default => [],
		};
		$allowed = [ 'cetech_de_action', 'cetech_de_nonce', '_wp_http_referer', ...$fields ];
		if ( array_diff( array_keys( $input ), $allowed ) ) { throw new \InvalidArgumentException( 'Unsupported policy assignment form.' ); }
		$parsed = [];
		foreach ( $allowed as $key ) {
			$max = match ( $key ) { 'reason' => 1000, '_wp_http_referer' => 2048, 'return_policy_uuid', 'refund_policy_uuid' => 36, 'scope_id', 'offer_id' => 20, default => 128 };
			$value = $input[$key] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 2 * $max ) { throw new \InvalidArgumentException(); }
			$value = wp_unslash( $value ); if ( ! is_string( $value ) || strlen( $value ) > $max || 1 !== preg_match( '//u', $value ) ) { throw new \InvalidArgumentException(); }
			$parsed[$key] = $value;
		}
		if ( 'open' === $verb ) {
			if ( ! in_array( $parsed['scope_type'], [ 'global', 'product', 'variation' ], true ) ) { throw new \InvalidArgumentException(); }
			self::number( $parsed['scope_id'] ); self::number( $parsed['offer_id'] );
		}
		if ( 'save' === $verb ) {
			// Successful controls omit disabled selects with JS. Preserve that same
			// meaning when a no-JS form still posts its previously selected UUID.
			foreach ( [ 'return', 'refund' ] as $prefix ) {
				if ( in_array( $parsed[$prefix . '_mode'], [ 'inherit', 'disabled' ], true ) ) { $parsed[$prefix . '_policy_uuid'] = ''; }
			}
		}
		return $parsed;
	}

	private function render_picker( array $offers, ?array $review ): void {
		AdminPageLayout::open_section( __( 'Choose where the policies apply', 'cetech-woocommerce-delivery-engine' ) );
		$this->form_start( 'open' ); AdminPageLayout::open_form_panel( __( 'Assignment target', 'cetech-woocommerce-delivery-engine' ) );
		AdminFormHelper::select_field( 'scope_type', __( 'Apply to', 'cetech-woocommerce-delivery-engine' ), [ 'global' => 'Global defaults', 'product' => 'Product', 'variation' => 'Product variation' ], $review['scope_type'] ?? 'global' );
		AdminFormHelper::number_field( 'scope_id', __( 'Product or variation ID', 'cetech-woocommerce-delivery-engine' ), $review['scope_id'] ?? 0, 0, __( 'Use 0 for global defaults, or enter the WooCommerce product or variation ID.', 'cetech-woocommerce-delivery-engine' ) );
		$choices = [ '0' => __( 'Default for this target', 'cetech-woocommerce-delivery-engine' ) ];
		foreach ( $offers as $offer ) { $choices[(string) $offer['id']] = $offer['label']; }
		echo '<tr><th scope="row"><label for="offer_id">' . esc_html__( 'Delivery choice', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td><select id="offer_id" name="offer_id" class="cetech-de-select">';
		foreach ( $choices as $value => $label ) { echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( (string) ( $review['offer_id'] ?? 0 ), (string) $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></td></tr>';
		AdminPageLayout::close_form_panel(); $this->button( 'Review assignments' ); echo '</form>';
		AdminPageLayout::close_section();
	}

	private function render_review( array $review ): void {
		AdminPageLayout::open_section( __( 'Review policy assignments', 'cetech-woocommerce-delivery-engine' ), $review['target_label'] . ' · ' . $review['offer_label'] );
		echo '<p>' . esc_html__( 'Each selected policy follows its latest published wording for future previews. Return and Refund policies are independent.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->form_start( 'save' ); $this->hidden( 'ticket', $review['ticket'] ); AdminPageLayout::open_form_panel( __( 'Policy choices', 'cetech-woocommerce-delivery-engine' ) );
		foreach ( [ 'return_policy_v1' => [ 'return', 'Return Policy' ], 'refund_policy_v1' => [ 'refund', 'Refund Policy' ] ] as $family => [ $prefix, $label ] ) {
			AdminFormHelper::select_field( $prefix . '_mode', __( $label, 'cetech-woocommerce-delivery-engine' ), [ 'inherit' => 'Use inherited defaults', 'disabled' => 'No policy at this setting', 'assigned' => 'Use a published policy' ], $review['fields'][$prefix . '_mode'] );
			$options = [ '' => __( 'Choose a published policy', 'cetech-woocommerce-delivery-engine' ) ];
			foreach ( $review['choices'][$family] as $choice ) { $options[$choice['policy_uuid']] = $choice['label']; }
			$selected = $review['fields'][$prefix . '_policy_uuid'];
			if ( '' !== $selected && ! isset( $options[$selected] ) ) { $options[$selected] = __( 'Previously assigned policy — unavailable', 'cetech-woocommerce-delivery-engine' ); }
			AdminFormHelper::select_field( $prefix . '_policy_uuid', __( 'Published ' . $label, 'cetech-woocommerce-delivery-engine' ), $options, $selected, __( 'Choose a policy when using a published policy.', 'cetech-woocommerce-delivery-engine' ) );
		}
		AdminFormHelper::text_field( 'reason', __( 'Reason for this change', 'cetech-woocommerce-delivery-engine' ), $review['fields']['reason'], true );
		AdminPageLayout::close_form_panel(); $this->button( 'Save both policy assignments' ); echo '</form>';
		// Native controls remain usable without JavaScript; this also clears inactive POST fields.
		echo '<script>(function(){document.querySelectorAll("form.cetech-de-policy-assignment-form").forEach(function(form){["return","refund"].forEach(function(kind){var mode=form.querySelector("select[name=\""+kind+"_mode\"]"),policy=form.querySelector("select[name=\""+kind+"_policy_uuid\"]");if(!mode||!policy)return;function update(){var active=mode.value==="assigned";policy.disabled=!active;policy.required=active;policy.setAttribute("aria-required",active?"true":"false");}mode.addEventListener("change",update);update();});});})();</script>';
		if ( $review['offer_id'] > 0 ) {
			$this->form_start( 'preview' ); $this->hidden( 'ticket', $review['ticket'] );
			echo '<p class="description">' . esc_html__( 'Preview the saved assignments for this delivery choice. Save and reopen the target after making changes.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			$this->button( 'Preview saved policies', false ); echo '</form>';
		} else { echo '<p class="description">' . esc_html__( 'Choose a delivery choice above to preview its saved policies.', 'cetech-woocommerce-delivery-engine' ) . '</p>'; }
		AdminPageLayout::close_section();
	}

	private function render_preview( array $preview ): void {
		echo '<div id="cetech-policy-assignment-preview">';
		AdminPageLayout::open_section( __( 'Saved policy preview', 'cetech-woocommerce-delivery-engine' ), __( 'Shows the current saved selection and its published wording for this delivery choice.', 'cetech-woocommerce-delivery-engine' ) );
		foreach ( [ 'return_policy_v1' => 'Return Policy', 'refund_policy_v1' => 'Refund Policy' ] as $family => $label ) {
			$selection = $preview['selections'][$family] ?? null;
			if ( ! $selection instanceof PolicyEffectiveSelection || $selection->family() !== $family ) { throw new \RuntimeException(); }
			echo '<article class="cetech-de-content-panel"><h3>' . esc_html__( $label, 'cetech-woocommerce-delivery-engine' ) . '</h3>';
			$wording = match ( $selection->disposition() ) {
				'assigned' => 'Current published policy', 'disabled' => 'Disabled for this selection', 'not_assigned' => 'No policy assigned at these settings', default => 'The assigned policy is unavailable. Review its published policy.',
			};
			echo '<p>' . esc_html__( $wording, 'cetech-woocommerce-delivery-engine' ) . '</p>';
			if ( null !== ( $version = $selection->version() ) ) {
				$fields = $version->content()->facts(); echo '<h4>' . esc_html( $fields['customer_title'] ) . '</h4>';
				foreach ( [ 'summary', 'full_text', 'exceptions' ] as $field ) { if ( '' !== $fields[$field] ) { echo '<p style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( $fields[$field] ) . '</p>'; } }
			}
			echo '</article>';
		}
		AdminPageLayout::close_section();
		echo '</div>';
	}

	private function render_recovery(): void {
		AdminPageLayout::open_section( __( 'Check the original request', 'cetech-woocommerce-delivery-engine' ), __( 'A request is awaiting confirmation. Resolve its original result before starting another change.', 'cetech-woocommerce-delivery-engine' ) );
		$this->form_start( 'reconcile' ); $this->button( 'Check original request' ); echo '</form>';
		$this->form_start( 'retry' ); $this->button( 'Retry original request', false ); echo '</form>';
		AdminPageLayout::close_section();
	}

	private function form_start( string $verb ): void { $action = self::ACTION_PREFIX . $verb; echo '<form method="post" action="" class="cetech-de-policy-assignment-form">'; AdminFormHelper::nonce_field( $action ); $this->hidden( 'cetech_de_action', $action ); }
	private function hidden( string $key, string $value ): void { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />'; }
	private function button( string $label, bool $primary = true ): void { echo '<p><button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html__( $label, 'cetech-woocommerce-delivery-engine' ) . '</button></p>'; }
	private static function number( string $value ): int { if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) || (string) (int) $value !== $value ) { throw new \InvalidArgumentException(); } return (int) $value; }
	private static function deny(): never { wp_die( esc_html__( 'You do not have permission to access this page.', 'cetech-woocommerce-delivery-engine' ) ); }
}
