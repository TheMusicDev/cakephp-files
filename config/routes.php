<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $builder): void {
    // Serving route: /files/serve/{id} — browser URLs live in the app
    // (/admin/files*); this route ships with the plugin so every host
    // mounts gated serving by just loading the plugin.
    $builder->plugin('TheMusicDev/Files', ['path' => '/files'], static function (RouteBuilder $routes): void {
        $routes->get('/serve/{id}', ['controller' => 'Dl', 'action' => 'serve'])
            ->setPatterns(['id' => '\d+'])
            ->setPass(['id']);
    });
};
