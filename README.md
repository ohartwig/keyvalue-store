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

The `KeyValueBackend` extends `RedisBackend` and overrides the
operations below to drop server-side anti-patterns and round trips.
`get`, `has` and `findIdentifiersByTag` are inherited from Core;
conditional reads are an addition next to `get` (see below).

| Operation | TYPO3 Core | `KeyValueBackend` (v4.3.0) |
|---|---|---|
| `set()` | SETEX + SMEMBERS + (optional MULTI/PIPELINE) tag diff | Single Lua `EVAL` (atomic, 1 roundtrip) |
| `flush()` | `KEYS prefix*` + `DEL` (event-loop block for all clients) | `SCAN` + `UNLINK` batches (server stays responsive) |
| `flushByTag()` / `flushByTags()` | N× sequential `flushByTag()` fan-out, `SDIFFSTORE` per related tag | One Lua `EVAL`: atomic, `SREM` instead of `SDIFFSTORE`; above 1,000 entries the batched three-round-trip path |
| `remove()` | `EXISTS` + `SMEMBERS` + pipeline (3 round trips) | One Lua `EVAL`; also clears a leftover tag index of an expired entry |
| `collectGarbage()` | `KEYS identTags:*` | `SCAN`-loop |
| Read of an entry the reader already holds | `GET` of the whole value | `getUnlessUnchanged()`: one Lua `EVAL` that compares length and closing bytes on the server and sends the value only if it changed |
| Connection | `pconnect()` only, no Sentinel/TLS | `KeyValueConnectionFactory` (Sentinel resolver, mTLS, backoff) |
| Serializer | hard-coded PHP-native | configurable: `php` / `igbinary` / `none` / `auto` |

Additionally:

- `lazy=true` is the default — the TCP/TLS handshake is deferred to
  the first command. Bootstrap of 11 cache backends drops from ~25 ms
  to ~0.07 ms on a real mTLS Valkey setup.
- `OPT_SCAN = SCAN_RETRY` is set on every connection so phpredis
  retries empty SCAN pages internally instead of returning `false`
  to the caller (a well-known phpredis 6.x footgun).

### Connection reuse in a FrankenPHP worker (`KEYVALUE_CONNECTION_REUSE`)

Off by default. With `KEYVALUE_CONNECTION_REUSE=1` in a FrankenPHP worker,
the factory keeps one `\Redis` object per complete option set for the life
of the worker, and later requests get it back instead of a new one. A request
then no longer sends `AUTH`, `ECHO` and `SELECT` again for every cache,
session and lock backend it touches (on the TYPO3 page cache hit of one
installation: 21 Valkey commands per request down to 9).

- One object per option set: everything the backend passed to `create()`,
  including TLS, credentials, database, serializer, compression and key
  prefix. Two option sets that differ in anything never share an object.
- Dropped and rebuilt when it is not in ATOMIC mode (a request ended inside
  `MULTI` or a pipeline), when it is on another database than its options
  say, when the server reported an error on it, or when a backend saw a
  connection error on it. Client options a backend set after `create()`
  (serializer, prefix, SCAN mode, ...) are put back before it is handed out.
- A connection the server closed meanwhile reconnects on its next command
  (phpredis applies `AUTH` and `SELECT` again from its configuration).
- Never kept: Sentinel option sets (the master is resolved on every
  `create()`), option sets without `lazy`, and anything outside a FrankenPHP
  worker (`$_SERVER['FRANKENPHP_WORKER']`).

The switch is read from `$_SERVER`, `$_ENV` or the process environment, so it
can be set in the worker's `env` lines or in the container.

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

#### `flushByTag(s)` and `remove()` as server-side scripts

Measured 2026-11-13 in the production PHP image (8.5.12, phpredis 6.3.0)
against Valkey 9.1.3 over mTLS; medians. "Before" is the batched
three-round-trip path of v4.3.0 to v4.7.

| Operation | Before | Script | Δ |
|---|---:|---:|---|
| `flushByTag()`, 10 entries that also carry a 1,000-member tag | 427 µs | 67 µs | **6.4×** |
| `flushByTag()`, 10 entries that also carry a 20,000-member tag | 4,761 µs | 104 µs | **46×** |
| `flushByTags(100 tags)`, 20,000-member shared tag | 16.2 ms | 0.54 ms | **30×** |
| `flushByTag()` of a tag without entries | 93 µs | 40 µs | 2.3× |
| `remove()`, 3 tags | 210 µs | 78 µs | 2.7× |

Why it matters beyond the caller: the old path pruned every other tag an
entry carried with `SDIFFSTORE`, which is O(size of that tag set). A tag that
nearly every entry carries (a cache namespace tag, a site-wide tag) made each
small flush rewrite that whole set — in the 20,000-member case one
`SDIFFSTORE` held the single-threaded server for 3.7 ms. The script removes
only the flushed members with `SREM`.

It also closes a race: a `set()` that landed between the old path's first and
last round trip survived the flush with its tag index already deleted, so a
second flush of the same tag no longer found it. One script runs without
another client in between.

One script blocks the server for its whole run, so a flush of more than
1,000 entries (or more than 1,000 tags at once) keeps the old, spread-out
path, as does a script error. Like `set()`, the scripts build key names
inside Lua and therefore target a standalone Valkey/Redis or a
Sentinel-managed primary, not Redis Cluster.

#### Conditional reads (`ConditionalReadBackendInterface`)

For a reader that keeps a verified copy of an entry in memory and only needs
to know whether that copy is still what the server holds. `koh/frankenphp`'s
worker cache memo is such a reader: it used to fetch the whole payload on
every `get()` only to compare it byte for byte with its copy.

```php
$read = $backend->getWithValidator('menu');           // payload as get() returns it, plus a validator
// ... keep $read->payload and $read->validator ...
$next = $backend->getUnlessUnchanged('menu', $read->validator);
// null: the server still stores those bytes. Otherwise the current payload
// (false when the entry is gone) and its new validator, from the same call.
```

The server is asked on every read, in one round trip either way (one script:
`STRLEN`, `GETRANGE` of the closing bytes, and `GET` only on a difference), so
a write, flush or expiry on any pod is seen on the next read, as with `get()`.

**What a validator is.** The length of the stored value and its last bytes,
opaque to the reader. TYPO3 14's `VariableFrontend` ends every payload in the
96 hex digits of an HMAC-SHA3-384 over everything before them
(`AuthenticatedMessageDeserializer`, `HashService::appendHmac()`), so two
different payloads differ in their closing bytes as surely as their HMACs
differ. That is the whole argument, and it only holds for such payloads: use
a validator only for a payload whose HMAC you have checked.

**Compression.** With the `compression` option the server holds a zlib
stream, not the payload, so the validator is taken from the stored bytes
before decompression: their length and their last 200 bytes. A zlib stream
ends in the deflate coding of the payload's end (the HMAC digits, at most
16 bits each) and the Adler-32 of the whole payload, so those bytes still
cover the complete HMAC. The same payload compressed at another level is
different stored bytes: it is fetched once and gets a new validator.

**What it does not change.** A conditional read never returns bytes; the
reader answers from the copy it verified. Someone with write access to the
server can store other bytes with the length and closing bytes of a payload a
reader verified, and that reader then keeps answering with its verified copy;
storing the old, validly signed payload again does the same to any reader.

**When there is no validator.** `getWithValidator()` returns `null` as
validator, and `getUnlessUnchanged()` is a plain read, when the entry is
missing or empty, when phpredis serialises values itself (`serializer`
option: the stored bytes are then not what a script returns), and after a
conditional read failed as a script (scripting forbidden for the user, for
one) on that backend instance.

Measured 2026-11-13 in the production PHP image (8.5.12, phpredis 6.3.0)
against Valkey 9.1.3 over mTLS; medians of the backend read alone:

| Entry | `GET` | conditional read, unchanged | Δ |
|---|---:|---:|---|
| 2.2 MB | 2,270 µs | 70 µs | **32×** |
| 120 kB | 165 µs | 71 µs | 2.3× |
| 30 kB | 100 µs | 70 µs | 1.4× |
| up to 2 kB | 70 µs | 70 µs | none |

Below some 16 kB the round trip is the whole cost, so a reader gains nothing
there; `koh/frankenphp` uses conditional reads from that size on.

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
- **The session ID is never persisted.** The key and the record's
  `ses_id` both hold `HMAC-SHA256(sessionId, hashSecret)` (the secret
  defaults to `SYS/encryptionKey`), as core's own backends store the
  hash; the backend implements `HashableSessionBackendInterface`, so the
  Backend users module marks the viewer's own session, and `remove()`
  accepts the plain or the hashed ID. Exception messages never name the
  session. Records written by earlier versions, which carried the plain
  ID in `ses_id`, stay readable: `getAll()` reports the hash for them and
  `get()` rewrites them on the first read (compare-and-set, TTL kept).

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

Tooling lives in [`koh/dev`](https://git.ole-hartwig.eu/ai-ready-platform/platform/dev). Common commands:

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
