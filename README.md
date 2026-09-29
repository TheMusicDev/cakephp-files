# TheMusicDev/Files

Reusable uploads + file management for TheMusicDev CakePHP sites. Built once,
used everywhere — every future upload venture connects through this plugin,
never to its own ad-hoc upload code.

Flysystem v3 storage, one central `stored_files` ledger row per file,
dot-path **collections** as virtual folders, an upload-validation +
lifecycle seam (`FileStore`), and gated file serving through a plugin-shipped
route. The host app never touches the filesystem and keeps only thin
`*_file_id` columns in its own tables.

## Why (one paragraph)

Uploads recur in every site we make (contact attachments, resumes, images),
and the naive per-module version loses the original filename, orphans files
on failure, and reinvents MIME validation each time. This plugin moves the
hard-correct parts here once; host modules only declare a collection and
store a file ID.

## Install

```bash
composer require themusicdev/cakephp-files
bin/cake migrations migrate -p Files   # on each DB (dev, dbtest via DATABASE_TEST_URL, prod)
```

Plugin load (`config/plugins.php`) ships the route with the plugin.

## Configure

Defaults live in the plugin's `config/app_default.php`; the plugin merges
them **under** host values — hosts win, override only what you differ on.
Host config sits in the app's `config/app.php` under `Files`:

```php
'Files' => [
    'backend' => 'local',                 // key into backends
    'backends' => [
        'local' => [
            'class' => \League\Flysystem\Local\LocalFilesystemAdapter::class,
            'root' => ROOT . DS . 'data' . DS . 'uploads',
        ],
    ],
    'collections' => [
        // label + MIME allowlist (+ size cap). Dot-path = virtual folder.
        'recruiting.resumes' => [
            'label' => 'Resumes',
            'mimes' => ['application/pdf'],
            'size' => 10 * 1024 * 1024,
            // 'public' => true,           // reserved; bypasses the serve gate
        ],
    ],
    // Serve gate: callable on the StoredFile row; throw to deny.
    'authCallable' => static function ($file): void {
        $session = \Cake\Routing\Router::getRequest()?->getSession();
        if (!$session?->read('admin.email')) {
            throw new \Cake\Http\Exception\RedirectException(
                \Cake\Routing\Router::url('/admin/login'));
        }
    },
],
```

Keys: `backend` picks the active backend; `collections` entries get a ledger
row allowance; `authCallable: null` denies all serving (safe default).

## Use

```php
use TheMusicDev\Files\Lib\FileStore;

$stored = FileStore::instance()->put($uploadedFile, 'recruiting.resumes');
// $stored->get('id') — persist this as <host_table>.resume_file_id
```

- Consumers: register a collection, call `put()`, keep `*_file_id` FKs.
  Files owns the file lifecycle: trash keeps files, purge deletes row + file.
- Serving: `GET /files/serve/{id}` (plugin route). Runs `authCallable` first;
  denial is whatever the callable throws (this app throws `RedirectException`
  to its admin login).
- Soft delete: `deleted` boolean + `find('active')`/`find('trashed')`
  finders on `StoredFilesTable`.

## Gotchas & design notes

- Lifecycle rules (row-before-file, trash semantics, no client input in
  paths) and the reasoning behind every decision, plus the v1 E2E
  verification record: [`docs/file-management-and-uploads.md`](docs/file-management-and-uploads.md)
  and [`docs/decisions.md`](docs/decisions.md). Those files are the record —
  this README says how, they say why.
- Known gotchas with workarounds (operator-caching fingerprint, streaming
  response pattern, MIME-based path generation) live in
  `docs/decisions.md` too.
- The admin file browser is **app-side** (this repo's
  `templates/Admin/file_browser.php`), not plugin-shipped — visible admin UI
  stays in the host app per the admin/plugin split.

## Tests

Plugin tests run through the host app suite (`composer check` in the
consuming app): `FileStoreTest`, `FilesServeTest`, `FilesBrowserTest`.
They need a backend root writeable by the test process; the default local
backend points at the host's `data/uploads` root.