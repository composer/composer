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
    public static function getCommandSuggestions(): iterable
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
        yield ['install ', null];

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

        yield ['config --list ', null];
        yield ['config --editor ', null];
        yield ['config --auth ', null];

        yield ['config ', ['bin-compat', 'extra', 'extra.branch-alias', 'home', 'name', 'repositories', 'repositories.packagist.org', 'suggest', 'suggest.ext-zip', 'type', 'version']];
        yield ['config bin', ['bin-dir']]; // global setting
        yield ['config nam', ['name']];    // existing package-property
        yield ['config ver', ['version']]; // non-existing package-property
        yield ['config repo', ['repositories', 'repositories.packagist.org']];
        yield ['config repositories.', ['repositories.packagist.org']];
        yield ['config sug', ['suggest', 'suggest.ext-zip']];
        yield ['config suggest.ext-', ['suggest.ext-zip']];
        yield ['config ext', ['extra', 'extra.branch-alias', 'extra.branch-alias.dev-main']];

        // as this test does not use a fixture (yet?), the completion
        // of setting authentication settings can have varying results
        // yield ['config http-basic.', […]];

        yield ['config --unset ', ['extra', 'extra.branch-alias', 'extra.branch-alias.dev-main', 'name', 'suggest', 'suggest.ext-zip', 'type']];
        yield ['config --unset bin-dir', null]; // global setting
        yield ['config --unset nam', ['name']]; // existing package-property
        yield ['config --unset version', null]; // non-existing package-property
        yield ['config --unset extra.', ['extra.branch-alias', 'extra.branch-alias.dev-main']];

        // as this test does not use a fixture (yet?), the completion
        // of unsetting authentication settings can have varying results
        // yield ['config --unset http-basic.', […]];

        yield ['config --global ', ['bin-compat', 'home', 'repositories', 'repositories.packagist.org']];
        yield ['config --global repo', ['repositories', 'repositories.packagist.org']];
        yield ['config --global repositories.', ['repositories.packagist.org']];

        // as this test does not use a fixture (yet?), the completion
        // of unsetting global settings can have varying results
        // yield ['config --global --unset ', null];

        // as this test does not use a fixture (yet?), the completion of
        // unsetting global authentication settings can have varying results
        // yield ['config --global --unset http-basic.', […]];
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
