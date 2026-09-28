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

use Composer\Util\Platform;
use Composer\Test\TestCase;

/**
 * PlatformTest
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class PlatformTest extends TestCase
{
    /** @var string|false */
    private $originalAllowUnsafePharMetadata;
    /** @var array<string, string|false> */
    private $originalCodingAgentEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAllowUnsafePharMetadata = Platform::getEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        // make sure the tests are not affected by an agent running them
        foreach (array_merge(['AI_AGENT'], array_keys(Platform::CODING_AGENT_ENV_VARS)) as $envVar) {
            $this->originalCodingAgentEnv[$envVar] = Platform::getEnv($envVar);
            Platform::clearEnv($envVar);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalCodingAgentEnv as $envVar => $value) {
            if (false === $value) {
                Platform::clearEnv($envVar);
            } else {
                Platform::putEnv($envVar, $value);
            }
        }
        Platform::clearEnv('COMPOSER_TEST_BOOL_ENV');
        if (false === $this->originalAllowUnsafePharMetadata) {
            Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');
        } else {
            Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', $this->originalAllowUnsafePharMetadata);
        }
        parent::tearDown();
    }

    public function testExpandPath(): void
    {
        putenv('TESTENV=/home/test');
        self::assertEquals('/home/test/myPath', Platform::expandPath('%TESTENV%/myPath'));
        self::assertEquals('/home/test/myPath', Platform::expandPath('$TESTENV/myPath'));
        self::assertEquals((getenv('HOME') ?: getenv('USERPROFILE')) . '/test', Platform::expandPath('~/test'));
    }

    public function testIsWindows(): void
    {
        // Compare 2 common tests for Windows to the built-in Windows test
        self::assertEquals(('\\' === DIRECTORY_SEPARATOR), Platform::isWindows());
        self::assertEquals(defined('PHP_WINDOWS_VERSION_MAJOR'), Platform::isWindows());
    }

    public function testGetAiAgentWithoutAnyAgentEnvVar(): void
    {
        if (file_exists('/opt/.devin')) {
            self::markTestSkipped('Tests are running inside Devin');
        }

        self::assertNull(Platform::getAiAgent());
    }

    /**
     * @dataProvider provideCodingAgentEnvVars
     */
    public function testGetAiAgentDetectsKnownEnvVars(string $envVar, string $expected): void
    {
        Platform::putEnv($envVar, '1');

        self::assertSame($expected, Platform::getAiAgent());
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function provideCodingAgentEnvVars(): iterable
    {
        foreach (Platform::CODING_AGENT_ENV_VARS as $envVar => $name) {
            yield $envVar => [$envVar, $name];
        }
    }

    public function testGetAiAgentIgnoresEmptyEnvVar(): void
    {
        if (file_exists('/opt/.devin')) {
            self::markTestSkipped('Tests are running inside Devin');
        }

        Platform::putEnv('CLAUDECODE', '');

        self::assertNull(Platform::getAiAgent());
    }

    /**
     * @dataProvider provideAiAgentPrecedence
     * @param array<non-empty-string, string> $env
     */
    public function testGetAiAgentPrecedence(array $env, string $expected): void
    {
        foreach ($env as $name => $value) {
            Platform::putEnv($name, $value);
        }

        self::assertSame($expected, Platform::getAiAgent());
    }

    /**
     * @return iterable<string, array{array<non-empty-string, string>, string}>
     */
    public static function provideAiAgentPrecedence(): iterable
    {
        // AI_AGENT
        yield 'known AI_AGENT name wins over env vars' => [['CLAUDECODE' => '1', 'AI_AGENT' => 'cursor'], 'cursor'];
        yield 'known AI_AGENT name after cleanup wins over env vars' => [['CLAUDECODE' => '1', 'AI_AGENT' => 'Codex@1.0'], 'codex'];
        yield 'unknown AI_AGENT name loses to env vars' => [['CLAUDECODE' => '1', 'AI_AGENT' => 'whatever'], 'claude-code'];
        yield 'bare true AI_AGENT loses to env vars' => [['CLAUDECODE' => '1', 'AI_AGENT' => '1'], 'claude-code'];
        yield 'falsy AI_AGENT does not disable env vars' => [['CLAUDECODE' => '1', 'AI_AGENT' => 'false'], 'claude-code'];
        yield 'blank AI_AGENT does not disable env vars' => [['CLAUDECODE' => '1', 'AI_AGENT' => ' '], 'claude-code'];

        // env vars, first match in CODING_AGENT_ENV_VARS order wins
        yield 'first matching env var wins' => [['CODEX_THREAD_ID' => 'abc', 'CURSOR_AGENT' => '1'], 'cursor'];
        yield 'empty env var is skipped' => [['CURSOR_AGENT' => '', 'CODEX_THREAD_ID' => 'abc'], 'codex'];

        // agents which set or inherit another agent's env vars
        yield 'amp over claude-code' => [['CLAUDECODE' => '1', 'AMP_CURRENT_THREAD_ID' => 'T-123'], 'amp'];
        yield 'codebuddy over claude-code' => [['CLAUDECODE' => '1', 'CODEBUDDY' => '1'], 'codebuddy'];
        yield 'cowork over claude-code' => [['CLAUDECODE' => '1', 'CLAUDE_CODE_IS_COWORK' => '1'], 'cowork'];
        yield 'qwen-code over gemini-cli' => [['GEMINI_CLI' => '1', 'QWEN_CODE' => '1'], 'qwen-code'];
        yield 'vecli over gemini-cli' => [['GEMINI_CLI' => '1', 'VECLI_DIR' => '/tmp'], 'vecli'];
        yield 'kilo-code over opencode' => [['OPENCODE' => '1', 'OPENCODE_CLIENT' => 'cli', 'KILO' => '1'], 'kilo-code'];
        yield 'known AI_AGENT name still wins over forks' => [['GEMINI_CLI' => '1', 'QWEN_CODE' => '1', 'AI_AGENT' => 'gemini'], 'gemini-cli'];
    }

    public function testGetAiAgentUnknownAiAgentNameIsUsedWithoutOtherMarkers(): void
    {
        if (file_exists('/opt/.devin')) {
            self::markTestSkipped('Tests are running inside Devin');
        }

        Platform::putEnv('AI_AGENT', 'whatever');

        self::assertSame('whatever', Platform::getAiAgent());
    }

    public function testGetAiAgentCacheIsResetWhenEnvChanges(): void
    {
        Platform::putEnv('CLAUDECODE', '1');
        self::assertSame('claude-code', Platform::getAiAgent());

        Platform::putEnv('AI_AGENT', 'cursor');
        self::assertSame('cursor', Platform::getAiAgent());

        Platform::clearEnv('AI_AGENT');
        Platform::putEnv('AMP_CURRENT_THREAD_ID', 'T-123');
        self::assertSame('amp', Platform::getAiAgent());
    }

    /**
     * @dataProvider provideAiAgentValues
     */
    public function testGetAiAgentFromAiAgentEnvVar(string $value, ?string $expected): void
    {
        if (file_exists('/opt/.devin')) {
            self::markTestSkipped('Tests are running inside Devin');
        }

        Platform::putEnv('AI_AGENT', $value);

        self::assertSame($expected, Platform::getAiAgent());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideAiAgentValues(): iterable
    {
        yield 'known name' => ['claude-code', 'claude-code'];
        yield 'alias and case' => [' Claude ', 'claude-code'];
        yield 'exact name with underscores' => ['github_copilot_vscode_agent', 'github-copilot'];
        yield 'at version' => ['codex@0.5.1', 'codex'];
        yield 'space and version' => ['gemini-cli 1.2.3', 'gemini-cli'];
        yield 'slash version' => ['cursor/2.0', 'cursor'];
        yield 'dash version' => ['opencode-1.2', 'opencode'];
        yield 'v-prefixed version' => ['goose v1.0', 'goose'];
        yield 'short name that looks like a version' => ['v0', 'v0'];
        yield 'bare true value' => ['1', 'unknown'];
        yield 'unknown name is preserved' => ['New-Agent', 'new-agent'];
        yield 'unknown name is sanitized' => ['New_Agent2.0 (beta)', 'new-agent'];
        yield 'underscore in known name' => ['roo_code', 'roo-code'];
        yield 'underscore suffix after known name' => ['claude-code_2-1-201_agent', 'claude-code'];
        yield 'repeated dashes are collapsed' => ['foo--9-bar', 'foo-bar'];
        yield 'unknown name with version' => ['foo-bar-1.2.3', 'foo-bar'];
        yield 'unknown name strips invalid chars' => ['foo.bar!', 'foobar'];
        yield 'unknown name is truncated' => [str_repeat('a', 30), str_repeat('a', 20)];
        yield 'unknown name with only invalid chars' => ['42!', 'unknown'];
        yield 'long known name' => ['dsh', 'deepseek-harness'];
        yield 'falsy value' => ['false', null];
        yield 'off value' => ['OFF', null];
        yield 'zero value' => ['0', null];
        yield 'blank value' => ['  ', null];
    }

    public function testGetAiAgentNamesAreAtMost20Chars(): void
    {
        $names = array_merge(array_values(Platform::CODING_AGENT_ENV_VARS), array_values((new \ReflectionClassConstant(Platform::class, 'KNOWN_AI_AGENT_NAMES'))->getValue()));
        foreach ($names as $name) {
            self::assertMatchesRegularExpression('{^[a-z0-9-]{1,20}$}', $name);
        }
    }

    /**
     * @return iterable<array{0: ?bool}>
     */
    public static function defaultProvider(): iterable
    {
        yield [false];
        yield [true];
        yield [null];
    }

    /**
     * @dataProvider defaultProvider
     */
    public function testGetBoolEnvReturnsDefaultWhenUnset(?bool $default): void
    {
        self::assertSame($default, Platform::getBoolEnv('COMPOSER_TEST_BOOL_ENV', $default));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideBoolEnvValues(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield '1' => ['1', true];
        yield '0' => ['0', false];
        yield 'on' => ['on', true];
        yield 'off' => ['off', false];
    }

    /**
     * @dataProvider provideBoolEnvValues
     */
    public function testGetBoolEnvReturnsExpectedValue(string $value, bool $expected): void
    {
        Platform::putEnv('COMPOSER_TEST_BOOL_ENV', $value);
        self::assertSame($expected, Platform::getBoolEnv('COMPOSER_TEST_BOOL_ENV'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideInvalidBoolEnvValues(): iterable
    {
        yield 'integer above 1' => ['2'];
        yield 'integer below 0' => ['-1'];
        yield 'arbitrary string' => ['abc'];
        yield 'whitespace' => [' 1 '];
    }

    /**
     * @dataProvider provideInvalidBoolEnvValues
     */
    public function testGetBoolEnvThrowsForInvalidValue(string $value): void
    {
        Platform::putEnv('COMPOSER_TEST_BOOL_ENV', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Invalid value for COMPOSER_TEST_BOOL_ENV');

        Platform::getBoolEnv('COMPOSER_TEST_BOOL_ENV');
    }

    public function testAssertPharMetadataSafeAllowsWhenOptedIn(): void
    {
        Platform::putEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA', '1');

        Platform::assertPharMetadataSafe();

        // reaching this point without an exception is the assertion
        $this->addToAssertionCount(1);
    }

    public function testAssertPharMetadataSafeAllowsOnPhp8WithoutOptIn(): void
    {
        if (\PHP_VERSION_ID < 80000) {
            self::markTestSkipped('Only relevant on PHP 8.0+');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        Platform::assertPharMetadataSafe();

        // reaching this point without an exception is the assertion
        $this->addToAssertionCount(1);
    }

    public function testAssertPharMetadataSafeThrowsOnPhp7WithoutOptIn(): void
    {
        if (\PHP_VERSION_ID >= 80000) {
            self::markTestSkipped('Only relevant on PHP < 8.0');
        }

        Platform::clearEnv('COMPOSER_ALLOW_UNSAFE_PHAR_METADATA');

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Refusing to parse a tar/phar archive on PHP < 8.0');

        Platform::assertPharMetadataSafe();
    }
}
