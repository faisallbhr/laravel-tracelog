# Changelog

All notable changes to Laravel TraceLog are documented in this file.

## [Unreleased]

### Added

- Add scoped trace IDs and additive trace tags to model event logs.
- Make daily JSONL output the default, with timestamps and configurable retention.
- Partition JSONL output by configurable maximum size.
- Add an internal streaming trace reader with safe file discovery and pagination.
- Coordinate size rotation between concurrent writers and read traces across parts of the same day.

## [1.0.0] - 2026-11-10

### Fixed

- Keep record-specific watches isolated between database connections.
- Preserve attributes from repeated `sensitive()` calls.
- Keep log entries valid when model attributes contain invalid UTF-8.
- Reset facade state with each Laravel application scope.
- Limit update output to attributes that actually changed.

### Changed

- Test the lowest and highest supported dependencies for Laravel 11, 12, and 13.
- Define the stable public API and move implementation details to an internal namespace.

### Documentation

- Document companion events emitted by restore and force-delete operations.
- Add package support links and a CI status badge.

## [0.5.0] - 2026-11-10

- Added Laravel 11 and 12 support while retaining Laravel 13 support.
- Added version-specific CI coverage and Laravel 11 change tracking compatibility.

## [0.4.0] - 2026-11-10

- Added attribute and event filtering.
- Added default and custom sensitive-attribute redaction.
- Added global, model-class, and record-specific filter precedence.

## [0.3.1] - 2026-11-10

- Preserved the native `deleted` event emitted during force-delete operations.

## [0.3.0] - 2026-11-10

- Improved model change accuracy and lifecycle event output.

## [0.2.0] - 2026-11-10

- Added installation and usage documentation.

## [0.1.0] - 2026-11-10

- Added the initial TraceLog implementation and MIT license.

[Unreleased]: https://github.com/faisallbhr/laravel-tracelog/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/faisallbhr/laravel-tracelog/compare/v0.5.0...v1.0.0
[0.5.0]: https://github.com/faisallbhr/laravel-tracelog/releases/tag/v0.5.0
[0.4.0]: https://github.com/faisallbhr/laravel-tracelog/releases/tag/v0.4.0
[0.3.1]: https://github.com/faisallbhr/laravel-tracelog/releases/tag/v0.3.1
[0.3.0]: https://github.com/faisallbhr/laravel-tracelog/releases/tag/v0.3.0
[0.2.0]: https://github.com/faisallbhr/laravel-tracelog/releases/tag/v0.2.0
[0.1.0]: https://github.com/faisallbhr/laravel-tracelog/releases/tag/v0.1.0
