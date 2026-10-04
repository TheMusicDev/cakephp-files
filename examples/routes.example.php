<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

/**
 * EXAMPLE routes for FilesAdminController: merge into your own config/routes.php.
 */
return static function (RouteBuilder $routes): void {
    $routes->scope('/admin/files', static function (RouteBuilder $builder): void {
        $builder->connect('/', ['controller' => 'FilesAdmin', 'action' => 'index'], ['_name' => 'files.admin']);
        $builder->connect('/c/{collection}', ['controller' => 'FilesAdmin', 'action' => 'index'])
            ->setPatterns(['collection' => '[A-Za-z0-9._-]+'])
            ->setPass(['collection']);
        $builder->connect('/upload', ['controller' => 'FilesAdmin', 'action' => 'upload']);
        $builder->connect('/delete/{id}', ['controller' => 'FilesAdmin', 'action' => 'delete'])
            ->setPatterns(['id' => '\d+'])
            ->setPass(['id']);
    });
};
