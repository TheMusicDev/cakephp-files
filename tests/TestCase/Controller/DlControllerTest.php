<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\RedirectException;
use Cake\TestSuite\IntegrationTestTrait;
use TheMusicDev\Files\Lib\FileStore;
use TheMusicDev\Files\Model\Entity\StoredFile;
use TheMusicDev\Files\Test\TestCase\FilesTestCase;

/**
 * Gated serving: /files/serve/{id}. The host's `Files.authCallable` decides access.
 */
final class DlControllerTest extends FilesTestCase
{
    use IntegrationTestTrait;

    public function testServeIsForbiddenWhenTheHostSetNoGate(): void
    {
        $id = $this->storePdf();

        $this->get('/files/serve/' . $id);

        $this->assertResponseCode(403);
    }

    public function testTheGateCanRefuseWithARedirect(): void
    {
        $id = $this->storePdf();
        Configure::write('Files.authCallable', static function (StoredFile $file): void {
            throw new RedirectException('/somewhere/login');
        });

        $this->get('/files/serve/' . $id);

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/somewhere/login');
    }

    public function testTheGateReceivesTheStoredRow(): void
    {
        $id = $this->storePdf('uploads.misc', 'mine.pdf');
        $seen = [];
        Configure::write('Files.authCallable', static function (StoredFile $file) use (&$seen): void {
            $seen = [$file->get('id'), $file->get('original_filename'), $file->get('collection')];
        });

        $this->get('/files/serve/' . $id);

        $this->assertSame([$id, 'mine.pdf', 'uploads.misc'], $seen);
    }

    public function testServeStreamsFileWithMimeAndDisposition(): void
    {
        $id = $this->storePdf();
        Configure::write('Files.authCallable', static function (StoredFile $file): void {
        });

        $this->get('/files/serve/' . $id);

        $this->assertResponseOk();
        $this->assertResponseContains('%PDF-1.4');
        $this->assertHeader('Content-Type', 'application/pdf');
        $this->assertHeader('Content-Disposition', 'attachment; filename="doc.pdf"; filename*=UTF-8\'\'doc.pdf');
        $this->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testServeFilenameRfc5987EscapesNonAscii(): void
    {
        $id = $this->storePdf('uploads.misc', 'résumé.pdf');
        Configure::write('Files.authCallable', static function (StoredFile $file): void {
        });

        $this->get('/files/serve/' . $id);

        $this->assertResponseOk();
        $this->assertHeader(
            'Content-Disposition',
            'attachment; filename="r__sum__.pdf"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.pdf',
        );
    }

    public function testAPublicCollectionBypassesTheGate(): void
    {
        $id = $this->storePdf('open.pdf');
        $called = false;
        Configure::write('Files.authCallable', static function (StoredFile $file) use (&$called): void {
            $called = true;
        });

        $this->get('/files/serve/' . $id);

        $this->assertResponseOk();
        $this->assertFalse($called);
    }

    public function testAPublicCollectionIsServedEvenWithoutAGate(): void
    {
        $id = $this->storePdf('open.pdf');

        $this->get('/files/serve/' . $id);

        $this->assertResponseOk();
    }

    public function testServeUnknownIdReturns404(): void
    {
        $this->get('/files/serve/999999');

        $this->assertResponseCode(404);
    }

    public function testServeNonNumericIdIsNotARoute(): void
    {
        $this->get('/files/serve/abc');

        $this->assertResponseCode(404);
    }

    public function testATrashedFileIsNotServed(): void
    {
        $id = $this->storePdf('open.pdf');
        $store = new FileStore();
        $store->delete($store->get($id));

        $this->get('/files/serve/' . $id);

        $this->assertResponseCode(404);
    }

    public function testServeMissingBackendFileReturns404(): void
    {
        $id = $this->storePdf('open.pdf');
        $store = new FileStore();
        $store->filesystem()->delete((string)$store->get($id)->get('path'));

        $this->get('/files/serve/' . $id);

        $this->assertResponseCode(404);
    }
}
