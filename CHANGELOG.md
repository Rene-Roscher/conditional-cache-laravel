# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/). While the version is `0.x`, minor versions may
contain breaking changes.

## Unreleased

## 0.1.0 - 2026-09-27

Initial release.

### Added

- `Cache::rememberWhen()`: like `remember()`, but the value is only cached when it passes a
  validator (a callable, or the class name of an invokable class resolved from the container).
  Without a validator, only `filled()` values are cached.
- `Cache::rememberForeverWhen()`: the same, stored with `forever()`.
- `Cache::flexibleWhen()`: like `flexible()` (stale-while-revalidate), but a background refresh
  only replaces the cached value when the new one is valid.
- `default`: a value (or lazy closure) returned instead of a rejected value. Never cached.
- `onInvalid`: a callback for side effects (logging, reporting, throwing) when a value is rejected.
- `retryAfter`: after a rejection, the callback isn't run again until the window has passed; the
  default is returned in the meantime. `Cache::forget($key)` also clears it.
- `lock`: only one process computes a missing value, the others wait for it.
- `CacheValueRejected` event for global logging and metrics.
- Works with every cache store, `Cache::store(...)`, tagged caches and enum keys.
- Supports PHP 8.2+ and Laravel 11.24+, 12 and 13. Tested against the array, Redis, database and
  file stores.
