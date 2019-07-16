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

namespace Composer\Test\Command;

use Composer\Command\InitCommand;
use Composer\Command\WrapCommand;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class WrapCommandTest extends TestCase
{
    public function testWith()
    {
        $wrap = new WrapCommand();
        $input = new ArrayInput(array());

        $directory = self::getUniqueTmpDirectory();
        chdir($directory);
        $wrap->run($input, new NullOutput());

        self::assertFileExists($directory . '/composer');
    }
}
