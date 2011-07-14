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

class SwiftmailerExtension implements ExtensionInterface
{
    public function register(Application $app)
    {
        $app['swiftmailer.options'] = array_replace(array(
            'host'       => 'localhost',
            'port'       => 25,
            'username'   => '',
            'password'   => '',
            'encryption' => null,
            'auth_mode'  => null,
        ), isset($app['swiftmailer.options']) ? $app['swiftmailer.options'] : array());

        $app['mailer'] = $app->share(function () use ($app) {
            $r = new \ReflectionClass('Swift_Mailer');
            require_once dirname($r->getFilename()).'/../../swift_init.php';

            return new \Swift_Mailer($app['swiftmailer.transport']);
        });

        $app['swiftmailer.transport'] = $app->share(function () use ($app) {
            $transport = new \Swift_Transport_EsmtpTransport(
                $app['swiftmailer.transport.buffer'],
                array($app['swiftmailer.transport.authhandler']),
                $app['swiftmailer.transport.eventdispatcher']
            );

            $transport->setHost($app['swiftmailer.options']['host']);
            $transport->setPort($app['swiftmailer.options']['port']);
            $transport->setEncryption($app['swiftmailer.options']['encryption']);
            $transport->setUsername($app['swiftmailer.options']['username']);
            $transport->setPassword($app['swiftmailer.options']['password']);
            $transport->setAuthMode($app['swiftmailer.options']['auth_mode']);

            return $transport;
        });

        $app['swiftmailer.transport.buffer'] = $app->share(function () {
            return new \Swift_Transport_StreamBuffer(new \Swift_StreamFilters_StringReplacementFilterFactory());
        });

        $app['swiftmailer.transport.authhandler'] = $app->share(function () {
            return new \Swift_Transport_Esmtp_AuthHandler(array(
                new \Swift_Transport_Esmtp_Auth_CramMd5Authenticator(),
                new \Swift_Transport_Esmtp_Auth_LoginAuthenticator(),
                new \Swift_Transport_Esmtp_Auth_PlainAuthenticator(),
            ));
        });

        $app['swiftmailer.transport.eventdispatcher'] = $app->share(function () {
            return new \Swift_Events_SimpleEventDispatcher();
        });

        if (isset($app['swiftmailer.class_path'])) {
            $app['autoloader']->registerPrefix('Swift_', $app['swiftmailer.class_path']);
        }
    }
}
