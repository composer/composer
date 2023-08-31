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

namespace Composer\Test\Command;

use Composer\Test\TestCase;

class StatusCommandTest extends TestCase
{
    public function testNoLocalChanges(): void
    {
        $this->initTempComposer(['require' => ['root/req' => '1.*']]);

        $package = self::getPackage('root/req');
        $package->setType('metapackage');

        $this->createComposerLock([$package], []);
        $this->createInstalledJson([$package], []);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status']);

        $this->assertSame('No local changes', trim($appTester->getDisplay(true)));
    }

    public function testLocalPackageHasChanges(): void
    {
        $this->initTempComposer([
            'require' => ['composer/class-map-generator' => '^1.1']
        ]);

        $package = self::getPackage('composer/class-map-generator', '1.1');
        $package->setInstallationSource('source');
        $package->setSourceType('git');
        $package->setSourceUrl('https://github.com/composer/class-map-generator.git');
        $package->setSourceReference('953cc4ea32e0c31f2185549c7d216d7921f03da9');

        $this->createComposerLock([$package], []);
        $this->createInstalledJson([$package], []);

        // installed.json LGTM
        var_dump(file_get_contents(getcwd() . '/vendor/composer/installed.json'));
        // Expected some source here instead of an empty dir. Do I actually need to run a `composer install` here?
        var_dump(scandir(getcwd() . '/vendor/composer/class-map-generator/'));

        // A hack to modify a package file just to get the test stood up. Will find a better way...
        file_put_contents(getcwd() . '/vendor/composer/class-map-generator/composer.json', '{}');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'status']);

        $expected = '<error>You have changes in the following dependencies:</error>';
        $actual = trim($appTester->getDisplay(true));
        $modifiedLocalPackage = 'class-map-generator';

        $this->assertStringContainsString($expected, $actual);
        $this->assertStringContainsString($modifiedLocalPackage, $actual);
    }
}
