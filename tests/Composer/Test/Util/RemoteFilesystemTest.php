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

use Composer\Util\RemoteFilesystem;
use Installer\Exception;

class RemoteFilesystemTest extends \PHPUnit_Framework_TestCase
{
    public function testGetOptionsForUrl()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(false))
        ;

        $res = $this->callGetOptionsForUrl($io, array('http://example.org', array()));
        $this->assertTrue(isset($res['http']['header']) && is_array($res['http']['header']), 'getOptions must return an array with headers');
        $found = false;
        foreach ($res['http']['header'] as $header) {
            if (0 === strpos($header, 'User-Agent:')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'getOptions must have a User-Agent header');
    }

    public function testGetOptionsForUrlWithAuthorization()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(true))
        ;
        $io
            ->expects($this->once())
            ->method('getAuthentication')
            ->will($this->returnValue(array('username' => 'login', 'password' => 'password')))
        ;

        $options = $this->callGetOptionsForUrl($io, array('http://example.org', array()));

        $found = false;
        foreach ($options['http']['header'] as $header) {
            if (0 === strpos($header, 'Authorization: Basic')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'getOptions must have an Authorization header');
    }

    public function testGetOptionsForUrlWithStreamOptions()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(true))
        ;

        $streamOptions = array('ssl' => array(
            'allow_self_signed' => true,
        ));

        $res = $this->callGetOptionsForUrl($io, array('https://example.org', array()), $streamOptions);
        $this->assertTrue(isset($res['ssl']) && isset($res['ssl']['allow_self_signed']) && true === $res['ssl']['allow_self_signed'], 'getOptions must return an array with a allow_self_signed set to true');
    }

    public function testGetOptionsForUrlWithCallOptionsKeepsHeader()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthentication')
            ->will($this->returnValue(true))
        ;

        $streamOptions = array('http' => array(
            'header' => 'Foo: bar',
        ));

        $res = $this->callGetOptionsForUrl($io, array('https://example.org', $streamOptions));
        $this->assertTrue(isset($res['http']['header']), 'getOptions must return an array with a http.header key');

        $found = false;
        foreach ($res['http']['header'] as $header) {
            if ($header === 'Foo: bar') {
                $found = true;
            }
        }

        $this->assertTrue($found, 'getOptions must have a Foo: bar header');
        $this->assertGreaterThan(1, count($res['http']['header']));
    }

    public function testCallbackGetFileSize()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));
        $this->callCallbackGet($fs, STREAM_NOTIFY_FILE_SIZE_IS, 0, '', 0, 0, 20);
        $this->assertAttributeEquals(20, 'bytesMax', $fs);
    }

    public function testCallbackGetNotifyProgress()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('overwrite')
        ;

        $fs = new RemoteFilesystem($io);
        $this->setAttribute($fs, 'bytesMax', 20);
        $this->setAttribute($fs, 'progress', true);

        $this->callCallbackGet($fs, STREAM_NOTIFY_PROGRESS, 0, '', 0, 10, 20);
        $this->assertAttributeEquals(50, 'lastProgress', $fs);
    }

    public function testCallbackGetPassesThrough404()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));

        $this->assertNull($this->callCallbackGet($fs, STREAM_NOTIFY_FAILURE, 0, 'HTTP/1.1 404 Not Found', 404, 0, 0));
    }

    public function testCaptureAuthenticationParamsFromUrl()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->equalTo('example.com'), $this->equalTo('user'), $this->equalTo('pass'));

        $fs = new RemoteFilesystem($io);
        try {
            $fs->getContents('example.com', 'http://user:pass@www.example.com/something');
        } catch (\Exception $e) {
            $this->assertInstanceOf('Composer\Downloader\TransportException', $e);
            $this->assertEquals(404, $e->getCode());
        }
    }

    public function testGetContents()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));

        $this->assertContains('testGetContents', $fs->getContents('http://example.org', 'file://'.__FILE__));
    }

    public function testCopy()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));

        $file = tempnam(sys_get_temp_dir(), 'c');
        $this->assertTrue($fs->copy('http://example.org', 'file://'.__FILE__, $file));
        $this->assertFileExists($file);
        $this->assertContains('testCopy', file_get_contents($file));
        unlink($file);
    }

    protected function callGetOptionsForUrl($io, array $args = array(), array $options = array())
    {
        $fs = new RemoteFilesystem($io, null, $options);
        $ref = new \ReflectionMethod($fs, 'getOptionsForUrl');
        $ref->setAccessible(true);

        return $ref->invokeArgs($fs, $args);
    }

    protected function callCallbackGet(RemoteFilesystem $fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        $ref = new \ReflectionMethod($fs, 'callbackGet');
        $ref->setAccessible(true);
        $ref->invoke($fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax);
    }

    protected function setAttribute($object, $attribute, $value)
    {
        $attr = new \ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }
}
