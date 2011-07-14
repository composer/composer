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

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Silex\Application;
use Silex\ExtensionInterface;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MonologExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['monolog'] = $app->share(function () use ($app) {
            $log = new Logger(isset($app['monolog.name']) ? $app['monolog.name'] : 'myapp');

            $app['monolog.configure']($log);

            return $log;
        });

        $app['monolog.configure'] = $app->protect(function ($log) use ($app) {
            $log->pushHandler($app['monolog.handler']);
        });

        $app['monolog.handler'] = function () use ($app) {
            return new StreamHandler($app['monolog.logfile'], $app['monolog.level']);
        };

        if (!isset($app['monolog.level'])) {
            $app['monolog.level'] = function () {
                return Logger::DEBUG;
            };
        }

        if (isset($app['monolog.class_path'])) {
            $app['autoloader']->registerNamespace('Monolog', $app['monolog.class_path']);
        }

        $app->before(function () use ($app) {
            $app['monolog']->addInfo($app['request']->getMethod().' '.$app['request']->getRequestUri());
        });

        $app->error(function (\Exception $e) use ($app) {
            if ($e instanceof HttpException) {
                $app['monolog']->addWarning($e->getStatusCode().' '.$app['request']->getMethod().' '.$app['request']->getRequestUri());
            } else {
                $app['monolog']->addError($e->getMessage());
            }
        });
    }
}
