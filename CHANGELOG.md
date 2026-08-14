## [4.6.1](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.6.0...v4.6.1) (2026-08-12)


### Bug Fixes

* **deps:** update dependency php to ^8.5.9 ([3cd7c30](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/3cd7c300188dac2f5c0265f76d16e72813b7cab5))

# [4.6.0](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.5.5...v4.6.0) (2026-08-01)


### Features

* **commit-signing:** add .gitsigners + lefthook hint (G-SDLC-002 step 3) ([7136609](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/7136609d5d04151bb500f9ab53981133779c1997))

## [4.5.5](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.5.4...v4.5.5) (2026-07-29)


### Bug Fixes

* **docs:** point at the handbook repository, not an unreachable domain ([c656c3d](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/c656c3d45460a151a78ea206c850606739b75a4b))

## [4.5.4](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.5.3...v4.5.4) (2026-07-28)


### Bug Fixes

* repair the unit suite and the option typing it was hiding ([cb1e7e4](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/cb1e7e4ee4d895e4d13252c1451a2ab60b7b729a))

## [4.5.3](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.5.2...v4.5.3) (2026-07-24)


### Bug Fixes

* **ci:** drop github-mirror (no public mirroring for now) ([9c6d0bc](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/9c6d0bc0cfb282f01aa57190e102424872f69cb9))

## [4.5.2](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.5.1...v4.5.2) (2026-07-24)


### Bug Fixes

* **ci:** drop ter-publish (no TER publishing for now) ([12e5e8f](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/12e5e8f09e489c65351b19fef1fca7ff10e55067))
* **ci:** ter-publish 1.2.12 (az1a IPv4 for tailor install) ([0c56dd7](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/0c56dd7e4330815afff70e018fa659161fa0211c))

## [4.5.1](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.5.0...v4.5.1) (2026-07-24)


### Bug Fixes

* **ci:** adopt github-mirror 1.2.10 (skip mirror when no token) ([61e0da7](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/61e0da7deb62758d4313b74b1627c188003bb278))
* **ci:** github-mirror 1.2.11 (contains skip-if-no-token) ([636a41b](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/636a41b70f5cbf03c73ebfaba993035d37c36840))

# [4.5.0](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.4.1...v4.5.0) (2026-06-11)


### Bug Fixes

* **release:** re-tag to publish to TER for the first time ([2fae9aa](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/2fae9aa166841ba3f0a14900806f437ab6b8f936))


### Features

* **release:** add develop branch as rc-prerelease channel ([1193bf6](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/1193bf62cc7945ba633cc75cc1ec7a9931da95f7))

## [4.4.1](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.4.0...v4.4.1) (2026-06-07)


### Bug Fixes

* **release:** drop [skip ci] from semantic-release commit ([44a9e14](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/44a9e1402c8a4846adf1e001f5541b01fe47ade8))

# [4.4.0](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/compare/v4.3.4...v4.4.0) (2026-06-07)


### Bug Fixes

* **ci:** allow_failure on 9 known-broken component jobs ([87926b0](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/87926b08c752239b7e6ab9773ff4d1c0a60d7387))
* composer normalize + require-checker whitelist for transitive symbols ([566fd43](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/566fd43b08bc2248907a9ce854e2dd6e41c650e5))
* **composer:** add homepage field ([2e78769](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/2e78769770ac9fba0848c156d7146f9b8862533d))
* extension-key keyvalue-store -> keyvalue_store ([5bf846f](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/5bf846fce0109a60ff5a5d90c2864ae89c0d3b64))
* **md:** replace bare fenced codeblocks with ```text ([144499a](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/144499a4ae6bf0cd4012482d240d2fba92c3470f))
* **md:** scope markdownlint to public surfaces ([8a0008b](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/8a0008b0f7ce5ecae291f034e97db7b90daa6f28))


### Features

* **ci:** add TER publish to release stage ([76c77c2](https://git.ole-hartwig.eu/development/moselwal/keyvalue-store/commit/76c77c26715b64d6a08b7687784300857c768235))

# Changelog

All notable changes to `moselwal/keyvalue-store` are documented in this file.
The format is loosely based on Keep-a-Changelog; this package follows
SemVer (breaking changes bump the major).

## [4.3.1] - 2026-05-24 — P0 HOTFIX

### Fixed (CRITICAL)

- **`serializer` default no longer activates phpredis OPT_SERIALIZER**.
  v4.3.0 set `Redis::SERIALIZER_PHP` whenever no explicit option was
  given. This silently double-encoded every cache write (TYPO3
  VariableFrontend already calls `serialize()` before passing the
  payload to the backend; phpredis then wrapped that string in another
  `serialize()` call). On read, phpredis `unserialize()`d once,
  TYPO3 `unserialize()`d a second time — and the second call received
  an `array` instead of a string, throwing:

  ```
  TypeError: unserialize(): Argument #1 ($data) must be of type string, array given
    in TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::get()
  ```

  Symptoms: BE login renders 500 (FluidTemplateCache write fails),
  FE pages render 500 (RootlineUtility cache read fails), CFB
  metadata writes throw "ClusterFileBackend write failed; see log
  for details" (the wrapped error is the unserialize TypeError).

  v4.3.1 fix: `applySerializerOption()` is a no-op when no `serializer`
  option is set, leaving phpredis at its default `SERIALIZER_NONE`.
  This restores v4.2.0 wire behaviour exactly. The `serializer`
  option is now strictly opt-in: setting it explicitly activates
  phpredis-side serialisation; not setting it lets TYPO3 own the
  encoding.

- **`'auto'` fallback** when ext-igbinary is missing now resolves to
  `SERIALIZER_NONE` (was `SERIALIZER_PHP` in v4.3.0). Same reasoning:
  the wire format must stay identical to the default-unset case so
  TYPO3-owned encoding keeps working.

- **`'igbinary'` fallback** when ext-igbinary is missing now resolves
  to `SERIALIZER_NONE` (was `SERIALIZER_PHP`). Operators who explicitly
  request igbinary on a host without the extension get a notice + a
  format-stable fallback instead of a silently broken cache.

### Deploy notes

Sites that ran any traffic on v4.3.0 have double-encoded payloads in
their Valkey cache databases. After deploying v4.3.1 those entries
will surface as `unserialize()` TypeErrors on the next read. **Flush
the affected cache databases** as part of the deploy:

```bash
# DBs depend on the typo3-config wiring; the moselwal default is 3-11.
for db in 3 4 5 6 7 8 9 10 11; do
    valkey-cli ... -n $db FLUSHDB
done
```

Or via TYPO3 (after restart):
```bash
docker exec moselwal-websites-httpd bin/typo3 cache:flush --group all
```

## [4.3.0] - 2026-05-24

### Performance

- **`KeyValueBackend::set()` override** with a single Lua `EVAL`.
  Replaces TYPO3 Core's 2–3-roundtrip flow (SETEX + SMEMBERS + optional
  MULTI/PIPELINE for tag diff) with one atomic server-side operation.
  Bench against live Valkey/mTLS with a 2 KB payload:

  | Tag count | Core | v4.3.0 | Saved |
  |---|---:|---:|---:|
  | 1 tag | 353 µs | 264 µs | **+88 µs (1.3×)** |
  | 5 tags | 421 µs | 266 µs | **+155 µs (1.6×)** |
  | 10 tags | 421 µs | 286 µs | **+134 µs (1.5×)** |
  | 20 tags | 582 µs | 299 µs | **+284 µs (1.9×)** |

  Behaviour-identical to Core for the `lifetime=0` case: we normalise
  to `FAKED_UNLIMITED_LIFETIME` (one year) **before** invoking Lua —
  the script therefore always receives a positive TTL and uses SETEX,
  never SET-without-TTL.

- **Opt-in `serializer` option** for `KeyValueBackend`:

  ```php
  'serializer' => 'php' | 'igbinary' | 'none' | 'auto'
  ```

  - `'php'` (**default**) — `Redis::SERIALIZER_PHP`. **BC-safe**, identical
    to v4.2.0 on-disk format.
  - `'igbinary'` — `Redis::SERIALIZER_IGBINARY` if ext-igbinary is loaded;
    otherwise emits a notice and falls back to PHP-native so the cache
    stays operational.
  - `'none'` — `Redis::SERIALIZER_NONE`. Caller handles serialisation.
  - `'auto'` — igbinary if loaded, php otherwise. **Not the default**:
    an image update that ships ext-igbinary would otherwise silently
    switch the on-disk format and break existing payloads.

  **⚠️ Switching the serializer requires a full cache flush of all
  affected cache databases.** Existing payloads stay in the previous
  format and will fail to deserialize. Recommended deploy sequence:
  (1) flush cache DBs, (2) restart workers, (3) deploy new config.

  **When igbinary actually helps** (measured against live Valkey/mTLS):

  | Cache shape | PHP serializer | igbinary | Delta |
  |---|---:|---:|---|
  | Small (~100 B, flat) | 102 µs | 179 µs | **−77 µs slower** |
  | Medium nested | 96 µs | 96 µs | parity |
  | Large string-blob (16 KB) | 119 µs | 122 µs | parity |
  | Extbase deep (50 props) | 119 µs | 110 µs | +9 µs |

  Bottom line: igbinary is **only** worthwhile for caches with deeply
  nested arrays/objects (e.g. extbase ClassSchema, fluid template
  reflection). For string-content caches (rendered pages, large text
  blobs) or flat key/value caches (`hash`, `imagesizes`) the overhead
  of igbinary's encoder dominates the marginal payload-size win — keep
  `serializer = 'php'` (the default) for those.

### KeyValueLockingStrategy audit fixes

- **`wait()` no longer issues a TTL roundtrip** — reuses the configured
  TTL directly. The TTL was always set to `$this->ttl` by every lock
  holder, so asking the server was a roundtrip for no gain.
- **`acquire()` blocking-loop honours a configurable cap** —
  `maxAcquireAttempts` config option (default 100). Operators with
  short lock TTLs (e.g. 2 s) can lower it; setups with long-running
  protected operations can raise it. Prevents the previous unbounded
  blocking loop on Redis outages.
- **Logger levels differentiated** — `tryLock()` only logs at `critical`
  when a Throwable is actually caught (real exception path). The
  expected SET-returns-false case (lock contended) stays silent so a
  busy lock under contention does not flood the log.
- **Locking connection is lazy** — `KeyValueConnectionFactory` is now
  invoked with `lazy=true`; the eager ping at lock-strategy construction
  is gone.

### Fixed

- `set()` override's signature stays identical to TYPO3 Core's `array $tags`
  type-hint (intentionally untyped, for interface contravariance).

## [4.2.0] - 2026-05-24

### Fixed (CRITICAL)

- **`KeyValueSessionBackend::getAll()` returned `[]` against real Redis**:
  v4.1.0 changed the SCAN cursor initialisation from `null` to `0` based
  on a misread of the phpredis API. phpredis 6.3.0 treats `int 0` as
  "iteration finished" and returns `false` immediately — without ever
  talking to the server. The intended T2.3 fix in v4.1.0 was therefore
  the opposite of an improvement: it silently broke session enumeration
  in the BE "Active sessions" module. v4.2.0 restores `cursor = null`
  initial and uses `0 !== (int) $cursor` as the loop guard. Bench
  confirms 500 sessions are now found (was: 0).

### Performance (override pack against TYPO3 Core anti-patterns)

- **`flush()` override**: Replaces TYPO3 Core's `KEYS prefix*` + `DEL`
  pipeline with `SCAN` + `UNLINK`. `KEYS` server-side-blocks Redis for
  every other client while it walks the keyspace — at millions of keys
  that is hundreds of milliseconds of stop-the-world for *all* pods.
  Wallclock cost for the caller increases ~30 % (10k keys: 7.4 ms →
  9.5 ms) because `SCAN` is iterative, but Redis stays responsive to
  concurrent clients throughout. In a shared multi-site setup this is
  the right trade.
- **`flushByTag()` / `flushByTags()` override**: One sUnion across all
  passed tags plus one pipelined `UNLINK` cleanup, replacing TYPO3
  Core's N× sequential `flushByTag()` fan-out. **3.3× faster for
  multi-tag flushes** (10 tags × ~100 entries: 4.1 ms → 1.2 ms).
- **`collectGarbage()` override**: Same `KEYS → SCAN` swap as `flush()`,
  with `UNLINK` for orphan cleanup. Same wallclock-vs-server-loop
  trade-off (1.4 ms → 2.8 ms wallclock; Redis stays responsive). GC
  runs on the scheduler-container clock so the caller-side latency is
  uncritical — server-side responsiveness is what matters.
- **`OPT_SCAN = SCAN_RETRY`**: Set in `initializeObject()` so phpredis
  internally re-issues empty SCAN pages until either keys appear or
  the cursor is exhausted. Removes a footgun for any future code that
  uses SCAN through this backend.

### Bench (real, against the moselwal Valkey container)

| Op | TYPO3 Core | v4.2.0 | Δ |
|---|---:|---:|---|
| Bootstrap 11 caches (T1.1) | 25.1 ms | 0.07 ms | **381×** |
| getAll() 500 sessions (T1.2) | 37.2 ms | 1.5 ms | **24.6×** |
| renew() (T2.2) | 360 µs | 161 µs | 2.2× |
| Retry-Backoff 2 failures (T2.1) | 162 ms | 31 ms | **5.1×** |
| flushByTags 10 tags | 4.1 ms | 1.2 ms | **3.3×** |
| flush 10k keys (wallclock) | 7.4 ms | 9.5 ms | −30 % wallclock, +∞ server fairness |
| collectGarbage 5k keys | 1.4 ms | 2.8 ms | −50 % wallclock, +∞ server fairness |

## [4.1.0] - 2026-05-24

### Performance

- **Lazy-connect default** (`KeyValueBackend`): `buildFactoryOptions()` now
  emits `lazy=true`. The TCP/TLS handshake is deferred to the first command
  instead of being paid at TYPO3 bootstrap for every configured cache
  backend. Removes ~11× ping-roundtrips per request on multi-cache setups
  (and the full TLS handshake on the first warm request after a worker
  reload). Operators can still force eager connect by passing
  `lazy => false` in the backend options.
- **`KeyValueSessionBackend::getAll()`**: Replaced the SCAN+per-key GET
  loop with a SCAN+MGET pipeline. One roundtrip per SCAN page instead of
  N+1 — at 500 active sessions, that is ~5 roundtrips instead of ~505.
  Improves the BE "Active sessions" module load time from seconds to
  sub-second.

### Fixed

- **`KeyValueSessionBackend::getAll()` cursor bug**: The cursor was
  initialised to `null`, which made `while ($cursor > 0)` short-circuit
  on the first iteration whenever phpredis did not implicit-cast. Result:
  the session list was silently truncated to the first SCAN page (~100
  entries). Now initialised to `0`.
- **`KeyValueSessionBackend::renew()` race**: Previously executed GET,
  TTL, SETEX, DEL as four separate commands. A concurrent `update()`
  landing between our GET and SETEX was silently overwritten with the
  older snapshot we had just copied. Rewritten as a single Lua `EVAL`
  so the rename is atomic relative to other writes.

### Changed

- **`KeyValueSessionBackend::getRedis()` retry-backoff**: Linear
  50/100/150 ms sleeps replaced with decorrelated jitter (10 ms base,
  100 ms cap). Two failed attempts now complete in well under 200 ms
  instead of ~300 ms — fail-fast for transient Valkey blips, less
  synchronised retry storms across pods.
- **`KeyValueBackend::__construct(array $options = [])`**: Dropped the
  TYPO3 11/12/13 compatibility shim (string-context first arg + parent
  signature detection via runtime reflection). The package is TYPO3
  14-only since v4.0.0, so the shim was dead code costing one reflection
  call per cache instantiation.

### BC

- `new KeyValueBackend('production', [...])` (old TYPO3 11-13 call shape)
  now throws `TypeError`. The TYPO3 14 CacheManager calls
  `new KeyValueBackend([...])` and is unaffected — `autoconfigureCaching()`
  in `moselwal/typo3-config` already targets that signature.

## [4.0.0] - 2026-04-03

- PHP 8.5 baseline, `ext-redis >= 6.3` required.
- TYPO3 14-only support; the 11/12/13 matrix collapsed.
- `moselwal/dev` bumped to v5.

## [3.x] and earlier

Multi-version TYPO3 11/12/13/14 matrix. See git history for the full
incremental log.
