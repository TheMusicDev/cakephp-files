<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Lib;

use RuntimeException;

/** Every intake/validation failure in FileStore carries a user-presentable message. */
class FileStoreException extends RuntimeException
{
}
