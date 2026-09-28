# Conditional Cache for Laravel

![Before: Cache::remember() always caches. After: Cache::rememberWhen() only caches valid responses.](https://raw.githubusercontent.com/Rene-Roscher/conditional-cache-laravel/main/art/banner.webp)

Only cache a value when it is actually worth caching.

`Cache::remember()` stores whatever the callback returns: an empty array, a `['success' => false]`
error payload, a half-broken API response. This package adds `Cache::rememberWhen()` and friends:
the value is only written to the cache when it passes your validator. If it doesn't, the value is
not cached: you get it back (or a `default` of your choice), and an optional `onInvalid` callback
runs.

The idea was proposed for the framework in
[laravel/framework#55951](https://github.com/laravel/framework/pull/55951), which was closed with the
suggestion to ship it as a package. This is that package.

## Installation

```bash
composer require rene-roscher/conditional-cache-laravel
```

The service provider is auto-discovered. It registers the macros on `Illuminate\Cache\Repository`,
so they work on the `Cache` facade, on `Cache::store('redis')`, on `Cache::tags([...])` and on any
injected `Illuminate\Contracts\Cache\Repository`.

Requires PHP 8.2+ and Laravel 11.24+, 12 or 13.

| Macro | Like | Stores valid values |
| --- | --- | --- |
| `rememberWhen` | `remember` | with a TTL |
| `rememberForeverWhen` | `rememberForever` | forever |
| `flexibleWhen` | `flexible` | stale-while-revalidate |

## Usage

### `rememberWhen`

```php
Cache::rememberWhen(
    string|UnitEnum $key,
    Closure|DateTimeInterface|DateInterval|int|null $ttl,
    Closure $callback,
    callable|string|null $validator = null,   // fn (mixed $value): bool, or an invokable class name
    ?callable $onInvalid = null,              // fn (mixed $value, string $key): void
    mixed $default = null,                    // a value, or fn (mixed $value, string $key): mixed
    DateTimeInterface|DateInterval|int|null $retryAfter = null,   // requires a default
    ?int $lock = null,                        // seconds
): mixed
```

1. If the key is in the cache, the cached value is returned. The callback and validator don't run.
2. Otherwise the callback runs and its result goes to the validator.
3. If the validator returns `true`, the value is cached (like `remember()`) and returned.
4. If it returns `false`, nothing is cached, a `CacheValueRejected` event is dispatched,
   `onInvalid` runs, and the `default` is returned (or the rejected value, if there is no
   default). The next call runs the callback again.

The later parameters are easiest to use as named arguments, so you only pass what you need:

```php
$data = Cache::rememberWhen('api-data', 3600, fn () => $api->fetch(),
    validator: fn ($v) => ($v['success'] ?? false) === true,
    default: ['success' => false, 'data' => []],
);
```

#### A default instead of the invalid value

Pass `default` to get a clean value back instead of the broken one. It is never cached.

```php
// A plain value
$rates = Cache::rememberWhen('rates', 600, fn () => $api->rates(), filled(...), default: []);

// A closure: only called when the validation fails, gets the rejected value and the key
$rates = Cache::rememberWhen('rates', 600, fn () => $api->rates(), filled(...),
    default: fn ($value, $key) => config('rates.defaults'),
);
```

- A closure `default` is lazy, so an expensive fallback (a DB query, a config lookup) only runs
  when it's needed.
- Because `null` means "no default", use `default: fn () => null` if you really want `null` back.
- Without a `default`, you get the rejected value back, like `remember()` would return it.

#### Reacting to invalid values: `onInvalid`

`onInvalid` is for side effects (logging, reporting, alerting). Its return value is ignored. It
runs before the `default` is resolved.

```php
$data = Cache::rememberWhen('api-data', 3600,
    fn () => Http::get('api.example.com/data')->json(),
    fn ($value) => ($value['success'] ?? false) === true,
    onInvalid: fn ($value, string $key) => Log::warning("Not caching [{$key}]", ['payload' => $value]),
    default: [],
);

// Or fail loudly instead of returning anything
Cache::rememberWhen('rates', 600, fn () => $api->rates(), filled(...), onInvalid: fn () => throw new RatesUnavailable);
```

#### Reusable validators

Instead of a closure you can pass the class name of an invokable class. It's resolved from the
container, so it can use dependency injection:

```php
class SuccessfulApiResponse
{
    public function __invoke(mixed $value): bool
    {
        return is_array($value) && ($value['success'] ?? false) === true;
    }
}

Cache::rememberWhen('api-data', 3600, fn () => Http::get('...')->json(), SuccessfulApiResponse::class);
```

#### Default validator

Without a validator, only [`filled()`](https://laravel.com/docs/helpers#method-filled) values are
cached. `null`, `''`, whitespace-only strings and empty arrays/collections are not cached.
`0` and `false` are cached.

```php
$user = Cache::rememberWhen("github-user:{$name}", 3600, fn () => $github->user($name));
```

#### Named arguments

Named arguments work through the facade:

```php
Cache::rememberWhen(
    key: 'api-data',
    ttl: now()->addHour(),
    callback: fn () => Http::get('...')->json(),
    validator: fn ($value) => $value['success'] ?? false,
    onInvalid: fn ($value) => report(new InvalidApiResponse($value)),
    default: [],
);

// Default validator, only a default value
Cache::rememberWhen('api-data', 3600, fn () => $api->fetch(), default: []);
```

#### TTL based on the value

As with `remember()`, the TTL can be a closure that receives the (validated) value:

```php
Cache::rememberWhen(
    'token',
    fn (array $token) => $token['expires_in'] - 60,
    fn () => $oauth->fetchToken(),
    fn ($token) => isset($token['access_token']),
);
```

#### Don't hammer a broken upstream: `retryAfter`

By default nothing is cached when the value is rejected, so every request runs the callback again.
If the API is down, that means every request hits it. With `retryAfter` the resolved `default` is
kept for that long and returned without running the callback, `onInvalid` or a closure `default`
again:

```php
$data = Cache::rememberWhen(
    'api-data',
    3600,
    fn () => Http::get('api.example.com/data')->json(),
    fn ($value) => ($value['success'] ?? false) === true,
    default: ['success' => false, 'data' => []],
    retryAfter: 30,   // retry the API at most every 30 seconds while it's failing
);
```

- `retryAfter` **requires a `default`**, because that's what's returned while waiting. This way a
  rejected value is never stored by accident. If you really want the rejected value back, say so:
  `default: fn ($value) => $value`.
- The default is stored under a separate key (`conditional-cache:retry:{key}`). It never counts as
  a cache hit for the valid entry, and the first valid value is cached normally.
- `Cache::forget('api-data')` also forgets that marker, so you can always force a retry. This works
  on every store and on tagged caches. (It's done by a listener on Laravel's `ForgettingKey` event,
  so each `forget()` in your app sends one extra, cheap delete for the marker key.)

#### Only compute once under load: `lock`

Like `remember()`, many requests that miss at the same time all run the callback in parallel. For
an expensive or rate-limited upstream, pass `lock` (in seconds): only one process computes the value,
the others wait for it and then read it from the cache.

```php
$data = Cache::rememberWhen('api-data', 3600, fn () => $api->fetch(), $validator,
    default: [],
    lock: 10,   // one fetch at a time, others wait up to 10 s
);
```

If the lock can't be acquired within that time, the waiting process computes the value itself, so a
stuck lock never breaks a request. It needs a store with lock support (Redis, database, file,
array, ...).

### `rememberForeverWhen`

The same as `rememberWhen`, but valid values are stored with `forever()`:

```php
Cache::rememberForeverWhen('settings', fn () => Setting::all()->pluck('value', 'key'), fn ($s) => $s->isNotEmpty());
```

### `flexibleWhen` (stale-while-revalidate)

`flexibleWhen` is the validated version of Laravel's
[`Cache::flexible()`](https://laravel.com/docs/cache#swr), the "stale-while-revalidate" pattern.
It takes two TTLs, `[$fresh, $stale]`:

| Age of the cached value | `Cache::flexible()` | `Cache::flexibleWhen()` |
| --- | --- | --- |
| No value yet | Runs the callback, caches the result | Runs the callback, caches it **only if valid** |
| Younger than `$fresh` | Returns it | Returns it |
| Between `$fresh` and `$stale` | Returns it **immediately** and refreshes it **after the response** has been sent | The same, but the refresh only overwrites the value **if the new one is valid** |
| Older than `$stale` | Expired, like a miss | Expired, like a miss |

So users never wait for a slow API once the value is cached: they get the slightly stale value
instantly and the refresh happens in the background (via Laravel's `defer()`).

The problem with plain `flexible()`: if the API is broken during a background refresh, the broken
response replaces your good value. `flexibleWhen` keeps the last valid value instead:

```php
$stats = Cache::flexibleWhen(
    'dashboard-stats',
    [300, 3600],                                   // fresh for 5 min, stale for up to 1 h
    fn () => Http::get('stats.example.com')->json(),
    fn ($value) => ($value['success'] ?? false) === true,
    onInvalid: fn ($value, $key) => Log::warning("Refresh for [{$key}] rejected"),
    default: ['success' => false, 'data' => []],
);
```

- **No value yet, invalid response**: nothing is stored, the `default` (or the rejected value) is
  returned.
- **Stale value, invalid refresh**: the last valid value stays in the cache and keeps being served.
  The refresh runs again on the next stale hit, until a valid value comes back or `$stale` runs out.
- **`retryAfter`**: after a rejected refresh, no new refresh is started until the window has
  passed.
- It uses the same keys and lock as `Cache::flexible()`, so the two can share an entry.

Signature: `flexibleWhen($key, array $ttl, callable $callback, $validator = null, ?callable $onInvalid = null, $default = null, ?array $lock = null, bool $alwaysDefer = false, $retryAfter = null)`.
Here `lock` is the same option as in `Cache::flexible()` (`['seconds' => 10, 'owner' => ...]`) and
guards the background refresh.

> A background refresh doesn't use `default`: the request was already answered with the stale
> value, and a rejected refresh never writes anything.

## Listening for rejected values globally

Every rejection dispatches `ReneRoscher\ConditionalCache\Events\CacheValueRejected`. You can use it
for logging or metrics without adding an `onInvalid` to every call:

```php
use ReneRoscher\ConditionalCache\Events\CacheValueRejected;

Event::listen(function (CacheValueRejected $event) {
    Log::debug('Cache value rejected', [
        'store' => $event->storeName,
        'key' => $event->key,
        'method' => $event->method, // rememberWhen | rememberForeverWhen | flexibleWhen
    ]);
});
```

## Notes

- `null` can never be cached, in `remember()` or here, because `null` means "not in the cache".
- Overhead on a cache hit is about 1 µs compared to `remember()` (measured on the array store and on
  Redis). `retryAfter` reads the marker in the same round trip (`many()`), and `lock` is only
  taken on a miss.
- If you queue a listener for `CacheValueRejected`, the rejected value is serialized with the event.
  Keep that in mind for large or non-serializable values.
- If the callback throws, nothing is cached and the exception propagates, the same as `remember()`.
- The validator gets only the value, so you can pass callables like `is_array(...)`.
  `onInvalid` gets `($value, $key)`.
- Invalid arguments (a TTL closure that returns a string, a malformed `[$fresh, $stale]` pair, an
  unknown validator class) throw an `InvalidArgumentException` right away.
- IDE autocompletion: [barryvdh/laravel-ide-helper](https://github.com/barryvdh/laravel-ide-helper)
  picks up the macros when it generates `_ide_helper.php`. [Larastan](https://github.com/larastan/larastan)
  reads the macros of the `Cache` facade on its own and takes the parameter types from them.
- The macros are registered on `Illuminate\Cache\Repository`. Should Laravel ever add a native
  method with the same name, the native method wins. The test suite checks for that and runs weekly
  against the latest Laravel releases.
- `flexibleWhen` mirrors the bookkeeping of `Cache::flexible()` (keys, lock and `defer()`), which is
  why Laravel 11.24 is the minimum. The weekly CI run also catches changes there.

## Development

```bash
composer test      # PHPUnit
composer analyse   # PHPStan (level max)
composer format    # Laravel Pint
composer check     # all of the above, style in check mode
```

`StoreIntegrationTest` also runs everything against real Redis, database (SQLite) and file stores.
Without a local Redis on `127.0.0.1:6379` its Redis cases are skipped; CI runs them with a Redis
service and fails if it isn't reachable.

## License

MIT. See [LICENSE.md](LICENSE.md).
