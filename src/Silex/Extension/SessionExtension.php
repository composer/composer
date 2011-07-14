<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex\Extension;

use Silex\Application;
use Silex\ExtensionInterface;

use Symfony\Component\HttpFoundation\SessionStorage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session;
use Symfony\Component\HttpKernel\KernelEvents;

class SessionExtension implements ExtensionInterface
{
    private $app;

    public function register(Application $app)
    {
        $this->app = $app;

        $app['session'] = $app->share(function () use ($app) {
            return new Session($app['session.storage']);
        });

        $app['session.storage'] = $app->share(function () use ($app) {
            return new NativeSessionStorage($app['session.storage.options']);
        });

        $app['dispatcher']->addListener(KernelEvents::REQUEST, array($this, 'onKernelRequest'), -255);

        if (!isset($app['session.storage.options'])) {
            $app['session.storage.options'] = array();
        }
    }

    public function onKernelRequest($event)
    {
        $request = $event->getRequest();
        $request->setSession($this->app['session']);

        // starts the session if a session cookie already exists in the request...
        if ($request->hasSession()) {
            $request->getSession()->start();
        }
    }
}
