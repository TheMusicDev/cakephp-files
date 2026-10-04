<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\TestCase\Lib;

use Cake\Core\Configure;
use Laminas\Diactoros\UploadedFile;
use League\Flysystem\Local\LocalFilesystemAdapter;
use TheMusicDev\Files\Lib\FileStore;
use TheMusicDev\Files\Lib\FileStoreException;
use TheMusicDev\Files\Model\Entity\StoredFile;
use TheMusicDev\Files\Test\TestCase\FilesTestCase;

/**
 * The FileStore seam: the rules the design fixes must actually hold: row-before-file, trash keeps files, purge
 * deletes them, sniffed MIME, caps.
 */
final class FileStoreTest extends FilesTestCase
{
    public function testPutStoresRowAndFileWithPersistedOriginalName(): void
    {
        $store = new FileStore();
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\nrésumé payload\n";
        $row = $store->put($this->upload($pdf, 'résumé.pdf'), 'uploads.misc');

        $this->assertSame('résumé.pdf', $row->get('original_filename'));
        $this->assertSame('uploads.misc', $row->get('collection'));
        $this->assertSame('application/pdf', $row->get('mime'));
        $this->assertSame(strlen($pdf), $row->get('size'));
        $this->assertSame(32 + 4, strlen((string)$row->get('path'))); // 16-byte hex + .pdf
        $this->assertTrue($store->exists($row));
        $this->assertSame($pdf, $store->read($row));
    }

    public function testPutNeverUsesTheClientNameInThePath(): void
    {
        $row = (new FileStore())->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\nx\n", '../../evil.php'), 'uploads.misc');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string)$row->get('path'));
        $this->assertSame('../../evil.php', $row->get('original_filename'));
    }

    public function testPutTrustsTheContentsNotTheClientDeclaredType(): void
    {
        $upload = $this->upload('just some plain text', 'looks-like.pdf'); // declared application/pdf
        $row = (new FileStore())->put($upload, 'uploads.misc');

        $this->assertSame('text/plain', $row->get('mime'));
        $this->assertStringEndsWith('.txt', (string)$row->get('path'));
    }

    public function testPutRejectsUnknownCollection(): void
    {
        $this->expectException(FileStoreException::class);
        (new FileStore())->put($this->upload("%PDF-1.4\n"), 'no.such');
    }

    public function testPutRejectsDisallowedMime(): void
    {
        $this->expectException(FileStoreException::class);
        $this->expectExceptionMessage('not allowed');
        (new FileStore())->put($this->upload('plain note', 'note.txt'), 'open.pdf');
    }

    public function testPutRejectsEmptyUpload(): void
    {
        $this->expectException(FileStoreException::class);
        (new FileStore())->put($this->upload(''), 'uploads.misc');
    }

    public function testPutRejectsAFailedUpload(): void
    {
        $upload = new UploadedFile('', 0, UPLOAD_ERR_NO_FILE, 'x.pdf', 'application/pdf');

        $this->expectException(FileStoreException::class);
        (new FileStore())->put($upload, 'uploads.misc');
    }

    public function testPutRejectsOversize(): void
    {
        // docs.pdf caps at 2 bytes: any real PDF exceeds it.
        $this->expectException(FileStoreException::class);
        $this->expectExceptionMessage('size limit');
        (new FileStore())->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n"), 'docs.pdf');
    }

    public function testARejectedUploadLeavesNoRowAndNoFile(): void
    {
        $store = new FileStore();
        try {
            $store->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\n"), 'docs.pdf');
        } catch (FileStoreException) {
        }

        $this->assertSame(0, $store->storedFiles()->find()->count());
        $this->assertSame([], iterator_to_array($store->filesystem()->listContents('/')));
    }

    public function testDeleteSoftKeepsFilePurgeRemovesBoth(): void
    {
        $store = new FileStore();
        $row = $store->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\ntrash test\n"), 'uploads.misc');
        $path = (string)$row->get('path');

        $this->assertTrue($store->filesystem()->has($path));
        $store->delete($row); // trash keeps files
        $this->assertTrue($store->filesystem()->has($path));
        $this->assertNull($store->storedFiles()->find('active')->where(['id' => $row->get('id')])->first());
        $trashed = $store->storedFiles()->find('trashed')->where(['id' => $row->get('id')])->first();
        $this->assertInstanceOf(StoredFile::class, $trashed);

        $store->purge($trashed);
        $this->assertFalse($store->filesystem()->has($path));
        $this->assertNull($store->storedFiles()->find()->where(['id' => $row->get('id')])->first());
    }

    public function testPurgeSurvivesMissingBackendFile(): void
    {
        $store = new FileStore();
        $row = $store->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\npurge missing\n"), 'uploads.misc');
        $store->filesystem()->delete((string)$row->get('path'));

        $store->purge($store->storedFiles()->find()->where(['id' => $row->get('id')])->firstOrFail());
        $this->assertNull($store->storedFiles()->find()->where(['id' => $row->get('id')])->first());
    }

    public function testExtensionComesFromMimeOnly(): void
    {
        $store = new FileStore();
        $this->assertSame('pdf', $store->extension('application/pdf'));
        $this->assertSame('bin', $store->extension('application/x-unknown'));
        $this->assertSame('application/pdf', $store->normalizeMime('application/x-pdf'));
    }

    public function testGetRejectsTrashed(): void
    {
        $store = new FileStore();
        $row = $store->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\nget test\n"), 'uploads.misc');
        $this->assertSame($row->get('id'), $store->get((int)$row->get('id'))->get('id'));

        $store->delete($row);
        $this->expectException(FileStoreException::class);
        $store->get((int)$row->get('id'));
    }

    public function testGetRejectsAnUnknownId(): void
    {
        $this->expectException(FileStoreException::class);
        (new FileStore())->get(999999);
    }

    public function testCollectionsAreNormalized(): void
    {
        $collections = FileStore::collections();
        $this->assertSame(['application/pdf'], $collections['docs.pdf']['mimes']);
        $this->assertFalse($collections['docs.pdf']['public']);
        $this->assertTrue($collections['open.pdf']['public']);
        $this->assertSame([], $collections['uploads.misc']['mimes']);
        $this->assertSame('Downloads', $collections['uploads.misc']['label']);
    }

    public function testACollectionWithoutALabelOrSizeGetsDefaults(): void
    {
        Configure::write('Files.collections', ['bare.thing' => []]);

        $this->assertSame(
            ['label' => 'bare.thing', 'mimes' => [], 'size' => 25 * 1024 * 1024, 'public' => false],
            FileStore::collections()['bare.thing'],
        );
    }

    public function testTheOperatorFollowsTheConfiguredBackendDefinition(): void
    {
        $store = new FileStore();
        $row = $store->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\nfirst root\n"), 'uploads.misc');
        $this->assertTrue($store->filesystem()->has((string)$row->get('path')));

        // Repointing the same key at another root must not keep serving the old operator.
        Configure::write('Files.backends', [
            'test' => ['class' => LocalFilesystemAdapter::class, 'root' => $this->root . '-other'],
        ]);
        $this->assertFalse($store->filesystem()->has((string)$row->get('path')));
    }

    public function testAnUnconfiguredBackendThrows(): void
    {
        Configure::write('Files.backend', 'nope');

        $this->expectException(FileStoreException::class);
        (new FileStore())->filesystem();
    }

    public function testInstanceIsShared(): void
    {
        $this->assertSame(FileStore::instance(), FileStore::instance());
    }
}
