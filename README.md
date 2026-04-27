# Partner Program for WooCommerce

White-label, fully configurable affiliate / partner program for WooCommerce by [Beenacle](https://beenacle.com).

Tiered commissions, coupon attribution, hold periods, manual payouts, compliance gating, and a private partner portal — all configurable from the WordPress admin so a single codebase can serve any number of merchant sites without code changes.

## Highlights

- **Tiered commissions** evaluated on prior calendar month's attributed sales (defaults: $0–4,999 → 15%, $5,000–14,999 → 18%, $15,000+ → 22%, all editable).
- **Coupon attribution** with optional bonus rate when an attributed coupon is used (default +2%).
- **Configurable hold period** (default 15 days) before commissions become payable.
- **Subtotal-after-discount** calculation by default; shipping and tax exclusions are toggleable.
- **Auto-rejection** on refunds, cancellations, and failed orders; chargebacks and other risky orders are excluded by flagging them with the configured fraud / compliance order-meta keys (`_pp_fraud_risk`, `_pp_compliance_violation` by default). Partial refunds proportionally clawback.
- **Built-in partner portal**: overview with tier progress, links + codes, marketing materials CPT, versioned compliance agreement with re-acceptance, commissions table, payout history with threshold progress.
- **Built-in application form** with custom field builder, honeypot, rate-limit, private file uploads — no extra form plugin needed.
- **Manual payout** batch generator with per-method CSV export. Admin marks paid → commissions roll to `paid`.
- **WP-CLI**: `wp partner-program release-holds | recalculate-tiers | generate-payouts --period=YYYY-MM`.
- **REST API** namespace `partner-program/v1` for portal AJAX.
- **Compliance**: prohibited-term scanner, agreement versioning, configurable penalties (suspend, forfeit unpaid, optional clawback window).
- **Encrypted payout details** (libsodium-backed when available).
- **White-label**: program name, logo, accent color, support email and legal text are settings; templates are theme-overridable at `your-theme/partner-program/...`.
- **GDPR**: WordPress personal-data exporter and eraser hooks.

## Requirements

- WordPress 6.2+
- WooCommerce 7.0+
- PHP 7.4+

## Installation

1. Download the latest zip from [Releases](../../releases) (or clone this repo and zip the `partner-program/` folder).
2. WordPress admin → *Plugins → Add New → Upload Plugin* → upload the zip.
3. Activate. The activator creates 9 `pp_*` tables, a `partner_program_partner` role, three pages (`/partner-portal`, `/partner-application`, `/partner-login`), and schedules cron jobs for hold release and tier recalculation.
4. Visit *Partner Program → Settings* to configure tiers, hold, threshold, application fields and compliance text.

> **Pre-launch gating**: the portal is already gated by WP login + the partner role + an approval check, so unauthorized users can't read it. If you additionally want to hide the portal *page itself* during setup or share it with non-partner stakeholders, use WordPress's built-in page password (Pages → edit *Partner Portal* → *Visibility → Password protected*). No extra plugin feature needed.

## Architecture

```
partner-program/
  partner-program.php          # bootstrap + plugin headers
  src/
    Core/                      # Plugin, Activator, Installer, Autoloader
    Domain/                    # Affiliate, Commission, Payout, Application, Agreement repos
    Admin/                     # Settings + list-table screens
    Frontend/                  # Portal (shortcode + block-friendly)
    Application/               # Public form + admin review
    Tracking/                  # Cookie + visit logging
    Woo/                       # OrderHooks + CouponManager
    Payouts/                   # Batch generator + CSV export
    Compliance/                # Agreements, scanner, violation penalties
    Rest/                      # /partner-program/v1 endpoints
    Cli/                       # WP-CLI commands
    Support/                   # Logger, SettingsRepo, Encryption, Capabilities, Privacy, Money, Template
  templates/                   # theme-overridable views
  assets/css/
  languages/
```

All money math is done in integer cents. Settings are stored as a single JSON blob under `wp_options.partner_program_settings` so a configured site can be exported/imported in one click.

## Extension hooks

A non-exhaustive list of stable filters/actions:

```
partner_program_application_fields
partner_program_resolve_attribution
partner_program_calculate_commission_rate
partner_program_calculate_commission_amount
partner_program_should_pay_commission
partner_program_commission_recorded
partner_program_commission_approved
partner_program_affiliate_approved
partner_program_payout_created
partner_program_payout_paid
partner_program_violation_flagged
```

## Updates

The plugin ships with a built-in updater that polls this repo's GitHub releases. Sites running the plugin will see "Update available" in the WordPress admin within a few hours of each new release (or instantly via *Dashboard → Updates → Check Again*).

To point a site at a fork instead of this repo, define in `wp-config.php`:

```php
define( 'PARTNER_PROGRAM_GITHUB_REPO', 'your-org/your-fork' );
define( 'PARTNER_PROGRAM_GITHUB_TOKEN', 'ghp_...' ); // only for private repos
```

## Releasing (maintainers)

1. Bump the version in `partner-program.php` (Version header + `PARTNER_PROGRAM_VERSION` define) and `readme.txt` Stable tag. The build script can do this for you: `bin/build-release.sh 1.1.0`.
2. Commit, tag, push:
   ```bash
   git commit -am "Release 1.1.0"
   git tag -a v1.1.0 -m "v1.1.0"
   git push --follow-tags
   ```
3. The `Release` GitHub Actions workflow runs on the tag push: it lints PHP, verifies the tag matches the in-file version, builds `dist/partner-program.zip`, and creates the GitHub release with the zip attached and auto-generated notes from the commit log. The version is encoded in the release tag, not the zip filename.

The zip's top-level folder is always `partner-program/` (no version suffix), so installs and in-place WordPress updates work without renaming.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
