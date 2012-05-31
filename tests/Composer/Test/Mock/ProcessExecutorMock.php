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

use Composer\Util\ProcessExecutor;

class ProcessExecutorMock extends ProcessExecutor
{
    private $execute;

    public function __construct(\Closure $execute)
    {
        $this->execute = $execute;
    }

    public function execute($command, &$output = null, $cwd = null)
    {
        $execute = $this->execute;

        return $execute($command, $output, $cwd);
    }
}
