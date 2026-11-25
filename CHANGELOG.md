# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `wp fluentform-privacy prune` prints "1 entry" and "3 entries" in its messages. Before, it printed the text `entr(y|ies)`.

## [1.1.0] - 2026-11-25

### Added

- The export includes each sub-field, the entry details Fluent Forms records (such as the IP address, browser and payment total) and the consent record.
- Export and erase also match the entries that a WordPress user with the requested address submitted.
- Retention and erasure anonymize paid entries and keep their payment records, as WooCommerce does with completed orders. The `outstand_fluentform_privacy_kept_payment_statuses` filter sets which payment statuses are kept.
- The `outstand_fluentform_privacy_entries_anonymized` action fires after entries are anonymized.

### Changed

- `wp fluentform-privacy prune` reports the deleted and the anonymized entries per form.

### Fixed

- Export and erase find entries that admin approval or double opt-in still hold.
- The consent snapshot is taken when the visitor submits, so held entries get one with the wording and language of that moment.
- Export pages come in a fixed order, so a large export does not skip or repeat entries.
- `wp fluentform-privacy prune` refuses a `--months` value below 1 or that is not a whole number. Before, `--months=0` pruned with a 1-month window.

## [1.0.0] - 2026-11-24

### Added

- A personal-data exporter registers Fluent Forms submissions with WordPress core. It matches the email in any field (case-insensitive) and pages through large result sets.
- A personal-data eraser deletes the matching submissions through Fluent Forms' `SubmissionService::deleteEntries()` cascade and reports the count actually removed.
- Consent capture stores, on each submission, the wording of each consent field, whether it was accepted, and the submission language.
- Retention deletes entries past a configurable window. It is off by default; a site turns it on with `outstand_fluentform_privacy_retention_months`. A daily WP-Cron event enforces it, and `wp fluentform-privacy prune` runs it on demand.
- Data minimization applies to the forms a site selects with `outstand_fluentform_privacy_minimal_data`. These forms store no IP address, country, browser or device with the entry, and uploaded JPEG and PNG photos are saved again without their metadata.
- The filters `outstand_fluentform_privacy_group_label`, `outstand_fluentform_privacy_retention_months` and `outstand_fluentform_privacy_minimal_data` let a site change the export group label, the retention window and the minimal-data forms.

[Unreleased]: https://github.com/pixelalbatross/fluentform-privacy/compare/1.1.0...HEAD
[1.1.0]: https://github.com/pixelalbatross/fluentform-privacy/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/pixelalbatross/fluentform-privacy/releases/tag/1.0.0
