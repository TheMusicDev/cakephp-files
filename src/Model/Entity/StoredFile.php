<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Model\Entity;

use Cake\ORM\Entity;

/**
 * One row of the shared file ledger. `path` is an opaque server-generated
 * path inside the storage root — never client input. The original filename
 * is persisted because serving needs the real name (a bug in the monorepo's
 * apply.php: it lost it and guessed on download).
 */
class StoredFile extends Entity
{
    protected array $_accessible = [
        'path' => true,
        'original_filename' => true,
        'mime' => true,
        'size' => true,
        'collection' => true,
        'deleted' => true,
    ];
}
