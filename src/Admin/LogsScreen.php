<?php
/**
 * Audit log viewer.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Admin;

use PartnerProgram\Support\Capabilities;
use PartnerProgram\Support\Logger;
use PartnerProgram\Support\SettingsRepo;

defined( 'ABSPATH' ) || exit;

final class LogsScreen {

	public const NONCE_ACTION = 'pp_logs_prune';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}

		self::handle_prune();

		global $wpdb;
		$table = $wpdb->prefix . 'pp_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A );

		$repo      = new SettingsRepo();
		$retention = (int) $repo->get( 'logs.retention_days', 90 );

		echo '<div class="wrap"><h1>' . esc_html__( 'Logs', 'partner-program' ) . '</h1>';

		// Notice for the just-completed prune action.
		if ( isset( $_GET['pruned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = (int) $_GET['pruned']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of log rows deleted. */
						_n( 'Pruned %d log row.', 'Pruned %d log rows.', $count, 'partner-program' ),
						$count
					)
				)
			);
		}

		echo '<p class="description">';
		if ( $retention > 0 ) {
			printf(
				/* translators: %d: retention days. */
				esc_html__( 'Logs older than %d days are pruned automatically by a daily cron.', 'partner-program' ),
				(int) $retention
			);
		} else {
			esc_html_e( 'Log retention is set to "keep forever". The daily prune cron is a no-op.', 'partner-program' );
		}
		echo ' ';
		printf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=partner-program-settings&tab=logs' ) ),
			esc_html__( 'Edit retention setting', 'partner-program' )
		);
		echo '</p>';

		echo '<form method="post" class="pp-admin-toolbar">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<label>' . esc_html__( 'Clear logs older than', 'partner-program' ) . ' ';
		echo '<input type="number" name="pp_prune_days" min="1" class="small-text" value="' . esc_attr( (string) max( 1, $retention ) ) . '" /> ';
		echo esc_html__( 'days', 'partner-program' ) . '</label> ';
		echo '<button type="submit" name="pp_prune" value="1" class="button button-secondary">'
			. esc_html__( 'Clear now', 'partner-program' )
			. '</button>';
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
			. '<th>' . esc_html__( 'ID', 'partner-program' ) . '</th><th>' . esc_html__( 'When', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Channel', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Level', 'partner-program' ) . '</th>'
			. '<th>' . esc_html__( 'Message', 'partner-program' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				(int) $r['id'],
				esc_html( (string) $r['created_at'] ),
				esc_html( (string) $r['channel'] ),
				esc_html( (string) $r['level'] ),
				esc_html( (string) $r['message'] )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No logs.', 'partner-program' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function handle_prune(): void {
		if ( empty( $_POST['pp_prune'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		$days    = isset( $_POST['pp_prune_days'] ) ? (int) $_POST['pp_prune_days'] : 0;
		$deleted = Logger::prune_older_than( max( 1, $days ) );
		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'partner-program-logs', 'pruned' => (int) $deleted ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
