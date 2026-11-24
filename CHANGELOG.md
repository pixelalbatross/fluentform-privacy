# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-24

### Added

- A personal-data exporter registers Fluent Forms submissions with WordPress core. It matches the email in any field (case-insensitive) and pages through large result sets.
- A personal-data eraser deletes the matching submissions through Fluent Forms' `SubmissionService::deleteEntries()` cascade and reports the count actually removed.
- Consent capture stores, on each submission, the wording of each consent field, whether it was accepted, and the submission language.
- Retention deletes entries past a configurable window. It is off by default; a site turns it on with `outstand_fluentform_privacy_retention_months`. A daily WP-Cron event enforces it, and `wp fluentform-privacy prune` runs it on demand.
- Data minimization applies to the forms a site selects with `outstand_fluentform_privacy_minimal_data`. These forms store no IP address, country, browser or device with the entry, and uploaded JPEG and PNG photos are saved again without their metadata.
- The filters `outstand_fluentform_privacy_group_label`, `outstand_fluentform_privacy_retention_months` and `outstand_fluentform_privacy_minimal_data` let a site change the export group label, the retention window and the minimal-data forms.

[Unreleased]: https://github.com/pixelalbatross/fluentform-privacy/compare/1.0.0...HEAD
[1.0.0]: https://github.com/pixelalbatross/fluentform-privacy/releases/tag/1.0.0
