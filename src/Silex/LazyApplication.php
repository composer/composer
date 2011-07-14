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

use Symfony\Component\HttpFoundation\Request;

/**
 * A Lazy application wrapper.
 *
 * Acts as a closure, so it can be used as a lazy app
 * factory for Silex\Application::mount().
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class LazyApplication
{
    protected $appPath;
    protected $app;

    /**
     * Constructor.
     *
     * The $appPath argument is the path to a Silex app file.
     * This file must return a Silex application.
     *
     * @param string $appPath The absolute path to a Silex app file
     */
    public function __construct($appPath)
    {
        $this->appPath = $appPath;
    }

    /**
     * Returns the application.
     */
    public function __invoke()
    {
        if (!$this->app) {
            $this->app = require $this->appPath;
        }

        if (!$this->app instanceof Application) {
            throw new \InvalidArgumentException('The provided path did not return a Silex\Application on inclusion.');
        }

        return $this->app;
    }
}
