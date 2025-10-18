<?php

declare(strict_types=1);

/*
* This file is part of Composer.
*
* (c) Nils Adermann <naderman@naderman.de>
*     Jordi Boggiano <j.boggiano@seld.be>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Composer\Test\OutputStability;

use Composer\Test\TestCase;
use Loophp\PathHasher\NAR;

class OutputStabilityTest extends TestCase
{
    public function testOutputStability(): void
    {
        $dir = $this->initTempComposer();

        $packages = [
            self::getPackage('foo/nakano', '1.2.3'),
            self::getPackage('foo/apollo', '4.5.6'),
        ];

        $this->createComposerLock($packages);
        $this->createInstalledJson($packages);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'install']);

        # The hash of the lockfile should be stable
        $json = file_get_contents($dir . '/composer.lock');

        if ($json === false) {
            # This is mostly to please PHPStan.
            self::fail('Failed to read file.');
        }

        $hash = json_decode($json, true)['content-hash'];

        self::assertSame('d751713988987e9331980363e24189ce', $hash, 'The lockfile hash is stable.');

        # Calculate a hash of the vendor directory to ensure its content is stable
        # This is equivalent to run `nix hash path <dir>`
        $sha256 = (new NAR())->hash($dir);

        self::assertSame('sha256-hTlal0K1SOTFgvw6G5cfUmHKQvjGPorqzKG5zmf/XIU=', $sha256, 'The sha256 of the archive is stable.');
    }
}
