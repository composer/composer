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
use Composer\TestCase;

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
        $reflection      = new \ReflectionClass(get_class($configValidator));
        $method          = $reflection->getMethod('checkForCommitReferences');
        $warnings        = $reflection->getProperty('warnings');

        $method->setAccessible(true);
        $warnings->setAccessible(true);

        $this->assertEquals(0, count($warnings->getValue($configValidator)));

        $method->invokeArgs($configValidator, array(
            array(
                'some-package'    => 'dev-master#62c4da6',
                'another-package' => '^1.0.0'
            )
        ));

        $this->assertEquals(1, count($warnings->getValue($configValidator)));
    }
}
