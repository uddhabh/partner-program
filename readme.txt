=== Partner Program for WooCommerce ===
Contributors: beenacle
Tags: affiliate, partner, woocommerce, referral, commission
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

White-label, fully configurable affiliate / partner program for WooCommerce by Beenacle. Tiered commissions, coupon attribution, hold periods, manual payouts, compliance gating, and a private partner portal.

== Description ==

Drop-in partner program by [Beenacle](https://beenacle.com) that you can install on any WooCommerce site without writing code. Every dollar amount, percentage, hold day, threshold, tier, exclusion rule, prohibited claim, and form field is editable in the admin.

Highlights:

* Tiered commissions evaluated on prior calendar month sales.
* Coupon attribution with optional bonus rate when an attributed coupon is used.
* Configurable hold period (e.g. 15 days) before commissions become payable.
* Subtotal-after-discount calculation by default; shipping and tax exclusions are toggleable.
* Auto-rejection on refunds, cancellations, and failed orders. Chargebacks and other risky orders are excluded by flagging them with the configured fraud / compliance order-meta keys.
* Built-in partner portal: links + codes, marketing materials, compliance agreement (versioned), commissions table, payout history.
* Built-in application form with custom field builder; no extra form plugin needed.
* Manual payout batch generator with per-method CSV export.
* WP-CLI commands: `wp partner-program release-holds`, `recalculate-tiers`, `generate-payouts --period=YYYY-MM`.
* REST API for portal AJAX.
* Compliance: prohibited-term scanner, agreement versioning with re-acceptance, configurable penalty including clawback.
* Encrypted payout details (libsodium when available).
* White-label: program name, logo, color, support email, and legal text are settings.

== Installation ==

1. Upload the `partner-program` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Visit *Partner Program → Settings* to configure tiers, hold period, payout threshold, application fields, and compliance text.
4. Three pages are auto-created on activation: `/partner-application`, `/partner-portal`, `/partner-login`.

== Frequently Asked Questions ==

= Can I rebrand this for my own product or another site? =

Yes. There is no hard-coded site name. Set program name, logo, accent color, support email and legal text in *Settings → General*. Templates can be overridden by your theme at `your-theme/partner-program/...`.

= How does attribution work when both a referral cookie and a coupon are used? =

If both refer to the same affiliate, the source is recorded as `both` and the configurable bonus rate is added on top of the affiliate's tier rate.

= How do I pay partners? =

Generate a payout batch from *Partner Program → Payouts*. Download the CSV, send funds via your preferred method (ACH, PayPal, Zelle, CashApp, Wise, check), then click "Mark paid" so commissions roll to status `paid`.

== Changelog ==

= 1.2.0 =
* Correctness: partial refunds now adjust commissions linearly off the original commission amount; previously a second partial refund decayed geometrically.
* Correctness: coupon-bonus rate is no longer applied when the cookie-attributed affiliate differs from the coupon's affiliate.
* Correctness: WooCommerce Subscriptions renewals no longer inherit the parent order's coupon-used meta, so renewals don't keep getting the coupon-bonus rate forever.
* Reliability: hold-release cron now processes in batches with a MySQL advisory lock, and re-checks affiliate status before approving.
* Reliability: tier recalculation and log pruning crons take advisory locks so concurrent wp-cron runs can't double-fire side effects.
* Performance: capability re-grant moved out of the per-request `init` hook (was triggering autoloaded option writes on every front-end page load).
* Security: REST `/me/link` rejects off-site URLs (was a phishing/SEO-laundering vector for any approved partner).
* Security: settings import validates the JSON shape against a key allowlist instead of merging arbitrary input.
* Security: payout-detail encryption key is generated once on activation and stored separately, so rotating WordPress salts no longer bricks stored payout blobs.

= 1.1.0 =
* Correctness: enforce one commission row per WooCommerce order via UNIQUE constraint on `pp_commissions.order_id` (manual adjustments are now stored with `order_id = NULL`).
* Correctness: tiers are matched by stable `key` (auto-generated from label) rather than positional index; reordering tiers no longer silently shifts every affiliate's assigned tier.
* Correctness: tier list is sorted by Min on save so the "next tier" lookup in the portal is always consistent.
* Portal: tier-progress amounts in the overview tab now use the configured WooCommerce currency instead of a hardcoded `$`.
* Portal: payout method selection is validated against the admin's enabled methods list.
* Tracking: dedup visit logging by IP + referral code within a 1-hour window.
* WooCommerce: partial-refund adjustments append to the commission notes (and dedup by `refund_id`) instead of overwriting prior history.
* Updates: built-in updater now also runs on cron-driven `wp_update_plugins` checks, not only admin pageloads.
* Admin: re-grant administrator capabilities and re-register the Partner role on every plugin upgrade, not just on activation.
* Settings: removed dead-UI toggles `application.require_id_upload`, `application.enable_recaptcha` (+ key fields) and `exclusions.reject_chargeback`. Per-field "Required" still controls the ID/business proof upload; mark chargebacks via the existing fraud / compliance meta keys.

= 1.0.0 =
* Initial release.
