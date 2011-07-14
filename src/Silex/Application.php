<?php

/*
 * This file is part of the Silex framework.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Silex;

use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\ClassLoader\UniversalClassLoader;
use Silex\RedirectableUrlMatcher;

/**
 * The Silex framework class.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Application extends \Pimple implements HttpKernelInterface, EventSubscriberInterface
{
    const VERSION = '@package_version@';

    /**
     * Constructor.
     */
    public function __construct()
    {
        $app = $this;

        $this['autoloader'] = $this->share(function () {
            $loader = new UniversalClassLoader();
            $loader->register();

            return $loader;
        });

        $this['routes'] = $this->share(function () {
            return new RouteCollection();
        });

        $this['controllers'] = $this->share(function () use ($app) {
            return new ControllerCollection($app['routes']);
        });

        $this['dispatcher'] = $this->share(function () use ($app) {
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber($app);

            return $dispatcher;
        });

        $this['resolver'] = $this->share(function () {
            return new ControllerResolver();
        });

        $this['kernel'] = $this->share(function () use ($app) {
            return new HttpKernel($app['dispatcher'], $app['resolver']);
        });

        $this['request.http_port'] = 80;
        $this['request.https_port'] = 443;
    }

    /**
     * Registers an extension.
     *
     * @param ExtensionInterface $extension An ExtensionInterface instance
     * @param array              $values    An array of values that customizes the extension
     */
    public function register(ExtensionInterface $extension, array $values = array())
    {
        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }

        $extension->register($this);
    }

    /**
     * Maps a pattern to a callable.
     *
     * You can optionally specify HTTP methods that should be matched.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     * @param string $method Matched HTTP methods, multiple can be supplied,
     *                       delimited by a pipe character '|', eg. 'GET|POST'.
     *
     * @return Silex\Controller
     */
    public function match($pattern, $to, $method = null)
    {
        $requirements = array();

        if ($method) {
            $requirements['_method'] = $method;
        }

        $route = new Route($pattern, array('_controller' => $to), $requirements);
        $controller = new Controller($route);
        $this['controllers']->add($controller);

        return $controller;
    }

    /**
     * Maps a GET request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function get($pattern, $to)
    {
        return $this->match($pattern, $to, 'GET');
    }

    /**
     * Maps a POST request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function post($pattern, $to)
    {
        return $this->match($pattern, $to, 'POST');
    }

    /**
     * Maps a PUT request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function put($pattern, $to)
    {
        return $this->match($pattern, $to, 'PUT');
    }

    /**
     * Maps a DELETE request to a callable.
     *
     * @param string $pattern Matched route pattern
     * @param mixed $to Callback that returns the response when matched
     *
     * @return Silex\Controller
     */
    public function delete($pattern, $to)
    {
        return $this->match($pattern, $to, 'DELETE');
    }

    /**
     * Registers a before filter.
     *
     * Before filters are run before any route has been matched.
     *
     * @param mixed $callback Before filter callback
     */
    public function before($callback)
    {
        $this['dispatcher']->addListener(SilexEvents::BEFORE, $callback);
    }

    /**
     * Registers an after filter.
     *
     * After filters are run after the controller has been executed.
     *
     * @param mixed $callback After filter callback
     */
    public function after($callback)
    {
        $this['dispatcher']->addListener(SilexEvents::AFTER, $callback);
    }

    /**
     * Registers an error handler.
     *
     * Error handlers are simple callables which take a single Exception
     * as an argument. If a controller throws an exception, an error handler
     * can return a specific response.
     *
     * When an exception occurs, all handlers will be called, until one returns
     * something (a string or a Response object), at which point that will be
     * returned to the client.
     *
     * For this reason you should add logging handlers before output handlers.
     *
     * @param mixed $callback Error handler callback, takes an Exception argument
     */
    public function error($callback)
    {
        $this['dispatcher']->addListener(SilexEvents::ERROR, function (GetResponseForErrorEvent $event) use ($callback) {
            $exception = $event->getException();
            $result = $callback($exception);

            if (null !== $result) {
                $event->setStringResponse($result);
            }
        });
    }

    /**
     * Flushes the controller collection.
     */
    public function flush()
    {
        $this['controllers']->flush();
    }

    /**
     * Redirects the user to another URL.
     *
     * @param string  $url    The URL to redirect to
     * @param integer $status The status code (302 by default)
     *
     * @see Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirect($url, $status = 302)
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * Escapes a text for HTML.
     *
     * @param string $text The input text to be escaped
     * @return string Escaped text
     */
    public function escape($text, $flags = ENT_COMPAT, $charset = 'UTF-8', $doubleEncode = true)
    {
        return htmlspecialchars($text, $flags, $charset, $doubleEncode);
    }

    /**
     * Mounts an application under the given route prefix.
     *
     * @param string               $prefix The route prefix
     * @param Application|\Closure $app    An Application instance or a Closure that returns an Application instance
     */
    public function mount($prefix, $app)
    {
        $mountHandler = function (Request $request, $prefix) use ($app) {
            if (is_callable($app)) {
                $app = $app();
            }

            foreach ($app['controllers']->all() as $controller) {
                $controller->getRoute()->setPattern(rtrim($prefix, '/').$controller->getRoute()->getPattern());
            }

            return $app->handle($request);
        };

        $prefix = rtrim($prefix, '/');

        $this
            ->match($prefix.'/{path}', $mountHandler)
            ->assert('path', '.*')
            ->value('prefix', $prefix);
    }

    /**
     * Handles the request and deliver the response.
     *
     * @param Request $request Request to process
     */
    public function run(Request $request = null)
    {
        if (null === $request) {
            $request = Request::createFromGlobals();
        }

        $this->handle($request)->send();
    }

    public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
    {
        return $this['kernel']->handle($request, $type, $catch);
    }

    /**
     * Handles onKernelRequest events.
     */
    public function onKernelRequest(KernelEvent $event)
    {
        $this['request'] = $event->getRequest();

        $this['request_context'] = new RequestContext(
            $this['request']->getBaseUrl(),
            $this['request']->getMethod(),
            $this['request']->getHost(),
            $this['request']->getScheme(),
            !$this['request']->isSecure() ? $this['request']->getPort() : $this['request.http_port'],
            $this['request']->isSecure() ? $this['request']->getPort() : $this['request.https_port']
        );

        $this['controllers']->flush();

        $matcher = new RedirectableUrlMatcher($this['routes'], $this['request_context']);

        try {
            $attributes = $matcher->match($this['request']->getPathInfo());

            $this['request']->attributes->add($attributes);
        } catch (RoutingException $e) {
            // make sure onSilexBefore event is dispatched

            $this['dispatcher']->dispatch(SilexEvents::BEFORE);

            if ($e instanceof ResourceNotFoundException) {
                $message = sprintf('No route found for "%s %s"', $this['request']->getMethod(), $this['request']->getPathInfo());
                throw new NotFoundHttpException($message, $e);
            } else if ($e instanceof MethodNotAllowedException) {
                $message = sprintf('No route found for "%s %s": Method Not Allowed (Allow: %s)', $this['request']->getMethod(), $this['request']->getPathInfo(), strtoupper(implode(', ', $e->getAllowedMethods())));
                throw new MethodNotAllowedHttpException($e->getAllowedMethods(), $message, $e);
            }

            throw $e;
        }

        $this['dispatcher']->dispatch(SilexEvents::BEFORE);
    }

    /**
     * Handles converters.
     *
     * @param FilterControllerEvent $event A FilterControllerEvent instance
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        $request = $event->getRequest();
        $route = $this['routes']->get($request->attributes->get('_route'));
        if ($route && $converters = $route->getOption('_converters')) {
            foreach ($converters as $name => $callback) {
                $request->attributes->set($name, call_user_func($callback, $request->attributes->get($name, null), $request));
            }
        }
    }

    /**
     * Handles string responses.
     *
     * Handler for onKernelView.
     */
    public function onKernelView(GetResponseForControllerResultEvent $event)
    {
        $response = $event->getControllerResult();
        $converter = new StringResponseConverter();
        $event->setResponse($converter->convert($response));
    }

    /**
     * Runs after filters.
     *
     * Handler for onKernelResponse.
     */
    public function onKernelResponse(Event $event)
    {
        $this['dispatcher']->dispatch(SilexEvents::AFTER);
    }

    /**
     * Executes registered error handlers until a response is returned,
     * in which case it returns it to the client.
     *
     * Handler for onKernelException.
     *
     * @see error()
     */
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $errorEvent = new GetResponseForErrorEvent($this, $event->getRequest(), $event->getRequestType(), $event->getException());
        $this['dispatcher']->dispatch(SilexEvents::ERROR, $errorEvent);

        if ($errorEvent->hasResponse()) {
            $event->setResponse($errorEvent->getResponse());
        }
    }

    /**
     * {@inheritdoc}
     */
    static public function getSubscribedEvents()
    {
        // onKernelView listener is added manually because it has lower priority

        return array(
            KernelEvents::REQUEST    => 'onKernelRequest',
            KernelEvents::CONTROLLER => 'onKernelController',
            KernelEvents::RESPONSE   => 'onKernelResponse',
            KernelEvents::EXCEPTION  => 'onKernelException',
            KernelEvents::VIEW       => array('onKernelView', -10),
        );
    }
}
