<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Laminas\Diactoros\Stream;
use League\Flysystem\FilesystemException;
use TheMusicDev\Files\Lib\FileStore;
use TheMusicDev\Files\Lib\FileStoreException;
use TheMusicDev\Files\Model\Entity\StoredFile;

/**
 * Serves files — always through application routes, never disk hrefs.
 * The host supplies the gate: `Files.authCallable` receives the row and may
 * throw (e.g. RedirectException for a session gate) to refuse. Collections
 * with `public => true` bypass the callable (reserved; none in v1).
 */
class DlController extends Controller
{
    /** Stream a stored file's binary with its persisted filename + snuffed mime. */
    public function serve(string $id = ''): ?Response
    {
        $store = FileStore::instance();
        try {
            /** @var \TheMusicDev\Files\Model\Entity\StoredFile $file */
            $file = $store->get((int)$id);
        } catch (FileStoreException) {
            throw new NotFoundException('File not found.');
        }

        $collections = FileStore::collections();
        if (!($collections[$file->get('collection')]['public'] ?? false)) {
            $callable = Configure::read('Files.authCallable');
            if (!is_callable($callable)) {
                throw new ForbiddenException('Files are not publicly served.');
            }
            $callable($file);
        }

        try {
            $stream = $store->filesystem()->readStream($file->get('path'));
        } catch (FilesystemException) {
            throw new NotFoundException('File content is missing.');
        }

        // Cake's response has no withStreamedBody(); a PSR-7 Stream body works.
        $body = new Stream($stream);

        return $this->response->withType($file->get('mime'))
            ->withHeader('Content-Disposition', $this->dispositionHeader($file))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($body);
    }

    /** ASCII fallback + RFC 5987 filename* so Unicode names survive. */
    private function dispositionHeader(StoredFile $file): string
    {
        $name = (string)$file->get('original_filename');
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
