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

use Symfony\Component\Routing\Generator\UrlGenerator;

class UrlGeneratorExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['url_generator'] = $app->share(function () use ($app) {
            $app->flush();

            return new UrlGenerator($app['routes'], $app['request_context']);
        });
    }
}
