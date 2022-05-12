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
use React\Promise\Promise;
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
            ->method('writeRaw')
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
     *
     * @param string $command
     * @param string $expectedCommandOutput
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

        $process->execute('php -ddisplay_errors=0 -derror_reporting=0 -r "echo \'<error>foo</error>\'.PHP_EOL;"');
        $this->assertSame('<error>foo</error>'.PHP_EOL, $output->fetch());
    }

    public function testExecuteAsyncCancel()
    {
        $process = new ProcessExecutor($buffer = new BufferIO('', StreamOutput::VERBOSITY_DEBUG));
        $process->enableAsync();
        $start = microtime(true);
        /** @var Promise $promise */
        $promise = $process->executeAsync('sleep 2');
        $this->assertEquals(1, $process->countActiveJobs());
        $promise->cancel();
        $this->assertEquals(0, $process->countActiveJobs());
        $end = microtime(true);
        $this->assertTrue($end - $start < 0.5, 'Canceling took longer than it should, lasted '.($end - $start));
    }

    /**
     * Test various arguments are escaped as expected
     *
     * @dataProvider dataEscapeArguments
     *
     * @param string|false|null $argument
     * @param string            $win
     * @param string            $unix
     */
    public function testEscapeArgument($argument, $win, $unix)
    {
        $expected = defined('PHP_WINDOWS_VERSION_BUILD') ? $win : $unix;
        $this->assertSame($expected, ProcessExecutor::escape($argument));
    }

    /**
     * Each named test is an array of:
     *   argument, win-expected, unix-expected
     */
    public function dataEscapeArguments()
    {
        return array(
            // empty argument - must be quoted
            'empty' => array('', '""', "''"),

            // null argument - must be quoted
            'empty null' => array(null, '""', "''"),

            // false argument - must be quoted
            'empty false' => array(false, '""', "''"),

            // unix single-quote must be escaped
            'unix-sq' => array("a'bc", "a'bc", "'a'\\''bc'"),

            // new lines must be replaced
            'new lines' => array("a\nb\nc", '"a b c"', "'a\nb\nc'"),

            // whitespace <space> must be quoted
            'ws space' => array('a b c', '"a b c"', "'a b c'"),

            // whitespace <tab> must be quoted
            'ws tab' => array("a\tb\tc", "\"a\tb\tc\"", "'a\tb\tc'"),

            // no whitespace must not be quoted
            'no-ws' => array('abc', 'abc', "'abc'"),

            // commas must be quoted
            'comma' => array('a,bc', '"a,bc"', "'a,bc'"),

            // double-quotes must be backslash-escaped
            'dq' => array('a"bc', 'a\^"bc', "'a\"bc'"),

            // double-quotes must be backslash-escaped with preceeding backslashes doubled
            'dq-bslash' => array('a\\"bc', 'a\\\\\^"bc', "'a\\\"bc'"),

            // backslashes not preceeding a double-quote are treated as literal
            'bslash' => array('ab\\\\c\\', 'ab\\\\c\\', "'ab\\\\c\\'"),

            // trailing backslashes must be doubled up when the argument is quoted
            'bslash dq' => array('a b c\\\\', '"a b c\\\\\\\\"', "'a b c\\\\'"),

            // meta: outer double-quotes must be caret-escaped as well
            'meta dq' => array('a "b" c', '^"a \^"b\^" c^"', "'a \"b\" c'"),

            // meta: percent expansion must be caret-escaped
            'meta-pc1' => array('%path%', '^%path^%', "'%path%'"),

            // meta: expansion must have two percent characters
            'meta-pc2' => array('%path', '%path', "'%path'"),

            // meta: expansion must have have two surrounding percent characters
            'meta-pc3' => array('%%path', '%%path', "'%%path'"),

            // meta: bang expansion must be double caret-escaped
            'meta-bang1' => array('!path!', '^^!path^^!', "'!path!'"),

            // meta: bang expansion must have two bang characters
            'meta-bang2' => array('!path', '!path', "'!path'"),

            // meta: bang expansion must have two surrounding ang characters
            'meta-bang3' => array('!!path', '!!path', "'!!path'"),

            // meta: caret-escaping must escape all other meta chars (triggered by double-quote)
            'meta-all-dq' => array('<>"&|()^', '^<^>\^"^&^|^(^)^^', "'<>\"&|()^'"),

            // other meta: no caret-escaping when whitespace in argument
            'other meta' => array('<> &| ()^', '"<> &| ()^"', "'<> &| ()^'"),

            // other meta: quote escape chars when no whitespace in argument
            'other-meta' => array('<>&|()^', '"<>&|()^"', "'<>&|()^'"),
        );
    }
}
