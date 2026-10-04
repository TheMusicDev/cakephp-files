# Files plugin — uploads & file-management design of record

Status: **accepted + built** as `TheMusicDev/Files`. Goal: one uploads /
file-management solution built once and reused across **all future
upload-related ventures in all TheMusicDev projects** — not a feature internal
to one module. All open decisions below are resolved; this doc is the
canonical spec, code is the implementation.

First consumer: the first site that used it (themusicdev.llc). Next consumers: a
recruiting plugin's applications (resume + cover letter); later: portfolio images,
post cover images, client-site assets.

Source of truth for intake requirements was the first site's one production upload
endpoint (a PHP script); its design bugs (orphan-creating write order, lost
original filename) are deliberately inverted here.

## 1. Shape

```
plugins/TheMusicDev/Files            ← the plugin package (themusicdev/cakephp-files)
├── composer.json                    type: cakephp-plugin, requires flysystem ^3
├── src/FilesPlugin.php              empty BasePlugin subclass (daisyui pattern)
├── src/Lib/FileStore.php            the integration seam — all consumers call this
├── src/Model/Table/StoredFilesTable.php + Entity/StoredFile.php
├── src/Controller/DlController.php  gated serving controller + routes.php
└── config/Migrations/*CreateStoredFiles.php
```

- **Storage backend: league/flysystem v3 from day one.** Local adapter now
  (`league/flysystem-local`, root `ROOT/data/uploads`); S3/Azure/SFTP are
  officially supported adapters, so swapping later = one composer package +
  one config block. Plugin code only ever talks to `FilesystemOperator` —
  never to disk APIs directly.
- **Central ledger table `stored_files`** — one row per file that ever enters:
  opaque generated `path` (unique), persisted `original_filename`
  (the old script loses it forever; we don't port that), finfo-sniffed `mime`,
  `size`, `collection`, timestamps, `trashed` (muffin/trash soft delete).
  Per-column trios on owning tables can't power the browser, lifecycle rules,
  orphan scans, or audits — rejected.

## 2. Collections — virtual dirs

Every file carries one `collection` dot-path (`recruiting.resumes`,
`portfolio.images`, `post.cover_images`), **indexed, many-to-one**. The admin
file browser renders distinct collections as a folder tree and lists a
collection's files — "all resumes, not mixed with images" is a single indexed
query.

- **Decision reason** (vs tagging): folder membership is a partition, not a
  facet; lifecycle rules key off the collection (per-collection purge, trash
  semantics, orphan scan); a tag mechanism is additive later without touching
  the collection concept. Deferred until a real ad-hoc-organization need.
- **Registration**: consumers declare their collections in config under
  `Files.collections` — each: `label`, `mimes` allowlist, `size` cap, and a
  reserved `public` flag (v1: always false; no serving-CDN path yet). The
  plugin validates every `put()` against the registering collection's
  allowlist. Plugin builds **`uploads.misc`** (allowlist-all, 25 MB) as the
  one generic collection consumers don't have to declare.
- **Not a config seam to hand-roll** — config layout as shipped:
  - Plugin side: `plugins/TheMusicDev/Files/config/app_default.php` holds
    the ship-with defaults under the `Files` key; the plugin's
    `config/bootstrap.php` (auto-required by `BasePlugin::bootstrap()`)
    merges them UNDER whatever the host wrote — hosts win, shallow `+` per
    sub-key (`backends`, `collections`).
  - Host side: override/add in the app's own config file under `Files`
    (backend key, backends map, collections, `authCallable`). Sketch:

```php
'Files' => [
    'backend' => 'local',
    'backends' => [
        'local' => ['class' => \League\Flysystem\Local\LocalFilesystemAdapter::class, 'root' => ROOT . DS . 'data' . DS . 'uploads'],
    ],
    'collections' => [
        'uploaded_docs' => ['label' => 'Uploaded docs', 'mimes' => ['application/pdf'], 'size' => 5_242_880],
    ],
],
```

- Consumer **integration**: other plugins never touch the filesystem. They
  register collections in their own config contribution and call
  `FileStore::put($upload, $collection)` → ledger row back; they keep only
  thin reference columns (`*_file_id` FK into `stored_files`) in their own
  tables. Lifecycle (trash keeps files, purge deletes them) is owned by
  Files.

## 3. Serving

- Plugin ships the gated serving controller (`DlController`): streams a
  stored file by ledger id — right MIME, `Content-Disposition` filename from
  persisted `original_filename`. Route: `/files/serve/{id}`, shipped by the plugin.
- **Auth seam** (C decision): `Files.authCallable` — the host supplies a
  callable deciding access (for example an admin-session check). No host auth
  logic inside the plugin. Collections with `public => true` bypass the gate
  (reserved for images; none exist in v1).
- **Rule stays: serving is always an application route, never disk hrefs.**

## 4. Rules that override any module's convenience (unchanged)

1. **Row-before-file** persistence ordering: validate → persist ledger row →
   store file → done. A failure after the row leaves a visible "file missing"
   row, never silent orphan binaries (inverts the old script's order).
2. **Trash keeps files; purge deletes them.** muffin/trash soft delete on
   `stored_files` leaves the binary in place for restore; a hard purge
   removes row + file.
3. **No client input ever reaches a filesystem path** — extensions derived
   from the verified MIME, names generated server-side, original filename
   stored in the ledger only.
4. Files never live in webroot; storage root from config per project.

## 5. Not decided here (belongs to consumer modules)

- Image resizing, CDN prefixing, cache headers for public images.
- A consuming module's endpoint/form specifics.
- Client-site hosting specifics (data dir path, backup cadence).
- Orphan-scan maintenance command (decision D): documented now, built with
  the second consumer + real data.