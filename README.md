# Fluent Forms - Privacy

Registers [Fluent Forms](https://fluentforms.com/) submissions with WordPress core's privacy
tooling, so form entries are covered by **Tools → Export Personal Data** and **Tools → Erase
Personal Data** (GDPR data-subject requests).

## Why

Fluent Forms (free and Pro) does **not** register a personal-data exporter or eraser. On a stock
install, a data-subject export silently omits everything a visitor submitted through a form, and an
erasure request leaves those submissions untouched. This plugin closes that gap.

## What it does

- **Export** — a WordPress *Export Personal Data* request for an email address includes every matching Fluent Forms submission: each submitted field and sub-field, the entry details Fluent Forms records (date, page, user, IP address, browser, device, city, country, status, payment status, method and total), and the consent record.
- **Erase** — a WordPress *Erase Personal Data* request deletes those submissions through Fluent Forms' own `SubmissionService::deleteEntries()`, so the entry, its meta, its detail rows and its entry logs are removed together (the same path the Fluent Forms admin uses). Uploaded files are removed too when Fluent Forms Pro is active. A paid entry is anonymized instead (see **Payment entries**), and the request reports it as retained.
- **Consent proof** — on every submission, in the visitor's own request, snapshots the wording of each consent field (`gdpr_agreement`/`terms_and_condition`) as it read at submission time, **whether that field was accepted** (read from the submitted value), plus the submission language, onto the entry's submission-meta. An entry that admin approval or double opt-in holds gets its snapshot when it is submitted, not when it is approved. A stored boolean proves nothing once a policy is reworded; the wording — with the recorded acceptance — does.
- **Retention** — deletes entries past a configurable window, through the same Fluent Forms cascade, and anonymizes the paid ones. **Off by default**; once a window is set, a daily WP-Cron event enforces it automatically. The window applies to every form.
- **Data minimization** — for the forms a site selects, stores no IP address, country, browser or device with the entry, and saves uploaded JPEG and PNG photos again without their metadata (GPS position, camera). **Off by default.**

Matching (export/erase) is by the email address stored in any submission field or sub-field,
case-insensitively, across every form. Entries that a WordPress user with that address submitted
match too. Entries that admin approval or double opt-in still hold are included.

## Requirements

- WordPress 6.7+
- PHP 8.2+
- **Fluent Forms** (free)
- **Fluent Forms Pro** for two parts only: the photo metadata removal (Pro has the file upload field) and the removal of uploaded files on erasure.

## Installation

### Manual Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/pixelalbatross/fluentform-privacy/releases/latest).
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin area.
3. Upload the ZIP file and click Install Now.
4. Activate the plugin.

### Install with Composer

To include this plugin as a dependency in your Composer-managed WordPress project:

1. Add the plugin to your project using the following command:

```bash
composer require outstand/fluentform-privacy
```

1. Run `composer install`.
2. Activate the plugin from your WordPress admin area or using WP-CLI.

## Retention

Retention is disabled until a site sets a positive window:

```php
add_filter( 'outstand_fluentform_privacy_retention_months', fn() => 12 );
```

Once a window is set, a daily WP-Cron event (`outstand_fluentform_privacy_prune`) prunes
automatically. For on-demand runs — or to drive
the prune from the host's cron, which is more reliable than WP-Cron on low-traffic sites — use the
CLI:

```
wp fluentform-privacy prune            # uses the configured window
wp fluentform-privacy prune --months=12 --dry-run
```

`--months` takes a whole number of 1 or more.

### Payment entries

An entry whose payment status is `paid`, `processing`, `refunded` or `partially-refunded` is not
deleted, by retention or by an erasure request. It is anonymized, as WooCommerce does with
completed orders:

- Removed: the submitted fields and sub-fields, uploaded files (with Fluent Forms Pro), the source page, user, IP address, browser, device, city and country, the detail rows, the entry logs and the consent record.
- Removed from each transaction: payer name and email, billing and shipping address, user, last four card digits and the payment note. The gateway response and note of a subscription go too.
- Kept: the entry with its form, dates, status and payment columns, and the order items, transactions and subscriptions with their amounts, status, method and gateway ids.

An anonymized entry is marked with the submission meta `_os_ff_privacy_anonymized` and is not
processed again. Entries with any other payment status (`pending`, `failed`, `cancelled`) are
deleted. Keep the fiscal documents in the invoicing system: the anonymized entry is not one.

## Data minimization

Data minimization is off until a site turns it on for a form:

```php
add_filter(
	'outstand_fluentform_privacy_minimal_data',
	fn( bool $minimal, int $form_id ) => $minimal || 5 === $form_id,
	10,
	2
);
```

For that form, Fluent Forms stores no IP address, country, browser or device with the entry. An
uploaded JPEG or PNG photo is rotated upright and saved again with the GD image editor, which writes
no metadata. The saved photo keeps its file type, also when a plugin maps JPEG to WebP or AVIF.
Other files (GIF, video, PDF) keep their metadata. When GD is not available, the photo is kept as
it was uploaded.

Fluent Forms limits submissions per visitor by the stored IP address, so that limit does not apply
to these forms. Use a captcha on them.

Two Fluent Forms features still record the IP address on these forms:

- Fluent Forms analytics, when it is on, stores the IP address and browser of each form view, for every form. Keep it off.
- Fluent Forms Pro double opt-in writes the IP address into the entry log when the visitor confirms. Do not turn on double opt-in for these forms.

## For Developers

### Hooks & Filters

- `outstand_fluentform_privacy_group_label` — the export/erasure group label (default: *Fluent Forms*).
- `outstand_fluentform_privacy_retention_months` — retention window in months (default `0` = off).
- `outstand_fluentform_privacy_minimal_data` — whether a form keeps only the data the visitor enters (default `false`). Receives the form ID as its second argument.
- `outstand_fluentform_privacy_kept_payment_statuses` — the payment statuses of the entries that are anonymized and kept (default `paid`, `processing`, `refunded`, `partially-refunded`).
- `outstand_fluentform_privacy_entries_anonymized` — action that fires after entries of one form are anonymized. Receives the submission IDs and the form ID.

### Building from Source

```
composer install
npm install
npm run test:setup   # starts wp-env (bundles Fluent Forms for integration tests)
npm run test:unit
composer lint
```

## Changelog

All notable changes to this project are documented in [CHANGELOG.md](https://github.com/pixelalbatross/fluentform-privacy/blob/main/CHANGELOG.md).

## License

This project is licensed under the [GPL-3.0-or-later](https://spdx.org/licenses/GPL-3.0-or-later.html).
