<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Lib;

use Cake\Core\Configure;
use Cake\Datasource\FactoryLocator;
use Cake\Log\Log;
use DateTime;
use finfo;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use TheMusicDev\Files\Model\Entity\StoredFile;
use TheMusicDev\Files\Model\Table\StoredFilesTable;

/**
 * The integration seam: everything that stores or reads files goes through
 * this class — never through the filesystem APIs, never through disk paths.
 * Backends are Flysystem operators resolved from
 * `Files.backend` → `Files.backends` config, so swapping to S3 later is a
 * composer package + one config block.
 *
 * Lifecycle rules enforced here (docs/file-management-and-uploads.md):
 * row-before-file (a failed write leaves a visible "file missing" row, never
 * an orphan binary), trash keeps files, purge deletes them.
 */
final class FileStore
{
    private static ?FileStore $instance = null;

    private ?FilesystemOperator $filesystem = null;

    private ?string $backendKey = null;

    /** Shared handle for controllers/consumers that don't build their own. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Registered collections, normalized: name => {label, mimes, size,
     * public}. `mimes` empty means "any MIME".
     *
     * @return array<string, array{label: string, mimes: list<string>, size: int, public: bool}>
     */
    public static function collections(): array
    {
        $out = [];
        foreach ((array)Configure::read('Files.collections', []) as $name => $definition) {
            $out[(string)$name] = [
                'label' => (string)($definition['label'] ?? $name),
                'mimes' => array_values(array_map('strval', (array)($definition['mimes'] ?? []))),
                'size' => (int)($definition['size'] ?? 25 * 1024 * 1024),
                'public' => (bool)($definition['public'] ?? false),
            ];
        }

        return $out;
    }

    /** The active backend, resolved + cached (re-resolved if Files.backend changes). */
    public function filesystem(): FilesystemOperator
    {
        $key = (string)Configure::read('Files.backend');
        $definition = (array)Configure::read('Files.backends.' . $key, []);
        // Cache the operator per backend *definition*, not per key — hosts
        // (and tests) repoint the same key at different roots.
        $fingerprint = $key . '|' . json_encode($definition);
        if ($this->filesystem !== null && $this->backendKey === $fingerprint) {
            return $this->filesystem;
        }

        if ($definition === [] || (string)($definition['class'] ?? '') === '') {
            throw new FileStoreException("Storage backend \"{$key}\" is not configured.");
        }

        $adapterClass = (string)$definition['class'];
        /** @var \League\Flysystem\FilesystemAdapter $adapter */
        $adapter = new $adapterClass(...array_values($this->adapterArgs($adapterClass, $definition)));
        $this->filesystem = new Filesystem($adapter);
        $this->backendKey = $fingerprint;

        return $this->filesystem;
    }

    /**
     * Named args for known adapter classes.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function adapterArgs(string $adapterClass, array $definition): array
    {
        if ($adapterClass === LocalFilesystemAdapter::class) {
            return ['root' => (string)($definition['root'] ?? (ROOT . DS . 'data' . DS . 'uploads'))];
        }

        return []; // ponytail: remote adapters get their args when one is actually used
    }

    /**
     * Validate + store an upload. Returns the ledger row. Throws
     * FileStoreException with a user-presentable message on any intake
     * failure. Reads the whole upload into memory — capped by every
     * collection's size limit, so bounded. // ponytail: stream to disk
     * instead if a collection ever needs >100 MB.
     *
     * @param \Psr\Http\Message\UploadedFileInterface $upload PSR-7 upload (request->getFileUpload()).
     * @param string $collection Registered collection dot-path.
     * @param string|null $originalName Override the client-supplied name (tests, curation).
     */
    public function put(UploadedFileInterface $upload, string $collection, ?string $originalName = null): StoredFile
    {
        $collections = self::collections();
        if (!isset($collections[$collection])) {
            throw new FileStoreException("Unknown collection \"{$collection}\".");
        }
        $definition = $collections[$collection];

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new FileStoreException('The file upload failed — nothing arrived.');
        }

        /** ponytail: whole-file read; swap to writeStream if a collection outgrows memory. */
        $contents = (string)$upload->getStream()->getContents();
        if ($contents === '') {
            // Zero-byte / placeholder files are surfaced, not silently
            // dropped (apply.php learned this the hard way).
            throw new FileStoreException('Received an empty file (0 bytes).');
        }

        $mime = $this->sniff($contents);
        if ($definition['mimes'] !== [] && !in_array($mime, $definition['mimes'], true)) {
            throw new FileStoreException("File type \"{$mime}\" is not allowed in \"{$collection}\".");
        }
        if (strlen($contents) > $definition['size']) {
            throw new FileStoreException('The file exceeds the size limit for this collection.');
        }

        $path = bin2hex(random_bytes(16)) . '.' . $this->extension($mime);
        $original = (string)($originalName ?? $upload->getClientFilename() ?? 'unnamed.' . $this->extension($mime));
        if ($original === '') {
            $original = 'unnamed.' . $this->extension($mime);
        }

        // Row-before-file: persist the ledger row first. If the write below
        // fails, the row survives as a visible "file missing" entry —
        // known-recoverable, unlike an orphan binary already on disk.
        /** @var \TheMusicDev\Files\Model\Table\StoredFilesTable $storedFiles */
        $storedFiles = $this->storedFiles();

        $row = $storedFiles->newEntity([
            'path' => $path,
            'original_filename' => $original,
            'mime' => $mime,
            'size' => strlen($contents),
            'collection' => $collection,
        ]);
        if (!$row instanceof StoredFile) {
            throw new FileStoreException('Could not save the file metadata.');
        }
        if (!$storedFiles->save($row) || $row->get('id') === null) {
            throw new FileStoreException('Could not save the file metadata.');
        }

        try {
            $this->filesystem()->write($path, $contents);
        } catch (RuntimeException $e) {
            Log::error('Files plugin: ledger row saved but file write failed: ' . $e->getMessage());

            throw $e; // row remains as "file missing"; no silent orphan binary
        }

        return $row;
    }

    /** Server-side MIME sniffing (finfo) — never the client-declared type. */
    public function sniff(string $contents): string
    {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if ($mime === '') {
            throw new FileStoreException('Could not determine the file type.');
        }

        return $this->normalizeMime($mime);
    }

    /** Fold finfo's occasional `x-` aliases onto the canonical types. */
    public function normalizeMime(string $mime): string
    {
        return $mime === 'application/x-pdf' ? 'application/pdf' : $mime;
    }

    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/zip' => 'zip',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/json' => 'json',
    ];

    /** Extension comes ONLY from the verified MIME (rule: no client input in paths). */
    public function extension(string $mime): string
    {
        return self::EXTENSIONS[$mime] ?? 'bin';
    }

    /** The row for a file id, active (not trashed) only. */
    public function get(int $id): StoredFile
    {
        $row = $this->storedFiles()->find('active')->where(['id' => $id])->first();
        if ($row === null) {
            throw new FileStoreException("No file with id {$id} is stored.");
        }
        if (!$row instanceof StoredFile) {
            throw new FileStoreException("File id {$id} is not a stored-file row.");
        }

        return $row;
    }

    /** Does the binary still exist on the backend? */
    public function exists(StoredFile $file): bool
    {
        return $this->filesystem()->has($file->get('path'));
    }

    /** Full contents (bounded by the collection size cap). */
    public function read(StoredFile $file): string
    {
        return (string)$this->filesystem()->read($file->get('path'));
    }

    /**
     * Soft-delete: the ledger row is trashed, the binary stays — trash keeps
     * files, restore keeps working.
     */
    public function delete(StoredFile $file): void
    {
        $file->set('deleted', new DateTime());
        /** @var \TheMusicDev\Files\Model\Table\StoredFilesTable $storedFiles */
        $storedFiles = $this->storedFiles();
        $storedFiles->save($file);
    }

    /**
     * Hard delete: remove row AND file. The only path that touches the
     * backend's delete is called from the admin trash purge.
     */
    public function purge(StoredFile $file): void
    {
        try {
            $this->filesystem()->delete($file->get('path'));
        } catch (RuntimeException $e) {
            // File already gone from the backend — purge finishes the ledger row either way.
        }
        $this->storedFiles()->delete($file);
    }

    /** A shared instance of the ledger table. */
    public function storedFiles(): StoredFilesTable
    {
        /** @var \TheMusicDev\Files\Model\Table\StoredFilesTable $table */
        $table = FactoryLocator::get('Table')->get('TheMusicDev/Files.StoredFiles');

        return $table;
    }
}
