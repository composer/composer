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

use Composer\IO\NullIO;
use Composer\Util\ConfigValidator;
use Composer\Test\TestCase;

/**
 * ConfigValidator test case
 */
class ConfigValidatorTest extends TestCase
{
    /**
     * Test ConfigValidator warns on commit reference
     */
    public function testConfigValidatorCommitRefWarning(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_commit-ref.json');

        self::assertContains(
            'The package "some/package" is pointing to a commit-ref, this is bad practice and can cause unforeseen issues.',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnScriptDescriptionForNonexistentScript(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-descriptions.json');

        self::assertContains(
            'Description for non-existent script "phpcsxxx" found in "scripts-descriptions"',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnScriptAliasForNonexistentScript(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-aliases.json');

        self::assertContains(
            'Aliases for non-existent script "phpcsxxx" found in "scripts-aliases"',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnReferenceToNonexistentScriptInList(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-references.json');

        self::assertContains(
            'Script "ci" references non-existent script "missing" (unless provided by a plugin)',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnReferenceToNonexistentScriptInString(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-references.json');

        self::assertContains(
            'Script "lint" references non-existent script "also-missing" (unless provided by a plugin)',
            $warnings
        );
    }

    public function testConfigValidatorOnlyWarnsOnUnresolvableScriptReferences(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-references.json');

        $referenceWarnings = array_values(array_filter($warnings, static function (string $warning): bool {
            return str_contains($warning, 'references non-existent script');
        }));

        self::assertSame([
            'Script "ci" references non-existent script "missing" (unless provided by a plugin)',
            'Script "lint" references non-existent script "also-missing" (unless provided by a plugin)',
        ], $referenceWarnings);
    }

    public function testConfigValidatorDoesNotWarnOnReferencesToExistingScriptsOrEvents(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-references.json');

        foreach (['test', 'post-install-cmd', 'php', 'putenv', 'composer'] as $reference) {
            foreach ($warnings as $warning) {
                self::assertStringNotContainsString(sprintf('non-existent script "%s"', $reference), $warning);
            }
        }
    }

    public function testConfigValidatorWarnsOnUnnecessaryProvideReplace(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_provide-replace-requirements.json');

        self::assertContains(
            'The package a/a in require is also listed in provide which satisfies the requirement. Remove it from provide if you wish to install it.',
            $warnings
        );
        self::assertContains(
            'The package b/b in require is also listed in replace which satisfies the requirement. Remove it from replace if you wish to install it.',
            $warnings
        );
        self::assertContains(
            'The package c/c in require-dev is also listed in provide which satisfies the requirement. Remove it from provide if you wish to install it.',
            $warnings
        );
    }
}
