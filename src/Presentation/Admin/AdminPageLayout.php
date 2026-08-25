<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Shared admin page layout, styles, and UI fragments matching the operations dashboard.
 */
final class AdminPageLayout {

	public const ENTITY_FORM_ID = 'cetech-de-entity-form';

	private static bool $styles_rendered = false;

	public static function open_page( string $extra_class = '' ): void {
		self::render_styles();
		$class = 'wrap cetech-de-admin-page';
		$extra = sanitize_html_class( $extra_class );
		if ( '' !== $extra ) {
			$class .= ' ' . $extra;
		}
		echo '<div class="' . esc_attr( $class ) . '">';
		echo '<hr class="wp-header-end" />';
	}

	public static function close_page(): void {
		echo '</div>';
	}

	/**
	 * @param array{label: string, url?: string, class?: string, type?: string}|null $primary_action
	 * @param array{label: string, url?: string, class?: string, type?: string}|null $secondary_action
	 */
	public static function render_page_header(
		string $eyebrow,
		string $title,
		string $subtitle,
		?array $primary_action = null,
		?array $secondary_action = null
	): void {
		echo '<header class="cetech-de-dashboard-header cetech-de-page-header">';
		echo '<div class="cetech-de-dashboard-header-text">';
		echo '<p class="cetech-de-dashboard-eyebrow">' . esc_html( $eyebrow ) . '</p>';
		echo '<h1 class="cetech-de-dashboard-title">' . esc_html( $title ) . '</h1>';
		echo '<p class="cetech-de-dashboard-subtitle">' . esc_html( $subtitle ) . '</p>';
		echo '</div>';

		if ( null !== $primary_action || null !== $secondary_action ) {
			echo '<div class="cetech-de-dashboard-header-actions">';
			echo '<div class="cetech-de-button-group cetech-de-button-group--primary">';

			if ( null !== $primary_action ) {
				self::render_header_button( $primary_action );
			}

			if ( null !== $secondary_action ) {
				self::render_header_button( $secondary_action, 'secondary' );
			}

			echo '</div></div>';
		}

		echo '</header>';
	}

	public static function open_entity_form( string $nonce_action, string $post_action, string $submit_label, ?int $record_id = null ): void {
		printf(
			'<form method="post" action="" class="cetech-de-entity-form" id="%s">',
			esc_attr( self::ENTITY_FORM_ID )
		);
		AdminFormHelper::nonce_field( $nonce_action );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $post_action ) . '" />';

		if ( null !== $record_id && $record_id > 0 ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record_id ) . '" />';
		}

		echo '<div class="cetech-de-entity-form-toolbar">';
		submit_button( $submit_label, 'primary', 'cetech_de_save', false );
		echo '</div>';
	}

	/**
	 * @param list<array{label: string, value: int|string, empty?: bool}> $stats
	 */
	public static function render_summary_stats( array $stats ): void {
		if ( [] === $stats ) {
			return;
		}

		echo '<div class="cetech-de-summary-grid cetech-de-admin-summary">';

		foreach ( $stats as $stat ) {
			$empty_class = ! empty( $stat['empty'] ) ? ' cetech-de-summary-stat--empty' : '';
			echo '<div class="cetech-de-summary-stat' . esc_attr( $empty_class ) . '">';
			echo '<span class="cetech-de-summary-value">' . esc_html( (string) $stat['value'] ) . '</span>';
			echo '<span class="cetech-de-summary-label">' . esc_html( $stat['label'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	public static function render_example( string $text ): void {
		echo '<p class="cetech-de-help-example cetech-de-admin-example">';
		echo '<span class="cetech-de-help-example-label">' . esc_html__( 'Example', 'cetech-woocommerce-delivery-engine' ) . '</span>';
		echo esc_html( $text );
		echo '</p>';
	}

	public static function render_empty_state(
		string $title,
		string $text,
		?string $action_label = null,
		?string $action_url = null
	): void {
		echo '<div class="cetech-de-empty-state cetech-de-admin-empty">';
		echo '<p class="cetech-de-empty-state-title">' . esc_html( $title ) . '</p>';
		echo '<p class="cetech-de-empty-state-text">' . esc_html( $text ) . '</p>';

		if ( null !== $action_label && null !== $action_url && '' !== $action_url ) {
			echo '<p class="cetech-de-empty-state-action">';
			printf(
				'<a href="%1$s" class="button button-primary">%2$s</a>',
				esc_url( $action_url ),
				esc_html( $action_label )
			);
			echo '</p>';
		}

		echo '</div>';
	}

	public static function render_warning(
		string $title,
		string $message,
		?string $action_label = null,
		?string $action_url = null
	): void {
		echo '<div class="cetech-de-warning-card cetech-de-admin-warning">';
		echo '<span class="cetech-de-warning-icon" aria-hidden="true">!</span>';
		echo '<div>';
		echo '<p class="cetech-de-warning-title">' . esc_html( $title ) . '</p>';
		echo '<p class="cetech-de-warning-message">' . esc_html( $message ) . '</p>';

		if ( null !== $action_label && null !== $action_url && '' !== $action_url ) {
			echo '<p class="cetech-de-warning-action">';
			printf(
				'<a href="%1$s" class="button button-secondary">%2$s</a>',
				esc_url( $action_url ),
				esc_html( $action_label )
			);
			echo '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * @param list<array{number: int, label: string}> $steps
	 */
	public static function render_step_indicator( array $steps, int $current ): void {
		echo '<ol class="cetech-de-wizard-steps" aria-label="' . esc_attr__( 'Setup steps', 'cetech-woocommerce-delivery-engine' ) . '">';
		foreach ( $steps as $index => $step ) {
			$number = (int) $step['number'];
			$class  = $number === $current ? ' is-current' : ( $number < $current ? ' is-complete' : '' );
			echo '<li class="' . esc_attr( trim( $class ) ) . '">';
			echo '<span class="cetech-de-wizard-step-number">' . esc_html( (string) $number ) . '</span>';
			echo '<span class="cetech-de-wizard-step-label">' . esc_html( $step['label'] ) . '</span>';
			if ( $index < count( $steps ) - 1 ) {
				echo '<span class="cetech-de-wizard-step-arrow" aria-hidden="true">→</span>';
			}
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * @param array{tone?: string, title: string, text?: string, meta?: string, action_label?: string, action_url?: string} $banner
	 */
	public static function render_status_banner( array $banner ): void {
		$tone = sanitize_key( (string) ( $banner['tone'] ?? 'ready' ) );
		if ( ! in_array( $tone, [ 'ready', 'attention', 'info', 'neutral' ], true ) ) {
			$tone = 'info';
		}

		echo '<div class="cetech-de-status-banner cetech-de-status-banner--' . esc_attr( $tone ) . '" role="status">';
		echo '<span class="cetech-de-status-banner-icon dashicons ' . esc_attr( self::banner_icon( $tone ) ) . '" aria-hidden="true"></span>';
		echo '<div class="cetech-de-status-banner-body">';
		echo '<p class="cetech-de-status-banner-title">' . esc_html( $banner['title'] ) . '</p>';
		if ( ! empty( $banner['text'] ) ) {
			echo '<p class="cetech-de-status-banner-text">' . esc_html( (string) $banner['text'] ) . '</p>';
		}
		if ( ! empty( $banner['meta'] ) ) {
			echo '<p class="cetech-de-status-banner-meta">' . wp_kses_post( (string) $banner['meta'] ) . '</p>';
		}
		echo '</div>';
		if ( ! empty( $banner['action_label'] ) && ! empty( $banner['action_url'] ) ) {
			echo '<a class="button" href="' . esc_url( (string) $banner['action_url'] ) . '">' . esc_html( (string) $banner['action_label'] ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * @param list<array{value: string, title: string, text: string, icon?: string, checked?: bool, name?: string, type?: string}> $cards
	 */
	public static function render_choice_cards( array $cards, string $legend, string $helper = '' ): void {
		echo '<fieldset class="cetech-de-choice-block">';
		echo '<legend class="cetech-de-choice-legend">' . esc_html( $legend ) . '</legend>';
		if ( '' !== $helper ) {
			echo '<p class="description cetech-de-choice-helper">' . esc_html( $helper ) . '</p>';
		}
		echo '<div class="cetech-de-choice-grid">';
		foreach ( $cards as $card ) {
			$type    = ( $card['type'] ?? 'radio' ) === 'checkbox' ? 'checkbox' : 'radio';
			$name    = (string) ( $card['name'] ?? 'choice' );
			$checked = ! empty( $card['checked'] ) ? ' checked' : '';
			$icon    = (string) ( $card['icon'] ?? 'dashicons-admin-generic' );
			echo '<label class="cetech-de-choice-card">';
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $card['value'] ) . '"' . $checked . ' />';
			echo '<span class="cetech-de-choice-card-body">';
			echo '<span class="cetech-de-choice-card-mark" aria-hidden="true"></span>';
			echo '<span class="cetech-de-choice-card-icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			echo '<span class="cetech-de-choice-card-title">' . esc_html( $card['title'] ) . '</span>';
			echo '<span class="cetech-de-choice-card-text">' . esc_html( $card['text'] ) . '</span>';
			echo '</span></label>';
		}
		echo '</div></fieldset>';
	}

	public static function render_info_notice( string $text, string $tone = 'info' ): void {
		$tone = in_array( $tone, [ 'info', 'success', 'warning' ], true ) ? $tone : 'info';
		echo '<div class="cetech-de-info-notice cetech-de-info-notice--' . esc_attr( $tone ) . '" role="note">';
		echo '<span class="dashicons ' . esc_attr( 'success' === $tone ? 'dashicons-yes-alt' : ( 'warning' === $tone ? 'dashicons-warning' : 'dashicons-info' ) ) . '" aria-hidden="true"></span>';
		echo '<p>' . esc_html( $text ) . '</p>';
		echo '</div>';
	}

	private static function banner_icon( string $tone ): string {
		return match ( $tone ) {
			'ready' => 'dashicons-yes-alt',
			'attention' => 'dashicons-warning',
			'neutral' => 'dashicons-marker',
			default => 'dashicons-info',
		};
	}

	public static function open_section( string $title, ?string $description = null ): void {
		echo '<section class="cetech-de-section cetech-de-admin-section">';
		echo '<div class="cetech-de-section-head">';
		echo '<h2 class="cetech-de-section-title">' . esc_html( $title ) . '</h2>';

		if ( null !== $description && '' !== $description ) {
			echo '<p class="cetech-de-section-desc">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';
	}

	public static function close_section(): void {
		echo '</section>';
	}

	public static function open_content_panel( string $title, ?string $description = null ): void {
		echo '<section class="cetech-de-form-panel cetech-de-content-panel">';
		echo '<div class="cetech-de-form-panel-head">';
		echo '<h2 class="cetech-de-form-panel-title">' . esc_html( $title ) . '</h2>';

		if ( null !== $description && '' !== $description ) {
			echo '<p class="cetech-de-form-panel-desc">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';
		echo '<div class="cetech-de-content-panel-body">';
	}

	public static function close_content_panel(): void {
		echo '</div></section>';
	}

	public static function open_form_panel( string $title, ?string $description = null ): void {
		echo '<div class="cetech-de-form-panel">';
		echo '<div class="cetech-de-form-panel-head">';
		echo '<h2 class="cetech-de-form-panel-title">' . esc_html( $title ) . '</h2>';

		if ( null !== $description && '' !== $description ) {
			echo '<p class="cetech-de-form-panel-desc">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';
		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
	}

	public static function close_form_panel(): void {
		echo '</tbody></table></div>';
	}

	public static function open_advanced( string $title ): void {
		printf(
			'<details class="cetech-de-advanced cetech-de-admin-advanced"><summary>%s</summary>',
			esc_html( $title )
		);
	}

	public static function close_advanced(): void {
		echo '</details>';
	}

	public static function open_technical_details( ?string $title = null ): void {
		printf(
			'<details class="cetech-de-technical-details"><summary>%s</summary>',
			esc_html( $title ?? AdminLanguage::technical_details_summary() )
		);
	}

	public static function close_technical_details(): void {
		echo '</details>';
	}

	public static function open_developer_information(): void {
		self::open_technical_details( AdminLanguage::developer_information_summary() );
	}

	public static function close_developer_information(): void {
		self::close_technical_details();
	}

	public static function render_styles(): void {
		if ( self::$styles_rendered ) {
			return;
		}

		self::$styles_rendered = true;

		echo '<style>
			.cetech-de-admin-page,
			.cetech-de-dashboard {
				--cetech-de-bg: #fff;
				--cetech-de-border: #dcdcde;
				--cetech-de-muted: #646970;
				--cetech-de-text: #1d2327;
				--cetech-de-accent: #2271b1;
				--cetech-de-radius: 8px;
				--cetech-de-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
				max-width: 1180px;
				margin-top: 8px;
			}
			.cetech-de-admin-page > hr.wp-header-end {
				display: none;
				margin: 0;
				border: 0;
				height: 0;
			}
			.cetech-de-page-header .notice,
			.cetech-de-dashboard-header .notice {
				margin: 0 0 12px;
				box-shadow: none;
			}
			.cetech-de-admin-page > h1 { display: none; }
			.cetech-de-dashboard-header,
			.cetech-de-page-header {
				display: flex;
				flex-wrap: wrap;
				gap: 20px;
				justify-content: space-between;
				align-items: flex-start;
				margin-bottom: 20px;
				padding: 24px;
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				box-shadow: var(--cetech-de-shadow);
			}
			.cetech-de-dashboard-eyebrow {
				margin: 0 0 6px;
				color: var(--cetech-de-accent);
				font-size: 12px;
				font-weight: 600;
				letter-spacing: 0.04em;
				text-transform: uppercase;
			}
			.cetech-de-dashboard-title {
				margin: 0 0 8px;
				font-size: 24px;
				font-weight: 600;
				line-height: 1.25;
				color: var(--cetech-de-text);
			}
			.cetech-de-dashboard-subtitle {
				margin: 0;
				color: var(--cetech-de-muted);
				font-size: 14px;
				line-height: 1.6;
				max-width: 640px;
			}
			.cetech-de-dashboard-header-actions {
				display: flex;
				flex-direction: column;
				gap: 10px;
				align-items: flex-end;
				min-width: min(100%, 420px);
			}
			.cetech-de-button-group {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				justify-content: flex-end;
			}
			.cetech-de-header-button { margin: 0 !important; }
			.cetech-de-section,
			.cetech-de-admin-section {
				margin-top: 28px;
				padding-top: 4px;
			}
			.cetech-de-section-head { margin-bottom: 14px; }
			.cetech-de-section-title {
				margin: 0 0 4px;
				font-size: 18px;
				font-weight: 600;
				line-height: 1.3;
				color: var(--cetech-de-text);
			}
			.cetech-de-section-desc {
				margin: 0;
				color: var(--cetech-de-muted);
				font-size: 13px;
				line-height: 1.5;
				max-width: 760px;
			}
			.cetech-de-admin-summary { margin: 0 0 20px; }
			.cetech-de-admin-example { margin: 0 0 20px; }
			.cetech-de-summary-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
				gap: 12px;
			}
			.cetech-de-summary-stat {
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				padding: 18px 16px;
				text-align: center;
				box-shadow: var(--cetech-de-shadow);
			}
			.cetech-de-summary-stat--empty .cetech-de-summary-value { color: #a7aaad; }
			.cetech-de-summary-value {
				display: block;
				font-size: 28px;
				font-weight: 700;
				line-height: 1.1;
				color: var(--cetech-de-text);
			}
			.cetech-de-summary-label {
				display: block;
				margin-top: 6px;
				color: var(--cetech-de-muted);
				font-size: 12px;
				line-height: 1.4;
			}
			.cetech-de-empty-state {
				background: var(--cetech-de-bg);
				border: 1px dashed var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				padding: 24px;
				text-align: center;
				margin: 0 0 20px;
			}
			.cetech-de-empty-state-action { margin: 16px 0 0; }
			.cetech-de-empty-state-title {
				margin: 0 0 6px;
				font-size: 15px;
				font-weight: 600;
				color: var(--cetech-de-text);
			}
			.cetech-de-empty-state-text {
				margin: 0;
				color: var(--cetech-de-muted);
				font-size: 13px;
				line-height: 1.55;
				max-width: 560px;
				margin-left: auto;
				margin-right: auto;
			}
			.cetech-de-warning-card {
				display: grid;
				grid-template-columns: auto 1fr;
				gap: 14px;
				align-items: start;
				background: #fffaf0;
				border: 1px solid #f0d58a;
				border-left: 4px solid #dba617;
				border-radius: var(--cetech-de-radius);
				padding: 16px 18px;
				margin: 0 0 20px;
			}
			.cetech-de-warning-icon {
				width: 28px;
				height: 28px;
				border-radius: 999px;
				background: #dba617;
				color: #fff;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				font-weight: 700;
				font-size: 14px;
				flex-shrink: 0;
			}
			.cetech-de-warning-title {
				margin: 0 0 6px;
				font-size: 14px;
				font-weight: 600;
				color: var(--cetech-de-text);
			}
			.cetech-de-warning-message {
				margin: 0;
				color: var(--cetech-de-muted);
				font-size: 13px;
				line-height: 1.55;
			}
			.cetech-de-warning-action { margin: 12px 0 0; }
			.cetech-de-help-example {
				margin: 0;
				padding: 10px 12px;
				background: #f6f7f7;
				border-radius: 6px;
				color: var(--cetech-de-text);
				font-size: 13px;
			}
			.cetech-de-help-example-label {
				display: inline-block;
				margin-right: 6px;
				padding: 2px 8px;
				border-radius: 999px;
				background: #e5f5fa;
				color: var(--cetech-de-accent);
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
			}
			.cetech-de-form-panel {
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				padding: 4px 20px 8px;
				margin: 0 0 16px;
				box-shadow: var(--cetech-de-shadow);
			}
			.cetech-de-form-panel-head {
				padding: 16px 0 8px;
				border-bottom: 1px solid #f0f0f1;
				margin-bottom: 4px;
			}
			.cetech-de-form-panel-title {
				margin: 0 0 4px;
				font-size: 15px;
				font-weight: 600;
				color: var(--cetech-de-text);
			}
			.cetech-de-form-panel-desc {
				margin: 0;
				color: var(--cetech-de-muted);
				font-size: 13px;
				line-height: 1.5;
			}
			.cetech-de-content-panel-body {
				padding: 12px 0 16px;
			}
			.cetech-de-form-table th { width: 220px; }
			.cetech-de-form-actions {
				margin: 8px 0 0;
				padding: 12px 0;
				position: sticky;
				bottom: 0;
				z-index: 10;
				background: #f0f0f1;
				border-top: 1px solid var(--cetech-de-border);
			}
			.cetech-de-entity-form-toolbar {
				position: sticky;
				top: 32px;
				z-index: 20;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				align-items: center;
				margin: 0 0 16px;
				padding: 10px 0 12px;
				background: #f0f0f1;
				border-bottom: 1px solid var(--cetech-de-border);
			}
			.cetech-de-dashboard-header-actions input.button-primary,
			.cetech-de-dashboard-header-actions button.button-primary,
			.cetech-de-entity-form-toolbar input.button-primary {
				display: inline-block !important;
				visibility: visible !important;
			}
			.cetech-de-admin-table-wrap {
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				overflow: hidden;
				box-shadow: var(--cetech-de-shadow);
				margin-bottom: 20px;
			}
			.cetech-de-admin-table-wrap .widefat {
				border: 0;
				box-shadow: none;
				margin: 0;
			}
			.cetech-de-admin-table-wrap .widefat thead th {
				font-weight: 600;
				color: var(--cetech-de-text);
			}
			.cetech-de-badge {
				display: inline-flex;
				align-items: center;
				padding: 4px 10px;
				border-radius: 999px;
				font-size: 11px;
				font-weight: 600;
				line-height: 1.4;
				white-space: nowrap;
				border: 1px solid transparent;
			}
			.cetech-de-badge--ready { background: #edfaef; color: #007017; border-color: #b8e6bf; }
			.cetech-de-badge--needs_setup { background: #fcf9e8; color: #8a6d1d; border-color: #f0e6b8; }
			.cetech-de-badge--not_active { background: #f6f7f7; color: #50575e; border-color: #dcdcde; }
			.cetech-de-badge--attention { background: #fcf0f1; color: #8a2424; border-color: #f1aeb5; }
			.cetech-de-advanced {
				margin-top: 28px;
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				padding: 0 20px 20px;
				box-shadow: var(--cetech-de-shadow);
			}
			.cetech-de-advanced > summary {
				cursor: pointer;
				font-weight: 600;
				font-size: 14px;
				padding: 18px 0;
				color: var(--cetech-de-text);
			}
			.cetech-de-advanced[open] > summary {
				border-bottom: 1px solid #f0f0f1;
				margin-bottom: 16px;
			}
			.cetech-de-technical-details {
				margin: 12px 0 0;
				padding: 8px 12px;
				background: #f6f7f7;
				border-radius: 6px;
			}
			.cetech-de-technical-details > summary {
				cursor: pointer;
				font-weight: 600;
				font-size: 12px;
				color: var(--cetech-de-muted);
			}
			.cetech-de-technical-details[open] > summary {
				margin-bottom: 8px;
			}
			.cetech-de-contact-line {
				display: block;
				font-size: 13px;
				line-height: 1.5;
			}
			.cetech-de-contact-line + .cetech-de-contact-line { margin-top: 2px; }
			.cetech-de-setting-code {
				color: #a7aaad;
				font-family: Consolas, Monaco, monospace;
				font-size: 11px;
				margin-top: 6px;
			}
			.cetech-de-help-card {
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				padding: 20px 22px;
				box-shadow: var(--cetech-de-shadow);
			}
			.cetech-de-help-steps {
				margin: 0 0 14px 20px;
				color: var(--cetech-de-text);
				font-size: 13px;
				line-height: 1.6;
			}
			.cetech-de-help-action { margin: 14px 0 0; }
			.cetech-de-delete-link {
				color: #b32d2e;
			}
			.cetech-de-delete-link:hover,
			.cetech-de-delete-link:focus {
				color: #8a2424;
			}
			.cetech-de-action-sep {
				color: #c3c4c7;
				margin: 0 4px;
			}
			.cetech-de-delete-confirm {
				background: var(--cetech-de-bg);
				border: 1px solid var(--cetech-de-border);
				border-radius: var(--cetech-de-radius);
				padding: 20px 22px;
				box-shadow: var(--cetech-de-shadow);
			}
			.cetech-de-delete-confirm-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				align-items: center;
				margin-top: 18px;
			}
			.cetech-de-delete-confirm-form {
				display: inline;
				margin-left: auto;
			}
			@media (max-width: 782px) {
				.cetech-de-dashboard-header,
				.cetech-de-page-header { padding: 18px; }
				.cetech-de-dashboard-header-actions {
					align-items: stretch;
					min-width: 100%;
				}
				.cetech-de-button-group { justify-content: flex-start; }
				.cetech-de-form-table th,
				.cetech-de-form-table td { display: block; width: 100%; }
				.cetech-de-form-table th { padding-bottom: 4px; }
			}
		</style>';
	}

	/**
	 * @param array{label: string, url?: string, class?: string, type?: string} $action
	 */
	private static function render_header_button( array $action, string $default_class = 'primary' ): void {
		$class = $action['class'] ?? $default_class;
		$button_class = 'button cetech-de-header-button';

		if ( 'primary' === $class ) {
			$button_class .= ' button-primary';
		} elseif ( 'link' === $class ) {
			$button_class = 'button-link cetech-de-header-button';
		}

		if ( 'submit' === ( $action['type'] ?? '' ) ) {
			$form_id = (string) ( $action['form'] ?? self::ENTITY_FORM_ID );
			printf(
				'<input type="submit" name="cetech_de_save" class="%1$s" value="%2$s" form="%3$s" />',
				esc_attr( $button_class ),
				esc_attr( $action['label'] ),
				esc_attr( $form_id )
			);

			return;
		}

		printf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( (string) ( $action['url'] ?? '' ) ),
			esc_attr( $button_class ),
			esc_html( $action['label'] )
		);
	}
}
