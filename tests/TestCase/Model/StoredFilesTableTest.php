<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\TestCase\Model;

use PDOException;
use TheMusicDev\Files\Lib\FileStore;
use TheMusicDev\Files\Test\TestCase\FilesTestCase;

final class StoredFilesTableTest extends FilesTestCase
{
    public function testActiveAndTrashedFindersSplitOnTheDeletedColumn(): void
    {
        $store = new FileStore();
        $keep = $this->storePdf();
        $trash = $this->storePdf();
        $store->delete($store->get($trash));
        $table = $store->storedFiles();

        $this->assertSame([$keep], $table->find('active')->all()->extract('id')->toList());
        $this->assertSame([$trash], $table->find('trashed')->all()->extract('id')->toList());
        $this->assertSame(2, $table->find()->count());
    }

    public function testCollectionCountsCountActiveRowsPerCollection(): void
    {
        $store = new FileStore();
        $this->storePdf('uploads.misc');
        $this->storePdf('uploads.misc');
        $this->storePdf('open.pdf');
        $this->storePdf('open.pdf');
        $gone = $this->storePdf('open.pdf');
        $store->delete($store->get($gone));

        $this->assertSame(['open.pdf' => 2, 'uploads.misc' => 2], $store->storedFiles()->collectionCounts());
    }

    public function testCollectionCountsAreEmptyForAnEmptyLedger(): void
    {
        $this->assertSame([], (new FileStore())->storedFiles()->collectionCounts());
    }

    public function testTheLedgerRequiresItsFields(): void
    {
        $table = (new FileStore())->storedFiles();

        $errors = $table->newEntity([])->getErrors();

        $this->assertEqualsCanonicalizing(
            ['path', 'original_filename', 'mime', 'size', 'collection'],
            array_keys($errors),
        );
    }

    public function testThePathIsUnique(): void
    {
        $table = (new FileStore())->storedFiles();
        $data = ['path' => 'abc.pdf', 'original_filename' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1,
            'collection' => 'uploads.misc'];
        $this->assertNotFalse($table->save($table->newEntity($data)));

        $this->expectException(PDOException::class);
        $table->save($table->newEntity($data));
    }
}
