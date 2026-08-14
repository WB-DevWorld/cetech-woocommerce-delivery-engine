<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * WordPress admin confirmation screen and helpers for guarded permanent deletes.
 */
final class AdminPermanentDeleteFlow {

	public static function confirm_nonce_action( string $delete_post_action, int $record_id ): string {
		return $delete_post_action . '_confirm_' . $record_id;
	}

	/**
	 * @param array<string, int|string> $query_args
	 */
	public static function confirm_url( string $page_slug, int $record_id, string $delete_post_action, array $query_args = [] ): string {
		$args = array_merge(
			[
				'page'   => $page_slug,
				'action' => 'delete',
				'id'     => $record_id,
			],
			$query_args
		);

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin.php' ) ),
			self::confirm_nonce_action( $delete_post_action, $record_id )
		);
	}

	public static function verify_confirm_get( string $delete_post_action, int $record_id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['_wpnonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ),
			self::confirm_nonce_action( $delete_post_action, $record_id )
		);
	}

	/**
	 * @param array<string, int|string> $query_args
	 */
	public static function list_delete_link(
		string $page_slug,
		int $record_id,
		string $delete_post_action,
		string $capability,
		array $query_args = []
	): string {
		if ( ! current_user_can( $capability ) ) {
			return '';
		}

		$url = self::confirm_url( $page_slug, $record_id, $delete_post_action, $query_args );

		return sprintf(
			' <span class="cetech-de-action-sep" aria-hidden="true">|</span> <span class="trash"><a href="%1$s" class="cetech-de-delete-link submitdelete">%2$s</a></span>',
			esc_url( $url ),
			esc_html__( 'Delete permanently', 'cetech-woocommerce-delivery-engine' )
		);
	}

	/**
	 * @param array<string, int|string> $query_args
	 */
	public static function render_confirmation_screen(
		string $page_slug,
		string $delete_post_action,
		string $deactivate_post_action,
		string $capability,
		string $record_type_label,
		int $record_id,
		string $record_name,
		?string $record_code,
		AdminDeleteDependencyResult $dependency_result,
		array $query_args = []
	): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cetech-woocommerce-delivery-engine' ) );
		}

		if ( ! self::verify_confirm_get( $delete_post_action, $record_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'cetech-woocommerce-delivery-engine' ) );
		}

		$cancel_url = add_query_arg(
			array_merge( [ 'page' => $page_slug ], $query_args ),
			admin_url( 'admin.php' )
		);

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Permanent delete', 'cetech-woocommerce-delivery-engine' ),
			__( 'Confirm permanent delete', 'cetech-woocommerce-delivery-engine' ),
			__( 'Review the details below before removing this record from Delivery Engine.', 'cetech-woocommerce-delivery-engine' ),
			[
				'label' => __( 'Cancel and go back', 'cetech-woocommerce-delivery-engine' ),
				'url'   => $cancel_url,
				'class' => 'secondary',
			]
		);

		echo '<div class="cetech-de-delete-confirm">';
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'This action cannot be undone.', 'cetech-woocommerce-delivery-engine' ) . '</strong></p></div>';

		echo '<table class="form-table cetech-de-form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Record type', 'cetech-woocommerce-delivery-engine' ) . '</th><td>' . esc_html( $record_type_label ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Name', 'cetech-woocommerce-delivery-engine' ) . '</th><td><strong>' . esc_html( $record_name ) . '</strong></td></tr>';

		if ( null !== $record_code && '' !== $record_code ) {
			echo '<tr><th scope="row">' . esc_html__( 'Code', 'cetech-woocommerce-delivery-engine' ) . '</th><td><code>' . esc_html( $record_code ) . '</code></td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__( 'What will happen', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
		echo esc_html(
			sprintf(
				/* translators: %s: record type label, e.g. rate card */
				__(
					'You are about to permanently delete this %s. This will remove it from Delivery Engine records and it will no longer appear in the admin list. If you only want to stop using it temporarily, deactivate it instead.',
					'cetech-woocommerce-delivery-engine'
				),
				strtolower( $record_type_label )
			)
		);
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Dependencies', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';

		if ( $dependency_result->can_delete ) {
			echo esc_html__( 'No blocking dependencies were found.', 'cetech-woocommerce-delivery-engine' );
		} else {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'This record cannot be deleted yet.', 'cetech-woocommerce-delivery-engine' ) . '</strong></p><ul>';

			foreach ( $dependency_result->blocking_reasons as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}

			echo '</ul></div>';
		}

		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<div class="cetech-de-delete-confirm-actions">';

		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a> ',
			esc_url( $cancel_url ),
			esc_html__( 'Cancel', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<form method="post" style="display:inline;margin-left:8px;">';
		AdminFormHelper::nonce_field( $deactivate_post_action );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $deactivate_post_action ) . '" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record_id ) . '" />';
		submit_button( __( 'Deactivate instead', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $dependency_result->can_delete ) {
			echo '<form method="post" class="cetech-de-delete-confirm-form" onsubmit="return confirm(\'' . esc_js( __( 'Permanently delete this record? This cannot be undone.', 'cetech-woocommerce-delivery-engine' ) ) . '\');">';
			AdminFormHelper::nonce_field( $delete_post_action );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $delete_post_action ) . '" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $record_id ) . '" />';
			submit_button( __( 'Confirm permanent delete', 'cetech-woocommerce-delivery-engine' ), 'delete', 'submit', false );
			echo '</form>';
		}

		echo '</div></div>';
		AdminPageLayout::close_page();
	}

	/**
	 * @param array<string, int|string> $query_args
	 */
	public static function render_edit_danger_zone(
		string $page_slug,
		int $record_id,
		string $delete_post_action,
		string $capability,
		array $query_args = []
	): void {
		if ( $record_id <= 0 || ! current_user_can( $capability ) ) {
			return;
		}

		$url = self::confirm_url( $page_slug, $record_id, $delete_post_action, $query_args );

		AdminPageLayout::open_section(
			__( 'Danger zone', 'cetech-woocommerce-delivery-engine' ),
			__( 'Permanent delete removes this record from storage. Deactivate is the safer option if you may need it again.', 'cetech-woocommerce-delivery-engine' )
		);
		echo '<p><a href="' . esc_url( $url ) . '" class="button button-link-delete cetech-de-delete-link">' . esc_html__( 'Delete permanently…', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
		AdminPageLayout::close_section();
	}
}
