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

use Symfony\Component\Routing\RouteCollection;

/**
 * A collection of Silex controllers.
 *
 * It acts as a staging area for routes. You are able to set the route name
 * until flush() is called, at which point all controllers are frozen and
 * added to the RouteCollection.
 *
 * @author Igor Wiedler igor@wiedler.ch
 */
class ControllerCollection
{
    private $controllers = array();
    private $routes;

    public function __construct(RouteCollection $routes)
    {
        $this->routes = $routes;
    }

    /**
     * Adds a controller to the staging area.
     *
     * @param Controller $controller
     */
    public function add(Controller $controller)
    {
        $this->controllers[] = $controller;
    }

    /**
     * Persists and freezes staged controllers.
     */
    public function flush()
    {
        foreach ($this->controllers as $controller) {
            $this->routes->add($controller->getRouteName(), $controller->getRoute());
            $controller->freeze();
        }

        $this->controllers = array();
    }

    /**
     * Gets all controllers.
     *
     * @return array An array of Controllers
     */
    public function all()
    {
        return $this->controllers;
    }
}
