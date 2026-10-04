<?php
declare(strict_types=1);

namespace App\Controller;

use TheMusicDev\Files\Lib\FileStore;
use TheMusicDev\Files\Lib\FileStoreException;

/**
 * EXAMPLE admin file browser for TheMusicDev/Files. Copy into your app; the plugin does not load it.
 *
 * Everything goes through the FileStore seam; the controller never touches the filesystem.
 */
class FilesAdminController extends AppController
{
    /**
     * Your admin gate goes here, and the same check belongs in the `Files.authCallable` config so the files
     * themselves (/files/serve/{id}) are protected, not just this screen.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event
     * @return void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);
        // e.g. throw new \Cake\Http\Exception\ForbiddenException() unless the user is an admin.
    }

    /**
     * Folder list (no argument) or one collection's files with the upload form.
     *
     * @param string|null $collection Registered collection name.
     * @return void
     */
    public function index(?string $collection = null): void
    {
        $collections = FileStore::collections();
        /** @var \TheMusicDev\Files\Model\Table\StoredFilesTable $storedFiles */
        $storedFiles = $this->fetchTable('TheMusicDev/Files.StoredFiles');

        if ($collection !== null) {
            if (!isset($collections[$collection])) {
                $this->Flash->error("Unknown collection \"{$collection}\".");
                $this->redirect(['action' => 'index']);

                return;
            }

            $this->set('rows', $this->paginate($storedFiles->find('active')->where(['collection' => $collection])))
                ->set('collection', ['name' => $collection] + $collections[$collection]);
        }

        $this->set('collections', $collections)->set('counts', $storedFiles->collectionCounts());
    }

    /**
     * POST: store an upload into one collection.
     *
     * @return void
     */
    public function upload(): void
    {
        $this->request->allowMethod('post');
        $collection = (string)$this->request->getData('collection');
        $file = $this->request->getUploadedFile('file');

        try {
            if ($file === null || (string)$file->getClientFilename() === '') {
                throw new FileStoreException('Choose a file to upload.');
            }
            FileStore::instance()->put($file, $collection); // validates the collection, MIME and size
            $this->Flash->success('Uploaded.');
        } catch (FileStoreException $e) {
            $this->Flash->error($e->getMessage());
        }

        $this->redirect(['action' => 'index', $collection]);
    }

    /**
     * POST: soft delete. The row is trashed, the file is kept until FileStore::purge().
     *
     * @param string $id Ledger id.
     * @return void
     */
    public function delete(string $id): void
    {
        $this->request->allowMethod('post');
        $store = FileStore::instance();
        try {
            $store->delete($store->get((int)$id));
            $this->Flash->success('Moved to trash (the file is kept until purge).');
        } catch (FileStoreException) {
            $this->Flash->error('No such file.');
        }

        $back = (string)$this->request->getData('collection');
        $this->redirect($back !== '' ? ['action' => 'index', $back] : ['action' => 'index']);
    }
}
