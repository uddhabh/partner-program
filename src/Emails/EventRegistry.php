<?php
/**
 * Registry of transactional email events the plugin can send.
 *
 * Each event declares its audience (admin / partner), the lifecycle
 * action hook that triggers it, the default subject + body templates,
 * and the set of placeholder tokens available inside those templates.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Emails;

defined( 'ABSPATH' ) || exit;

final class EventRegistry {

	/**
	 * @return array<string, array{
	 *     label:string,
	 *     description:string,
	 *     audience:string,
	 *     default_enabled:bool,
	 *     subject:string,
	 *     body:string,
	 *     tokens:array<string,string>,
	 * }>
	 */
	public static function all(): array {
		static $events = null;
		if ( null !== $events ) {
			return $events;
		}

		$events = [
			'application_received' => [
				'label'           => __( 'Application received (to admin)', 'partner-program' ),
				'description'     => __( 'Sent to the support email whenever a new partner application is submitted.', 'partner-program' ),
				'audience'        => 'admin',
				'default_enabled' => true,
				'subject'         => __( '[{program_name}] New partner application from {applicant_name}', 'partner-program' ),
				'body'            => __(
					"A new partner application has been submitted.\n\n"
					. "Applicant: {applicant_name}\n"
					. "Email: {applicant_email}\n\n"
					. "Submitted fields:\n{fields_dump}\n\n"
					. "Review it here: {review_url}",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}'    => __( 'Program name (from General settings).', 'partner-program' ),
					'{applicant_name}'  => __( 'Full name submitted on the application.', 'partner-program' ),
					'{applicant_email}' => __( 'Applicant email address.', 'partner-program' ),
					'{application_id}'  => __( 'Numeric application ID.', 'partner-program' ),
					'{fields_dump}'     => __( 'All submitted fields, one per line.', 'partner-program' ),
					'{review_url}'      => __( 'Admin URL to the application review screen.', 'partner-program' ),
				],
			],

			'application_approved' => [
				'label'           => __( 'Application approved (to partner)', 'partner-program' ),
				'description'     => __( 'Welcome email sent to the partner when their affiliate account is approved.', 'partner-program' ),
				'audience'        => 'partner',
				'default_enabled' => true,
				'subject'         => __( 'You are approved for {program_name}', 'partner-program' ),
				'body'            => __(
					"Hi {partner_name},\n\n"
					. "Your application for the {program_name} has been approved.\n\n"
					. "Your referral code: {referral_code}\n\n"
					. "Log in to your partner portal to grab your referral link, coupon code, and marketing materials:\n"
					. "{portal_url}",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}'  => __( 'Program name.', 'partner-program' ),
					'{partner_name}'  => __( 'Partner display name.', 'partner-program' ),
					'{partner_email}' => __( 'Partner email.', 'partner-program' ),
					'{referral_code}' => __( 'The affiliate\'s referral code.', 'partner-program' ),
					'{portal_url}'    => __( 'Partner portal URL.', 'partner-program' ),
					'{login_url}'     => __( 'Login URL (override or wp-login.php).', 'partner-program' ),
				],
			],

			'application_rejected' => [
				'label'           => __( 'Application rejected (to applicant)', 'partner-program' ),
				'description'     => __( 'Notifies the applicant that their application was not accepted.', 'partner-program' ),
				'audience'        => 'partner',
				'default_enabled' => false,
				'subject'         => __( 'Your {program_name} application', 'partner-program' ),
				'body'            => __(
					"Hi {applicant_name},\n\n"
					. "Thanks for your interest in the {program_name}. After review, we are not able to approve your application at this time.\n\n"
					. "{review_notes}\n\n"
					. "If you have questions, reply to this email or contact {support_email}.",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}'    => __( 'Program name.', 'partner-program' ),
					'{applicant_name}'  => __( 'Applicant name.', 'partner-program' ),
					'{applicant_email}' => __( 'Applicant email.', 'partner-program' ),
					'{review_notes}'    => __( 'Reviewer notes (may be empty).', 'partner-program' ),
					'{support_email}'   => __( 'Configured support email.', 'partner-program' ),
				],
			],

			'affiliate_suspended' => [
				'label'           => __( 'Affiliate suspended (to partner)', 'partner-program' ),
				'description'     => __( 'Sent when a partner is suspended manually or by a compliance violation.', 'partner-program' ),
				'audience'        => 'partner',
				'default_enabled' => true,
				'subject'         => __( 'Your {program_name} account has been suspended', 'partner-program' ),
				'body'            => __(
					"Hi {partner_name},\n\n"
					. "Your {program_name} account has been suspended and is no longer earning commissions.\n\n"
					. "If you believe this was a mistake, please reach out to {support_email}.",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}'  => __( 'Program name.', 'partner-program' ),
					'{partner_name}'  => __( 'Partner display name.', 'partner-program' ),
					'{partner_email}' => __( 'Partner email.', 'partner-program' ),
					'{support_email}' => __( 'Configured support email.', 'partner-program' ),
					'{portal_url}'    => __( 'Partner portal URL.', 'partner-program' ),
				],
			],

			'violation_flagged' => [
				'label'           => __( 'Compliance violation flagged (to admin)', 'partner-program' ),
				'description'     => __( 'Sent to the support email when a compliance violation is recorded against a partner.', 'partner-program' ),
				'audience'        => 'admin',
				'default_enabled' => true,
				'subject'         => __( '[{program_name}] Compliance violation flagged for affiliate #{affiliate_id}', 'partner-program' ),
				'body'            => __(
					"A compliance violation was flagged.\n\n"
					. "Affiliate: {partner_name} ({partner_email})\n"
					. "Affiliate ID: {affiliate_id}\n"
					. "Reason: {reason}\n\n"
					. "Open the affiliate record: {affiliate_url}",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}'  => __( 'Program name.', 'partner-program' ),
					'{affiliate_id}'  => __( 'Affiliate ID.', 'partner-program' ),
					'{partner_name}'  => __( 'Partner display name.', 'partner-program' ),
					'{partner_email}' => __( 'Partner email.', 'partner-program' ),
					'{reason}'        => __( 'Reason recorded with the violation.', 'partner-program' ),
					'{affiliate_url}' => __( 'Admin URL to the affiliate record.', 'partner-program' ),
				],
			],

			'payout_paid' => [
				'label'           => __( 'Payout sent (to partner)', 'partner-program' ),
				'description'     => __( 'Notifies the partner when a payout is marked as paid.', 'partner-program' ),
				'audience'        => 'partner',
				'default_enabled' => true,
				'subject'         => __( '{program_name}: your payout of {amount} has been sent', 'partner-program' ),
				'body'            => __(
					"Hi {partner_name},\n\n"
					. "Good news — we just sent you a payout of {amount} via {method}.\n\n"
					. "Reference: {reference}\n\n"
					. "View the full history in your partner portal:\n{portal_url}",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}' => __( 'Program name.', 'partner-program' ),
					'{partner_name}' => __( 'Partner display name.', 'partner-program' ),
					'{amount}'       => __( 'Payout amount (formatted).', 'partner-program' ),
					'{method}'       => __( 'Payout method (ach, paypal, etc).', 'partner-program' ),
					'{reference}'    => __( 'Reference / transaction ID (may be blank).', 'partner-program' ),
					'{portal_url}'   => __( 'Partner portal URL.', 'partner-program' ),
				],
			],

			'commission_approved' => [
				'label'           => __( 'Commission approved (to partner)', 'partner-program' ),
				'description'     => __( 'Optional per-commission notification once a commission clears the hold period. Disabled by default — can be noisy on high-volume programs.', 'partner-program' ),
				'audience'        => 'partner',
				'default_enabled' => false,
				'subject'         => __( '{program_name}: a new commission of {amount} is approved', 'partner-program' ),
				'body'            => __(
					"Hi {partner_name},\n\n"
					. "A commission of {amount} just cleared the hold period and is eligible for the next payout run.\n\n"
					. "See it in your portal: {portal_url}",
					'partner-program'
				),
				'tokens'          => [
					'{program_name}' => __( 'Program name.', 'partner-program' ),
					'{partner_name}' => __( 'Partner display name.', 'partner-program' ),
					'{amount}'       => __( 'Commission amount (formatted).', 'partner-program' ),
					'{portal_url}'   => __( 'Partner portal URL.', 'partner-program' ),
				],
			],
		];

		return $events;
	}

	public static function get( string $key ): ?array {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Default values stored under `emails.events` in settings.
	 *
	 * @return array<string, array{enabled:bool, subject:string, body:string}>
	 */
	public static function settings_defaults(): array {
		$out = [];
		foreach ( self::all() as $key => $event ) {
			$out[ $key ] = [
				'enabled' => (bool) $event['default_enabled'],
				'subject' => '',
				'body'    => '',
			];
		}
		return $out;
	}
}
