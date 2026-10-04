<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\TestCase;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;
use League\Flysystem\Local\LocalFilesystemAdapter;
use TheMusicDev\Files\Lib\FileStore;

/**
 * Shared setup: an isolated temp storage root, known collections and an empty ledger.
 */
abstract class FilesTestCase extends TestCase
{
    protected string $root;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/files-plugin-test-' . bin2hex(random_bytes(4));
        Configure::write('Files.backends', [
            'test' => ['class' => LocalFilesystemAdapter::class, 'root' => $this->root],
        ]);
        Configure::write('Files.backend', 'test');
        Configure::write('Files.collections', [
            'uploads.misc' => ['label' => 'Downloads', 'mimes' => [], 'size' => 25 * 1024 * 1024],
            'docs.pdf' => ['label' => 'PDFs', 'mimes' => ['application/pdf'], 'size' => 2],
            'open.pdf' => ['label' => 'Public PDFs', 'mimes' => ['application/pdf'], 'size' => 1024, 'public' => true],
        ]);
        Configure::write('Files.authCallable', null);
        $this->clearLedger();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->clearLedger();
        parent::tearDown();
    }

    /**
     * @return void
     */
    protected function clearLedger(): void
    {
        TableRegistry::getTableLocator()->get('TheMusicDev/Files.StoredFiles')->deleteAll([]);
    }

    /**
     * An in-memory PSR-7 upload whose stream really holds $contents.
     *
     * @param string $contents File bytes.
     * @param string $name Client filename.
     * @return \Laminas\Diactoros\UploadedFile
     */
    protected function upload(string $contents, string $name = 'file.pdf'): UploadedFile
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $contents);
        rewind($stream);

        return new UploadedFile($stream, strlen($contents), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    /**
     * Store a small PDF through the seam and return its ledger id.
     *
     * @param string $collection Collection to store into.
     * @param string $name Client filename.
     * @return int
     */
    protected function storePdf(string $collection = 'uploads.misc', string $name = 'doc.pdf'): int
    {
        $row = (new FileStore())->put($this->upload("%PDF-1.4\n%\xe2\xe3\xcf\xd3\nhello serve\n", $name), $collection);

        return (int)$row->get('id');
    }
}
