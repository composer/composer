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
use Silex\HttpCache;
use Symfony\Component\HttpKernel\HttpCache\Esi;
use Symfony\Component\HttpKernel\HttpCache\Store;

class HttpCacheExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['http_cache'] = $app->share(function () use ($app) {
            return new HttpCache($app, $app['http_cache.store'], $app['http_cache.esi'], $app['http_cache.options']);
        });

        $app['http_cache.esi'] = $app->share(function () use ($app) {
            return new Esi();
        });

        $app['http_cache.store'] = $app->share(function () use ($app) {
            return new Store($app['http_cache.cache_dir']);
        });

        if (!isset($app['http_cache.options'])) {
            $app['http_cache.options'] = array();
        }
    }
}
