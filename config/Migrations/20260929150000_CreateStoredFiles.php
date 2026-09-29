<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The shared file ledger (docs/file-management-and-uploads.md). One row per
 * file that ever enters the store: opaque server-generated path, persisted
 * original filename, finfo-verified MIME, size, collection (virtual dir).
 */
class CreateStoredFiles extends BaseMigration
{
    public function change(): void
    {
        $this->table('stored_files')
            ->addColumn('path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('original_filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('size', 'integer', ['null' => false])
            ->addColumn('collection', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('deleted', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex('path', ['unique' => true])
            ->addIndex('collection')
            ->addIndex('deleted')
            ->create();
    }
}