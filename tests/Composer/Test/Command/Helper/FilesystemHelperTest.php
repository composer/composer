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

namespace Composer\Test\Command\Helper;

use Composer\Command\Helper\FilesystemHelper;

class FilesystemHelperTest extends \PHPUnit_Framework_TestCase
{
    public function testEnsureFileExists()
    {
        $file = 'foo';
        $content = 'bar';

        $filesystem = $this->getMockBuilder('\Composer\Util\Filesystem')
            ->disableOriginalConstructor()
            ->setMethods(array('ensureFileExists'))
            ->getMock();

        $filesystem->expects($this->once())
            ->method('ensureFileExists')
            ->with($file, $content);

        $filesystemHelper = new FilesystemHelper($filesystem);
        $filesystemHelper->ensureFileExists($file, $content);
    }
}
