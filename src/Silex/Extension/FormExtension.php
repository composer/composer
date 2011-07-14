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
use Symfony\Component\Form\Extension\Csrf\CsrfProvider\DefaultCsrfProvider;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\Form\Extension\Core\CoreExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension as FormValidatorExtension;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;

class FormExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['form.secret'] = md5(__DIR__);

        $app['form.factory'] = $app->share(function () use ($app) {
            $extensions = array(
                new CoreExtension(),
                new CsrfExtension($app['form.csrf_provider']),
            );

            if (isset($app['validator'])) {
                $extensions[] = new FormValidatorExtension($app['validator']);
            }

            return new FormFactory($extensions);
        });

        $app['form.csrf_provider'] = $app->share(function () use ($app) {
            return new DefaultCsrfProvider($app['form.secret']);
        });

        if (isset($app['form.class_path'])) {
            $app['autoloader']->registerNamespace('Symfony\\Component\\Form', $app['form.class_path']);
        }
    }
}
