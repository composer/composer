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

class SymfonyBridgesExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['symfony_bridges'] = true;

        if (isset($app['symfony_bridges.class_path'])) {
            $app['autoloader']->registerNamespace('Symfony\\Bridge', $app['symfony_bridges.class_path']);
        }
    }
}
