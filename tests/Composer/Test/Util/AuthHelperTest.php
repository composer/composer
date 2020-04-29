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

namespace Composer\Test\Util;

use Composer\Test\TestCase;
use Composer\Util\AuthHelper;

/**
 * @author Michael Chekin <mchekin@gmail.com>
 */
class AuthHelperTest extends TestCase
{
    /** @type \Composer\IO\IOInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $io;

    /** @type \Composer\Config|\PHPUnit_Framework_MockObject_MockObject */
    private $config;

    /** @type AuthHelper */
    private $authHelper;

    protected function setUp()
    {
        $this->io = $this
            ->getMockBuilder('Composer\IO\IOInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->config = $this->getMockBuilder('Composer\Config')->getMock();

        $this->authHelper = new AuthHelper($this->io, $this->config);
    }

    public function testAddAuthenticationHeaderWithoutAuthCredentials()
    {
        $headers = array(
            'Accept-Encoding: gzip',
            'Connection: close'
        );
        $origin = 'http://example.org';
        $url = 'file://' . __FILE__;

        $this->io->expects($this->once())
            ->method('hasAuthentication')
            ->with($origin)
            ->willReturn(false);

        $this->assertSame(
            $headers,
            $this->authHelper->addAuthenticationHeader($headers, $origin, $url)
        );
    }
}
