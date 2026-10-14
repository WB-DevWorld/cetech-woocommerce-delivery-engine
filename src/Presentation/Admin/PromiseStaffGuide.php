<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseConfigurationService;
use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffDraft;
use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\PublicPromiseView;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDeliveryOfferRepository;

/** Staff workflow for one supported delivery-time rule. It never enables customer admission. */
final class PromiseStaffGuide {
	public const ACTION = 'cetech_de_promise_guide';
	private const INDEX = 'cetech_de_promise_staff_index';
	/** @var array<string, string> */
	private array $values = [];
	/** @var list<string> */
	private array $missing = [];
	/** @var list<string> */
	private array $preview = [];
	private string $message = '';
	private string $type = 'info';

	public function __construct( private PromiseConfigurationService $service, private AdminActionHandler $actions ) {}

	public function handles(): bool {
		return ( $_POST['cetech_de_action'] ?? '' ) === self::ACTION;
	}

	public function handle(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}
		$cap = current_user_can( 'manage_delivery_settings' ) ? 'manage_delivery_settings' : 'manage_product_delivery_rules';
		if ( ! $this->actions->verify_post( self::ACTION, self::ACTION, $cap, PromiseConfigurationPage::SLUG ) ) {
			return;
		}
		$this->values = self::fields( $_POST );
		if ( 'review' === $this->values['guide_step'] ) {
			$this->review( $this->values['review_id'] );
			return;
		}
		if ( ! in_array( $this->values['guide_step'], [ 'preview', 'draft' ], true ) ) {
			$this->message = __( 'The form is unchanged. Choose a variation if needed, then preview or save the draft.', 'cetech-woocommerce-delivery-engine' );
			return;
		}
		$offer = $this->offer( $this->values['offer_code'] );
		$this->values['target_id'] = (string) $this->target_id();
		$draft = PromiseStaffDraft::assemble( $this->values, $offer ?? [] );
		$this->missing = $draft['missing'];
		if ( [] !== $this->missing ) {
			$this->type = 'warning';
			$this->message = __( 'This delivery rule is not ready. The list below is the next thing to complete. Nothing was saved.', 'cetech-woocommerce-delivery-engine' );
			return;
		}
		if ( 'preview' === $this->values['guide_step'] ) {
			$this->preview( $draft );
			return;
		}
		if ( 'draft' === $this->values['guide_step'] ) {
			$this->save( $draft, $offer ?? [] );
		}
	}

	public function render(): void {
		$this->styles();
		echo '<div class="cetech-de-guide">';
		echo '<p>' . esc_html__( 'A delivery-time rule tells customers how long preparation and delivery are expected to take. It does not change the delivery price, and saving it does not turn estimates on for customers.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->adoption();
		if ( '' !== $this->message ) {
			echo '<div class="notice notice-' . esc_attr( $this->type ) . '"><p role="status">' . esc_html( $this->message ) . '</p></div>';
		}
		$this->saved_rules();
		echo '<p><a class="button button-primary" href="#cetech-de-create-rule">' . esc_html__( 'Create delivery rule', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
		$this->form();
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=cetech-delivery-engine-shipments' ) ) . '">' . esc_html__( 'Update an estimate on an existing shipment', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
		echo '</div>';
	}

	private function adoption(): void {
		try {
			$status = $this->service->adoption_status();
		} catch ( \Throwable ) {
			echo '<p role="status">' . esc_html__( 'Customer estimates: status could not be confirmed. No change was made.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}
		$enabled = ! empty( $status['enabled'] );
		echo '<p role="status"><strong>' . esc_html( $enabled ? __( 'Customer estimates are on.', 'cetech-woocommerce-delivery-engine' ) : __( 'Customer estimates are off.', 'cetech-woocommerce-delivery-engine' ) ) . '</strong> ';
		echo esc_html__( 'Drafts, previews and publication stay separate from that switch.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
	}

	private function saved_rules(): void {
		$index = $this->index();
		echo '<h2 id="cetech-de-saved-rules">' . esc_html__( 'Saved rules', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		if ( [] === $index ) {
			echo '<p>' . esc_html__( 'No delivery-time rule has been created here yet. A rule that already exists only in advanced configuration is left as it is.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Rule', 'cetech-woocommerce-delivery-engine' ) . '</th><th>' . esc_html__( 'Applies to', 'cetech-woocommerce-delivery-engine' ) . '</th><th>' . esc_html__( 'Saved state', 'cetech-woocommerce-delivery-engine' ) . '</th><th>' . esc_html__( 'Next action', 'cetech-woocommerce-delivery-engine' ) . '</th></tr></thead><tbody>';
		foreach ( $index as $id => $row ) {
			if ( ! is_string( $id ) || ! is_array( $row ) ) {
				continue;
			}
			$opened = $this->confirm( $row );
			echo '<tr><td>' . esc_html( (string) ( $row['label'] ?? $id ) ) . '</td><td>' . esc_html( (string) ( $row['applies'] ?? '' ) ) . '</td><td>' . esc_html( $opened['label'] ) . '</td><td>';
			if ( $opened['editable'] ) {
				echo '<form method="post" action="">';
				AdminFormHelper::nonce_field( self::ACTION );
				echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" />';
				echo '<input type="hidden" name="guide_step" value="review" /><input type="hidden" name="review_id" value="' . esc_attr( $id ) . '" />';
				echo '<button class="button" type="submit">' . esc_html__( 'Review draft', 'cetech-woocommerce-delivery-engine' ) . '</button></form>';
				$this->protected_action( $row, 'seal', __( 'Mark ready to publish', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'sealed' === $opened['code'] ) {
				$this->protected_action( $row, 'publish', __( 'Publish this rule', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'published' === $opened['code'] ) {
				$this->assignment_action( $row );
			} elseif ( 'preserved' === $opened['code'] ) {
				echo esc_html__( 'Open advanced configuration. This guided editor will not change it.', 'cetech-woocommerce-delivery-engine' );
			} else {
				echo esc_html( $opened['next'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function form(): void {
		$offers = $this->offers();
		echo '<h2 id="cetech-de-create-rule">' . esc_html__( 'Create delivery rule', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		if ( [] !== $this->missing ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Still needed', 'cetech-woocommerce-delivery-engine' ) . '</strong></p><ul>';
			foreach ( $this->missing as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( [] !== $this->preview ) {
			echo '<div class="notice notice-info" role="status"><p><strong>' . esc_html__( 'Customer wording preview', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
			foreach ( $this->preview as $line ) {
				echo '<p>' . esc_html( $line ) . '</p>';
			}
			echo '<p>' . esc_html__( 'This preview is not shown to customers and does not reserve a delivery date.', 'cetech-woocommerce-delivery-engine' ) . '</p></div>';
		}
		if ( [] === $offers ) {
			echo '<p>' . esc_html__( 'Create a delivery option first. This guide uses an option that already exists and does not invent one.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<form method="post" action="" class="cetech-de-guide-form">';
		AdminFormHelper::nonce_field( self::ACTION );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<fieldset><legend>' . esc_html__( '1. Delivery option', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<label for="cetech-de-offer">' . esc_html__( 'Which existing delivery option does this rule describe?', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<select id="cetech-de-offer" name="offer_code"><option value="">' . esc_html__( 'Select a delivery option', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $offers as $offer ) {
			echo '<option value="' . esc_attr( $offer['internal_code'] ) . '"' . selected( $this->values['offer_code'] ?? '', $offer['internal_code'], false ) . '>' . esc_html( $offer['public_label'] ) . '</option>';
		}
		echo '</select></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '2. Time ranges', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p>' . esc_html__( 'Enter the shortest and longest time. These are estimates, not guaranteed dates.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->number( 'processing_min', __( 'Preparation, at least', 'cetech-woocommerce-delivery-engine' ) );
		$this->number( 'processing_max', __( 'Preparation, at most', 'cetech-woocommerce-delivery-engine' ) );
		$this->number( 'delivery_min', __( 'Delivery, at least', 'cetech-woocommerce-delivery-engine' ) );
		$this->number( 'delivery_max', __( 'Delivery, at most', 'cetech-woocommerce-delivery-engine' ) );
		echo '<label for="cetech-de-unit">' . esc_html__( 'Unit', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-unit" name="unit">';
		echo '<option value="elapsed_minutes"' . selected( $this->values['unit'] ?? '', 'elapsed_minutes', false ) . '>' . esc_html__( 'Elapsed minutes, including time outside working hours', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="business_days"' . selected( $this->values['unit'] ?? '', 'business_days', false ) . '>' . esc_html__( 'Business days, using the working calendar below', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '3. Working calendar', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p>' . esc_html__( 'Leave a day blank when it is closed. Blank days are not turned into opening hours.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<label for="cetech-de-timezone">' . esc_html__( 'Timezone', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-timezone" name="timezone"><option value="">' . esc_html__( 'Select a timezone', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( \DateTimeZone::listIdentifiers() as $zone ) {
			echo '<option value="' . esc_attr( $zone ) . '"' . selected( $this->values['timezone'] ?? '', $zone, false ) . '>' . esc_html( str_replace( '_', ' ', $zone ) ) . '</option>';
		}
		echo '</select>';
		foreach ( [ 'mon' => __( 'Monday', 'cetech-woocommerce-delivery-engine' ), 'tue' => __( 'Tuesday', 'cetech-woocommerce-delivery-engine' ), 'wed' => __( 'Wednesday', 'cetech-woocommerce-delivery-engine' ), 'thu' => __( 'Thursday', 'cetech-woocommerce-delivery-engine' ), 'fri' => __( 'Friday', 'cetech-woocommerce-delivery-engine' ), 'sat' => __( 'Saturday', 'cetech-woocommerce-delivery-engine' ), 'sun' => __( 'Sunday', 'cetech-woocommerce-delivery-engine' ) ] as $day => $label ) {
			echo '<div class="cetech-de-day"><span>' . esc_html( $label ) . '</span>';
			echo '<label>' . esc_html__( 'Opens', 'cetech-woocommerce-delivery-engine' ) . ' <input name="' . esc_attr( $day . '_open' ) . '" value="' . esc_attr( $this->values[ $day . '_open' ] ?? '' ) . '" placeholder="09:00" inputmode="numeric" /></label>';
			echo '<label>' . esc_html__( 'Closes', 'cetech-woocommerce-delivery-engine' ) . ' <input name="' . esc_attr( $day . '_close' ) . '" value="' . esc_attr( $this->values[ $day . '_close' ] ?? '' ) . '" placeholder="17:00" inputmode="numeric" /></label></div>';
		}
		echo '<label for="cetech-de-closures">' . esc_html__( 'Closure dates, optional', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<textarea id="cetech-de-closures" name="closures" rows="3" placeholder="2026-12-25">' . esc_textarea( $this->values['closures'] ?? '' ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'The placeholder shows the date format. It is not saved unless you enter a date.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<label for="cetech-de-cutoff">' . esc_html__( 'Order cutoff, optional', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<input id="cetech-de-cutoff" name="cutoff" value="' . esc_attr( $this->values['cutoff'] ?? '' ) . '" placeholder="15:00" inputmode="numeric" />';
		echo '<label for="cetech-de-cutoff-miss">' . esc_html__( 'If the cutoff has passed', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-cutoff-miss" name="cutoff_miss">';
		echo '<option value="next_opening"' . selected( $this->values['cutoff_miss'] ?? '', 'next_opening', false ) . '>' . esc_html__( 'Use the next working period', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="reject"' . selected( $this->values['cutoff_miss'] ?? '', 'reject', false ) . '>' . esc_html__( 'Do not offer this estimate', 'cetech-woocommerce-delivery-engine' ) . '</option></select></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '4. Where it applies', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		foreach ( [ 'global' => __( 'Whole store', 'cetech-woocommerce-delivery-engine' ), 'product' => __( 'One product', 'cetech-woocommerce-delivery-engine' ), 'variation' => __( 'One variation', 'cetech-woocommerce-delivery-engine' ) ] as $scope => $label ) {
			echo '<label><input type="radio" name="scope" value="' . esc_attr( $scope ) . '"' . checked( $this->values['scope'] ?? 'global', $scope, false ) . ' /> ' . esc_html( $label ) . '</label> ';
		}
		echo '<div class="cetech-de-products"><label for="cetech-de-product">' . esc_html__( 'Product', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-product" name="product_id"><option value="">' . esc_html__( 'Select a product', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->products() as $product ) {
			echo '<option value="' . esc_attr( (string) $product['id'] ) . '"' . selected( $this->values['product_id'] ?? '', (string) $product['id'], false ) . '>' . esc_html( $product['name'] ) . '</option>';
		}
		echo '</select>';
		echo '<label for="cetech-de-variation">' . esc_html__( 'Variation', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-variation" name="variation_id"><option value="">' . esc_html__( 'Select a variation', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->variations() as $variation ) {
			echo '<option value="' . esc_attr( (string) $variation['id'] ) . '"' . selected( $this->values['variation_id'] ?? '', (string) $variation['id'], false ) . '>' . esc_html( $variation['name'] ) . '</option>';
		}
		echo '</select><button class="button" type="submit" name="guide_step" value="variations">' . esc_html__( 'Show this product’s variations', 'cetech-woocommerce-delivery-engine' ) . '</button></div></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '5. Customer requirement', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<label><input type="radio" name="estimate_required" value="no"' . checked( $this->values['estimate_required'] ?? 'no', 'no', false ) . ' /> ' . esc_html__( 'The estimate is optional', 'cetech-woocommerce-delivery-engine' ) . '</label> ';
		echo '<label><input type="radio" name="estimate_required" value="yes"' . checked( $this->values['estimate_required'] ?? '', 'yes', false ) . ' /> ' . esc_html__( 'Checkout needs an estimate for this rule', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'This guided rule starts at checkout, does not reserve capacity, and is refused when the time cannot be met. Payment-confirmed timing and capacity booking stay in advanced configuration.', 'cetech-woocommerce-delivery-engine' ) . '</p></fieldset>';
		echo '<p><button class="button" type="submit" name="guide_step" value="preview">' . esc_html__( 'Preview customer wording', 'cetech-woocommerce-delivery-engine' ) . '</button> ';
		echo '<button class="button button-primary" type="submit" name="guide_step" value="draft">' . esc_html__( 'Save draft', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	/** @param array{missing: list<string>, calendar: mixed, policy: mixed, summary: string} $draft */
	private function preview( array $draft ): void {
		try {
			$policy = $draft['policy'];
			if ( ! $policy instanceof ServicePromisePolicy ) {
				throw new \InvalidArgumentException( 'Draft policy is absent.' );
			}
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$result  = $this->service->preview( $binding, $policy->to_private_json(), null === $draft['calendar'] ? [] : [ $draft['calendar']->private_facts() ] );
			$format  = new PublicPromiseFormatter();
			foreach ( $result['views'] as $view ) {
				$this->preview[] = $format->text( PublicPromiseView::from_array( $view ) );
			}
			$this->message = __( 'Preview only. Nothing was saved and customer estimates were not turned on.', 'cetech-woocommerce-delivery-engine' );
		} catch ( \Throwable ) {
			$this->type = 'warning';
			$this->message = $draft['summary'] . ' ' . __( 'A customer date could not be calculated from these entries. Nothing was saved.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	/** @param array{missing: list<string>, calendar: mixed, policy: mixed, summary: string} $draft @param array<string, mixed> $offer */
	private function save( array $draft, array $offer ): void {
		$policy = $draft['policy'];
		if ( ! $policy instanceof ServicePromisePolicy ) {
			return;
		}
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$facts   = $policy->private_facts();
			$scope   = [ 'kind' => $facts['scope']['kind'], 'target_id' => (int) ( $facts['scope']['target_id'] ?? 0 ) ];
			$prior   = $this->index()[ $facts['policy_id'] ] ?? null;
			if ( ! is_array( $prior ) ) {
				try {
					$existing = $this->service->inspect_version( $binding, 'policy', $facts['policy_id'], 1, $scope );
					if ( is_array( $existing['version'] ?? null ) ) {
						$this->type = 'warning';
						$this->message = __( 'A saved rule already uses this delivery option and scope. It was not overwritten. Your entries are still on this page; use advanced configuration if that rule is not listed here.', 'cetech-woocommerce-delivery-engine' );
						return;
					}
				} catch ( \Throwable ) {
					$prior = null;
				}
			}
			$version = 1;
			$saved_calendar_version = 0;
			$preconditions = [ 'object_revision' => 0, 'version_revision' => 0, 'published_version_id' => 0 ];
			if ( is_array( $prior ) ) {
				$opened = $this->service->inspect_version( $binding, 'policy', $facts['policy_id'], (int) $prior['version'], $scope );
				$saved  = is_array( $opened['version'] ?? null ) ? $opened['version'] : [];
				if ( ( $saved['body_digest'] ?? '' ) !== ( $prior['digest'] ?? '' ) ) {
					$this->type = 'warning';
					$this->message = __( 'This rule changed outside the guided editor. Your entries are still on this page, and the saved rule was not overwritten.', 'cetech-woocommerce-delivery-engine' );
					return;
				}
				$version = (int) $opened['next_version'];
				$preconditions['object_revision'] = (int) $opened['object_revision'];
				$preconditions['published_version_id'] = (int) $opened['published_version_id'];
				$draft = PromiseStaffDraft::assemble( $this->values, $offer, $version );
				if ( [] !== $draft['missing'] || ! $draft['policy'] instanceof ServicePromisePolicy ) {
					$this->missing = $draft['missing'];
					$this->type = 'warning';
					$this->message = __( 'The next draft could not be prepared. Your entries are still on this page.', 'cetech-woocommerce-delivery-engine' );
					return;
				}
				$policy = $draft['policy'];
				$facts  = $policy->private_facts();
			}
			if ( $draft['calendar'] instanceof BusinessCalendarVersion ) {
				$calendar_prior = is_array( $prior ) ? (int) ( $prior['calendar_version'] ?? 0 ) : 0;
				$calendar_version = $calendar_prior > 0 ? $version : 1;
				$calendar_pre = 1 === $calendar_version ? [ 'object_revision' => 0, 'version_revision' => 0, 'published_version_id' => 0 ] : $preconditions;
				$calendar = $calendar_version === $version ? $draft['calendar'] : PromiseStaffDraft::assemble( $this->values, $offer, $calendar_version )['calendar'];
				if ( ! $calendar instanceof BusinessCalendarVersion ) {
					$this->type = 'warning';
					$this->message = __( 'The working calendar could not be prepared. Your entries are still on this page.', 'cetech-woocommerce-delivery-engine' );
					return;
				}
				$result = $this->service->version( $binding, 'promise.version.create', RequestContext::create()->request_id, $this->payload( 'calendar', $calendar->private_facts(), $calendar->to_private_json(), $calendar->digest(), [ 'kind' => 'global', 'target_id' => 0 ], $calendar_pre ), false );
				if ( 'accepted' !== $result->outcome->state ) {
					$this->type = 'unconfirmed' === $result->outcome->state ? 'warning' : 'error';
					$this->message = __( 'The working calendar was not saved. Your entries are still on this page. Review the current rule before trying again.', 'cetech-woocommerce-delivery-engine' );
					return;
				}
				$saved_calendar_version = $calendar_version;
			}
			$result = $this->service->version( $binding, 'promise.version.create', RequestContext::create()->request_id, $this->payload( 'policy', $facts, $policy->to_private_json(), $policy->digest(), $scope, $preconditions ), false );
			if ( 'accepted' !== $result->outcome->state ) {
				$this->type = 'unconfirmed' === $result->outcome->state ? 'warning' : 'error';
				$this->message = __( 'The draft was not saved. Your entries are still on this page. Review the current rule before trying again.', 'cetech-woocommerce-delivery-engine' );
				return;
			}
			$this->remember( $facts, $policy->digest(), $version, $saved_calendar_version, (string) ( $offer['public_label'] ?? '' ) );
			$this->type = 'success';
			$this->message = __( 'Draft saved. Customer estimates were not turned on. Review it below, then use the protected publication actions when it is ready.', 'cetech-woocommerce-delivery-engine' );
		} catch ( \Throwable ) {
			$this->type = 'error';
			$this->message = __( 'The draft could not be confirmed. Your entries are still on this page, and no saved rule was overwritten.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	private function review( string $id ): void {
		$row = $this->index()[ $id ] ?? null;
		if ( ! is_array( $row ) || ! is_array( $row['fields'] ?? null ) ) {
			$this->type = 'warning';
			$this->message = __( 'That rule is not available to review here.', 'cetech-woocommerce-delivery-engine' );
			return;
		}
		$confirmed = $this->confirm( $row );
		if ( ! $confirmed['editable'] ) {
			$this->type = 'warning';
			$this->message = 'preserved' === $confirmed['code']
				? __( 'This saved rule uses settings the guided editor cannot change. It was left as saved.', 'cetech-woocommerce-delivery-engine' )
				: __( 'This saved rule could not be confirmed, so the form was not filled from it.', 'cetech-woocommerce-delivery-engine' );
			return;
		}
		foreach ( $row['fields'] as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$this->values[ $key ] = $value;
			}
		}
		$this->message = __( 'Draft reopened. Check the entries, preview the customer wording, then save another draft if you change them.', 'cetech-woocommerce-delivery-engine' );
	}

	/** @param array<string, mixed> $row @return array{label: string, code: string, editable: bool, next: string} */
	private function confirm( array $row ): array {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$scope   = [ 'kind' => (string) ( $row['scope_kind'] ?? 'global' ), 'target_id' => (int) ( $row['scope_id'] ?? 0 ) ];
			$opened  = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], (int) $row['version'], $scope );
			$saved   = is_array( $opened['version'] ?? null ) ? $opened['version'] : null;
			if ( null === $saved || ( $saved['body_digest'] ?? '' ) !== ( $row['digest'] ?? '' ) ) {
				return [ 'label' => __( 'Preserved outside this guide', 'cetech-woocommerce-delivery-engine' ), 'code' => 'preserved', 'editable' => false, 'next' => '' ];
			}
			$state = (string) ( $saved['state'] ?? '' );
			return [
				'label'    => $this->state_label( $state ),
				'code'     => $state,
				'editable' => 'draft' === $state,
				'next'     => match ( $state ) {
					'draft' => __( 'Review the draft, then seal it for publication.', 'cetech-woocommerce-delivery-engine' ),
					'sealed' => __( 'Publish this sealed rule from advanced configuration.', 'cetech-woocommerce-delivery-engine' ),
					'published' => __( 'Assign the published rule from advanced configuration. Customers still do not see it until estimates are turned on.', 'cetech-woocommerce-delivery-engine' ),
					default => __( 'Review this rule in advanced configuration.', 'cetech-woocommerce-delivery-engine' ),
				},
			];
		} catch ( \Throwable ) {
			return [ 'label' => __( 'Not confirmed', 'cetech-woocommerce-delivery-engine' ), 'code' => 'unknown', 'editable' => false, 'next' => __( 'Refresh this page before trying again.', 'cetech-woocommerce-delivery-engine' ) ];
		}
	}

	/** @param array<string, mixed> $row */
	private function protected_action( array $row, string $verb, string $label ): void {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$scope   = [ 'kind' => (string) ( $row['scope_kind'] ?? 'global' ), 'target_id' => (int) ( $row['scope_id'] ?? 0 ) ];
			$opened  = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], (int) $row['version'], $scope );
			$saved   = is_array( $opened['version'] ?? null ) ? $opened['version'] : null;
			if ( null === $saved || ( $saved['body_digest'] ?? '' ) !== ( $row['digest'] ?? '' ) ) {
				return;
			}
			$payload = [
				'kind' => 'policy', 'logical_id' => (string) $row['policy_id'], 'domain_version' => (int) $saved['domain_version'], 'version_uuid' => (string) $saved['version_uuid'],
				'body_digest' => (string) $saved['body_digest'], 'scope' => $scope, 'body_json' => null, 'declared_from' => $saved['declared_from'], 'declared_until' => $saved['declared_until'],
				'reason' => '', 'scheduled_author_user_id' => null,
				'preconditions' => [ 'object_revision' => (int) $opened['object_revision'], 'version_revision' => (int) $saved['row_revision'], 'published_version_id' => (int) $opened['published_version_id'] ],
			];
			$action = PromiseConfigurationPage::ACTION_PREFIX . $verb;
			echo '<form method="post" action="">';
			AdminFormHelper::nonce_field( $action );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
			echo '<input type="hidden" name="site_key" value="' . esc_attr( PromiseStaffDraft::SITE_KEY ) . '" />';
			echo '<input type="hidden" name="command_json" value="' . esc_attr( PromiseJson::encode( $payload ) ) . '" />';
			echo '<input type="hidden" name="request_token" value="' . esc_attr( RequestContext::create()->request_id ) . '" />';
			echo '<label>' . esc_html__( 'Reason', 'cetech-woocommerce-delivery-engine' ) . ' <input name="reason" required /></label>';
			echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
		} catch ( \Throwable ) {
			echo '<p>' . esc_html__( 'The protected action is unavailable until this saved rule can be confirmed.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
	}

	/** @param array<string, mixed> $row */
	private function assignment_action( array $row ): void {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$scope   = [ 'kind' => (string) ( $row['scope_kind'] ?? 'global' ), 'target_id' => (int) ( $row['scope_id'] ?? 0 ) ];
			$opened  = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], (int) $row['version'], $scope );
			$saved   = is_array( $opened['version'] ?? null ) ? $opened['version'] : null;
			if ( null === $saved || ( $saved['body_digest'] ?? '' ) !== ( $row['digest'] ?? '' ) || ! is_string( $saved['body_json'] ?? null ) ) {
				return;
			}
			$policy = ServicePromisePolicy::from_json( $saved['body_json'] );
			$facts  = $policy->private_facts();
			$key    = [ 'scope_kind' => $scope['kind'], 'scope_id' => $scope['target_id'], 'service_kind' => (string) $facts['service']['kind'], 'service_code' => (string) $facts['service']['code'], 'endpoint' => (string) $facts['endpoint'], 'endpoint_kind' => (string) $facts['endpoint_kind'] ];
			$current = [ 'revision' => 0, 'generation' => 0 ];
			try {
				$assignment = $this->service->inspect_assignment( $binding, $key );
				$current['revision'] = (int) ( $assignment['revision'] ?? 0 );
				$current['generation'] = (int) ( $assignment['generation'] ?? 0 );
			} catch ( \Throwable ) {
				$current = [ 'revision' => 0, 'generation' => 0 ];
			}
			$action = PromiseConfigurationPage::ACTION_PREFIX . 'assignment';
			echo '<form method="post" action="">';
			AdminFormHelper::nonce_field( $action );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
			echo '<input type="hidden" name="site_key" value="' . esc_attr( PromiseStaffDraft::SITE_KEY ) . '" />';
			foreach ( $key as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
			echo '<input type="hidden" name="mode" value="assigned" />';
			echo '<input type="hidden" name="policy_reference_json" value="' . esc_attr( PromiseJson::encode( $policy->reference()->private_facts() ) ) . '" />';
			echo '<input type="hidden" name="expected_revision" value="' . esc_attr( (string) $current['revision'] ) . '" />';
			echo '<input type="hidden" name="expected_generation" value="' . esc_attr( (string) $current['generation'] ) . '" />';
			echo '<input type="hidden" name="request_token" value="' . esc_attr( RequestContext::create()->request_id ) . '" />';
			echo '<label>' . esc_html__( 'Reason for assigning this published rule', 'cetech-woocommerce-delivery-engine' ) . ' <input name="reason" required /></label>';
			echo '<button class="button" type="submit">' . esc_html__( 'Assign published rule', 'cetech-woocommerce-delivery-engine' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'Assignment does not turn customer estimates on.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		} catch ( \Throwable ) {
			echo '<p>' . esc_html__( 'Assignment is unavailable until this published rule can be confirmed.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
	}

	private function state_label( string $state ): string {
		return match ( $state ) {
			'draft' => __( 'Draft', 'cetech-woocommerce-delivery-engine' ),
			'sealed' => __( 'Ready to publish', 'cetech-woocommerce-delivery-engine' ),
			'scheduled' => __( 'Scheduled', 'cetech-woocommerce-delivery-engine' ),
			'published' => __( 'Published', 'cetech-woocommerce-delivery-engine' ),
			'retired' => __( 'Retired', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Saved', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/** @param array<string, mixed> $facts @param array<string, int> $preconditions @return array<string, mixed> */
	private function payload( string $kind, array $facts, string $json, string $digest, array $scope, array $preconditions ): array {
		$id = 'policy' === $kind ? (string) $facts['policy_id'] : (string) $facts['calendar_id'];
		return [
			'kind' => $kind, 'logical_id' => $id, 'domain_version' => (int) $facts['version'], 'version_uuid' => RequestContext::create()->request_id,
			'body_digest' => $digest, 'scope' => $scope, 'body_json' => $json,
			'declared_from' => 'policy' === $kind ? (string) $facts['effective_from'] : gmdate( 'Y-m-d H:i:s' ) . '.000000',
			'declared_until' => 'policy' === $kind ? $facts['effective_until'] : null,
			'reason' => __( 'Saved from the delivery rule guide.', 'cetech-woocommerce-delivery-engine' ),
			'scheduled_author_user_id' => null, 'preconditions' => $preconditions,
		];
	}

	/** @param array<string, mixed> $facts */
	private function remember( array $facts, string $digest, int $version, int $calendar_version, string $label ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$index = $this->index();
		$scope = (string) $facts['scope']['kind'];
		$target = (int) ( $facts['scope']['target_id'] ?? 0 );
		$index[ (string) $facts['policy_id'] ] = [
			'policy_id' => (string) $facts['policy_id'], 'version' => $version, 'calendar_version' => $calendar_version,
			'scope_kind' => $scope, 'scope_id' => $target, 'label' => '' !== $label ? $label : (string) $facts['service']['customer_label'],
			'applies' => 'global' === $scope ? __( 'Whole store', 'cetech-woocommerce-delivery-engine' ) : ( 'product' === $scope ? __( 'One product', 'cetech-woocommerce-delivery-engine' ) : __( 'One variation', 'cetech-woocommerce-delivery-engine' ) ),
			'digest' => $digest, 'fields' => $this->values,
		];
		update_option( self::INDEX, $index, false );
	}

	/** @return array<string, mixed> */
	private function index(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}
		$index = get_option( self::INDEX, [] );
		return is_array( $index ) ? $index : [];
	}

	/** @return array<string, mixed>|null */
	private function offer( string $code ): ?array {
		foreach ( $this->offers() as $offer ) {
			if ( $offer['internal_code'] === $code ) {
				return $offer;
			}
		}
		return null;
	}

	/** @return list<array{internal_code: string, public_label: string, route: string}> */
	private function offers(): array {
		try {
			$rows = ( new WpdbDeliveryOfferRepository() )->list( [ 'status' => RecordStatus::Active->value, 'limit' => 200 ] );
		} catch ( \Throwable ) {
			return [];
		}
		$offers = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$code = trim( (string) ( $row['internal_code'] ?? '' ) );
			$label = trim( (string) ( $row['public_label'] ?? '' ) );
			if ( '' === $code || '' === $label ) {
				continue;
			}
			$offers[] = [ 'internal_code' => $code, 'public_label' => $label, 'route' => (string) ( $row['route'] ?? '' ) ];
		}
		usort( $offers, static fn( array $a, array $b ): int => strcasecmp( $a['public_label'], $b['public_label'] ) );
		return $offers;
	}

	/** @return list<array{id: int, name: string}> */
	private function products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$products = wc_get_products( [ 'status' => 'publish', 'limit' => 100, 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ] );
		$out = [];
		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product && ! $product->is_type( 'variation' ) ) {
				$out[] = [ 'id' => $product->get_id(), 'name' => $product->get_name() ];
			}
		}
		return $out;
	}

	/** @return list<array{id: int, name: string}> */
	private function variations(): array {
		$product_id = (int) ( $this->values['product_id'] ?? 0 );
		if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return [];
		}
		$out = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof \WC_Product && $child->is_type( 'variation' ) ) {
				$out[] = [ 'id' => $child->get_id(), 'name' => wc_get_formatted_variation( $child, true, false, false ) ];
			}
		}
		return $out;
	}

	private function target_id(): int {
		$scope = $this->values['scope'] ?? '';
		if ( 'global' === $scope ) {
			return 0;
		}
		$id = (int) ( 'variation' === $scope ? ( $this->values['variation_id'] ?? 0 ) : ( $this->values['product_id'] ?? 0 ) );
		if ( $id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}
		if ( 'product' === $scope ) {
			return $product->is_type( 'variation' ) ? 0 : $id;
		}
		return $product->is_type( 'variation' ) && $product->get_parent_id() === (int) ( $this->values['product_id'] ?? 0 ) ? $id : 0;
	}

	/** @param array<string, mixed> $post @return array<string, string> */
	private static function fields( array $post ): array {
		$keys = [ 'guide_step', 'review_id', 'offer_code', 'processing_min', 'processing_max', 'delivery_min', 'delivery_max', 'unit', 'timezone', 'closures', 'cutoff', 'cutoff_miss', 'scope', 'product_id', 'variation_id', 'estimate_required', 'mon_open', 'mon_close', 'tue_open', 'tue_close', 'wed_open', 'wed_close', 'thu_open', 'thu_close', 'fri_open', 'fri_close', 'sat_open', 'sat_close', 'sun_open', 'sun_close' ];
		$out = [];
		foreach ( $keys as $key ) {
			$value = $post[ $key ] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 2000 ) {
				$value = '';
			}
			$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
			if ( 'closures' === $key && function_exists( 'sanitize_textarea_field' ) ) {
				$out[ $key ] = sanitize_textarea_field( $raw );
				continue;
			}
			$out[ $key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : trim( $raw );
		}
		return $out;
	}

	private function number( string $name, string $label ): void {
		echo '<label>' . esc_html( $label ) . ' <input name="' . esc_attr( $name ) . '" inputmode="numeric" value="' . esc_attr( $this->values[ $name ] ?? '' ) . '" /></label> ';
	}

	private function styles(): void {
		echo '<style>.cetech-de-guide{max-width:46rem}.cetech-de-guide form,.cetech-de-guide select,.cetech-de-guide input,.cetech-de-guide textarea{max-width:100%}.cetech-de-guide fieldset{margin:1rem 0;padding:1rem}.cetech-de-guide label,.cetech-de-guide select,.cetech-de-guide input,.cetech-de-guide textarea{display:block;margin:.4rem 0}.cetech-de-day{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}.cetech-de-guide .cetech-de-products{margin-top:.75rem}.cetech-de-guide-form:not(:has(input[name=scope][value=product]:checked)):not(:has(input[name=scope][value=variation]:checked)) .cetech-de-products{display:none}.cetech-de-guide-form:not(:has(input[name=scope][value=variation]:checked)) #cetech-de-variation{display:none}@media (max-width:782px){.cetech-de-guide .button{display:block;margin:.5rem 0}}</style>';
	}
}
