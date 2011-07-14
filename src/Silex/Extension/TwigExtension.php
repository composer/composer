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

use Symfony\Bridge\Twig\Extension\RoutingExtension as TwigRoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension as TwigTranslationExtension;
use Symfony\Bridge\Twig\Extension\FormExtension as TwigFormExtension;

class TwigExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['twig'] = $app->share(function () use ($app) {
            $twig = new \Twig_Environment($app['twig.loader'], isset($app['twig.options']) ? $app['twig.options'] : array());
            $twig->addGlobal('app', $app);

            if (isset($app['symfony_bridges'])) {
                if (isset($app['url_generator'])) {
                    $twig->addExtension(new TwigRoutingExtension($app['url_generator']));
                }

                if (isset($app['translator'])) {
                    $twig->addExtension(new TwigTranslationExtension($app['translator']));
                }

                if (isset($app['form.factory'])) {
                    $twig->addExtension(new TwigFormExtension(array('form_div_layout.html.twig')));
                }
            }

            if (isset($app['twig.configure'])) {
                $app['twig.configure']($twig);
            }

            return $twig;
        });

        $app['twig.loader'] = $app->share(function () use ($app) {
            if (isset($app['twig.templates'])) {
                return new \Twig_Loader_Array($app['twig.templates']);
            } else {
                return new \Twig_Loader_Filesystem($app['twig.path']);
            }
        });

        if (isset($app['twig.class_path'])) {
            $app['autoloader']->registerPrefix('Twig_', $app['twig.class_path']);
        }
    }
}
