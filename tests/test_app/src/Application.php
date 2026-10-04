<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;

/**
 * The smallest application that installs the plugin the way a host does.
 */
class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        $this->addPlugin('TheMusicDev/Files', ['path' => ROOT . DS]);
    }

    /**
     * @inheritDoc
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error', [])))
            ->add(new RoutingMiddleware($this));
    }
}
