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

use Composer\XdebugHandler;

class XdebugHandlerMock extends XdebugHandler
{
    public $command;
    public $restarted;

    public function __construct(array $argv, $loaded)
    {
        parent::__construct($argv);

        $class = new \ReflectionClass(get_parent_class($this));
        $prop = $class->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue($this, $loaded);

        $this->command = '';
        $this->restarted = false;
    }

    protected function restart($command)
    {
        $this->command = $command;
        $this->restarted = true;
    }
}
