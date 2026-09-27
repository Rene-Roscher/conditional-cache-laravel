# Changelog

All notable changes to this package are documented here.

## Unreleased

### Added

- `retryAfter` option for all macros: after a rejected value, the callback isn't run again until
  the window has passed. `flexibleWhen` pauses background refreshes instead.
- Validators can be the class name of an invokable class, resolved from the container.
- Invalid arguments (TTL, `[$fresh, $stale]` pair, lock options, key, validator) throw an
  `InvalidArgumentException` with a clear message.
- Laravel Pint and PHPStan (level max), with a CI job and `composer check`.

### Changed

- The macros are now thin wrappers around `ConditionalCache`, which holds the logic.

### Fixed

- CI: the Laravel 11 jobs failed to install because Composer 2.10+ blocks packages with security
  advisories. Advisory blocking is now disabled for those jobs only.

## Initial version

- `Cache::rememberWhen()`, `Cache::rememberForeverWhen()` and `Cache::flexibleWhen()`.
- `onInvalid` callback with fallback return values.
- `CacheValueRejected` event.
