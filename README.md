# Conditional Cache for Laravel

Only cache a value when it is actually worth caching.

`Cache::remember()` stores whatever the callback returns: an empty array, a `['success' => false]`
error payload, a half-broken API response. This package adds `Cache::rememberWhen()` and friends:
the value is only written to the cache when it passes your validator. If it doesn't, the value is
returned but not cached, and an optional `onInvalid` callback runs.

The idea was proposed for the framework in
[laravel/framework#55951](https://github.com/laravel/framework/pull/55951), which was closed with the
suggestion to ship it as a package. This is that package.

```php
// Before: always cached, even if empty or an error response
$data = Cache::remember('api-data', 3600, function () {
    return Http::get('api.example.com/data')->json();
});

// After: only cached when the response is valid
$data = Cache::rememberWhen('api-data', 3600, function () {
    return Http::get('api.example.com/data')->json();
}, function ($value) {
    return ! empty($value) && isset($value['success']) && $value['success'] === true;
});
```

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
    ?callable $onInvalid = null,              // fn (mixed $value, string $key): mixed
    DateTimeInterface|DateInterval|int|null $retryAfter = null,
): mixed
```

1. If the key is in the cache, the cached value is returned. The callback and validator don't run.
2. Otherwise the callback runs and its result goes to the validator.
3. If the validator returns `true`, the value is cached (like `remember()`) and returned.
4. If it returns `false`, nothing is cached, a `CacheValueRejected` event is dispatched,
   `onInvalid` runs, and the value is returned. The next call runs the callback again.

#### Reacting to invalid values

```php
$data = Cache::rememberWhen(
    'api-data',
    3600,
    fn () => Http::get('api.example.com/data')->json(),
    fn ($value) => ($value['success'] ?? false) === true,
    function ($value, string $key) {
        Log::warning("Not caching [{$key}], API returned an invalid payload.", ['payload' => $value]);
    },
);
```

If `onInvalid` returns something other than `null`, that value is returned instead of the rejected
one. It is **not** cached either. You can also throw from `onInvalid`:

```php
// Fallback value
$rates = Cache::rememberWhen('rates', 600, fn () => $api->rates(), fn ($r) => filled($r), fn () => config('rates.defaults'));

// Fail loudly
$rates = Cache::rememberWhen('rates', 600, fn () => $api->rates(), fn ($r) => filled($r), fn () => throw new RatesUnavailable);
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
);

// Default validator, only an onInvalid hook
Cache::rememberWhen('api-data', 3600, fn () => $api->fetch(), onInvalid: fn () => Log::info('empty'));
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
If the API is down, that means every request hits it. With `retryAfter` the rejected result (the
value, or your `onInvalid` fallback) is kept for that long and returned without running the
callback or `onInvalid` again:

```php
$data = Cache::rememberWhen(
    'api-data',
    3600,
    fn () => Http::get('api.example.com/data')->json(),
    fn ($value) => ($value['success'] ?? false) === true,
    fn () => ['success' => false, 'data' => []],
    retryAfter: 30,   // retry the API at most every 30 seconds while it's failing
);
```

The rejected result is stored under a separate key (`conditional-cache:retry:{key}`). It never
counts as a cache hit for the valid entry, and the first valid value is cached normally. It has to
be serializable, like any cached value.

### `rememberForeverWhen`

The same as `rememberWhen`, but valid values are stored with `forever()`:

```php
Cache::rememberForeverWhen('settings', fn () => Setting::all()->pluck('value', 'key'), fn ($s) => $s->isNotEmpty());
```

### `flexibleWhen` (stale-while-revalidate)

`Cache::flexible()` serves stale data while refreshing it in the background. If the background
refresh returns garbage (because the API is down, for example), the good value gets overwritten.
`flexibleWhen` only writes values that pass the validator:

```php
$stats = Cache::flexibleWhen(
    'dashboard-stats',
    [300, 3600],                                   // fresh for 5 min, stale for up to 1 h
    fn () => Http::get('stats.example.com')->json(),
    fn ($value) => ($value['success'] ?? false) === true,
    fn ($value, $key) => Log::warning("Refresh for [{$key}] rejected"),
);
```

- **Cache miss, invalid value**: nothing is stored, the value (or the `onInvalid` fallback) is
  returned.
- **Stale hit, invalid refresh**: the last valid value stays in the cache. The refresh runs again on
  the next stale hit, until a valid value comes back or the stale TTL runs out.
- **`retryAfter`**: after a rejected refresh, no new refresh is started until the window has
  passed. The stale value keeps being served in the meantime.
- It uses the same keys and lock as `Cache::flexible()`, so the two can share an entry.

Signature: `flexibleWhen($key, array $ttl, callable $callback, $validator = null, ?callable $onInvalid = null, ?array $lock = null, bool $alwaysDefer = false, $retryAfter = null)`.

> During a deferred refresh the return value of `onInvalid` is ignored, because the request has
> already been answered with the stale value.

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
- If the callback throws, nothing is cached and the exception propagates, the same as `remember()`.
- The validator gets only the value, so you can pass callables like `is_array(...)`.
  `onInvalid` gets `($value, $key)`.
- Invalid arguments (a TTL closure that returns a string, a malformed `[$fresh, $stale]` pair, an
  unknown validator class) throw an `InvalidArgumentException` right away.
- IDE autocompletion: [barryvdh/laravel-ide-helper](https://github.com/barryvdh/laravel-ide-helper)
  picks up the macros when it generates `_ide_helper.php`.

## Development

```bash
composer test      # PHPUnit
composer analyse   # PHPStan (level max)
composer format    # Laravel Pint
composer check     # all of the above, style in check mode
```

## License

MIT. See [LICENSE.md](LICENSE.md).
