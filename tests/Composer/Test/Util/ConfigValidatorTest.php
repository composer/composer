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
    public function testConfigValidatorCommitRefWarning()
    {
        $configValidator = new ConfigValidator(new NullIO());
        list(, , $warnings) = $configValidator->validate(__DIR__ . '/Fixtures/composer_commit-ref.json');

        $this->assertContains(
            'The package "some/package" is pointing to a commit-ref, this is bad practice and can cause unforeseen issues.',
            $warnings
        );
    }

    public function testConfigValidatorWarnsOnScriptDescriptionForNonexistentScript()
    {
        $configValidator = new ConfigValidator(new NullIO());
        list(, , $warnings) = $configValidator->validate(__DIR__ . '/Fixtures/composer_scripts-descriptions.json');

        $this->assertContains(
            'Description for non-existent script "phpcsxxx" found in "scripts-descriptions"',
            $warnings
        );
    }
}
