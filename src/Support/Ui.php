<?php
/**
 * Tiny shared HTML helpers used by both wp-admin screens and the public
 * partner portal. Anything here must produce escaped output and stay
 * stylistically neutral — context-specific styling lives in admin.css /
 * portal.css and components.css.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Ui {

	/**
	 * Render the .pp-grid + .pp-card stat block used by the admin
	 * Dashboard and the portal Overview tab. Each card is:
	 *
	 *   [ 'title' => string, 'value' => string|int, 'sub' => string ]
	 *
	 * Title/sub are escaped; `value` is escaped too — pass already-formatted
	 * money strings (Money::format()) rather than raw integers.
	 *
	 * @param array<int, array{title: string, value: string|int, sub?: string}> $cards
	 */
	public static function stat_cards( array $cards ): void {
		if ( ! $cards ) {
			return;
		}
		echo '<div class="pp-grid">';
		foreach ( $cards as $card ) {
			$title = (string) ( $card['title'] ?? '' );
			$value = (string) ( $card['value'] ?? '' );
			$sub   = (string) ( $card['sub'] ?? '' );
			echo '<div class="pp-card">';
			echo '<div class="pp-card-title">' . esc_html( $title ) . '</div>';
			echo '<div class="pp-card-value">' . esc_html( $value ) . '</div>';
			if ( '' !== $sub ) {
				echo '<div class="pp-card-sub">' . esc_html( $sub ) . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the standard "page + status (+ optional search) + Filter button"
	 * GET form used by the Affiliates, Applications, and Commissions list
	 * screens. Mirrors how core list tables present a status dropdown.
	 *
	 * @param string                $page          Admin page slug (the value for ?page=).
	 * @param string                $current_status Selected status value (may be '').
	 * @param array<string, string> $statuses      Map of value => label, including the "" => "All" option.
	 * @param array{name?: string, value?: string, placeholder?: string}|null $search
	 *        When provided, renders a <input type="search"> with the given name + value + placeholder.
	 */
	public static function status_filter( string $page, string $current_status, array $statuses, ?array $search = null ): void {
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( $page ) );
		echo '<select name="status">';
		foreach ( $statuses as $val => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $val ),
				selected( $current_status, (string) $val, false ),
				esc_html( (string) $label )
			);
		}
		echo '</select> ';
		if ( $search ) {
			printf(
				'<input type="search" name="%s" value="%s" placeholder="%s" /> ',
				esc_attr( (string) ( $search['name'] ?? 's' ) ),
				esc_attr( (string) ( $search['value'] ?? '' ) ),
				esc_attr( (string) ( $search['placeholder'] ?? '' ) )
			);
		}
		echo get_submit_button( __( 'Filter', 'partner-program' ), 'secondary', 'submit', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</form>';
	}
}
