# Changelog

All notable changes to this package are documented here.

## Unreleased

### Added

- `lock` option for `rememberWhen` and `rememberForeverWhen`: only one process computes a missing
  value, the others wait and read it from the cache (and compute it themselves on timeout).
- `Cache::forget($key)` also forgets the `retryAfter` marker of that key (including tagged caches).
- Weekly CI run against the latest Laravel releases, and a test that fails if Laravel adds a
  native method with the same name as one of the macros.
- `default` parameter: returned instead of the rejected value, never cached. A closure default is
  only called when the validation fails and receives `($value, $key)`.
- `retryAfter` option for all macros: after a rejected value, the callback isn't run again until
  the window has passed. `flexibleWhen` pauses background refreshes instead.
- Validators can be the class name of an invokable class, resolved from the container.
- Invalid arguments (TTL, `[$fresh, $stale]` pair, lock options, key, validator) throw an
  `InvalidArgumentException` with a clear message.
- Laravel Pint and PHPStan (level max), with a CI job and `composer check`.

### Changed

- **Breaking:** `retryAfter` requires a `default`, so a rejected value is never stored implicitly.
  Use `default: fn ($value) => $value` to get the rejected value back.
- **Breaking:** the return value of `onInvalid` is now ignored. Use `default` for fallback values.
- The macros are now thin wrappers around `ConditionalCache`, which holds the logic.

### Fixed

- Laravel 11.24 is now the minimum version (was 11.23). 11.23 has no `Illuminate\Support\defer()`,
  uses different bookkeeping keys for `Cache::flexible()` and has a protected `Repository::getName()`,
  so `flexibleWhen` and the rejected event didn't work there. Found by testing against the lowest supported versions.
- Enum keys no longer depend on Laravel's `enum_value()` helper.
- CI: the Laravel 11 jobs failed to install because Composer 2.10+ blocks packages with security
  advisories. Advisory blocking is now disabled for those jobs only.
- CI: with `--prefer-lowest`, Composer paired the oldest Laravel 11 release with Testbench 9.0.x, whose `setUp()`
  crashes on that Laravel version. The minimum Testbench version is now 9.2.

## Initial version

- `Cache::rememberWhen()`, `Cache::rememberForeverWhen()` and `Cache::flexibleWhen()`.
- `onInvalid` callback with fallback return values.
- `CacheValueRejected` event.
