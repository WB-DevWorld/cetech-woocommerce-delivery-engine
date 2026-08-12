<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Shared admin page layout, styles, and UI fragments matching the operations dashboard.
 */
final class AdminPageLayout {

	private static bool $styles_rendered = false;

	public static function open_page(): void {
		self::render_styles();
		echo '<div class="wrap cetech-de-admin-page">';
	}

	public static function close_page(): void {
		echo '</div>';
	}

	/**
	 * @param array{label: string, url: string, class?: string}|null $primary_action
	 * @param array{label: string, url: string}|null                 $secondary_action
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
			.cetech-de-form-table th { width: 220px; }
			.cetech-de-form-actions {
				margin: 8px 0 24px;
				padding-top: 4px;
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
	 * @param array{label: string, url: string, class?: string} $action
	 */
	private static function render_header_button( array $action, string $default_class = 'primary' ): void {
		$class = $action['class'] ?? $default_class;
		$button_class = 'button cetech-de-header-button';

		if ( 'primary' === $class ) {
			$button_class .= ' button-primary';
		} elseif ( 'link' === $class ) {
			$button_class = 'button-link cetech-de-header-button';
		}

		printf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( $action['url'] ),
			esc_attr( $button_class ),
			esc_html( $action['label'] )
		);
	}
}
