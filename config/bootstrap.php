<?php
declare(strict_types=1);

use Cake\Core\Configure;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Plugin config bootstrap (auto-required by BasePlugin::bootstrap()).
 * Merges ship-with defaults UNDER the host's `Files.*` values — anything
 * the host or a consumer wrote wins (shallow replace per sub-key, hosts
 * keep their backend definitions and collections untouched).
 */
/**
 * @var array{backend: string, backends: array<string, mixed>, collections: array<string, mixed>, authCallable: mixed} $defaults
 */
$defaults = (require __DIR__ . '/app_default.php')['Files'];
/** @param string $key @return array<string, mixed> */
$host = static fn (string $key): array => (array)Configure::read('Files.' . $key, []);

Configure::write('Files.backends', $host('backends') + $defaults['backends']);
if ((string)Configure::read('Files.backend') === '') {
    Configure::write('Files.backend', $defaults['backend']);
}
Configure::write('Files.collections', $host('collections') + $defaults['collections']);
if (!is_callable(Configure::read('Files.authCallable'))) {
    Configure::write('Files.authCallable', $defaults['authCallable']);
}

// Create the local backend's storage root on first run. Remote backends
// don't need it, so this only touches disk for the local default.
$definition = (array)Configure::read('Files.backends.' . (string)Configure::read('Files.backend'), []);
$root = (string)($definition['root'] ?? '');
if (
    ($definition['class'] ?? null) === LocalFilesystemAdapter::class
    && $root !== ''
    && !is_dir($root)
) {
    mkdir($root, 0770, true); // ponytail: best-effort; failures surface on the first put()
}