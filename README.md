# TheMusicDev/Files

Uploads and file management for CakePHP 5 sites, built once and reused: [Flysystem](https://flysystem.thephpleague.com/)
v3 storage, one `stored_files` ledger row per file, dot-path **collections** as virtual folders, a validation and
lifecycle seam (`FileStore`), and gated serving through a route the plugin ships. Your application never touches the
filesystem and keeps only a thin `*_file_id` column in its own tables.

**Status:** stable, v1.0.0. It has run in production on one site (themusicdev.llc) and is now a standalone package.
Requires PHP 8.2+ (with `ext-fileinfo`) and CakePHP 5.2+.

## Why

Uploads recur in every site, and the naive per-module version loses the original filename, leaves orphan files
behind when a write fails, and reinvents MIME validation each time. This plugin does the hard-to-get-right parts once:
a module declares a collection and stores a file id. The reasoning behind each decision is in
[`docs/decisions.md`](docs/decisions.md) and the design of record in
[`docs/file-management-and-uploads.md`](docs/file-management-and-uploads.md); this README is the how.

## Install

```bash
composer require themusicdev/files
bin/cake plugin load TheMusicDev/Files
bin/cake migrations migrate -p TheMusicDev/Files   # creates stored_files; run on every database
```

Loading the plugin also mounts its one route, `GET /files/serve/{id}`. After the migration on an existing install, run `bin/cake schema_cache clear`: Cake silently drops columns its cached
schema does not know when saving.

## Configure

Defaults ship in the plugin's `config/app_default.php` and are merged **under** your values, so a host overrides only
what it differs on. Put yours in `config/app.php` under `Files`:

```php
'Files' => [
    'backend' => 'local',                       // key into `backends`
    'backends' => [
        'local' => [
            'class' => \League\Flysystem\Local\LocalFilesystemAdapter::class,
            'root' => ROOT . DS . 'data' . DS . 'uploads',   // keep it outside webroot
        ],
    ],
    'collections' => [
        // 'dot.path' => label, MIME allow-list (empty = any), size cap in bytes
        'contracts.pdf' => [
            'label' => 'Contracts',
            'mimes' => ['application/pdf'],
            'size' => 10 * 1024 * 1024,
            // 'public' => true,                // skips the serve gate (see below)
        ],
    ],
    'authCallable' => static function (\TheMusicDev\Files\Model\Entity\StoredFile $file): void {
        // Throw to refuse; return to allow.
    },
],
```

| Key | Default | Meaning |
|---|---|---|
| `backend` | `local` | Which entry of `backends` is active. |
| `backends` | `local` at `ROOT/data/uploads` | Flysystem adapters: `class` plus the arguments that adapter needs. Only the local adapter is wired to its arguments today; another adapter needs a small change in `FileStore::adapterArgs()` (see the decisions doc). The local root is created on first run. |
| `collections` | `uploads.misc` (25 MB, any type) | Your collections are added beside the built-in one; redefine `uploads.misc` to change it. |
| `authCallable` | `null` | The serve gate. `null` means **nothing is served** (403). |

### The serve gate (`Files.authCallable`)

`GET /files/serve/{id}` calls `authCallable($file)` with the ledger row before it reads a byte. The plugin has no
auth of its own: your callable decides, and refuses by **throwing** (a `RedirectException` to your login page, a
`ForbiddenException`, whatever fits). Returning normally allows the download. Collections with `'public' => true` skip
the callable.

```php
'authCallable' => static function ($file): void {
    $identity = \Cake\Routing\Router::getRequest()?->getSession()->read('Auth.id');
    if ($identity === null) {
        throw new \Cake\Http\Exception\RedirectException(
            \Cake\Routing\Router::url(['plugin' => false, 'controller' => 'Users', 'action' => 'login']),
        );
    }
},
```

Two details that bite: build the login URL with `'plugin' => false` (the closure runs inside the plugin's request), and
re-check that the account still **exists**, not just that a session is present, or a deleted user keeps downloading.

## Use

```php
use TheMusicDev\Files\Lib\FileStore;

$store = FileStore::instance();
$row = $store->put($request->getUploadedFile('file'), 'contracts.pdf');   // throws FileStoreException on any intake failure
$id = $row->get('id');                                                    // keep this as <your_table>.file_id

$store->get($id);        // active row, or FileStoreException
$store->read($row);      // contents (bounded by the collection size cap)
$store->delete($row);    // soft delete: row trashed, file kept
$store->purge($row);     // hard delete: row and file
```

- `put()` sniffs the MIME from the **bytes** (never the client's declared type), checks the collection's allow-list and
  size, derives the file extension from the verified MIME, names the file with random hex (no client input reaches a
  path), keeps the original filename in the ledger, and saves the row **before** writing the file, so a failed write
  leaves a visible "file missing" row instead of an orphan binary.
- Link to a file by building the route, never by a disk path:
  `$this->Url->build(['plugin' => 'TheMusicDev/Files', 'controller' => 'Dl', 'action' => 'serve', $id])`.
- `StoredFilesTable` has `find('active')`, `find('trashed')` and `collectionCounts()`. Soft delete is a plain `deleted`
  column (not muffin/trash), written by `FileStore::delete()`.
- Trash keeps files; only `purge()` removes the binary. If your own table references `stored_files`, decide its foreign
  key accordingly (`ON DELETE SET NULL` keeps the referencing row when a file is purged).

## Host your own admin screens

The plugin ships **storage, the ledger and the serving route, not admin screens**: branding, auth and layout belong to
the site. [`examples/`](examples/) has a working admin file browser (controller, template, routes: list collections,
paginated file list, upload, soft delete) to copy and restyle. It is not autoloaded. Whatever gate you put on those
screens, put the same check in `Files.authCallable`.

## Gotchas

- Files are never served from disk paths; always through the route (the storage root is not in webroot).
- `FileStore` caches its Flysystem operator per backend **definition** (key plus the definition JSON), so repointing a
  key at another root, as tests do, builds a fresh operator.
- Cake 5's `Response` has no `withStreamedBody()`; the serve action wraps the stream in a PSR-7 body.
- A host that registers `Files.collections` or `Files.backends` replaces the plugin default for the **same key** only;
  a missing `uploads.misc` is filled in from the defaults.
- `put()` reads the whole upload into memory, bounded by the collection size cap; stream to disk if you ever allow
  more than about 100 MB.
- There is no orphan-scan command yet (design item D, deferred until real data exists).

## Development

```bash
composer install
docker compose up -d --wait dbtest    # MariaDB 11.8 on 127.0.0.1:3309, database files_test
composer check                        # phpunit + phpcs + phpstan (level 8)
```

Tests run on MariaDB, never sqlite. Override the connection with `DATABASE_TEST_URL`. Conventions shared by every
TheMusicDev plugin (naming, layout, CI, workflow) live in
[`TheMusicDev/cakephp-conventions`](https://github.com/TheMusicDev/cakephp-conventions).

## License

MIT, see [LICENSE](LICENSE).
