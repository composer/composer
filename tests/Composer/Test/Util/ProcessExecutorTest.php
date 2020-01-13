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

use Composer\IO\ConsoleIO;
use Composer\Util\ProcessExecutor;
use Composer\Test\TestCase;
use Composer\IO\BufferIO;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class ProcessExecutorTest extends TestCase
{
    public function testExecuteCapturesOutput()
    {
        $process = new ProcessExecutor;
        $process->execute('echo foo', $output);
        $this->assertEquals("foo".PHP_EOL, $output);
    }

    public function testExecuteOutputsIfNotCaptured()
    {
        $process = new ProcessExecutor;
        ob_start();
        $process->execute('echo foo');
        $output = ob_get_clean();
        $this->assertEquals("foo".PHP_EOL, $output);
    }

    public function testUseIOIsNotNullAndIfNotCaptured()
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->once())
            ->method('write')
            ->with($this->equalTo('foo'.PHP_EOL), false);

        $process = new ProcessExecutor($io);
        $process->execute('echo foo');
    }

    public function testExecuteCapturesStderr()
    {
        $process = new ProcessExecutor;
        $process->execute('cat foo', $output);
        $this->assertNotNull($process->getErrorOutput());
    }

    public function testTimeout()
    {
        ProcessExecutor::setTimeout(1);
        $process = new ProcessExecutor;
        $this->assertEquals(1, $process->getTimeout());
        ProcessExecutor::setTimeout(60);
    }

    /**
     * @dataProvider hidePasswordProvider
     */
    public function testHidePasswords($command, $expectedCommandOutput)
    {
        $process = new ProcessExecutor($buffer = new BufferIO('', StreamOutput::VERBOSITY_DEBUG));
        $process->execute($command, $output);
        $this->assertEquals('Executing command (CWD): ' . $expectedCommandOutput, trim($buffer->getOutput()));
    }

    public function hidePasswordProvider()
    {
        return array(
            array('echo https://foo:bar@example.org/', 'echo https://foo:***@example.org/'),
            array('echo http://foo@example.org', 'echo http://foo@example.org'),
            array('echo http://abcdef1234567890234578:x-oauth-token@github.com/', 'echo http://***:***@github.com/'),
            array("svn ls --verbose --non-interactive  --username 'foo' --password 'bar'  'https://foo.example.org/svn/'", "svn ls --verbose --non-interactive  --username 'foo' --password '***'  'https://foo.example.org/svn/'"),
            array("svn ls --verbose --non-interactive  --username 'foo' --password 'bar \'bar'  'https://foo.example.org/svn/'", "svn ls --verbose --non-interactive  --username 'foo' --password '***'  'https://foo.example.org/svn/'"),
        );
    }

    public function testDoesntHidePorts()
    {
        $process = new ProcessExecutor($buffer = new BufferIO('', StreamOutput::VERBOSITY_DEBUG));
        $process->execute('echo https://localhost:1234/', $output);
        $this->assertEquals('Executing command (CWD): echo https://localhost:1234/', trim($buffer->getOutput()));
    }

    public function testSplitLines()
    {
        $process = new ProcessExecutor;
        $this->assertEquals(array(), $process->splitLines(''));
        $this->assertEquals(array(), $process->splitLines(null));
        $this->assertEquals(array('foo'), $process->splitLines('foo'));
        $this->assertEquals(array('foo', 'bar'), $process->splitLines("foo\nbar"));
        $this->assertEquals(array('foo', 'bar'), $process->splitLines("foo\r\nbar"));
        $this->assertEquals(array('foo', 'bar'), $process->splitLines("foo\r\nbar\n"));
    }

    public function testConsoleIODoesNotFormatSymfonyConsoleStyle()
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $process = new ProcessExecutor(new ConsoleIO(new ArrayInput(array()), $output, new HelperSet(array())));

        $process->execute('echo \'<error>foo</error>\'');
        $this->assertSame('<error>foo</error>'.PHP_EOL, $output->fetch());
    }
}
