<?php
declare(strict_types=1);

namespace TheMusicDev\Files;

use Cake\Core\BasePlugin;

/**
 * TheMusicDev/Files — reusable uploads + file management. Flysystem v3
 * storage, a `stored_files` ledger, collections (virtual dirs), gated
 * serving. Design of record: docs/file-management-and-uploads.md.
 *
 * Configuration lives in config/app_default.php + config/bootstrap.php
 * (BasePlugin auto-requires the latter — no bootstrap() override needed),
 * merging defaults UNDER the host's `Files.*` values.
 */
final class FilesPlugin extends BasePlugin
{
    /**
     * Ship the serving route so hosts mount it by just loading the plugin.
     */
    protected bool $routesEnabled = true;
}
