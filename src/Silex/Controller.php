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

use Silex\Exception\ControllerFrozenException;

use Symfony\Component\Routing\Route;

/**
 * A wrapper for a controller, mapped to a route.
 *
 * @author Igor Wiedler igor@wiedler.ch
 */
class Controller
{
    private $route;
    private $routeName;
    private $isFrozen = false;

    /**
     * Constructor.
     *
     * @param Route $route
     */
    public function __construct(Route $route)
    {
        $this->route = $route;
        $this->bind($this->defaultRouteName());
    }

    /**
     * Gets the controller's route.
     */
    public function getRoute()
    {
        return $this->route;
    }

    /**
     * Gets the controller's route name.
     */
    public function getRouteName()
    {
        return $this->routeName;
    }

    /**
     * Sets the controller's route.
     *
     * @param string $routeName
     */
    public function bind($routeName)
    {
        if ($this->isFrozen) {
            throw new ControllerFrozenException(sprintf('Calling %s on frozen %s instance.', __METHOD__, __CLASS__));
        }

        $this->routeName = $routeName;

        return $this;
    }

    /**
     * Sets the requirement for a route variable.
     *
     * @param string $variable The variable name
     * @param string $regexp   The regexp to apply
     */
    public function assert($variable, $regexp)
    {
        $this->route->setRequirement($variable, $regexp);

        return $this;
    }

    /**
     * Sets the default value for a route variable.
     *
     * @param string $variable The variable name
     * @param mixed  $default  The default value
     */
    public function value($variable, $default)
    {
        $this->route->setDefault($variable, $default);

        return $this;
    }

    /**
     * Sets a converter for a route variable.
     *
     * @param string $variable The variable name
     * @param mixed  $callback A PHP callback that converts the original value
     */
    public function convert($variable, $callback)
    {
        $converters = $this->route->getOption('_converters');
        $converters[$variable] = $callback;
        $this->route->setOption('_converters', $converters);

        return $this;
    }

    /**
     * Sets the requirement of HTTP (no HTTPS) on this controller.
     */
    public function requireHttp()
    {
        $this->route->setRequirement('_scheme', 'http');

        return $this;
    }

    /**
     * Sets the requirement of HTTPS on this controller.
     */
    public function requireHttps()
    {
        $this->route->setRequirement('_scheme', 'https');

        return $this;
    }

    /**
     * Freezes the controller.
     *
     * Once the controller is frozen, you can no longer change the route name
     */
    public function freeze()
    {
        $this->isFrozen = true;
    }

    private function defaultRouteName()
    {
        $requirements = $this->route->getRequirements();
        $method = isset($requirements['_method']) ? $requirements['_method'] : '';

        $routeName = $method.$this->route->getPattern();
        $routeName = str_replace(array('/', ':', '|', '-'), '_', $routeName);
        $routeName = preg_replace('/[^a-z0-9A-Z_.]+/', '', $routeName);

        return $routeName;
    }
}
