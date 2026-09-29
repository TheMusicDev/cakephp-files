<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * The shared file ledger. Soft delete is managed explicitly by
 * `FileStore::delete()` (the `deleted` column) — files stay on disk while a
 * row is trashed; only `FileStore::purge()` removes row + file together.
 */
class StoredFilesTable extends Table
{
    /** Declare the shared ledger table and its timestamp behavior. */
    public function initialize(array $config): void
    {
        $this->setTable('stored_files');
        $this->setDisplayField('original_filename');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /** Required fields written only by `FileStore::put()`. */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->requirePresence('path', 'create')
            ->requirePresence('original_filename', 'create')
            ->requirePresence('mime', 'create')
            ->requirePresence('size', 'create')
            ->requirePresence('collection', 'create')
            ->notEmptyString('path')
            ->notEmptyString('original_filename')
            ->notEmptyString('mime')
            ->notEmptyString('collection')
            ->nonNegativeInteger('size'); // zero-byte rows exist transiently (row-before-file), not permanently

        return $validator;
    }

    /**
     * Rows not yet trashed — the default view everywhere.
     *
     * @param \Cake\ORM\Query\SelectQuery<\TheMusicDev\Files\Model\Entity\StoredFile> $query
     * @return \Cake\ORM\Query\SelectQuery<\TheMusicDev\Files\Model\Entity\StoredFile>
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where(['deleted IS' => null]);
    }

    /**
     * Trashed rows, for trash/restore views.
     *
     * @param \Cake\ORM\Query\SelectQuery<\TheMusicDev\Files\Model\Entity\StoredFile> $query
     * @return \Cake\ORM\Query\SelectQuery<\TheMusicDev\Files\Model\Entity\StoredFile>
     */
    public function findTrashed(SelectQuery $query): SelectQuery
    {
        return $query->where(['deleted IS NOT' => null]);
    }

    /**
     * Collections currently in use, with active counts — drives the admin
     * file browser's folder list.
     *
     * @return array<string, int>
     */
    public function collectionCounts(): array
    {
        $rows = $this->find('active')
            ->select(['collection', 'count' => $this->find()->func()->count('*')])
            ->groupBy('collection')
            ->orderBy('collection')
            ->all();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row->get('collection')] = (int)$row->get('count');
        }

        return $counts;
    }
}
