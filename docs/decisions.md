# TheMusicDev/Files — decisions & conversation record

Status: v1.0.0, extracted from its first site (themusicdev.llc) into this repo on 2026-10-03. The decisions below were
made while building it there; where they mention "the host" they mean any application that installs the plugin.

Rationale that would otherwise be lost when the plugin moves to its own
repo. Design of record: `docs/file-management-and-uploads.md` (same dir).

## Scope

Uploads/file-management is **not** for one module. Built once, used
everywhere: any future upload venture across any project connects to this
plugin, never to its own ad-hoc upload code.

## Decisions (reasoned, user-accepted)

- **league/flysystem v3 from day one.** Swapping to S3/Azure/SFTP later is a
  composer package + a `Files.backends` entry. Plugin code only ever talks
  to `FilesystemOperator`. Chosen over homegrown interface because Flysystem
  IS the interface.
- **Central `stored_files` ledger**, one row per file forever: opaque
  server-generated `path` (`bin2hex(random_bytes(16))` + extension derived
  from the verified MIME — no client input in paths), persisted
  `original_filename` (the site's old upload script lost it; we don't), finfo-sniffed `mime`
  (never client-declared), `size`, indexed `collection`, soft-delete
  `deleted`, timestamps.
- **`collection` column over tags.** Consumers aggregate files via
  dot-path virtual dirs (`recruiting.resumes`, `uploads.misc`) so browsing
  is organized instead of image-vs-resume soup. Tags deferred until a real
  ad-hoc-organization need appears.
- **`FileStore::put()` is the integration seam.** Other plugins/app register
  collections under `Files.collections` (label, MIME allowlist, size cap)
  and keep only thin `*_file_id` FK columns into `stored_files`. They never
  touch the filesystem; Files owns lifecycle (trash keeps files, purge
  deletes row + file, purge tolerates an already-missing backend file).
- **Gated serving, plugin-shipped.** `DlController` streams via the
  plugin route (`/files/serve/{id}`); the HOST supplies
  `Files.authCallable` (a callable on the row; for example an admin-session check). Collections with
  `public => true` bypass the gate (reserved for future images; none in
  v1). Attachment disposition uses RFC 5987 `filename*=UTF-8''` with a
  per-char `_` ASCII fallback.
- **Plugin config convention.** `config/app_default.php` holds ship-with
  defaults (backend `local`, root `ROOT/data/uploads`, `uploads.misc`
  25 MB all-MIME collection); `config/bootstrap.php` merges them UNDER host
  values (shallow `+` per sub-key, hosts win). Cake's `BasePlugin::bootstrap()`
  auto-requires that file — the plugin class carries no config seeding, and
  `parent::bootstrap($app)` is only required when a subclass does extra work
  (IdentityBridge pattern, yt-beat-finder).
- **Soft delete: hand-rolled `deleted` column + `findActive`/`findTrashed`
  finders. muffin/trash was deferred** (the first consumer's spec said
  muffin/trash; revisit if a consumer needs trash behavior beyond the column). The API already
  owns the column writes; behavior wiring gave nothing extra.

## Rules (override any module's convenience)

1. Row-before-file: validate → persist ledger row → write file. A failure
   after the row leaves a visible "file missing" row, never orphan binaries.
2. Trash keeps files; only purge deletes row + file.
3. Serving is always an application route, never disk hrefs.
4. No client input in filesystem paths — extension comes from verified MIME.

## Deviations & open items

- Orphan-scan maintenance command deferred until the second consumer +
  real data exist.
- Public collections / CDN prefixes, S3 adapters, tags: config seam only,
  nothing built.
- Whole-file read into memory on `put()`, bounded by the collection cap —
  stream to disk if a collection ever needs >100 MB.

## E2E verification at v1 (2026-09-29)

curl over a live server (on the first site): admin login → multipart upload → row + hex-named
binary under `data/uploads/` → sessioned serve (200, correct MIME, RFC 5987
disposition, nosniff) → unauth serve 302 → soft delete keeps binary →
trashed row serves 404. Purge covered by unit tests.

## Gotchas (Files-specific)

- `FileStore` caches its Flysystem operator per backend **definition
  fingerprint** (`key . '|' . json_encode($definition)`), so a config
  repoint (tests, hosts) always builds a fresh operator — cache is not keyed
  on the backend key alone.

## Tests (standalone since extraction)

The behavior tests live in this repo (`tests/`, MariaDB on port 3309, a `tests/test_app`): `FileStore::put()` rules,
soft delete and purge, the serving controller's gate, MIME and headers, the finders, and the config merge. Admin screens
are the host's, so their tests are too; `examples/` is untested sample code.
