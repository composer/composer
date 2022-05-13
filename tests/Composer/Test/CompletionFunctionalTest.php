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

namespace Composer\Test;

use Composer\Console\Application;
use Symfony\Component\Console\Tester\CommandCompletionTester;

/**
 * Validate autocompletion for all commands.
 *
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class CompletionFunctionalTest extends TestCase
{
    /**
     * @return iterable<array<string|string[]|null>>
     */
    public function getCommandSuggestions(): iterable
    {
        $randomVendor = 'a/';
        $installedPackages = ['composer/semver', 'psr/log'];
        $preferInstall = ['dist', 'source', 'auto'];

        yield ['archive ', [$randomVendor]];
        yield ['archive symfony/http-', ['symfony/http-kernel', 'symfony/http-foundation']];
        yield ['archive --format ', ['tar', 'zip']];

        yield ['create-project ', [$randomVendor]];
        yield ['create-project symfony/skeleton --prefer-install ', $preferInstall];

        yield ['depends ', $installedPackages];
        yield ['why ', $installedPackages];

        yield ['exec ', ['composer', 'jsonlint', 'phpstan', 'phpstan.phar', 'simple-phpunit', 'validate-json']];

        yield ['browse ', $installedPackages];
        yield ['home -H ', $installedPackages];

        yield ['init --require ', [$randomVendor]];
        yield ['init --require-dev foo/bar --require-dev ', [$randomVendor]];

        yield ['install --prefer-install ', $preferInstall];
        yield ['install ', $installedPackages];

        yield ['outdated ', $installedPackages];

        yield ['prohibits ', [$randomVendor]];
        yield ['why-not symfony/http-ker', ['symfony/http-kernel']];

        yield ['reinstall --prefer-install ', $preferInstall];
        yield ['reinstall ', $installedPackages];

        yield ['remove ', $installedPackages];

        yield ['require --prefer-install ', $preferInstall];
        yield ['require ', [$randomVendor]];
        yield ['require --dev symfony/http-', ['symfony/http-kernel', 'symfony/http-foundation']];

        yield ['run-script ', ['compile', 'test', 'phpstan']];
        yield ['run-script test ', null];

        yield ['search --format ', ['text', 'json']];

        yield ['show --format ', ['text', 'json']];
        yield ['info ', $installedPackages];

        yield ['suggests ', $installedPackages];

        yield ['update --prefer-install ', $preferInstall];
        yield ['update ', $installedPackages];
    }

    /**
     * @dataProvider getCommandSuggestions
     *
     * @param string $input The command that is typed
     * @param string[]|null $expectedSuggestions Sample expected suggestions. Null if nothing is expected.
     */
    public function testComplete(string $input, ?array $expectedSuggestions): void
    {
        $input = explode(' ', $input);
        $commandName = array_shift($input);
        $command = $this->getApplication()->get($commandName);

        $tester = new CommandCompletionTester($command);
        $suggestions = $tester->complete($input);

        if (null === $expectedSuggestions) {
            $this->assertEmpty($suggestions);

            return;
        }

        $diff = array_diff($expectedSuggestions, $suggestions);
        $this->assertEmpty($diff, sprintf('Suggestions must contain "%s". Got "%s".', implode('", "', $diff), implode('", "', $suggestions)));
    }

    private function getApplication(): Application
    {
        return new Application();
    }
}
