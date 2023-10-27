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

        $this->assertContains(
            'The package "some/package" is pointing to a commit-ref, this is bad practice and can cause unforeseen issues.',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnScriptDescriptionForNonexistentScript(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-descriptions.json');

        $this->assertContains(
            'Description for non-existent script "phpcsxxx" found in "scripts-descriptions"',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnScriptAliasForNonexistentScript(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-aliases.json');

        $this->assertContains(
            'Aliases for non-existent script "phpcsxxx" found in "scripts-aliases"',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnUnnecessaryProvideReplace(): void
    {
        $configValidator = new ConfigValidator(new NullIO());
        [, , $warnings] = $configValidator->validate(__DIR__ . '/Fixtures/composer_provide-replace-requirements.json');

        $this->assertContains(
            'The package a/a in require is also listed in provide which satisfies the requirement. Remove it from provide if you wish to install it.',
            $warnings
        );
        $this->assertContains(
            'The package b/b in require is also listed in replace which satisfies the requirement. Remove it from replace if you wish to install it.',
            $warnings
        );
        $this->assertContains(
            'The package c/c in require-dev is also listed in provide which satisfies the requirement. Remove it from provide if you wish to install it.',
            $warnings
        );
    }
}
