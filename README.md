# KeyValue Store — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)
[![phpredis 6.3+](https://img.shields.io/badge/phpredis-6.3%2B-red.svg)](https://github.com/phpredis/phpredis)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Redis/Valkey integration for TYPO3 14: cache backend, session backend,
distributed locking. Production-ready Sentinel discovery, TLS, and mTLS.
Built-in override pack against TYPO3 Core's legacy Redis anti-patterns
(`KEYS`, blocking `DEL`).

## Components

| Component | Class | Drop-in for |
|---|---|---|
| Cache backend | `KeyValueBackend` | `TYPO3\CMS\Core\Cache\Backend\RedisBackend` |
| Session backend | `KeyValueSessionBackend` | `TYPO3\CMS\Core\Session\Backend\RedisSessionBackend` |
| Locking strategy | `KeyValueLockingStrategy` | `LockingStrategyInterface` (registered automatically) |

## Installation

```bash
composer require moselwal/keyvalue-store
```text

### Requirements

- PHP 8.3+
- TYPO3 14.x
- `ext-redis` >= 6.3 (phpredis with the v6 constructor-config API,
  Sentinel resolver, TLS context, and decorrelated-jitter backoff)
- Redis 4.0+ or Valkey (UNLINK is required for the v4.2.0 override pack)

## Configuration

The cleanest path is via [`moselwal/typo3-config`](../typo3-config) —
its `autoconfigureCaching()`, session-binding, and locking helpers wire
all three components consistently. Manual configuration is supported
and documented below.

### Cache backend

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['pages'] = [
    'backend' => \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class,
    'options' => [
        'hostname'             => 'valkey.internal',
        'port'                 => 6379,
        'database'             => 3,
        'password'             => 'secret',
        'persistentConnection' => true,
        'defaultLifetime'      => 2592000,
        'compression'          => true,

        // TLS / mTLS (optional)
        'tls'                  => true,
        'ca_file'              => '/run/tls/ca.crt',
        'cert_file'            => '/run/tls/httpd.crt',
        'key_file'             => '/run/tls/httpd.key',
        'peer_name'            => 'valkey.internal',
        'verify_peer'          => true,
        'verify_peer_name'     => true,

        // Sentinel (optional)
        'sentinel'             => true,
        'sentinel_host'        => 'sentinel.internal',
        'sentinel_port'        => 26379,
        'sentinel_service'     => 'mymaster',

        // phpredis 6.x backoff (optional)
        'backoff' => [
            'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
            'base'      => 500,
            'cap'       => 750,
        ],
    ],
];
```

### Session backend

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['BE'] = [
    'backend' => \Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend::class,
    'options' => [
        'hostname'             => 'valkey.internal',
        'database'             => 1,
        'password'             => 'secret',
        'persistentConnection' => true,
        'persistentId'         => 'typo3-session-be',
        'prefix'               => 'typo3:sess:be:',
        'sessionLifetime'      => 3600,
        // TLS / Sentinel options as above
    ],
];
```text

### Locking strategy

The locking strategy registers via TYPO3's `LockFactory` configuration:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][
    \Moselwal\KeyValueStore\Locking\KeyValueLockingStrategy::class
] = [
    'hostname'             => 'valkey.internal',
    'database'             => 0,
    'password'             => 'secret',
    'persistentConnection' => true,
    'ttl'                  => 10,
    // TLS / Sentinel options as above
];
```

## What v4.x does differently from TYPO3 Core's `RedisBackend`

The `KeyValueBackend` extends `RedisBackend` and overrides four
operations to drop server-side anti-patterns. The other operations
(`get`, `set`, `has`, `remove`, `findIdentifiersByTag`) are inherited
verbatim — Core already pipelines tag tracking there, so there is
nothing to add.

| Operation | TYPO3 Core | `KeyValueBackend` (v4.3.0) |
|---|---|---|
| `set()` | SETEX + SMEMBERS + (optional MULTI/PIPELINE) tag diff | Single Lua `EVAL` (atomic, 1 roundtrip) |
| `flush()` | `KEYS prefix*` + `DEL` (event-loop block for all clients) | `SCAN` + `UNLINK` batches (server stays responsive) |
| `flushByTag()` / `flushByTags()` | N× sequential `flushByTag()` fan-out | One `sUnion` + one pipelined `UNLINK` |
| `collectGarbage()` | `KEYS identTags:*` | `SCAN`-loop |
| Connection | `pconnect()` only, no Sentinel/TLS | `KeyValueConnectionFactory` (Sentinel resolver, mTLS, backoff) |
| Serializer | hard-coded PHP-native | configurable: `php` / `igbinary` / `none` / `auto` |

Additionally:

- `lazy=true` is the default — the TCP/TLS handshake is deferred to
  the first command. Bootstrap of 11 cache backends drops from ~25 ms
  to ~0.07 ms on a real mTLS Valkey setup.
- `OPT_SCAN = SCAN_RETRY` is set on every connection so phpredis
  retries empty SCAN pages internally instead of returning `false`
  to the caller (a well-known phpredis 6.x footgun).

### Bench (real, container-side against Valkey/mTLS, phpredis 6.3.0)

| Operation | Core / v4.0.x | v4.3.0 | Δ |
|---|---:|---:|---|
| Bootstrap 11 caches | 25.1 ms | 0.07 ms | **381×** |
| `getAll()` 500 sessions | 37.2 ms | 1.5 ms | **24.6×** |
| `renew()` (session fixation) | 360 µs | 161 µs | 2.2× |
| Retry-Backoff (2 failures) | 162 ms | 31 ms | **5.1×** |
| `set()` 1 tag | 353 µs | 264 µs | 1.3× |
| `set()` 5 tags | 421 µs | 266 µs | **1.6×** |
| `set()` 10 tags | 421 µs | 286 µs | 1.5× |
| `set()` 20 tags | 582 µs | 299 µs | **1.9×** |
| `flushByTags(10 tags)` | 4.1 ms | 1.2 ms | **3.3×** |
| `flush(10 k keys)` | 7.4 ms | 9.5 ms | −30 % wallclock, no event-loop block |
| `collectGarbage(5 k keys)` | 1.4 ms | 2.8 ms | −50 % wallclock, no event-loop block |

The `flush()` and `collectGarbage()` overrides are intentionally
slower wallclock-wise for the caller. The trade-off is server-side
fairness: while `KEYS` runs, every other client in the Valkey
instance is blocked. With `SCAN`, the server can interleave other
clients between batches — for a multi-site, multi-pod setup that is
the more important property. Neither path is a hot-path operation
(`flush()` is BE/CLI-triggered; `collectGarbage()` runs in the
scheduler tick).

## Session backend internals

`KeyValueSessionBackend` is a from-scratch implementation of TYPO3's
`SessionBackendInterface`. Notable choices:

- **JSON serialisation** for session payloads (not PHP-native) so
  records remain debuggable with `valkey-cli`
- **WATCH / MULTI / EXEC** on `update()` with bounded retries —
  optimistic concurrency, two-tab login is safe
- **Lua EVAL for `renew()`** — atomic GET/SETEX/DEL so concurrent
  updates cannot be lost during the rename
- **`getAll()` via SCAN + MGET** instead of SCAN + N× GET — 24.6×
  faster on 500 sessions

## Locking strategy internals

`KeyValueLockingStrategy` uses the Lua-EVAL + BLPOP pattern recommended
by the Redis docs:

- `tryLock()` is a single atomic `SET key value NX EX ttl`
- `wait()` blocks via server-side `BLPOP` on a signal list — no
  client-side polling
- `unlockAndSignal()` runs a Lua script that atomically verifies
  ownership (GET), releases (DEL), and wakes one waiter
  (RPUSH + EXPIRE)

## Architecture

```text
Classes/
├── Cache/Backend/
│   └── KeyValueBackend.php          (extends TYPO3 Core RedisBackend)
├── Session/Backend/
│   └── KeyValueSessionBackend.php   (implements SessionBackendInterface)
├── Locking/
│   └── KeyValueLockingStrategy.php  (implements LockingStrategyInterface)
└── Connection/
    ├── KeyValueConnectionFactory.php
    ├── SentinelResolver.php
    ├── TlsContextBuilder.php
    └── ValueObject/
        ├── ConnectionParams.php
        └── Endpoint.php
```

All three TYPO3-facing components route their phpredis instantiation
through `KeyValueConnectionFactory` so Sentinel, TLS, mTLS, and the
phpredis 6.x backoff config are configured in exactly one place.

## Development

Tooling lives in [`moselwal/dev`](../dev). Common commands:

```bash
composer install
vendor/bin/phpunit -c Tests/phpunit.xml --testsuite=Unit
vendor/bin/phpunit -c Tests/phpunit.xml --testsuite=Functional   # needs Redis
vendor/bin/phpstan analyse Classes --level=8
vendor/bin/php-cs-fixer fix Classes Tests --config=vendor/moselwal/dev/.php-cs-fixer.dist.php
```text

Tests gated with `#[RequiresPhpExtension('redis')]` skip locally when
ext-redis is missing and run in CI / dev containers where it is
installed.

## Dependencies

| Package | Type | Purpose |
|---|---|---|
| `php` ^8.3 | Required | Language baseline |
| `typo3/cms-core` ^14.0 | Required | TYPO3 core |
| `ext-redis` >= 6.3 | Required | phpredis with v6 constructor API |
| `moselwal/dev` ^5.2 | Dev | Shared QA tooling (phpstan, cs-fixer, etc.) |

## Related

- [`moselwal/typo3-config`](../typo3-config) — Fluent TYPO3 config API
  with `autoconfigureCaching()` that wires this package
- [`moselwal/cluster-file-backend`](../cluster-file-backend) — Pod-local
  cache backend that uses this package's `KeyValueBackend` as its
  metadata storage layer

## Serializer

`KeyValueBackend` defaults to PHP-native serialization (`SERIALIZER_PHP`).
Operators can opt into other phpredis serializers via the `serializer`
option:

```php
'options' => [
    'serializer' => 'php' | 'igbinary' | 'none' | 'auto',
    // …
],
```

| Value | Behaviour |
|---|---|
| `'php'` *(default)* | PHP-native, BC-safe, identical to v4.2.0 |
| `'igbinary'` | igbinary when `ext-igbinary` is loaded; falls back to PHP-native with a notice otherwise |
| `'none'` | No phpredis-layer serialization; the caller serializes |
| `'auto'` | igbinary if loaded, php otherwise (**not the default**, see below) |

**⚠️ Switching the serializer requires a full cache flush of all
affected cache databases.** Existing payloads stay in the previous
format and will fail to deserialize on read. Recommended deploy
sequence:

```bash
# 1. Flush each affected Valkey DB
valkey-cli -n 3 FLUSHDB    # pages
valkey-cli -n 4 FLUSHDB    # hash
# … repeat for each cache DB

# 2. Restart workers so connections re-initialise with the new option

# 3. Deploy the new typo3-config with the serializer change
```text

**When `auto` is the wrong default**: an image update that ships
`ext-igbinary` would silently switch the on-disk format for any cache
that uses `auto` — and on the next read of an old PHP-serialised
payload, the cache would throw. Pinning the value explicitly (`'php'`
or `'igbinary'`) makes the contract observable.

**When `igbinary` is worth it**: only for caches storing deeply nested
arrays/objects (e.g. extbase ClassSchema, fluid template reflection).
For string-content caches (rendered pages, large text blobs) or flat
key/value caches (`hash`, `imagesizes`) the igbinary encoder overhead
dominates the marginal payload-size win — keep the default. See
[CHANGELOG.md](CHANGELOG.md) for measured numbers.

The session backend (`KeyValueSessionBackend`) uses JSON internally
for debuggability via `valkey-cli` — this option has no effect there.

## Changelog

See [CHANGELOG.md](CHANGELOG.md). Notable releases:

- **v4.3.0** — Lua-EVAL `set()` (1.3×–1.9× faster); `serializer` opt-in;
  KeyValueLockingStrategy audit (configurable `maxAcquireAttempts`,
  lazy connect, TTL-cache in `wait()`, log-level differentiation)
- **v4.2.0** — TYPO3 Core override pack (`flush`, `flushByTag`,
  `collectGarbage`); critical fix for `getAll()` SCAN cursor
- **v4.1.0** — Lazy-connect, MGET-based `getAll()`, atomic `renew()`,
  decorrelated-jitter backoff
- **v4.0.0** — PHP 8.5 baseline, TYPO3 14-only, phpredis 6.3 required

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
