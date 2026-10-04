<?php
declare(strict_types=1);

use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * TheMusicDev/Files ship-with defaults. The plugin's config/bootstrap.php
 * merges these UNDER whatever the host wrote into `Files.*` — hosts win,
 * consumers only need to override what they differ on.
 */
return [
    'Files' => [
        // Active backend key, resolved against `Files.backends`.
        'backend' => 'local',
        'backends' => [
            'local' => [
                'class' => LocalFilesystemAdapter::class,
                'root' => ROOT . DS . 'data' . DS . 'uploads',
            ],
        ],
        'collections' => [
            // The one generic collection every host gets for free —
            // ad-hoc uploads that need no registration.
            'uploads.misc' => [
                'label' => 'Downloads',
                'mimes' => [],
                'size' => 25 * 1024 * 1024,
            ],
        ],
        // Serving gate (`Files.authCallable`): the host's seam. Default
        // null means files are never served without the host opting in.
        'authCallable' => null,
    ],
];
