<?php
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Mock;

use Composer\Factory;
use Composer\XdebugHandler;

class XdebugHandlerMock extends XdebugHandler
{
    public $restarted;
    public $output;
    public $testVersion = '2.5.0';

    public function __construct($loaded = null)
    {
        $this->output = Factory::createOutput();
        parent::__construct($this->output);

        $loaded = null === $loaded ? true: $loaded;
        $class = new \ReflectionClass(get_parent_class($this));

        $prop = $class->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue($this, $loaded);

        $prop = $class->getProperty('version');
        $prop->setAccessible(true);
        $version = $loaded ? $this->testVersion : '';
        $prop->setValue($this, $version);

        $this->restarted = false;
    }

    protected function restart($command)
    {
        $this->restarted = true;
    }
}
