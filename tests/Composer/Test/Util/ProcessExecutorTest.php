<?php declare(strict_types=1);

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
    public function testExecuteCapturesOutput(): void
    {
        $process = new ProcessExecutor;
        $process->execute('echo foo', $output);
        self::assertEquals("foo".PHP_EOL, $output);
    }

    public function testExecuteOutputsIfNotCaptured(): void
    {
        $process = new ProcessExecutor;
        ob_start();
        $process->execute('echo foo');
        $output = ob_get_clean();
        self::assertEquals("foo".PHP_EOL, $output);
    }

    public function testUseIOIsNotNullAndIfNotCaptured(): void
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->once())
            ->method('writeRaw')
            ->with($this->equalTo('foo'.PHP_EOL), false);

        $process = new ProcessExecutor($io);
        $process->execute('echo foo');
    }

    public function testExecuteCapturesStderr(): void
    {
        $process = new ProcessExecutor;
        $process->execute('cat foo', $output);
        self::assertStringContainsString('foo: No such file or directory', $process->getErrorOutput());
    }

    public function testTimeout(): void
    {
        ProcessExecutor::setTimeout(1);
        $process = new ProcessExecutor;
        self::assertEquals(1, $process->getTimeout());
        ProcessExecutor::setTimeout(60);
    }

    /**
     * @dataProvider hidePasswordProvider
     */
    public function testHidePasswords(string $command, string $expectedCommandOutput): void
    {
        $process = new ProcessExecutor($buffer = new BufferIO('', StreamOutput::VERBOSITY_DEBUG));
        $process->execute($command, $output);
        self::assertEquals('Executing command (CWD): ' . $expectedCommandOutput, trim($buffer->getOutput()));
    }

    public static function hidePasswordProvider(): array
    {
        return [
            ['echo https://foo:bar@example.org/', 'echo https://foo:***@example.org/'],
            ['echo http://foo@example.org', 'echo http://foo@example.org'],
            ['echo http://abcdef1234567890234578:x-oauth-token@github.com/', 'echo http://***:***@github.com/'],
            ['echo http://github_pat_1234567890abcdefghijkl_1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVW:x-oauth-token@github.com/', 'echo http://***:***@github.com/'],
            ["svn ls --verbose --non-interactive  --username 'foo' --password 'bar'  'https://foo.example.org/svn/'", "svn ls --verbose --non-interactive  --username 'foo' --password '***'  'https://foo.example.org/svn/'"],
            ["svn ls --verbose --non-interactive  --username 'foo' --password 'bar \'bar'  'https://foo.example.org/svn/'", "svn ls --verbose --non-interactive  --username 'foo' --password '***'  'https://foo.example.org/svn/'"],
        ];
    }

    public function testDoesntHidePorts(): void
    {
        $process = new ProcessExecutor($buffer = new BufferIO('', StreamOutput::VERBOSITY_DEBUG));
        $process->execute('echo https://localhost:1234/', $output);
        self::assertEquals('Executing command (CWD): echo https://localhost:1234/', trim($buffer->getOutput()));
    }

    public function testSplitLines(): void
    {
        $process = new ProcessExecutor;
        self::assertEquals([], $process->splitLines(''));
        self::assertEquals([], $process->splitLines(null));
        self::assertEquals(['foo'], $process->splitLines('foo'));
        self::assertEquals(['foo', 'bar'], $process->splitLines("foo\nbar"));
        self::assertEquals(['foo', 'bar'], $process->splitLines("foo\r\nbar"));
        self::assertEquals(['foo', 'bar'], $process->splitLines("foo\r\nbar\n"));
    }

    public function testConsoleIODoesNotFormatSymfonyConsoleStyle(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $process = new ProcessExecutor(new ConsoleIO(new ArrayInput([]), $output, new HelperSet([])));

        $process->execute('php -ddisplay_errors=0 -derror_reporting=0 -r "echo \'<error>foo</error>\'.PHP_EOL;"');
        self::assertSame('<error>foo</error>'.PHP_EOL, $output->fetch());
    }

    public function testExecuteAsyncCancel(): void
    {
        $process = new ProcessExecutor($buffer = new BufferIO('', StreamOutput::VERBOSITY_DEBUG));
        $process->enableAsync();
        $start = microtime(true);
        $promise = $process->executeAsync('sleep 2');
        self::assertEquals(1, $process->countActiveJobs());
        $promise->cancel();
        self::assertEquals(0, $process->countActiveJobs());
        $process->wait();
        $end = microtime(true);
        self::assertTrue($end - $start < 2, 'Canceling took longer than it should, lasted '.($end - $start));
    }

    /**
     * Test various arguments are escaped as expected
     *
     * @dataProvider dataEscapeArguments
     *
     * @param string|false|null $argument
     */
    public function testEscapeArgument($argument, string $win, string $unix): void
    {
        $expected = defined('PHP_WINDOWS_VERSION_BUILD') ? $win : $unix;
        self::assertSame($expected, ProcessExecutor::escape($argument));
    }

    /**
     * Each named test is an array of:
     *   argument, win-expected, unix-expected
     */
    public static function dataEscapeArguments(): array
    {
        return [
            // empty argument - must be quoted
            'empty' => ['', '""', "''"],

            // null argument - must be quoted
            'empty null' => [null, '""', "''"],

            // false argument - must be quoted
            'empty false' => [false, '""', "''"],

            // unix single-quote must be escaped
            'unix-sq' => ["a'bc", "a'bc", "'a'\\''bc'"],

            // new lines must be replaced
            'new lines' => ["a\nb\nc", '"a b c"', "'a\nb\nc'"],

            // whitespace <space> must be quoted
            'ws space' => ['a b c', '"a b c"', "'a b c'"],

            // whitespace <tab> must be quoted
            'ws tab' => ["a\tb\tc", "\"a\tb\tc\"", "'a\tb\tc'"],

            // no whitespace must not be quoted
            'no-ws' => ['abc', 'abc', "'abc'"],

            // commas must be quoted
            'comma' => ['a,bc', '"a,bc"', "'a,bc'"],

            // double-quotes must be backslash-escaped
            'dq' => ['a"bc', 'a\^"bc', "'a\"bc'"],

            // double-quotes must be backslash-escaped with preceding backslashes doubled
            'dq-bslash' => ['a\\"bc', 'a\\\\\^"bc', "'a\\\"bc'"],

            // backslashes not preceding a double-quote are treated as literal
            'bslash' => ['ab\\\\c\\', 'ab\\\\c\\', "'ab\\\\c\\'"],

            // trailing backslashes must be doubled up when the argument is quoted
            'bslash dq' => ['a b c\\\\', '"a b c\\\\\\\\"', "'a b c\\\\'"],

            // meta: outer double-quotes must be caret-escaped as well
            'meta dq' => ['a "b" c', '^"a \^"b\^" c^"', "'a \"b\" c'"],

            // meta: percent expansion must be caret-escaped
            'meta-pc1' => ['%path%', '^%path^%', "'%path%'"],

            // meta: expansion must have two percent characters
            'meta-pc2' => ['%path', '%path', "'%path'"],

            // meta: expansion must have have two surrounding percent characters
            'meta-pc3' => ['%%path', '%%path', "'%%path'"],

            // meta: bang expansion must be double caret-escaped
            'meta-bang1' => ['!path!', '^^!path^^!', "'!path!'"],

            // meta: bang expansion must have two bang characters
            'meta-bang2' => ['!path', '!path', "'!path'"],

            // meta: bang expansion must have two surrounding ang characters
            'meta-bang3' => ['!!path', '!!path', "'!!path'"],

            // meta: caret-escaping must escape all other meta chars (triggered by double-quote)
            'meta-all-dq' => ['<>"&|()^', '^<^>\^"^&^|^(^)^^', "'<>\"&|()^'"],

            // other meta: no caret-escaping when whitespace in argument
            'other meta' => ['<> &| ()^', '"<> &| ()^"', "'<> &| ()^'"],

            // other meta: quote escape chars when no whitespace in argument
            'other-meta' => ['<>&|()^', '"<>&|()^"', "'<>&|()^'"],
        ];
    }
}
